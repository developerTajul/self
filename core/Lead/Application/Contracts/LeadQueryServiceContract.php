<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Shared\Application\DTOs\Result;

/**
 * Handles lead read use cases. Never changes state.
 *
 * Every method returns a `Result`: a read can still fail — the lead may not
 * exist — and the delivery layer branches on one shape rather than on a null
 * that could mean either "absent" or "the read broke".
 */
interface LeadQueryServiceContract
{
    /**
     * Reads one lead by id.
     *
     * @param int $leadId
     *
     * @return Result Carries a `LeadResponseDTO`, or a not-found failure.
     */
    public function getLead(int $leadId): Result;

    /**
     * Returns one page of the lead list.
     *
     * @param int                  $perPage
     * @param int                  $page
     * @param string|null          $status
     * @param string|null          $source
     * @param string|null          $search
     *
     * @return Result Carries a `PaginatedResult` of `LeadResponseDTO` instances.
     */
    public function getLeads(
        int $perPage = 15,
        int $page = 1,
        ?string $status = null,
        ?string $source = null,
        ?string $search = null,
    ): Result;
}
