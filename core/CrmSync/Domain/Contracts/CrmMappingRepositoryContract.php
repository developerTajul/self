<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Contracts;

use Core\CrmSync\Domain\Entities\CrmMappingEntity;

/**
 * PORT: persistence for CRM mappings (write side).
 *
 * Honour both UNIQUE indexes: the four-part local key (entity type, local id,
 * provider, object type) and the three-part remote key (provider, object type,
 * remote record id). A rejected key arrives as the module's conflict exception,
 * never as a persistence error.
 */
interface CrmMappingRepositoryContract
{
    /**
     * Inserts a new mapping.
     *
     * @param CrmMappingEntity $mapping
     *
     * @return CrmMappingEntity The stored mapping, carrying its id.
     */
    public function create(CrmMappingEntity $mapping): CrmMappingEntity;

    /**
     * Writes a stored mapping's current state back to its row.
     *
     * @param CrmMappingEntity $mapping
     *
     * @return CrmMappingEntity The stored mapping.
     */
    public function update(CrmMappingEntity $mapping): CrmMappingEntity;

    /**
     * Deletes a mapping by id.
     *
     * @param int $mappingId
     *
     * @return void
     */
    public function delete(int $mappingId): void;

    /**
     * Reads a mapping while holding a row lock, to be called inside a transaction.
     *
     * @param int $mappingId
     *
     * @return CrmMappingEntity|null Null when no mapping carries the id.
     */
    public function findByIdForUpdate(int $mappingId): ?CrmMappingEntity;
}
