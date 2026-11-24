<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

/**
 * PORT: re-points the CRM mappings of a merged lead onto the surviving lead.
 *
 * The lead module owns this port but not its implementation: the adapter lives
 * in `core/CrmSync/Infrastructure`, because only that module knows what a
 * mapping is. When two leads merge, the survivor already has an id the CRM and
 * any existing links reference, so the secondary's mappings must follow it —
 * and where the survivor already holds a mapping for the same provider and
 * object type (A4), only one can survive, because the four-part local key is
 * UNIQUE.
 */
interface LeadMappingRelinkContract
{
    /**
     * Moves a merged-away lead's CRM mappings onto the surviving lead.
     *
     * Called inside the transaction that performs the merge, so the mappings and
     * the lead rows change together. A mapping the surviving lead already holds
     * wins; the conflicting one is deleted and the removal logged (A4).
     *
     * @param int $secondaryLeadId The lead being merged away.
     * @param int $survivingLeadId The lead that survives.
     *
     * @return int How many mappings were re-pointed.
     */
    public function relinkMappings(int $secondaryLeadId, int $survivingLeadId): int;
}
