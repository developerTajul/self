<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Lead\Application\Contracts\LeadMapperContract;
use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Application\Contracts\LeadQueryServiceContract;
use Core\Lead\Application\Mappers\LeadResponseMapper;
use Core\Lead\Domain\Enums\LeadSource;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Exceptions\InvalidLeadFilterException;
use Core\Lead\Domain\ValueObjects\LeadFilter;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles lead read use cases. Never changes state.
 *
 * @implements LeadQueryServiceContract
 */
final class LeadQueryService implements LeadQueryServiceContract
{
    /**
     * @param LeadQueryContract      $leadQuery
     * @param LeadMapperContract     $leadMapper
     * @param LeadResponseMapper     $responseMapper
     * @param TranslatorContract     $translator
     * @param LoggerInterface        $logger
     */
    public function __construct(
        private readonly LeadQueryContract $leadQuery,
        private readonly LeadMapperContract $leadMapper,
        private readonly LeadResponseMapper $responseMapper,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Reads one lead by id.
     *
     * @param int $leadId
     *
     * @return Result
     */
    public function getLead(int $leadId): Result
    {
        try {
            $lead = $this->leadQuery->findById($leadId);

            if ($lead === null) {
                return Result::notFound($this->translator->translate('leads.errors.lead_not_found'));
            }

            return Result::success($this->responseMapper->toResponseDTO($lead));
        } catch (Throwable $exception) {
            $this->logger->error('Lead query failed.', ['lead_id' => $leadId, 'exception' => $exception]);

            return Result::failure($this->translator->translate('leads.errors.query_failed'), 'lead_query_failed');
        }
    }

    /**
     * Returns one bounded page of leads.
     *
     * @param int         $perPage
     * @param int         $page
     * @param string|null $status
     * @param string|null $source
     * @param string|null $search
     *
     * @return Result Carries a PaginatedResult of LeadResponseDTO instances.
     */
    public function getLeads(
        int $perPage = 15,
        int $page = 1,
        ?string $status = null,
        ?string $source = null,
        ?string $search = null,
    ): Result {
        try {
            $request = $this->leadMapper->mapToFilterDTO([
                'per_page' => $perPage,
                'page' => $page,
                'status' => $status,
                'source' => $source,
                'search' => $search,
            ]);

            $filter = new LeadFilter(
                perPage: $request->perPage,
                page: $request->page,
                status: $request->status === null ? null : LeadStatus::from($request->status),
                source: $request->source === null ? null : LeadSource::from($request->source),
                search: $request->search,
            );
            $pageResult = $this->leadQuery->getFilteredLeads($filter);
            $responses = $pageResult->map(fn ($lead) => $this->responseMapper->toProjectionResponseDTO($lead));

            return Result::success($responses);
        } catch (InvalidLeadFilterException $exception) {
            return Result::failure(
                $this->translator->translate('leads.errors.invalid_filter'),
                'lead_invalid_filter'
            );
        } catch (Throwable $exception) {
            $this->logger->error('Lead list query failed.', ['exception' => $exception]);

            return Result::failure($this->translator->translate('leads.errors.query_failed'), 'lead_query_failed');
        }
    }
}
