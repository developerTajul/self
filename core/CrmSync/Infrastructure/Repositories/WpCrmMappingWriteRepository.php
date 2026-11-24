<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Repositories;

use Core\CrmSync\Domain\Contracts\CrmMappingRepositoryContract;
use Core\CrmSync\Domain\Entities\CrmMappingEntity;
use Core\CrmSync\Domain\Exceptions\CrmMappingDuplicateException;
use Core\CrmSync\Infrastructure\Mappers\CrmMappingEntityMapper;
use Core\Shared\Infrastructure\Repositories\AbstractWpRepository;
use Core\Shared\Infrastructure\Repositories\BaseWriteRepository;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * `$wpdb` writes for CRM mappings, honouring both UNIQUE indexes.
 *
 * @implements CrmMappingRepositoryContract
 */
final class WpCrmMappingWriteRepository extends BaseWriteRepository implements CrmMappingRepositoryContract
{
    /** The table's name without the WordPress prefix. */
    private const TABLE_NAME = 'crm_mappings';

    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * The columns a read-back selects, in a fixed order.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'entity_type', 'local_entity_id', 'provider', 'crm_object_type',
        'crm_record_id', 'last_synced_hash', 'last_synced_at', 'created_at', 'updated_at',
    ];

    /**
     * @param CrmMappingEntityMapper $mapper
     * @param wpdb|null              $wpdb Defaults to the `$wpdb` global, resolved lazily; see
     *                                     {@see AbstractWpRepository::db()}.
     */
    public function __construct(
        private readonly CrmMappingEntityMapper $mapper,
        ?wpdb $wpdb = null,
    ) {
        parent::__construct($wpdb);
    }

    /**
     * Reports a rejected unique index as the module's conflict.
     *
     * Two indexes can reject a mapping. Which one is not read back from the
     * driver's message — that text is localised and not reliably parseable (D-025)
     * — so the generic conflict is raised and the caller, which knows what it
     * just tried to write, refines it with {@see CrmMappingDuplicateException::forLocalKey()}
     * or {@see CrmMappingDuplicateException::forRemoteKey()} where the distinction
     * matters.
     *
     * @param Throwable $exception
     *
     * @return Throwable
     */
    protected function duplicateException(Throwable $exception): Throwable
    {
        return new CrmMappingDuplicateException(previous: $exception);
    }

    /**
     * Inserts a new mapping.
     *
     * @param CrmMappingEntity $mapping
     *
     * @return CrmMappingEntity The stored mapping, carrying its id.
     *
     * @throws CrmMappingDuplicateException When either UNIQUE index rejects the row.
     * @throws RuntimeException When the insert fails.
     */
    public function create(CrmMappingEntity $mapping): CrmMappingEntity
    {
        $now = $this->now();

        $id = $this->insert(
            [
                ...$this->mapper->toPersistenceArray($mapping),
                'created_at' => $mapping->createdAt === null ? $now : $this->toStorageDate($mapping->createdAt),
                'updated_at' => $now,
            ],
            self::TABLE_NAME,
            'insert into ' . self::TABLE_NAME
        );

        return $this->readBack($id);
    }

    /**
     * Writes a stored mapping's current state back to its row.
     *
     * @param CrmMappingEntity $mapping
     *
     * @return CrmMappingEntity The stored mapping.
     *
     * @throws RuntimeException When the mapping has no id.
     * @throws CrmMappingDuplicateException When either UNIQUE index rejects the row.
     */
    public function update(CrmMappingEntity $mapping): CrmMappingEntity
    {
        $mappingId = $this->storedId($mapping);

        $this->write(
            $mappingId,
            [...$this->mapper->toPersistenceArray($mapping), 'updated_at' => $this->now()],
            self::TABLE_NAME,
            sprintf('update crm mapping %d', $mappingId)
        );

        return $this->readBack($mappingId);
    }

    /**
     * Deletes a mapping.
     *
     * A zero-row delete is a no-op rather than a not-found outcome: the merge
     * relink (A4) deletes a mapping the caller has just read inside the same
     * transaction, so a missing row would mean the read and the delete disagreed
     * — but the caller's next step is the same either way, and the contract
     * declares no not-found exception for this port.
     *
     * @param int $mappingId
     *
     * @return void
     *
     * @throws RuntimeException When the delete fails.
     */
    public function delete(int $mappingId): void
    {
        $this->deleteRecord(
            $mappingId,
            self::TABLE_NAME,
            sprintf('delete crm mapping %d', $mappingId)
        );
    }

    /**
     * Reads a mapping while holding a row lock.
     *
     * @param int $mappingId
     *
     * @return CrmMappingEntity|null Null when no mapping carries the id.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByIdForUpdate(int $mappingId): ?CrmMappingEntity
    {
        $row = $this->findRecordForUpdate(
            $mappingId,
            self::TABLE_NAME,
            sprintf('lock crm mapping %d for update', $mappingId)
        );

        return $row !== null ? $this->mapper->toEntity($row) : null;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Reads back a row just written.
     *
     * @param int $mappingId
     *
     * @return CrmMappingEntity
     *
     * @throws RuntimeException When the row cannot be read back.
     */
    private function readBack(int $mappingId): CrmMappingEntity
    {
        $db = $this->db();

        $row = $db->get_row($db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `id` = %%d LIMIT 1',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->tableName(self::TABLE_NAME))
            ),
            $mappingId
        ), self::OUTPUT_ASSOC);

        if (! is_array($row)) {
            throw new RuntimeException(sprintf(
                'The row for crm mapping %d could not be read back after it was written.',
                $mappingId
            ));
        }

        return $this->mapper->toEntity($row);
    }

    /**
     * The id a write must target.
     *
     * @param CrmMappingEntity $mapping
     *
     * @return int
     *
     * @throws RuntimeException When the mapping has never been saved.
     */
    private function storedId(CrmMappingEntity $mapping): int
    {
        if ($mapping->id === null) {
            throw new RuntimeException(
                'A write was attempted for a crm mapping with no id. Use create() for a mapping that '
                . 'has never been saved.'
            );
        }

        return $mapping->id;
    }

    /**
     * A datetime as the UTC string the columns store.
     *
     * @param \DateTimeImmutable $date
     *
     * @return string
     */
    private function toStorageDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
