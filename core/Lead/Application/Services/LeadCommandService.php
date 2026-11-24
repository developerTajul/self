<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Lead\Application\Contracts\LeadCaptureOutboxContract;
use Core\Lead\Application\Contracts\LeadCommandServiceContract;
use Core\Lead\Application\Contracts\LeadInboundEnrichmentContract;
use Core\Lead\Application\Contracts\LeadMappingRelinkContract;
use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Application\DTOs\Requests\LeadCaptureDTO;
use Core\Lead\Application\DTOs\Requests\LeadCrmEnrichmentDTO;
use Core\Lead\Application\Mappers\LeadResponseMapper;
use Core\Lead\Domain\Contracts\LeadRepositoryContract;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\Exceptions\InvalidEmailAddressException;
use Core\Lead\Domain\Exceptions\InvalidPhoneNumberException;
use Core\Lead\Domain\Exceptions\LeadContactKeyMissingException;
use Core\Lead\Domain\Exceptions\LeadDuplicateException;
use Core\Lead\Domain\Exceptions\LeadNotFoundException;
use Core\Lead\Domain\ValueObjects\EmailAddress;
use Core\Lead\Domain\ValueObjects\PhoneNumber;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\ClockContract;
use Core\Shared\Domain\Contracts\EventDispatcherContract;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Core\Shared\Domain\Contracts\UuidGeneratorContract;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles lead write use cases.
 *
 * @implements LeadCommandServiceContract
 */
final class LeadCommandService implements LeadCommandServiceContract, LeadInboundEnrichmentContract
{
    /**
     * @param LeadRepositoryContract     $leadRepository
     * @param LeadQueryContract          $leadQuery
     * @param LeadCaptureOutboxContract  $outbox
     * @param LeadMappingRelinkContract  $mappingRelink
     * @param LeadResponseMapper         $responseMapper
     * @param TransactionManagerContract $transactionManager
     * @param EventDispatcherContract    $eventDispatcher
     * @param UuidGeneratorContract      $uuidGenerator
     * @param ClockContract              $clock
     * @param TranslatorContract         $translator
     * @param LoggerInterface            $logger
     */
    public function __construct(
        private readonly LeadRepositoryContract $leadRepository,
        private readonly LeadQueryContract $leadQuery,
        private readonly LeadCaptureOutboxContract $outbox,
        private readonly LeadMappingRelinkContract $mappingRelink,
        private readonly LeadResponseMapper $responseMapper,
        private readonly TransactionManagerContract $transactionManager,
        private readonly EventDispatcherContract $eventDispatcher,
        private readonly UuidGeneratorContract $uuidGenerator,
        private readonly ClockContract $clock,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Captures, enriches or merges a lead and records its outbound obligation.
     *
     * @param LeadCaptureDTO $leadCaptureDTO
     *
     * @return Result Carries a LeadResponseDTO.
     */
    public function captureLead(LeadCaptureDTO $leadCaptureDTO): Result
    {
        try {
            $email = EmailAddress::fromNullable($leadCaptureDTO->email);
            $phone = PhoneNumber::fromNullable($leadCaptureDTO->phone);

            return $this->executeInTransaction(function () use ($leadCaptureDTO, $email, $phone): Result {
                $leadByEmail = $email === null ? null : $this->leadQuery->findByEmail($email->value);
                $leadByPhone = $phone === null ? null : $this->leadQuery->findByPhone($phone->value);

                if ($leadByEmail !== null && $leadByPhone !== null && $leadByEmail->id !== $leadByPhone->id) {
                    $ids = [$leadByEmail->id, $leadByPhone->id];
                    sort($ids, SORT_NUMERIC);
                    $first = $this->leadRepository->findByIdForUpdate((int) $ids[0]);
                    $second = $this->leadRepository->findByIdForUpdate((int) $ids[1]);

                    if ($first === null || $second === null) {
                        throw new LeadNotFoundException((int) ($first === null ? $ids[0] : $ids[1]));
                    }

                    $survivor = $first->absorb($second, $this->clock->now());
                    $this->mappingRelink->relinkMappings((int) $second->id, (int) $first->id);
                    $this->leadRepository->delete((int) $second->id);
                    $lead = $this->leadRepository->update($survivor);
                } elseif ($leadByEmail !== null || $leadByPhone !== null) {
                    $matched = $leadByEmail ?? $leadByPhone;
                    $lead = $this->leadRepository->findByIdForUpdate((int) $matched->id);

                    if ($lead === null) {
                        throw new LeadNotFoundException((int) $matched->id);
                    }

                    $lead = $this->leadRepository->update($lead->enrichWith(
                        email: $email,
                        phone: $phone,
                        name: $leadCaptureDTO->name,
                        companyName: $leadCaptureDTO->companyName,
                        websiteUrl: $leadCaptureDTO->websiteUrl,
                        meta: $leadCaptureDTO->meta,
                        now: $this->clock->now(),
                    ));
                } else {
                    $lead = $this->leadRepository->create(LeadEntity::capture(
                        uuid: $this->uuidGenerator->generate(),
                        email: $email,
                        phone: $phone,
                        name: $leadCaptureDTO->name,
                        companyName: $leadCaptureDTO->companyName,
                        websiteUrl: $leadCaptureDTO->websiteUrl,
                        source: $leadCaptureDTO->source,
                        meta: $leadCaptureDTO->meta,
                        now: $this->clock->now(),
                        assignedTo: $leadCaptureDTO->assignedTo,
                    ));
                }

                $this->outbox->enqueueForCapturedLead($lead);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($lead),
                    message: $this->translator->translate('leads.capture.succeeded'),
                );
            });
        } catch (LeadDuplicateException) {
            try {
                return $this->captureAfterDuplicate($leadCaptureDTO);
            } catch (Throwable $retryException) {
                return $this->failureFor($retryException);
            }
        } catch (Throwable $exception) {
            return $this->failureFor($exception);
        }
    }

