<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Contracts;

use Core\CrmSync\Domain\Entities\CrmMappingEntity;
use Core\CrmSync\Domain\Enums\CrmObjectType;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\LocalEntityType;
use Core\CrmSync\Domain\ValueObjects\RemoteId;

/**
 * Read side of the CRM mapping port.
 *
 * Both lookups name their key explicitly, because the two UNIQUE indexes answer
 * different questions: the local key asks "which remote record does this lead
 * already point at?", the remote key asks "which local row does this inbound
 * webhook concern?". An inbound event only carries the second, and a capture
 * only the first.
 */
interface CrmMappingQueryContract
{
    /**
     * Reads the mapping for a local entity and one CRM object.
     *
     * @param LocalEntityType $entityType
     * @param int             $localEntityId
     * @param CrmProviderName $provider
     * @param CrmObjectType   $crmObjectType
     *
     * @return CrmMappingEntity|null Null when the local entity is not mapped for that object.
     */
    public function findByLocalKey(
        LocalEntityType $entityType,
        int $localEntityId,
        CrmProviderName $provider,
        CrmObjectType $crmObjectType,
    ): ?CrmMappingEntity;

    /**
     * Reads the mapping that claims a remote record.
     *
     * Uses the three-part remote key, which is what makes an inbound webhook
     * resolve to exactly one local row.
     *
     * @param CrmProviderName $provider
     * @param CrmObjectType   $crmObjectType
     * @param RemoteId        $remoteId
     *
     * @return CrmMappingEntity|null Null when no local row is mapped to that record.
     */
    public function findByRemoteKey(
        CrmProviderName $provider,
        CrmObjectType $crmObjectType,
        RemoteId $remoteId,
    ): ?CrmMappingEntity;

    /**
     * Reads every mapping a local entity holds, across providers and objects.
     *
     * A cross-lead merge has to re-point all of them, so the caller needs the
     * set rather than one at a time.
     *
     * @param LocalEntityType $entityType
     * @param int             $localEntityId
     *
     * @return list<CrmMappingEntity>
     */
    public function findAllForLocalEntity(LocalEntityType $entityType, int $localEntityId): array;
}
