<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Repositories;

use Core\CrmSync\Domain\Contracts\DeadLetterRepositoryContract;
use Core\CrmSync\Domain\Entities\DeadLetterEntity;
use Core\CrmSync\Domain\Exceptions\DeadLetterDuplicateException;
use Core\CrmSync\Infrastructure\Mappers\DeadLetterEntityMapper;
use Core\Shared\Infrastructure\Repositories\AbstractWpRepository;
use Core\Shared\Infrastructure\Repositories\BaseWriteRepository;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * `$wpdb` writes for dead letters. Inherits no read method.
 *
 * @implements DeadLetterRepositoryContract
 */
final class WpDeadLetterWriteRepository extends BaseWriteRepository implements DeadLetterRepositoryContract
{
    /** The table's name without the WordPress prefix. */
    private const TABLE_NAME = 'dead_letters';

    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * The columns a read-back selects, in a fixed order.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'sync_event_id', 'replayed_sync_event_id', 'provider', 'job_class',
        'payload_json', 'error_message', 'http_status_code', 'failed_at', 'retried_at',
    ];

    /**
     * @param DeadLetterEntityMapper $mapper
     * @param wpdb|null              $wpdb Defaults to the `$wpdb` global, resolved lazily; see
     *                                     {@see AbstractWpRepository::db()}.
     */
    public function __construct(
        private readonly DeadLetterEntityMapper $mapper,
        ?wpdb $wpdb = null,
    ) {
        parent::__construct($wpdb);
    }

    /**
     * Reports a rejected unique index as the module's conflict.
     *
     * `dead_letters.sync_event_id` is UNIQUE, so filing the same failed event
     * twice arrives here as an integrity violation rather than a row.
     *
     * @param Throwable $exception
     *
     * @return Throwable
     */
    protected function duplicateException(Throwable $exception): Throwable
    {
        return new DeadLetterDuplicateException(previous: $exception);
    }

    /**
     * Files a new dead letter.
     *
     * @param DeadLetterEntity $deadLetter
     *
     * @return DeadLetterEntity The stored dead letter, carrying its id.
     *
     * @throws DeadLetterDuplicateException When the event is already filed.
     * @throws RuntimeException When the insert fails.
     */
    public function create(DeadLetterEntity $deadLetter): DeadLetterEntity
    {
        $id = $this->insert(
            $this->mapper->toPersistenceArray($deadLetter),
            self::TABLE_NAME,
            'insert into ' . self::TABLE_NAME
        );

        return $this->readBack($id);
    }

    /**
     * Writes a new dead letter, or returns the existing one when the event is filed.
     *
     * Idempotent by intent rather than by luck: the reaper and a worker can both
     * reach the same conclusion about one failed event, and the second should
     * record nothing rather than raise. The collision is therefore caught and
     * turned into "here is what is already there" — which is the honest answer,
     * because the caller's next move (inspect or replay) is the same either way.
     *
     * The existing row is read back by `sync_event_id`, the column that caused
     * the collision, so this returns the row that actually blocked the insert
     * rather than a guess.
     *
     * @param DeadLetterEntity $deadLetter
     *
     * @return DeadLetterEntity|null The stored dead letter, or null when one already exists for the event.
     *
     * @throws RuntimeException When the row that exists cannot be read back.
     */
    public function createIfAbsentForEvent(DeadLetterEntity $deadLetter): ?DeadLetterEntity
    {
        try {
            return $this->create($deadLetter);
        } catch (DeadLetterDuplicateException) {
            return $this->findByEventId($deadLetter->syncEventId);
        }
    }

    /**
     * Writes a stored dead letter's current state back to its row.
     *
     * The replay trail (`replayed_sync_event_id`, `retried_at`) is what an update
     * carries; `failed_at` and the payload are written unchanged, because a
     * replay must not rewrite the record of what failed.
     *
     * @param DeadLetterEntity $deadLetter
     *
     * @return DeadLetterEntity The stored dead letter.
     *
     * @throws RuntimeException When the dead letter has no id.
     * @throws DeadLetterDuplicateException When the event is already filed under another row.
     */
    public function update(DeadLetterEntity $deadLetter): DeadLetterEntity
    {
        $deadLetterId = $this->storedId($deadLetter);

        $this->write(
            $deadLetterId,
            $this->mapper->toPersistenceArray($deadLetter),
            self::TABLE_NAME,
            sprintf('update dead letter %d', $deadLetterId)
        );

        return $this->readBack($deadLetterId);
    }

    /**
     * Reads a dead letter while holding a row lock.
     *
     * @param int $deadLetterId
     *
     * @return DeadLetterEntity|null Null when no dead letter carries the id.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByIdForUpdate(int $deadLetterId): ?DeadLetterEntity
    {
        $row = $this->findRecordForUpdate(
            $deadLetterId,
            self::TABLE_NAME,
            sprintf('lock dead letter %d for update', $deadLetterId)
        );

        return $row !== null ? $this->mapper->toEntity($row) : null;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Reads the dead letter filed for a ledger event.
     *
     * Used only to answer a collision on `sync_event_id`, so a null here means
     * the unique index rejected the insert but no row carries the event id —
     * which would be a contradiction, and is raised rather than returned.
     *
     * @param int|null $syncEventId
     *
     * @return DeadLetterEntity
     *
     * @throws RuntimeException When the colliding row cannot be found or read.
     */
    private function findByEventId(?int $syncEventId): DeadLetterEntity
    {
        if ($syncEventId === null) {
            throw new RuntimeException(
                'A dead letter insert lost to the unique index on sync_event_id, but the row being '
                . 'filed carries no event id, so the colliding row cannot be identified.'
            );
        }

        $db = $this->db();

        $row = $db->get_row($db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `sync_event_id` = %%d LIMIT 1',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->tableName(self::TABLE_NAME))
            ),
            $syncEventId
        ), self::OUTPUT_ASSOC);

        if (! is_array($row)) {
            throw new RuntimeException(sprintf(
                'A dead letter insert lost to the unique index on sync_event_id %d, but no dead letter '
                . 'carries that event.',
                $syncEventId
            ));
        }

        return $this->mapper->toEntity($row);
    }

    /**
     * Reads back a row just written.
     *
     * @param int $deadLetterId
     *
     * @return DeadLetterEntity
     *
     * @throws RuntimeException When the row cannot be read back.
     */
    private function readBack(int $deadLetterId): DeadLetterEntity
    {
        $db = $this->db();

        $row = $db->get_row($db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `id` = %%d LIMIT 1',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->tableName(self::TABLE_NAME))
            ),
            $deadLetterId
        ), self::OUTPUT_ASSOC);

        if (! is_array($row)) {
            throw new RuntimeException(sprintf(
                'The row for dead letter %d could not be read back after it was written.',
                $deadLetterId
            ));
        }

        return $this->mapper->toEntity($row);
    }

    /**
     * The id a write must target.
     *
     * @param DeadLetterEntity $deadLetter
     *
     * @return int
     *
     * @throws RuntimeException When the dead letter has never been saved.
     */
    private function storedId(DeadLetterEntity $deadLetter): int
    {
        if ($deadLetter->id === null) {
            throw new RuntimeException(
                'A write was attempted for a dead letter with no id. Use create() for a dead letter '
                . 'that has never been saved.'
            );
        }

        return $deadLetter->id;
    }
}
