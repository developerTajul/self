<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Repositories;

use Core\CrmSync\Domain\Contracts\SyncEventRepositoryContract;
use Core\CrmSync\Domain\Entities\SyncEventEntity;
use Core\CrmSync\Domain\Enums\SyncEventStatus;
use Core\CrmSync\Domain\Exceptions\SyncEventDuplicateException;
use Core\CrmSync\Domain\Exceptions\SyncEventTransitionException;
use Core\CrmSync\Domain\Rules\SyncRetryRules;
use Core\CrmSync\Infrastructure\Mappers\SyncEventEntityMapper;
use Core\Shared\Infrastructure\Repositories\AbstractWpRepository;
use Core\Shared\Infrastructure\Repositories\BaseWriteRepository;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * `$wpdb` writes for the sync ledger. Inherits no read method.
 *
 * @implements SyncEventRepositoryContract
 */
final class WpSyncEventWriteRepository extends BaseWriteRepository implements SyncEventRepositoryContract
{
    /** The table's name without the WordPress prefix. */
    private const TABLE_NAME = 'sync_events';

    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * The columns a read-back selects, in a fixed order.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'idempotency_key', 'direction', 'provider', 'event_type', 'payload_json',
        'status', 'attempts', 'next_attempt_at', 'locked_at', 'last_error',
        'processed_at', 'created_at', 'updated_at',
    ];

    /**
     * @param SyncEventEntityMapper $mapper
     * @param wpdb|null             $wpdb Defaults to the `$wpdb` global, resolved lazily; see
     *                                    {@see AbstractWpRepository::db()}.
     */
    public function __construct(
        private readonly SyncEventEntityMapper $mapper,
        ?wpdb $wpdb = null,
    ) {
        parent::__construct($wpdb);
    }

    /**
     * Reports a rejected unique index as the module's conflict.
     *
     * `sync_events.idempotency_key` is UNIQUE, so a redelivered webhook or a
     * retried enqueue arrives here as an integrity violation rather than as a row
     * (D-025). That is the ledger's whole idempotency guarantee: it is the
     * database, not a prior read, that decides two events are the same.
     *
     * @param Throwable $exception
     *
     * @return Throwable
     */
    protected function duplicateException(Throwable $exception): Throwable
    {
        return new SyncEventDuplicateException(previous: $exception);
    }

    /**
     * Inserts a new ledger event.
     *
     * @param SyncEventEntity $event
     *
     * @return SyncEventEntity The stored event, carrying its id.
     *
     * @throws SyncEventDuplicateException When the idempotency key is already recorded.
     * @throws RuntimeException When the insert fails.
     */
    public function create(SyncEventEntity $event): SyncEventEntity
    {
        $now = $this->now();

        $id = $this->insert(
            [
                ...$this->mapper->toPersistenceArray($event),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::TABLE_NAME,
            'insert into ' . self::TABLE_NAME
        );

        return $this->readBack($id);
    }

    /**
     * Writes a stored event's current state back to its row.
     *
     * @param SyncEventEntity $event
     *
     * @return SyncEventEntity The stored event.
     *
     * @throws RuntimeException When the event has no id.
     * @throws SyncEventDuplicateException When the idempotency key collides.
     */
    public function update(SyncEventEntity $event): SyncEventEntity
    {
        $eventId = $this->storedId($event);

        $this->write(
            $eventId,
            [...$this->mapper->toPersistenceArray($event), 'updated_at' => $this->now()],
            self::TABLE_NAME,
            sprintf('update sync event %d', $eventId)
        );

        return $this->readBack($eventId);
    }

    /**
     * Reads an event while holding a row lock.
     *
     * `FOR UPDATE` needs a transaction to mean anything. Read outside one, MySQL
     * releases the lock as soon as the statement's implicit transaction ends, and
     * the call becomes an ordinary read that merely looks safe. The caller takes
     * this inside the transaction that is about to write.
     *
     * @param int $eventId
     *
     * @return SyncEventEntity|null Null when no event carries the id.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByIdForUpdate(int $eventId): ?SyncEventEntity
    {
        $row = $this->findRecordForUpdate(
            $eventId,
            self::TABLE_NAME,
            sprintf('lock sync event %d for update', $eventId)
        );

        return $row !== null ? $this->mapper->toEntity($row) : null;
    }

    /**
     * Claims up to `$batchSize` events that are due, and returns them owned.
     *
     * Two statements, and the split is the point. The first is a pure range scan
     * over `sync_events_status_next_attempt_idx` — `status = 'pending' AND
     * next_attempt_at <= ?` ordered by the index's own columns — which names
     * candidates without taking anything. The second makes each candidate ours:
     * a conditional `UPDATE ... WHERE id = ? AND status = 'pending'`, kept only
     * when the driver reports exactly one affected row.
     *
     * A single `UPDATE ... LIMIT n` would be shorter and wrong: it gives no
     * record of WHICH rows it took, and MySQL's `UPDATE ... ORDER BY ... LIMIT`
     * has no `RETURNING` in the versions this plugin supports, so the caller
     * would have to read the batch back by some other predicate and might read
     * rows another worker had just claimed.
     *
     * `SELECT ... FOR UPDATE` on the candidate batch would be a third option, and
     * it is the one to avoid: it holds row locks for the whole scan, so two
     * workers claiming concurrently serialize on the first candidate rather than
     * merely losing the race for it. Here the `UPDATE` is the only statement that
     * locks, and it locks one row at a time.
     *
     * The affected-row check is what makes the claim atomic without a lock held
     * across it: `UPDATE` reports rows it changed, so a worker that lost the race
     * — because another worker moved the row to `processing` first — sees 0 and
     * drops the candidate rather than reporting it as claimed.
     *
     * The lease is stamped from the caller's `$now`, not the database's, so the
     * reaper's staleness comparison uses one clock rather than two.
     *
     * @param DateTimeImmutable $now       The instant the claim is made.
     * @param int               $batchSize The most events one pass may take.
     *
     * @return list<SyncEventEntity> The events this caller now owns.
     *
     * @throws RuntimeException When a statement fails.
     */
    public function claimDueEvents(DateTimeImmutable $now, int $batchSize): array
    {
        if ($batchSize < 1) {
            return [];
        }

        $db = $this->db();
        $table = $this->escapeIdentifier($this->tableName(self::TABLE_NAME));
        $dueAt = $this->toStorageDate($now);

        $candidates = $db->get_col($db->prepare(
            sprintf(
                'SELECT `id` FROM `%s` WHERE `status` = %%s AND `next_attempt_at` <= %%s '
                . 'ORDER BY `next_attempt_at` ASC, `id` ASC LIMIT %%d',
                $table
            ),
            SyncEventStatus::pending->value,
            $dueAt,
            $batchSize
        ));

        if ($db->last_error !== '') {
            throw new RuntimeException(sprintf(
                'Failed to select due sync events: %s',
                $db->last_error
            ));
        }

        if (! is_array($candidates) || $candidates === []) {
            return [];
        }

        $claimed = [];
        $lockedAt = $this->toStorageDate($now);

        foreach ($candidates as $candidateId) {
            $eventId = (int) $candidateId;

            // One conditional UPDATE per candidate. The `status = 'pending'`
            // predicate is the whole concurrency guard: it makes the claim a
            // compare-and-set, so exactly one worker can win a given row.
            $affected = $db->query($db->prepare(
                sprintf(
                    'UPDATE `%s` SET `status` = %%s, `locked_at` = %%s, `updated_at` = %%s '
                    . 'WHERE `id` = %%d AND `status` = %%s',
                    $table
                ),
                SyncEventStatus::processing->value,
                $lockedAt,
                $lockedAt,
                $eventId,
                SyncEventStatus::pending->value
            ));

            if ($affected === false) {
                throw new RuntimeException(sprintf(
                    'Failed to claim sync event %d: %s',
                    $eventId,
                    $db->last_error !== '' ? $db->last_error : 'the database reported no error message'
                ));
            }

            // Zero rows means another worker moved it first. That is an ordinary
            // lost race, not a failure, so the candidate is dropped silently.
            if ((int) $affected !== 1) {
                continue;
            }

            $claimed[] = $this->readBack($eventId);
        }

        return $claimed;
    }

    /**
     * Releases the leases of processing events that have gone stale.
     *
     * The reaper's operation. Candidates are locked with `FOR UPDATE` inside the
     * caller's transaction and their leases released through
     * {@see SyncEventEntity::releaseStaleLock()}, so the transition rules stay in
     * the Domain rather than being re-expressed as a bulk `UPDATE`: the ceiling
     * on `attempts`, the state the event must be in, and the value the counter
     * takes are all decided by the entity.
     *
     * An event that has no attempts left cannot be released, and the entity says
     * so by throwing. That throw is NOT swallowed here: this method's contract is
     * to release what it can and to name what it cannot, and the caller — the
     * reaper pass — is the layer that decides to dead-letter it (D-043). Absorbing
     * the exception here would leave the caller no way to tell an exhausted event
     * from a released one.
     *
     * The exhausted event is remembered and rethrown AFTER the batch has been
     * walked, rather than at the moment it is met. Rethrowing in place would be
     * simpler and would starve the batch: the scan is ordered by `locked_at`
     * ascending, so a single exhausted row older than everything behind it would
     * abort the pass at the same position on every subsequent pass, and no event
     * after it would ever be reclaimed. Draining first keeps the method's
     * guarantee — every releasable row in the batch is released — while still
     * naming the row that must be dead-lettered.
     *
     * The batch is bounded so one pass cannot lock an unbounded set of rows.
     *
     * @param DateTimeImmutable $now The instant the leases are judged stale at.
     *
     * @return list<SyncEventEntity> The events whose leases were released.
     *
     * @throws SyncEventTransitionException When the batch holds an event with no attempts left.
     * @throws RuntimeException When a read or write fails.
     */
    public function releaseStaleLeases(DateTimeImmutable $now): array
    {
        $db = $this->db();
        $table = $this->escapeIdentifier($this->tableName(self::TABLE_NAME));

        $staleBefore = $this->toStorageDate(
            $now->setTimestamp($now->getTimestamp() - SyncRetryRules::LEASE_TIMEOUT_SECONDS)
        );

        // The staleness boundary is `locked_at < (now - lease timeout)`, which is
        // the negation of the entity's own `hasStaleLease()` (`locked_at + lease
        // <= now`), so the SQL and the Domain rule agree on the boundary case.
        $rows = $db->get_results($db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `status` = %%s AND `locked_at` IS NOT NULL '
                . 'AND `locked_at` < %%s ORDER BY `locked_at` ASC, `id` ASC LIMIT %%d FOR UPDATE',
                implode('`, `', self::COLUMNS),
                $table
            ),
            SyncEventStatus::processing->value,
            $staleBefore,
            SyncRetryRules::BATCH_SIZE
        ), self::OUTPUT_ASSOC);

        if ($db->last_error !== '') {
            throw new RuntimeException(sprintf(
                'Failed to read stale sync event leases: %s',
                $db->last_error
            ));
        }

        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $released = [];
        $exhausted = null;

        foreach ($rows as $row) {
            $event = $this->mapper->toEntity($row);

            try {
                // `releaseStaleLock()` refuses an event with no attempts left by
                // throwing; the first such refusal is kept and rethrown below.
                $reclaimed = $event->releaseStaleLock($now);
            } catch (SyncEventTransitionException $exception) {
                $exhausted ??= $exception;

                continue;
            }

            $this->write(
                $this->storedId($reclaimed),
                [...$this->mapper->toPersistenceArray($reclaimed), 'updated_at' => $this->now()],
                self::TABLE_NAME,
                sprintf('release the stale lease of sync event %d', $reclaimed->id)
            );

            $released[] = $this->readBack($this->storedId($reclaimed));
        }

        // Reported only once the releasable rows are done, so one exhausted event
        // cannot deny the rest of the batch its release. The caller dead-letters
        // the event this names and calls again for the remainder (D-043).
        if ($exhausted !== null) {
            throw $exhausted;
        }

        return $released;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Reads back a row just written.
     *
     * Every write returns through here because the database owns the timestamps
     * and the id: an entity assembled from the caller's own values would be
     * missing both.
     *
     * @param int $eventId
     *
     * @return SyncEventEntity
     *
     * @throws RuntimeException When the row cannot be read back.
     */
    private function readBack(int $eventId): SyncEventEntity
    {
        $db = $this->db();

        $row = $db->get_row($db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `id` = %%d LIMIT 1',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->tableName(self::TABLE_NAME))
            ),
            $eventId
        ), self::OUTPUT_ASSOC);

        if (! is_array($row)) {
            throw new RuntimeException(sprintf(
                'The row for sync event %d could not be read back after it was written.',
                $eventId
            ));
        }

        return $this->mapper->toEntity($row);
    }

    /**
     * The id a write must target.
     *
     * The guard is what makes the assumption checkable: an entity with no id
     * would produce `WHERE id = 0` and update nothing.
     *
     * @param SyncEventEntity $event
     *
     * @return int
     *
     * @throws RuntimeException When the entity has never been saved.
     */
    private function storedId(SyncEventEntity $event): int
    {
        if ($event->id === null) {
            throw new RuntimeException(
                'A write was attempted for a sync event with no id. Use create() for an event that '
                . 'has never been saved.'
            );
        }

        return $event->id;
    }

    /**
     * A datetime as the UTC string the columns store.
     *
     * The instant is normalized to UTC before formatting, so a caller holding a
     * `DateTimeImmutable` in another zone cannot write a wall-clock time the
     * columns would then be read back in the wrong zone.
     *
     * @param DateTimeImmutable $date
     *
     * @return string
     */
    private function toStorageDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
