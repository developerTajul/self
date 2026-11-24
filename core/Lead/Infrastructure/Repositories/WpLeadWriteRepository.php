<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

use Core\Lead\Domain\Contracts\LeadRepositoryContract;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\Exceptions\LeadDuplicateException;
use Core\Lead\Domain\Exceptions\LeadNotFoundException;
use Core\Lead\Infrastructure\Mappers\LeadEntityMapper;
use Core\Shared\Infrastructure\Repositories\BaseWriteRepository;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * `$wpdb` writes for leads.
 *
 * @implements LeadRepositoryContract
 */
final class WpLeadWriteRepository extends BaseWriteRepository implements LeadRepositoryContract
{
    /** The table's name without the WordPress prefix. */
    private const TABLE_NAME = 'leads';

    /**
     * The columns a read-back selects, in a fixed order.
     *
     * Spelled out rather than `SELECT *`, so a column added to the table later
     * cannot change what the mapper is handed without this list being updated
     * too — a drift that would otherwise surface as a missing array key.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'uuid', 'name', 'email', 'phone', 'company_name', 'website_url',
        'status', 'source', 'meta_json', 'assigned_to', 'created_at', 'updated_at',
    ];

    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * @param LeadEntityMapper $mapper
     * @param wpdb|null        $wpdb Defaults to the `$wpdb` global, resolved lazily; see
     *                               {@see AbstractWpRepository::db()}.
     */
    public function __construct(
        private readonly LeadEntityMapper $mapper,
        ?wpdb $wpdb = null,
    ) {
        parent::__construct($wpdb);
    }

    /**
     * Reports a rejected unique index as the module's conflict.
     *
     * The `leads` table carries unique indexes on `uuid`, `email` and `phone`, so
     * a second lead under any one of them arrives here as an integrity violation
     * rather than as a row (D-025).
     *
     * @param Throwable $exception
     *
     * @return Throwable
     */
    protected function duplicateException(Throwable $exception): Throwable
    {
        return new LeadDuplicateException(previous: $exception);
    }

    /**
     * Inserts a lead that has no id yet.
     *
     * The timestamps the entity carries are deliberately not written: this row is
     * new, so the database's clock and not an entity that may have been built
     * some time earlier is what dates it.
     *
     * @param LeadEntity $lead
     *
     * @return LeadEntity The stored lead, carrying its new id and the timestamps the database holds.
     *
     * @throws LeadDuplicateException When a unique index rejects the row.
     * @throws RuntimeException When the insert fails.
     */
    public function create(LeadEntity $lead): LeadEntity
    {
        $now = $this->now();

        $id = $this->insert(
            [
                ...$this->mapper->toPersistenceArray($lead),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::TABLE_NAME,
            'insert into ' . self::TABLE_NAME
        );

        return $this->readBack($id);
    }

    /**
     * Writes a stored lead's current state back to its row.
     *
     * `created_at` is absent from the update: an update never re-dates the row it
     * changes, and passing it would let a mapper change the record's history.
     *
     * @param LeadEntity $lead
     *
     * @return LeadEntity The stored lead.
     *
     * @throws RuntimeException When the entity has no id.
     * @throws LeadDuplicateException When a unique index rejects the row.
     * @throws LeadNotFoundException When the row no longer exists.
     */
    public function update(LeadEntity $lead): LeadEntity
    {
        $leadId = $this->storedId($lead);

        $this->write(
            $leadId,
            [...$this->mapper->toPersistenceArray($lead), 'updated_at' => $this->now()],
            self::TABLE_NAME,
            sprintf('update lead %d', $leadId)
        );

        return $this->readBack($leadId);
    }

    /**
     * Reads a lead while holding a row lock.
     *
     * `FOR UPDATE` needs a transaction to mean anything. Read outside one, MySQL
     * releases the lock as soon as the statement's implicit transaction ends, and
     * the call becomes an ordinary read that merely looks safe. The caller takes
     * this inside the transaction that is about to write.
     *
     * @param int $leadId
     *
     * @return LeadEntity|null Null when no lead carries the id.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByIdForUpdate(int $leadId): ?LeadEntity
    {
        $row = $this->findRecordForUpdate(
            $leadId,
            self::TABLE_NAME,
            sprintf('lock lead %d for update', $leadId)
        );

        return $row !== null ? $this->mapper->toEntity($row) : null;
    }

    /**
     * Deletes a lead.
     *
     * A zero-row delete means the row was removed between the caller loading it
     * and this statement running, which is a not-found outcome rather than a
     * silent success.
     *
     * @param int $leadId
     *
     * @return void
     *
     * @throws LeadNotFoundException When the row no longer exists.
     * @throws RuntimeException When the delete fails.
     */
    public function delete(int $leadId): void
    {
        $deleted = $this->deleteRecord(
            $leadId,
            self::TABLE_NAME,
            sprintf('delete lead %d', $leadId)
        );

        if (! $deleted) {
            throw new LeadNotFoundException($leadId);
        }
    }

    /**
     * Reads back a row just written.
     *
     * Every write returns through here because the database owns the timestamps
     * and the id: an entity assembled from the caller's own values would be
     * missing both, and a Service returning it would report a timestamp that was
     * never stored.
     *
     * @param int $leadId
     *
     * @return LeadEntity
     *
     * @throws RuntimeException When the row cannot be read back.
     */
    private function readBack(int $leadId): LeadEntity
    {
        $db = $this->db();

        $row = $db->get_row($db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `id` = %%d LIMIT 1',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->tableName(self::TABLE_NAME))
            ),
            $leadId
        ), self::OUTPUT_ASSOC);

        if (! is_array($row)) {
            throw new RuntimeException(sprintf(
                'The row for lead %d could not be read back after it was written.',
                $leadId
            ));
        }

        return $this->mapper->toEntity($row);
    }

    /**
     * The id an update must target.
     *
     * The guard is what makes the assumption checkable: an entity with no id
     * would produce `WHERE id = 0` and update nothing, and the caller would be
     * told the write succeeded.
     *
     * @param LeadEntity $lead
     *
     * @return int
     *
     * @throws RuntimeException When the entity has never been saved.
     */
    private function storedId(LeadEntity $lead): int
    {
        if ($lead->id === null) {
            throw new RuntimeException(
                'An update was attempted for a lead with no id. Use create() for a lead that has '
                . 'never been saved.'
            );
        }

        return $lead->id;
    }
}
