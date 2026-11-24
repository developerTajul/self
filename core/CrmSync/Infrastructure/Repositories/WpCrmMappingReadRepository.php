<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Repositories;

use Core\CrmSync\Application\Contracts\CrmMappingQueryContract;
use Core\CrmSync\Domain\Entities\CrmMappingEntity;
use Core\CrmSync\Domain\Enums\CrmObjectType;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\LocalEntityType;
use Core\CrmSync\Domain\ValueObjects\RemoteId;
use Core\CrmSync\Infrastructure\Mappers\CrmMappingEntityMapper;
use Core\Shared\Infrastructure\Repositories\BaseReadRepository;
use Core\Shared\Infrastructure\Repositories\AbstractWpRepository;
use RuntimeException;
use wpdb;

/**
 * `$wpdb` reads for CRM mappings. Inherits no write method.
 *
 * Each lookup names the columns of the UNIQUE index that serves it, in the
 * index's own column order, so MySQL can use the index rather than scanning.
 * That is why the predicates are written out per method instead of shared
 * behind one generic "find by array of criteria": a shared builder would make
 * the column order an accident of the caller's array, and the index would stop
 * being used the first time someone reordered it.
 *
 * @implements CrmMappingQueryContract
 */
final class WpCrmMappingReadRepository extends BaseReadRepository implements CrmMappingQueryContract
{
    /** The table's name without the WordPress prefix. */
    private const TABLE_NAME = 'crm_mappings';

    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * The columns every read selects, in a fixed order.
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
     * Reads the mapping for a local entity and one CRM object.
     *
     * Served by `crm_mappings_local_provider_object_unique`, whose columns are
     * `(entity_type, local_entity_id, provider, crm_object_type)` — the order the
     * predicate follows.
     *
     * @param LocalEntityType $entityType
     * @param int             $localEntityId
     * @param CrmProviderName $provider
     * @param CrmObjectType   $crmObjectType
     *
     * @return CrmMappingEntity|null Null when the local entity is not mapped for that object.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByLocalKey(
        LocalEntityType $entityType,
        int $localEntityId,
        CrmProviderName $provider,
        CrmObjectType $crmObjectType,
    ): ?CrmMappingEntity {
        return $this->findOne(
            'WHERE `entity_type` = %s AND `local_entity_id` = %d AND `provider` = %s '
            . 'AND `crm_object_type` = %s',
            [
                $entityType->value,
                $localEntityId,
                $provider->value,
                $crmObjectType->value,
            ],
            'read a crm mapping by its local key'
        );
    }

    /**
     * Reads the mapping that claims a remote record.
     *
     * Served by `crm_mappings_remote_record_unique`, whose columns are
     * `(provider, crm_object_type, crm_record_id)`.
     *
     * @param CrmProviderName $provider
     * @param CrmObjectType   $crmObjectType
     * @param RemoteId        $remoteId
     *
     * @return CrmMappingEntity|null Null when no local row is mapped to that record.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByRemoteKey(
        CrmProviderName $provider,
        CrmObjectType $crmObjectType,
        RemoteId $remoteId,
    ): ?CrmMappingEntity {
        return $this->findOne(
            'WHERE `provider` = %s AND `crm_object_type` = %s AND `crm_record_id` = %s',
            [
                $provider->value,
                $crmObjectType->value,
                $remoteId->toString(),
            ],
            'read a crm mapping by its remote key'
        );
    }

    /**
     * Reads every mapping a local entity holds, across providers and objects.
     *
     * Served by the leading two columns of `crm_mappings_local_provider_object_unique`,
     * so the index answers this too rather than scanning the table.
     *
     * @param LocalEntityType $entityType
     * @param int             $localEntityId
     *
     * @return list<CrmMappingEntity>
     *
     * @throws RuntimeException When the read fails.
     */
    public function findAllForLocalEntity(LocalEntityType $entityType, int $localEntityId): array
    {
        $db = $this->db();

        $sql = $db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `entity_type` = %%s AND `local_entity_id` = %%d '
                . 'ORDER BY `provider` ASC, `crm_object_type` ASC',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->tableName(self::TABLE_NAME))
            ),
            $entityType->value,
            $localEntityId
        );

        // `prepare()` returns null on a malformed statement; handing that to the
        // driver would run an empty query and report "no mappings" for a coding
        // error.
        if (! is_string($sql) || $sql === '') {
            throw new RuntimeException('Failed to prepare the read of a local entity\'s crm mappings.');
        }

        $rows = $db->get_results($sql, self::OUTPUT_ASSOC);

        // `get_results()` reports a failed statement by leaving `last_error` set,
        // not by returning false, so the failure is read from there (see
        // WpCompanyReadRepository::rows() for the same reasoning).
        if ($db->last_error !== '') {
            throw new RuntimeException(sprintf(
                'Failed to read a local entity\'s crm mappings: %s',
                $db->last_error
            ));
        }

        if (! is_array($rows)) {
            return [];
        }

        return array_map(fn (array $row): CrmMappingEntity => $this->mapper->toEntity($row), $rows);
    }

    // ------------------------------------------------------------------ internals

    /**
     * Reads at most one row for a prepared `WHERE` body.
     *
     * @param string      $where  A `WHERE` body with `%s`/`%d` placeholders.
     * @param list<mixed> $args   Values for those placeholders, in order.
     * @param string      $action Phrased for the failure message.
     *
     * @return CrmMappingEntity|null
     *
     * @throws RuntimeException When the read fails.
     */
    private function findOne(string $where, array $args, string $action): ?CrmMappingEntity
    {
        $db = $this->db();

        $sql = $db->prepare(sprintf(
            'SELECT `%s` FROM `%s` %s LIMIT 1',
            implode('`, `', self::COLUMNS),
            $this->escapeIdentifier($this->tableName(self::TABLE_NAME)),
            $where
        ), ...$args);

        if (! is_string($sql) || $sql === '') {
            throw new RuntimeException(sprintf('Failed to prepare the statement to %s.', $action));
        }

        $row = $db->get_row($sql, self::OUTPUT_ASSOC);

        // A null from `get_row()` means a missing row and a broken statement
        // alike, so the two are told apart by `last_error` — a fault must not be
        // served to the caller as "no mapping exists", which would send a capture
        // down the create-a-new-link path.
        if ($row === null && $db->last_error !== '') {
            throw new RuntimeException(sprintf('Failed to %s: %s', $action, $db->last_error));
        }

        return is_array($row) ? $this->mapper->toEntity($row) : null;
    }
}
