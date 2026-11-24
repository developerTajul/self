<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Services;

use Core\CrmSync\Application\Contracts\CrmMappingQueryContract;
use Core\CrmSync\Application\Contracts\CrmProviderRegistryContract;
use Core\CrmSync\Application\Contracts\SyncCommandServiceContract;
use Core\CrmSync\Application\Contracts\SyncEventQueryContract;
use Core\Lead\Application\DTOs\Requests\LeadCrmEnrichmentDTO;
use Core\CrmSync\Application\DTOs\Requests\WebhookEnqueueDTO;
use Core\CrmSync\Application\DTOs\Responses\SyncBatchResponseDTO;
use Core\CrmSync\Domain\Contracts\CrmMappingRepositoryContract;
use Core\CrmSync\Domain\Contracts\DeadLetterRepositoryContract;
use Core\CrmSync\Domain\Contracts\SyncEventRepositoryContract;
use Core\CrmSync\Domain\Entities\CrmMappingEntity;
use Core\CrmSync\Domain\Entities\DeadLetterEntity;
use Core\CrmSync\Domain\Entities\SyncEventEntity;
use Core\CrmSync\Domain\Enums\CrmObjectType;
use Core\CrmSync\Domain\Enums\LocalEntityType;
use Core\CrmSync\Domain\Enums\SyncDirection;
use Core\CrmSync\Domain\Enums\SyncEventStatus;
use Core\CrmSync\Domain\Exceptions\CrmPermanentException;
use Core\CrmSync\Domain\Exceptions\CrmTransientException;
use Core\CrmSync\Domain\Exceptions\DeadLetterAlreadyReplayedException;
use Core\CrmSync\Domain\Exceptions\SyncEventDuplicateException;
use Core\CrmSync\Domain\Exceptions\SyncEventTransitionException;
use Core\CrmSync\Domain\Rules\SyncRetryRules;
use Core\CrmSync\Domain\ValueObjects\ContentHash;
use Core\CrmSync\Domain\ValueObjects\RemoteId;
use Core\CrmSync\Domain\ValueObjects\SanitizedErrorMessage;
use Core\Lead\Application\Contracts\LeadInboundEnrichmentContract;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\ClockContract;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Core\Shared\Domain\Contracts\UuidGeneratorContract;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles CRM sync write use cases.
 *
 * @implements SyncCommandServiceContract
 */
