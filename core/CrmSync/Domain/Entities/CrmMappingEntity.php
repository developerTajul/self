<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Entities;

use Core\CrmSync\Domain\Enums\CrmObjectType;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\LocalEntityType;
use Core\CrmSync\Domain\Exceptions\SyncMappingRelinkException;
use Core\CrmSync\Domain\ValueObjects\ContentHash;
use Core\CrmSync\Domain\ValueObjects\RemoteId;
use DateTimeImmutable;

/**
 * Domain entity linking one local entity to one remote CRM record.
 *
 * Two UNIQUE indexes decide the merge order the Service must respect: the
 * four-part local key (entity type, local id, provider, object type) and the
 * three-part remote key (provider, object type, remote record id).
 */
readonly class CrmMappingEntity
{
    /**
     * @param int|null               $id
     * @param LocalEntityType        $entityType
     * @param int                    $localEntityId    The local row's id (a lead, an order).
     * @param CrmProviderName        $provider
     * @param CrmObjectType          $crmObjectType
     * @param RemoteId               $remoteId         The provider-aware canonical remote identifier.
     * @param ContentHash|null       $lastSyncedHash   The state last written to the CRM, for echo suppression.
     * @param DateTimeImmutable|null $lastSyncedAt     UTC.
     * @param DateTimeImmutable|null $createdAt        UTC.
     * @param DateTimeImmutable|null $updatedAt        UTC.
     */
    public function __construct(
        public ?int $id,
        public LocalEntityType $entityType,
        public int $localEntityId,
        public CrmProviderName $provider,
        public CrmObjectType $crmObjectType,
        public RemoteId $remoteId,
        public ?ContentHash $lastSyncedHash = null,
        public ?DateTimeImmutable $lastSyncedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    /**
     * Creates the link between a local entity and a remote record.
     *
     * No hash is recorded yet: nothing has been pushed, so there is no state to
     * compare an inbound echo against. {@see recordSync()} sets it after the
     * first successful write.
     *
     * @param LocalEntityType   $entityType
     * @param int               $localEntityId
     * @param CrmProviderName   $provider
     * @param CrmObjectType     $crmObjectType
     * @param RemoteId          $remoteId
     *
     * @return self A new, unpersisted mapping.
     */
    public static function link(
        LocalEntityType $entityType,
        int $localEntityId,
        CrmProviderName $provider,
        CrmObjectType $crmObjectType,
        RemoteId $remoteId,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: null,
            entityType: $entityType,
            localEntityId: $localEntityId,
            provider: $provider,
            crmObjectType: $crmObjectType,
            remoteId: $remoteId,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Records the state just written to the CRM.
     *
     * This hash is the echo detector's reference point: an inbound webhook whose
     * state hashes to the same value is our own write coming back, and applying
     * it would push a change out again, which would come back again.
     *
     * @param ContentHash       $hash The digest of the state that was sent.
     * @param DateTimeImmutable $now
     *
     * @return self A new instance carrying the recorded hash.
     */
    public function recordSync(ContentHash $hash, DateTimeImmutable $now): self
    {
        return new self(
            id: $this->id,
            entityType: $this->entityType,
            localEntityId: $this->localEntityId,
            provider: $this->provider,
            crmObjectType: $this->crmObjectType,
            remoteId: $this->remoteId,
            lastSyncedHash: $hash,
            lastSyncedAt: $now,
            createdAt: $this->createdAt,
            updatedAt: $now,
        );
    }

    /**
     * Whether an inbound state is the echo of our own last write.
     *
     * True only when a hash has actually been recorded: a mapping with no
     * `lastSyncedHash` has never been pushed, so nothing inbound can be its echo,
     * and reporting true would silently drop the first genuine remote change.
     *
     * @param ContentHash $hash The digest of the state the webhook describes.
     *
     * @return bool
     */
    public function isEcho(ContentHash $hash): bool
    {
        return $this->lastSyncedHash !== null && $this->lastSyncedHash->equals($hash);
    }

    /**
     * Re-points this mapping at a different local row, after a merge.
     *
     * Only the local id changes: the remote record is the same record, and the
     * provider, object type and remote id are what address it. The callers
     * delete a conflicting mapping rather than re-point it, because the two
     * UNIQUE indexes mean only one mapping per local key can survive.
     *
     * @param int               $localEntityId The surviving local row's id.
     * @param DateTimeImmutable $now
     *
     * @return self A new instance pointing at the surviving row.
     *
     * @throws SyncMappingRelinkException When the target is not a positive row id.
     */
    public function relinkTo(int $localEntityId, DateTimeImmutable $now): self
    {
        if ($localEntityId < 1) {
            throw SyncMappingRelinkException::invalidTargetId($localEntityId);
        }

        // Re-pointing at the row it already names would be a silent no-op that
        // the caller would read as a completed re-link, so it is reported: the
        // caller is working from a stale view of which mapping is the survivor.
        if ($localEntityId === $this->localEntityId) {
            throw SyncMappingRelinkException::alreadyLinked($localEntityId);
        }

        return new self(
            id: $this->id,
            entityType: $this->entityType,
            localEntityId: $localEntityId,
            provider: $this->provider,
            crmObjectType: $this->crmObjectType,
            remoteId: $this->remoteId,
            lastSyncedHash: $this->lastSyncedHash,
            lastSyncedAt: $this->lastSyncedAt,
            createdAt: $this->createdAt,
            updatedAt: $now,
        );
    }

    /**
     * Whether this mapping's local and remote keys both match another's.
     *
     * @param self $other
     *
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->entityType === $other->entityType
            && $this->localEntityId === $other->localEntityId
            && $this->provider === $other->provider
            && $this->crmObjectType === $other->crmObjectType
            && $this->remoteId->equals($other->remoteId);
    }
}
