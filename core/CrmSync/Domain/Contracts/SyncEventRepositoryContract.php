<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Contracts;

use Core\CrmSync\Domain\Entities\SyncEventEntity;
use DateTimeImmutable;

/**
 * PORT: persistence for the sync ledger (write side).
 *
 * Declares no pure read. The two reads here exist because a write needs them:
 * a claim must select-and-then-own a row atomically, and the reaper must find
 * the leases it is about to release. Audit listings belong on the read port.
 *
 * `claimDueEvents()` is the one method whose name does not describe a single
 * write, and it is deliberate: the atomic claim is a domain operation with a
 * correctness requirement the SQL shape alone cannot express — a candidate must
 * be selected and then owned conditionally, or two workers would each take the
 * same event. Leaving that to the adapter as two calls would put the rule
 * outside the contract that promises it.
 */
interface SyncEventRepositoryContract
{
    /**
     * Inserts a new ledger event.
     *
     * @param SyncEventEntity $event
     *
     * @return SyncEventEntity The stored event, carrying its id.
     */
    public function create(SyncEventEntity $event): SyncEventEntity;

    /**
     * Writes a stored event's current state back to its row.
     *
     * @param SyncEventEntity $event
     *
     * @return SyncEventEntity The stored event.
     */
    public function update(SyncEventEntity $event): SyncEventEntity;

    /**
     * Reads an event while holding a row lock, to be called inside a transaction.
     *
     * @param int $eventId
     *
     * @return SyncEventEntity|null Null when no event carries the id.
     */
    public function findByIdForUpdate(int $eventId): ?SyncEventEntity;

    /**
     * Claims up to `$batchSize` events that are due, and returns them owned.
     *
     * Each returned event is in the `processing` state with its lease started,
     * so the caller performs the work without any other worker able to claim the
     * same row. An event another worker won the race for is simply absent from
     * the result rather than reported.
     *
     * @param DateTimeImmutable $now       The instant the claim is made.
     * @param int               $batchSize The most events one pass may take.
     *
     * @return list<SyncEventEntity> The events this caller now owns.
     */
    public function claimDueEvents(DateTimeImmutable $now, int $batchSize): array;

    /**
     * Releases the leases of processing events that have gone stale.
     *
     * The reaper's operation: each reclaimed event returns to `pending` with
     * `attempts` incremented, so work abandoned by a dead worker is retried
     * without a second worker having had to steal it. An event that has no
     * attempts left is dead-lettered by the caller rather than released.
     *
     * @param DateTimeImmutable $now The instant the leases are judged stale at.
     *
     * @return list<SyncEventEntity> The events whose leases were released.
     */
    public function releaseStaleLeases(DateTimeImmutable $now): array;
}
