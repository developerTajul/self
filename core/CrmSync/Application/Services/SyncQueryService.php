<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Services;

use Core\CrmSync\Application\Contracts\DeadLetterQueryContract;
use Core\CrmSync\Application\Contracts\SyncEventQueryContract;
use Core\CrmSync\Application\Contracts\SyncMapperContract;
use Core\CrmSync\Application\Contracts\SyncQueryServiceContract;
use Core\CrmSync\Application\DataObjects\SyncEventFilter;
use Core\CrmSync\Application\Mappers\SyncResponseMapper;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\SyncDirection;
use Core\CrmSync\Domain\Enums\SyncEventStatus;
use Core\CrmSync\Domain\Exceptions\InvalidSyncFilterException;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles CRM sync read use cases. Never changes state.
 *
 * @implements SyncQueryServiceContract
 */
final class SyncQueryService implements SyncQueryServiceContract
{
    /**
     * @param SyncEventQueryContract  $syncEventQuery
     * @param DeadLetterQueryContract $deadLetterQuery
     * @param SyncMapperContract      $syncMapper
     * @param SyncResponseMapper      $responseMapper
     * @param TranslatorContract      $translator
     * @param LoggerInterface         $logger
     */
    public function __construct(
        private readonly SyncEventQueryContract $syncEventQuery,
        private readonly DeadLetterQueryContract $deadLetterQuery,
        private readonly SyncMapperContract $syncMapper,
        private readonly SyncResponseMapper $responseMapper,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns one page of the sync audit log.
     *
     * @param int $perPage
     * @param int $page
     * @param string|null $direction
     * @param string|null $provider
     * @param string|null $status
     * @param string|null $eventType
     *
     * @return Result Carries a PaginatedResult of SyncEventResponseDTO instances.
     */
    public function getSyncEvents(
        int $perPage = 25,
        int $page = 1,
        ?string $direction = null,
        ?string $provider = null,
        ?string $status = null,
        ?string $eventType = null,
    ): Result {
        try {
            $request = $this->syncMapper->mapToSyncEventFilterDTO([
                'per_page' => $perPage,
                'page' => $page,
                'direction' => $direction,
                'provider' => $provider,
                'status' => $status,
                'event_type' => $eventType,
            ]);
            $filter = new SyncEventFilter(
                perPage: $request->perPage,
                page: $request->page,
                direction: $request->direction === null ? null : SyncDirection::from($request->direction),
                provider: $request->provider === null ? null : CrmProviderName::from($request->provider),
                status: $request->status === null ? null : SyncEventStatus::from($request->status),
                eventType: $request->eventType,
            );
            $pageResult = $this->syncEventQuery->getFilteredEvents($filter);

            return Result::success($pageResult->map(fn ($event) => $this->responseMapper->toProjectionResponseDTO($event)));
        } catch (InvalidSyncFilterException $exception) {
            return Result::failure($this->translator->translate($exception->translationKey, $exception->replace), 'crm_sync_invalid_filter');
        } catch (Throwable $exception) {
            $this->logger->error('CRM sync audit query failed.', ['exception' => $exception]);

            return Result::failure($this->translator->translate('crm_sync.query.failed'), 'crm_sync_query_failed');
        }
    }

    /**
     * Returns one page of dead letters.
     *
     * @param int $perPage
     * @param int $page
     * @param bool|null $replayed
     *
     * @return Result Carries a PaginatedResult of DeadLetterResponseDTO instances.
     */
    public function getDeadLetters(int $perPage = 25, int $page = 1, ?bool $replayed = null): Result
    {
        try {
            $filter = $this->syncMapper->mapToDeadLetterFilterDTO([
                'per_page' => $perPage,
                'page' => $page,
                'replayed' => $replayed,
            ]);
            $pageResult = $this->deadLetterQuery->getDeadLetters(
                $filter->perPage,
                $filter->page,
                $filter->replayed,
            );

            return Result::success($pageResult->map(
                fn ($deadLetter) => $this->responseMapper->deadLetterProjectionToResponseDTO($deadLetter)
            ));
        } catch (Throwable $exception) {
            $this->logger->error('Dead-letter query failed.', ['exception' => $exception]);

            return Result::failure($this->translator->translate('crm_sync.query.failed'), 'crm_sync_query_failed');
        }
    }
}
