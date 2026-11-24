<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Contracts;

use Core\CrmSync\Domain\Entities\DeadLetterEntity;

/**
 * PORT: persistence for dead letters (write side).
 *
 * The dead-letter table has no `created_at` / `updated_at` columns, so nothing
 * here writes a timestamp the schema does not hold.
 */
interface DeadLetterRepositoryContract
{
    /**
     * Inserts a new dead letter.
     *
     * @param DeadLetterEntity $deadLetter
     *
     * @return DeadLetterEntity The stored dead letter, carrying its id.
     */
    public function create(DeadLetterEntity $deadLetter): DeadLetterEntity;

    /**
     * Writes a new dead letter, or ignores it when the event is already filed.
     *
     * `dead_letters.sync_event_id` is UNIQUE, so a second attempt to file the
     * same failed event would lose to the index. Filing is idempotent by intent:
     * a reaper and a worker can both reach the same conclusion about one event,
     * and the second one should record nothing rather than raise.
     *
     * @param DeadLetterEntity $deadLetter
     *
     * @return DeadLetterEntity|null The stored dead letter, or null when one already exists for the event.
     */
    public function createIfAbsentForEvent(DeadLetterEntity $deadLetter): ?DeadLetterEntity;

    /**
     * Records that a dead letter has been replayed.
     *
     * @param DeadLetterEntity $deadLetter The dead letter, carrying its replay trail.
     *
     * @return DeadLetterEntity The stored dead letter.
     */
    public function update(DeadLetterEntity $deadLetter): DeadLetterEntity;

    /**
     * Reads a dead letter while holding a row lock, to be called inside a transaction.
     *
     * @param int $deadLetterId
     *
     * @return DeadLetterEntity|null Null when no dead letter carries the id.
     */
    public function findByIdForUpdate(int $deadLetterId): ?DeadLetterEntity;
}
