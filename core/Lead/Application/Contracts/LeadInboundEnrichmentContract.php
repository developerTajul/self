<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Application\DTOs\Requests\LeadCrmEnrichmentDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * PORT: applies a verified CRM contact to the matching local lead.
 *
 * CrmSync depends on this Lead-owned use-case boundary rather than importing a
 * Lead Entity or repository. A supplied expected id protects an existing CRM
 * mapping from being silently redirected by a mismatched external UUID.
 */
interface LeadInboundEnrichmentContract
{
    /**
     * Enriches the lead identified by its immutable public UUID.
     *
     * @param LeadCrmEnrichmentDTO $enrichment
     *
     * @return Result Carries a LeadResponseDTO on success.
     */
    public function enrichFromCrm(LeadCrmEnrichmentDTO $enrichment): Result;
}