final class SyncCommandService implements SyncCommandServiceContract
{
    /**
     * @param SyncEventRepositoryContract   $syncEventRepository
     * @param SyncEventQueryContract        $syncEventQuery
     * @param CrmMappingRepositoryContract  $crmMappingRepository
     * @param CrmMappingQueryContract       $crmMappingQuery
     * @param DeadLetterRepositoryContract  $deadLetterRepository
     * @param LeadInboundEnrichmentContract $leadEnrichment
     * @param CrmProviderRegistryContract   $providerRegistry
     * @param TransactionManagerContract    $transactionManager
     * @param ClockContract                 $clock
     * @param UuidGeneratorContract         $uuidGenerator
     * @param TranslatorContract            $translator
     * @param LoggerInterface               $logger
     */
    public function __construct(
        private readonly SyncEventRepositoryContract $syncEventRepository,
        private readonly SyncEventQueryContract $syncEventQuery,
        private readonly CrmMappingRepositoryContract $crmMappingRepository,
        private readonly CrmMappingQueryContract $crmMappingQuery,
        private readonly DeadLetterRepositoryContract $deadLetterRepository,
        private readonly LeadInboundEnrichmentContract $leadEnrichment,
        private readonly CrmProviderRegistryContract $providerRegistry,
        private readonly TransactionManagerContract $transactionManager,
        private readonly ClockContract $clock,
        private readonly UuidGeneratorContract $uuidGenerator,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Claims and processes one bounded batch of due events.
     *
     * @return Result Carries SyncBatchResponseDTO counts.
     */
    public function processOutboundBatch(): Result
    {
        $claim = $this->executeInTransaction(
            fn (): array => $this->syncEventRepository->claimDueEvents($this->clock->now(), SyncRetryRules::BATCH_SIZE),
            'Claim due CRM sync events',
            'crm_sync.worker.batch_failed',
            'crm_sync_operation_failed',
        );

        if ($claim->isFailure()) {
            return $claim;
        }

        $claimed = $claim->getData();
        $succeeded = 0;
        $retried = 0;
        $dead = 0;

        foreach ($claimed as $event) {
            $outcome = $this->performRemoteOperation($event);
            $recorded = $this->recordOutcome($event, $outcome);

            if ($recorded->isFailure()) {
                return $recorded;
            }

            $counts = $recorded->getData();
            $succeeded += $counts['succeeded'];
            $retried += $counts['retried'];
            $dead += $counts['dead'];
        }

        return Result::success(
            new SyncBatchResponseDTO(count($claimed), $succeeded, $retried, $dead),
            $this->translator->translate('crm_sync.worker.batch_succeeded'),
        );
    }

    /**
     * Enqueues one already-verified inbound webhook.
     *
     * @param WebhookEnqueueDTO $webhookEnqueueDTO
     *
     * @return Result Carries the event id, or succeeds as a duplicate no-op.
     */
    public function enqueueInboundWebhook(WebhookEnqueueDTO $webhookEnqueueDTO): Result
    {
        $key = hash('sha256', $webhookEnqueueDTO->source . ':' . $webhookEnqueueDTO->externalId);
        $result = $this->executeInTransaction(function () use ($webhookEnqueueDTO, $key): SyncEventEntity {
            return $this->syncEventRepository->create(SyncEventEntity::enqueue(
                idempotencyKey: $key,
                direction: SyncDirection::inbound,
                provider: $webhookEnqueueDTO->provider,
                eventType: $webhookEnqueueDTO->eventType,
                payload: [
                    'object_type' => CrmObjectType::contact->value,
                    'remote_id' => $webhookEnqueueDTO->remoteId,
                    'payload' => $webhookEnqueueDTO->payload,
                    'source' => $webhookEnqueueDTO->source,
                    'external_id' => $webhookEnqueueDTO->externalId,
                ],
                now: $this->clock->now(),
            ));
        }, 'Enqueue inbound CRM webhook', 'crm_sync.webhook.enqueue_failed', 'crm_sync_operation_failed');

        if ($result->errorCode() === 'crm_sync_duplicate') {
            return Result::success(null, $this->translator->translate('crm_sync.webhook.duplicate'));
        }

        if ($result->isFailure()) {
            return $result;
        }

        return Result::success($result->getData()->id, $this->translator->translate('crm_sync.webhook.enqueued'));
    }

    /**
     * Processes one claimed inbound event.
     *
     * @param int $syncEventId
     *
     * @return Result
     */
    public function processInboundEvent(int $syncEventId): Result
    {
        $loaded = $this->executeInTransaction(
            fn (): ?SyncEventEntity => $this->syncEventRepository->findByIdForUpdate($syncEventId),
            'Load claimed inbound CRM event',
            'crm_sync.worker.event_failed',
            'crm_sync_operation_failed',
        );

        if ($loaded->isFailure()) {
            return $loaded;
        }

        $event = $loaded->getData();

        if (! $event instanceof SyncEventEntity || $event->status !== SyncEventStatus::processing || $event->direction !== SyncDirection::inbound) {
            return Result::failure($this->translator->translate('crm_sync.errors.event_not_processing'), 'crm_sync_event_not_processing');
        }

        return $this->recordOutcome($event, $this->performRemoteOperation($event));
    }

    /**
     * Releases stale leases and dead-letters exhausted events.
     *
     * @return Result Carries released and dead-letter counts.
     */
    public function releaseStaleLeases(): Result
    {
        $releasedCount = 0;
        $deadCount = 0;

        for ($pass = 0; $pass < SyncRetryRules::BATCH_SIZE; $pass++) {
            // Catch inside the transaction callback: D-046 requires successful
            // releases in this batch to commit before the exhausted row is filed.
            $result = $this->executeInTransaction(function (): array|SyncEventTransitionException {
                try {
                    return $this->syncEventRepository->releaseStaleLeases($this->clock->now());
                } catch (SyncEventTransitionException $exception) {
                    return $exception;
                }
            }, 'Release stale CRM sync leases', 'crm_sync.reaper.failed', 'crm_sync_operation_failed');

            if ($result->isFailure()) {
                return $result;
            }

            $batch = $result->getData();

            if (is_array($batch)) {
                $releasedCount += count($batch);
                break;
            }

            $eventId = $this->syncEventQuery->findOldestExhaustedStaleEventId($this->clock->now());

            if ($eventId === null) {
                return Result::failure($this->translator->translate('crm_sync.reaper.failed'), 'crm_sync_reaper_failed');
            }

            $deadResult = $this->deadLetterExhaustedEvent($eventId, $batch->getMessage());

            if ($deadResult->isFailure()) {
                return $deadResult;
            }

            // The exhausted row is dead; the next pass releases whatever is left.
            // A row another actor settled first reports false and is not counted.
            if ($deadResult->getData() === true) {
                $deadCount++;
            }
        }

        return Result::success(
            new SyncBatchResponseDTO($releasedCount + $deadCount, $releasedCount, 0, $deadCount),
            $this->translator->translate('crm_sync.reaper.succeeded'),
        );
    }

    /**
     * Replays a dead letter once using a fresh event key.
     *
     * @param int $deadLetterId
     *
     * @return Result Carries the new event id.
     */
    public function replayDeadLetter(int $deadLetterId): Result
    {
        $result = $this->executeInTransaction(function () use ($deadLetterId): ?int {
            $deadLetter = $this->deadLetterRepository->findByIdForUpdate($deadLetterId);

            if ($deadLetter === null) {
                return null;
            }

            $direction = $deadLetter->jobClass === SyncEventEntity::HANDLER_INBOUND
                ? SyncDirection::inbound
                : SyncDirection::outbound;
            $event = SyncEventEntity::enqueue(
                idempotencyKey: hash('sha256', 'replay:' . $deadLetterId . ':' . $this->uuidGenerator->generate()),
                direction: $direction,
                provider: $deadLetter->provider,
                eventType: 'dead_letter.replay',
                payload: $deadLetter->payload,
                now: $this->clock->now(),
            );
            $stored = $this->syncEventRepository->create($event);
            $this->deadLetterRepository->update($deadLetter->markReplayed((int) $stored->id, $this->clock->now()));

            return (int) $stored->id;
        }, 'Replay CRM dead letter', 'crm_sync.dead_letter.replay_failed', 'crm_sync_operation_failed');

        if ($result->isFailure()) {
            return $result;
        }

        $newEventId = $result->getData();

        if ($newEventId === null) {
            return Result::notFound($this->translator->translate('crm_sync.errors.dead_letter_not_found'));
        }

        return Result::success($newEventId, $this->translator->translate('crm_sync.dead_letter.replayed'));
    }

    /**
     * Performs the CRM read/write outside a transaction and returns a typed outcome.
     *
     * @param SyncEventEntity $event
     *
     * @return array<string, mixed>
     */
    private function performRemoteOperation(SyncEventEntity $event): array
    {
        try {
            $gateway = $this->providerRegistry->gatewayFor($event->provider);
            $objectType = ($event->payload['object_type'] ?? 'contact') === 'deal'
                ? CrmObjectType::deal
                : CrmObjectType::contact;

            if ($event->direction === SyncDirection::outbound) {
                $properties = $this->outboundProperties($event->payload);
                $entityType = ($event->payload['entity_type'] ?? 'lead') === 'order'
                    ? LocalEntityType::order
                    : LocalEntityType::lead;
                $mapping = $this->crmMappingQuery->findByLocalKey(
                    $entityType,
                    (int) ($event->payload['local_entity_id'] ?? 0),
                    $event->provider,
                    $objectType,
                );
                $push = $mapping === null
                    ? $gateway->createRecord($objectType, $properties)
                    : $gateway->updateRecord($objectType, $mapping->remoteId, $properties);

                return ['kind' => 'success', 'remote_id' => $push->remoteId, 'hash' => ContentHash::fromArray($this->hashProperties($properties)), 'status' => $push->httpStatusCode];
            }

            $remoteId = RemoteId::fromString((string) ($event->payload['remote_id'] ?? ''), $event->provider);
            $properties = $gateway->fetchRecord($objectType, $remoteId);
            $normalized = $this->normalizeHubSpotProperties($properties);

            return ['kind' => 'inbound', 'remote_id' => $remoteId, 'hash' => ContentHash::fromArray($this->hashProperties($properties)), 'properties' => $normalized];
        } catch (CrmTransientException $exception) {
            return ['kind' => 'transient', 'message' => $exception->getMessage(), 'status' => $exception->httpStatusCode];
        } catch (CrmPermanentException $exception) {
            return ['kind' => 'permanent', 'message' => $exception->getMessage(), 'status' => $exception->httpStatusCode];
        } catch (Throwable $exception) {
            return ['kind' => 'permanent', 'message' => $exception->getMessage(), 'status' => null];
        }
    }

    /**
     * Records one event outcome atomically.
     *
     * @param SyncEventEntity $event
     * @param array<string, mixed> $outcome
     *
     * @return Result Carries outcome counts or a failure.
     */
    private function recordOutcome(SyncEventEntity $event, array $outcome): Result
    {
        $result = $this->executeInTransaction(function () use ($event, $outcome): array {
            $locked = $this->syncEventRepository->findByIdForUpdate((int) $event->id);

            if ($locked === null || $locked->status !== SyncEventStatus::processing) {
                throw SyncEventTransitionException::notProcessing($locked?->status ?? SyncEventStatus::dead, 'record an event outcome');
            }

            if ($outcome['kind'] === 'success') {
                $this->recordMapping($locked, $outcome);
                $this->syncEventRepository->update($locked->markSucceeded($this->clock->now()));
                return ['succeeded' => 1, 'retried' => 0, 'dead' => 0];
            }

            if ($outcome['kind'] === 'inbound') {
                try {
                    $this->applyInbound($locked, $outcome);
                    $this->syncEventRepository->update($locked->markSucceeded($this->clock->now()));
                    return ['succeeded' => 1, 'retried' => 0, 'dead' => 0];
                } catch (CrmPermanentException $exception) {
                    $outcome = [
                        'kind' => 'permanent',
                        'message' => $exception->getMessage(),
                        'status' => $exception->httpStatusCode,
                    ];
                }
            }

            $error = SanitizedErrorMessage::fromString((string) ($outcome['message'] ?? ''));
            $status = isset($outcome['status']) && is_int($outcome['status']) ? $outcome['status'] : null;

            if ($outcome['kind'] === 'transient' && SyncRetryRules::mayRetry($locked->attempts + 1)) {
                $this->syncEventRepository->update($locked->scheduleRetry($this->clock->now(), random_int(0, 10), $error->toString()));
                return ['succeeded' => 0, 'retried' => 1, 'dead' => 0];
            }

            $dead = $locked->markDead($this->clock->now(), $error);
            $this->syncEventRepository->update($dead);
            $this->deadLetterRepository->createIfAbsentForEvent(
                DeadLetterEntity::fromDeadEvent($dead, $error, $this->clock->now(), $status)
            );

            return ['succeeded' => 0, 'retried' => 0, 'dead' => 1];
        }, 'Record CRM sync event outcome', 'crm_sync.worker.event_failed', 'crm_sync_operation_failed');

        return $result;
    }

    /**
     * Stores the mapping and last-synced contact hash after a successful push.
     *
     * @param SyncEventEntity $event
     * @param array<string, mixed> $outcome
     *
     * @return void
     */
    private function recordMapping(SyncEventEntity $event, array $outcome): void
    {
        $entityType = ($event->payload['entity_type'] ?? 'lead') === 'order'
            ? LocalEntityType::order
            : LocalEntityType::lead;
        $localId = (int) ($event->payload['local_entity_id'] ?? 0);
        $objectType = ($event->payload['object_type'] ?? 'contact') === 'deal'
            ? CrmObjectType::deal
            : CrmObjectType::contact;
        $mapping = $this->crmMappingQuery->findByLocalKey($entityType, $localId, $event->provider, $objectType);

        if ($mapping === null) {
            $this->crmMappingRepository->create(CrmMappingEntity::link(
                $entityType,
                $localId,
                $event->provider,
                $objectType,
                $outcome['remote_id'],
                $this->clock->now(),
            )->recordSync($outcome['hash'], $this->clock->now()));
            return;
        }

        $this->crmMappingRepository->update($mapping->recordSync($outcome['hash'], $this->clock->now()));
    }

    /**
     * Applies a changed inbound snapshot after verifying the remote mapping and immutable UUID.
     *
     * @param SyncEventEntity $event
     * @param array<string, mixed> $outcome
     *
     * @return void
     */
    private function applyInbound(SyncEventEntity $event, array $outcome): void
    {
        $objectType = ($event->payload['object_type'] ?? 'contact') === 'deal' ? CrmObjectType::deal : CrmObjectType::contact;
        $mapping = $this->crmMappingQuery->findByRemoteKey($event->provider, $objectType, $outcome['remote_id']);

        if ($mapping === null || $mapping->entityType !== LocalEntityType::lead) {
            throw new CrmPermanentException('The inbound CRM record has no local lead mapping.');
        }

        if ($mapping->isEcho($outcome['hash'])) {
            return;
        }

        $properties = $outcome['properties'];
        $uuid = $properties['external_id'] ?? null;

        if (! is_string($uuid) || $uuid === '') {
            throw new CrmPermanentException('The inbound HubSpot external identifier is missing.');
        }

        $enrichment = new LeadCrmEnrichmentDTO(
            uuid: $uuid,
            expectedLeadId: $mapping->localEntityId,
            name: $properties['name'],
            email: $properties['email'],
            phone: $properties['phone'],
            companyName: $properties['company_name'],
            websiteUrl: $properties['website_url'],
        );
        $result = $this->leadEnrichment->enrichFromCrm($enrichment);

        if ($result->isFailure()) {
            throw new CrmPermanentException($result->getMessage());
        }

        $this->crmMappingRepository->update($mapping->recordSync($outcome['hash'], $this->clock->now()));
    }

    /**
     * Marks an exhausted stale event dead and files its scrubbed payload atomically.
     *
     * @param int $eventId
     * @param string $message
     *
     * @return Result Carries true when the event was filed, false when it was already settled.
     */
    private function deadLetterExhaustedEvent(int $eventId, string $message): Result
    {
        return $this->executeInTransaction(function () use ($eventId, $message): bool {
            $event = $this->syncEventRepository->findByIdForUpdate($eventId);

            if ($event === null || $event->status !== SyncEventStatus::processing || ! $event->isExhausted()) {
                return false;
            }

            $error = SanitizedErrorMessage::fromString($message);
            $dead = $event->markDead($this->clock->now(), $error);
            $this->syncEventRepository->update($dead);
            $this->deadLetterRepository->createIfAbsentForEvent(
                DeadLetterEntity::fromDeadEvent($dead, $error, $this->clock->now())
            );

            return true;
        }, 'Dead-letter exhausted CRM sync event', 'crm_sync.reaper.failed', 'crm_sync_operation_failed');
    }

    /**
     * Creates the canonical property set used by outbound sync and hash comparison.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function outboundProperties(array $payload): array
    {
        $name = $this->nullableString($payload['name'] ?? null);
        $parts = $name === null ? [] : preg_split('/\s+/', $name, 2);

        return [
            'leadbridge_external_id' => (string) ($payload['external_id'] ?? ''),
            'email' => $this->nullableString($payload['email'] ?? null),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'firstname' => $parts[0] ?? null,
            'lastname' => $parts[1] ?? null,
            'company' => $this->nullableString($payload['company_name'] ?? null),
            'website' => $this->nullableString($payload['website_url'] ?? null),
        ];
    }

    /**
     * Normalizes HubSpot properties to the Lead enrichment shape.
     *
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function normalizeHubSpotProperties(array $properties): array
    {
        $first = $this->nullableString($properties['firstname'] ?? null);
        $last = $this->nullableString($properties['lastname'] ?? null);
        $name = trim(($first ?? '') . ' ' . ($last ?? ''));

        return [
            'external_id' => $this->nullableString($properties['leadbridge_external_id'] ?? null),
            'email' => $this->nullableString($properties['email'] ?? null),
            'phone' => $this->nullableString($properties['phone'] ?? null),
            'firstname' => $first,
            'lastname' => $last,
            'company' => $this->nullableString($properties['company'] ?? null),
            'website' => $this->nullableString($properties['website'] ?? null),
            'name' => $name === '' ? null : $name,
            'company_name' => $this->nullableString($properties['company'] ?? null),
            'website_url' => $this->nullableString($properties['website'] ?? null),
        ];
    }

    /**
     * Canonicalizes the provider-shaped properties that both directions hash.
     *
     * Outbound and inbound state must produce the same digest for the echo check
     * to work, and the two arrive in different shapes: outbound properties are
     * built here from the ledger snapshot, inbound ones are returned by HubSpot.
     * Reducing both to this one map — with email case-folded and the phone reduced
     * to its digits behind a leading `+` — is what makes the comparison meaningful.
     *
     * @param array<string, mixed> $properties Provider-shaped properties.
     *
     * @return array<string, string|null>
     */
    private function hashProperties(array $properties): array
    {
        $email = $this->nullableString($properties['email'] ?? null);
        $phone = $this->nullableString($properties['phone'] ?? null);

        return [
            'leadbridge_external_id' => $this->nullableString($properties['leadbridge_external_id'] ?? null),
            'email' => $email === null ? null : mb_strtolower($email),
            'phone' => $phone === null ? null : $this->canonicalPhone($phone),
            'firstname' => $this->nullableString($properties['firstname'] ?? null),
            'lastname' => $this->nullableString($properties['lastname'] ?? null),
            'company' => $this->nullableString($properties['company'] ?? null),
            'website' => $this->nullableString($properties['website'] ?? null),
        ];
    }

    /**
     * Reduces a phone number to `+` followed by its digits.
     *
     * The lead stores E.164, so `+15550100199`, `15550100199` and
     * `+1 (555) 010-0199` all denote the same line but would hash differently.
     * Comparing the digits alone is what keeps an unchanged number from looking
     * like a remote edit and pushing a write back out.
     *
     * @param string $phone
     *
     * @return string|null Null when no digits remain.
     */
    private function canonicalPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? null : '+' . $digits;
    }

    /**
     * Normalizes optional text values from a provider payload.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Runs a single write operation with the shared rollback/error boundary.
     *
     * @param callable(): mixed $operation
     * @param string $logMessage
     * @param string $fallbackMessageKey
     * @param string $fallbackErrorCode
     * @param array<string, mixed> $context
     *
     * @return Result
     */
    private function executeInTransaction(
        callable $operation,
        string $logMessage,
        string $fallbackMessageKey,
        string $fallbackErrorCode,
        array $context = [],
    ): Result {
        try {
            $value = $this->transactionManager->run($operation);

            return Result::success($value);
        } catch (SyncEventDuplicateException $exception) {
            return Result::failure($this->translator->translate($exception->translationKey), 'crm_sync_duplicate');
        } catch (DeadLetterAlreadyReplayedException $exception) {
            return Result::failure($this->translator->translate($exception->translationKey), 'crm_sync_dead_letter_replayed');
        } catch (Throwable $exception) {
            $this->logger->error($logMessage, [...$context, 'exception' => $exception]);

            return Result::failure($this->translator->translate($fallbackMessageKey), $fallbackErrorCode);
        }
    }
}
