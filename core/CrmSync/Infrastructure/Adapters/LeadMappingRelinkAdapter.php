<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Adapters;

use Core\CrmSync\Application\Contracts\CrmMappingQueryContract;
use Core\CrmSync\Domain\Contracts\CrmMappingRepositoryContract;
use Core\CrmSync\Domain\Entities\CrmMappingEntity;
use Core\CrmSync\Domain\Enums\LocalEntityType;
use Core\CrmSync\Domain\Exceptions\CrmMappingDuplicateException;
use Core\Lead\Application\Contracts\LeadMappingRelinkContract;
use Core\Shared\Domain\Contracts\ClockContract;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Implements the lead module's re-link port by moving CRM mappings on merge.
 *
 * The lead module owns the port but not this class: when two leads merge, the
 * secondary lead's mappings are re-pointed at the survivor. A mapping the
 * survivor already holds wins, and the conflicting one is deleted and the
 * removal logged (A4).
 *
 * @implements LeadMappingRelinkContract
 */
final class LeadMappingRelinkAdapter implements LeadMappingRelinkContract
{
    /**
     * @param CrmMappingRepositoryContract $crmMappingRepository
     * @param CrmMappingQueryContract      $crmMappingQuery
     * @param ClockContract                $clock
     * @param LoggerInterface              $logger
     */
    public function __construct(
        private readonly CrmMappingRepositoryContract $crmMappingRepository,
        private readonly CrmMappingQueryContract $crmMappingQuery,
        private readonly ClockContract $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Moves a merged-away lead's CRM mappings onto the surviving lead.
     *
     * Called inside the transaction that performs the merge, so the mappings and
     * the lead rows change together.
     *
     * **The merge order is forced by the two UNIQUE indexes.** A mapping cannot
     * simply be re-pointed when the survivor already holds one for the same
     * provider and object type: the four-part local key would collide. So for
     * each of the secondary's mappings the survivor's own mapping for that key is
     * looked up first — if one exists, the SURVIVOR's mapping stays and the
     * secondary's is deleted (A4); only when the survivor has none is the
     * secondary's re-pointed.
     *
     * No transaction is opened here: the caller already has one open, and a
     * second begin inside it would either be a savepoint the adapter does not own
     * or a nested transaction the driver does not provide.
     *
     * @param int $secondaryLeadId The lead being merged away.
     * @param int $survivingLeadId The lead that survives.
     *
     * @return int How many mappings were re-pointed.
     *
     * @throws RuntimeException When the two ids are the same, or a write fails.
     */
    public function relinkMappings(int $secondaryLeadId, int $survivingLeadId): int
    {
        if ($secondaryLeadId === $survivingLeadId) {
            throw new RuntimeException(
                'A merge was asked to re-link a lead onto itself, which would re-point every mapping '
                . 'at the row it already names.'
            );
        }

        $secondaryMappings = $this->crmMappingQuery->findAllForLocalEntity(
            LocalEntityType::lead,
            $secondaryLeadId
        );

        if ($secondaryMappings === []) {
            return 0;
        }

        $relinked = 0;

        foreach ($secondaryMappings as $mapping) {
            $survivors = $this->crmMappingQuery->findByLocalKey(
                LocalEntityType::lead,
                $survivingLeadId,
                $mapping->provider,
                $mapping->crmObjectType,
            );

            if ($survivors !== null) {
                $this->deleteConflicting($mapping, $survivors, $survivingLeadId);

                continue;
            }

            $this->repoint($mapping, $survivingLeadId);
            $relinked++;
        }

        return $relinked;
    }

    /**
     * Re-points one mapping at the surviving lead.
     *
     * A duplicate-key failure is surfaced rather than absorbed: the survivor's
     * own mapping was just checked for, so a collision here means another writer
     * created one between the read and this write. Swallowing it would delete a
     * mapping that a concurrent merge had decided to keep.
     *
     * @param CrmMappingEntity $mapping
     * @param int              $survivingLeadId
     *
     * @return void
     *
     * @throws CrmMappingDuplicateException When a concurrent writer claimed the key.
     * @throws RuntimeException When the write fails.
     */
    private function repoint(CrmMappingEntity $mapping, int $survivingLeadId): void
    {
        $relinked = $mapping->relinkTo($survivingLeadId, $this->clock->now());

        $this->crmMappingRepository->update($relinked);
    }

    /**
     * Keeps the survivor's mapping and removes the secondary's conflicting one.
     *
     * The survivor wins because its mapping is the one the CRM and any existing
     * links already reference: it was created for the lead that is being kept.
     * The secondary's mapping points at the same remote record through the local
     * key, and only one mapping per local key can exist, so it is deleted and the
     * removal recorded — a mapping disappearing without a trace would be
     * indistinguishable from one that was never there (A4).
     *
     * The deletion is keyed on the secondary's own row, so it removes the mapping
     * that lost rather than the one that won.
     *
     * @param CrmMappingEntity $loser    The secondary lead's mapping.
     * @param CrmMappingEntity $survivor The surviving lead's mapping.
     * @param int              $survivingLeadId
     *
     * @return void
     *
     * @throws RuntimeException When the losing mapping has no id, or the delete fails.
     */
    private function deleteConflicting(
        CrmMappingEntity $loser,
        CrmMappingEntity $survivor,
        int $survivingLeadId,
    ): void {
        if ($loser->id === null) {
            throw new RuntimeException(
                'A conflicting CRM mapping was read without an id, so it cannot be deleted.'
            );
        }

        $this->crmMappingRepository->delete($loser->id);

        // Logged with the keys rather than the row, so the record names which
        // mapping was dropped without ever writing a remote record id or a lead's
        // contact details into the log.
        $this->logger->info('CRM mapping removed during a lead merge (A4): the surviving lead already held one.', [
            'surviving_lead_id' => $survivingLeadId,
            'secondary_lead_id' => $loser->localEntityId,
            'provider' => $loser->provider->value,
            'crm_object_type' => $loser->crmObjectType->value,
            'kept_mapping_id' => $survivor->id,
            'removed_mapping_id' => $loser->id,
        ]);
    }
}
