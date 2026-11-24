<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Adapters;

use Core\CrmSync\Domain\Contracts\SyncEventRepositoryContract;
use Core\CrmSync\Domain\Entities\SyncEventEntity;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\LocalEntityType;
use Core\CrmSync\Domain\Enums\SyncDirection;
use Core\CrmSync\Domain\Exceptions\SyncEventDuplicateException;
use Core\Lead\Application\Contracts\LeadCaptureOutboxContract;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Shared\Domain\Contracts\ClockContract;
use RuntimeException;

/**
 * Implements the lead module's outbox port by writing the `sync_events` row.
 *
 * The lead module owns the port but not this class: a lead is captured in the
 * Lead module's transaction, and this adapter writes the outbound event through
 * the same connection while that transaction is open, so the lead and its
 * outbound event commit or roll back together (transactional outbox).
 *
 * @implements LeadCaptureOutboxContract
 */
final class LeadCaptureOutboxAdapter implements LeadCaptureOutboxContract
{
    /**
     * The ledger's event type for a captured lead.
     *
     * A constant rather than a literal at the call site, because the worker
     * dispatches on this string and a typo would queue an event no handler
     * claims — leaving it pending until it aged out, with no error to explain it.
     */
    public const EVENT_TYPE = 'lead.captured';

    /**
     * The provider a capture is owed to.
     *
     * The first release targets HubSpot only (DP-8). When a second provider is
     * added, this becomes a registry lookup rather than a second adapter, which
     * is why it is named here as one constant instead of being spread through
     * the payload.
     */
    private const PROVIDER = CrmProviderName::hubspot;

    /**
     * @param SyncEventRepositoryContract $syncEventRepository
     * @param ClockContract               $clock
     */
    public function __construct(
        private readonly SyncEventRepositoryContract $syncEventRepository,
        private readonly ClockContract $clock,
    ) {}

    /**
     * Enqueues the outbound event owed to the CRM for a captured lead.
     *
     * Must be called inside the transaction that persists the lead. A second
     * call for the same lead and attempt is idempotent by the ledger's unique
     * idempotency key, so a retried capture does not queue two events.
     *
     * The key is derived from the lead's `uuid` and the event type (DP-6), never
     * from a mutable field: the uuid is minted once at capture and never changes,
     * so the same lead always produces the same key. Deriving it from, say, the
     * email would break the guarantee the moment the lead was enriched with a
     * corrected address — the retry would compute a different key and queue a
     * second event for work that was already owed.
     *
     * The collision is absorbed rather than raised: a duplicate key here means
     * the event this lead owes is already enqueued, which is the outcome the
     * caller wanted. Raising would abort the capture transaction for a condition
     * that is not a failure.
     *
     * @param LeadEntity $lead The persisted lead, carrying its id.
     *
     * @return void
     *
     * @throws RuntimeException When the lead has never been saved.
     */
    public function enqueueForCapturedLead(LeadEntity $lead): void
    {
        // The row is keyed by the lead's id, and an unsaved lead has none: the
        // event would be filed against `local_entity_id: 0` and the worker would
        // find no lead to push.
        if ($lead->id === null) {
            throw new RuntimeException(
                'The outbound event for a captured lead was requested before the lead was saved. The '
                . 'event addresses the lead by id, and an unsaved lead has none.'
            );
        }

        $event = SyncEventEntity::enqueue(
            idempotencyKey: $this->idempotencyKey($lead),
            direction: SyncDirection::outbound,
            provider: self::PROVIDER,
            eventType: self::EVENT_TYPE,
            payload: $this->payload($lead),
            now: $this->clock->now(),
        );

        try {
            $this->syncEventRepository->create($event);
        } catch (SyncEventDuplicateException) {
            // The event this lead owes is already queued. That is the intended
            // state, so the capture proceeds — see the docblock.
        }
    }

    /**
     * The ledger's unique key for this lead's outbound event.
     *
     * `sha256(source + ':' + external id)` per DP-6, with the lead's uuid as the
     * external id and the event type standing in for the source: a capture is
     * identified by the lead it describes, and the event type keeps a future
     * "lead updated" event from colliding with the capture that preceded it.
     *
     * 64 hex characters, which fits the `varchar(191)` unique column.
     *
     * @param LeadEntity $lead
     *
     * @return string
     */
    private function idempotencyKey(LeadEntity $lead): string
    {
        return hash('sha256', self::EVENT_TYPE . ':' . $lead->uuid);
    }

    /**
     * The snapshot the worker pushes to the CRM.
     *
     * Carries `entity_type` and `local_entity_id` because the ledger has no lead
     * column (A5): the payload is the only place the event says which row it
     * concerns. `lead.uuid` travels as the external id the CRM keys on, and it is
     * what the remote record is matched by on the way back in.
     *
     * The payload is a snapshot rather than a reference, by design: a replay from
     * the DLQ must push the state that failed, not whatever the lead looks like
     * today.
     *
     * @param LeadEntity $lead
     *
     * @return array<string, mixed>
     */
    private function payload(LeadEntity $lead): array
    {
        return [
            'entity_type' => LocalEntityType::lead->value,
            'local_entity_id' => $lead->id,
            'external_id' => $lead->uuid,
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'company_name' => $lead->companyName,
            'website_url' => $lead->websiteUrl,
            'status' => $lead->status->value,
            'source' => $lead->source->value,
        ];
    }
}