    /**
     * Retries a raced capture once after the failed transaction rolled back.
     *
     * @param LeadCaptureDTO $leadCaptureDTO
     *
     * @return Result
     */
    private function captureAfterDuplicate(LeadCaptureDTO $leadCaptureDTO): Result
    {
        $email = EmailAddress::fromNullable($leadCaptureDTO->email);
        $phone = PhoneNumber::fromNullable($leadCaptureDTO->phone);
        return $this->executeInTransaction(function () use ($leadCaptureDTO, $email, $phone): Result {
            $leadByEmail = $email === null ? null : $this->leadQuery->findByEmail($email->value);
            $leadByPhone = $phone === null ? null : $this->leadQuery->findByPhone($phone->value);
            $lead = $leadByEmail ?? $leadByPhone;

            if ($lead === null) {
                throw new LeadDuplicateException();
            }

            $lead = $this->leadRepository->findByIdForUpdate((int) $lead->id);

            if ($lead === null) {
                throw new LeadNotFoundException((int) ($leadByEmail ?? $leadByPhone)->id);
            }

            $lead = $this->leadRepository->update($lead->enrichWith(
                email: $email,
                phone: $phone,
                name: $leadCaptureDTO->name,
                companyName: $leadCaptureDTO->companyName,
                websiteUrl: $leadCaptureDTO->websiteUrl,
                meta: $leadCaptureDTO->meta,
                now: $this->clock->now(),
            ));
            $this->outbox->enqueueForCapturedLead($lead);

            return Result::success(
                data: $this->responseMapper->toResponseDTO($lead),
                message: $this->translator->translate('leads.capture.succeeded'),
            );
        });
    }

    /**
     * Applies an inbound CRM contact to the lead identified by its public UUID.
     *
     * @param LeadCrmEnrichmentDTO $enrichment
     *
     * @return Result Carries a LeadResponseDTO.
     */
    public function enrichFromCrm(LeadCrmEnrichmentDTO $enrichment): Result
    {
        try {
            $email = EmailAddress::fromNullable($enrichment->email);
            $phone = PhoneNumber::fromNullable($enrichment->phone);

            return $this->executeInTransaction(function () use ($enrichment, $email, $phone): Result {
                $matched = $this->leadQuery->findByUuid($enrichment->uuid);

                if ($matched === null || ($enrichment->expectedLeadId !== null && $matched->id !== $enrichment->expectedLeadId)) {
                    throw new LeadNotFoundException($enrichment->expectedLeadId ?? 0);
                }

                $lead = $this->leadRepository->findByIdForUpdate((int) $matched->id);

                if ($lead === null || $lead->uuid !== $enrichment->uuid) {
                    throw new LeadNotFoundException((int) $matched->id);
                }

                $updated = $lead->enrichWith(
                    email: $email,
                    phone: $phone,
                    name: $enrichment->name,
                    companyName: $enrichment->companyName,
                    websiteUrl: $enrichment->websiteUrl,
                    meta: [],
                    now: $this->clock->now(),
                );

                if (! $updated->hasContactKey()) {
                    throw LeadContactKeyMissingException::forCapture();
                }

                $stored = $this->leadRepository->update($updated);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($stored),
                    message: $this->translator->translate('leads.enrichment.succeeded'),
                );
            });
        } catch (Throwable $exception) {
            return $this->failureFor($exception);
        }
    }

    /**
     * Runs one lead write operation inside the transaction boundary.
     *
     * @param callable(): Result $operation
     *
     * @return Result
     */
    private function executeInTransaction(callable $operation): Result
    {
        return $this->transactionManager->run($operation);
    }

    /**
     * Maps an unexpected or domain failure and logs only unexpected faults.
     *
     * @param Throwable $exception
     *
     * @return Result
     */
    private function failureFor(Throwable $exception): Result
    {
        if ($exception instanceof LeadContactKeyMissingException) {
            return Result::failure(
                $this->translator->translate(LeadContactKeyMissingException::TRANSLATION_PREFIX . 'contact_key_missing'),
                'lead_contact_key_missing'
            );
        }

        if ($exception instanceof InvalidEmailAddressException || $exception instanceof InvalidPhoneNumberException) {
            return Result::failure(
                $this->translator->translate($exception->translationKey, $exception->replace),
                'lead_contact_invalid'
            );
        }

        if ($exception instanceof LeadNotFoundException) {
            return Result::failure(
                $this->translator->translate($exception->translationKey),
                'lead_not_found'
            );
        }

        $this->logger->error('Lead capture failed.', ['exception' => $exception]);

        return Result::failure(
            $this->translator->translate('leads.errors.capture_failed'),
            'lead_operation_failed'
        );
    }
}
