<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Repositories;

use Core\CrmSync\Application\Contracts\SyncEventQueryContract;
use Core\CrmSync\Application\DataObjects\SyncEventFilter;
use Core\CrmSync\Domain\Entities\SyncEventEntity;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\SyncDirection;
use Core\CrmSync\Domain\Enums\SyncEventStatus;
use Core\CrmSync\Domain\Rules\SyncRetryRules;
use Core\CrmSync\Domain\ReadModels\SyncEventProjectionDTO;
use Core\CrmSync\Infrastructure\Mappers\SyncEventEntityMapper;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Core\Shared\Infrastructure\Repositories\BaseReadRepository;
use wpdb;

/**
 * `$wpdb` reads for the sync ledger. Inherits no write method.
 *
 * @implements SyncEventQueryContract
 */
final class WpSyncEventReadRepository extends BaseReadRepository implements SyncEventQueryContract
{
    /** The table's name without the WordPress prefix. */
    private const TABLE_NAME = 'sync_events';

    /**
     * @param SyncEventEntityMapper $mapper
     * @param wpdb|null             $wpdb Defaults to the `$wpdb` global, resolved lazily.
     */
    public function __construct(
        private readonly SyncEventEntityMapper $mapper,
        ?wpdb $wpdb = null,
    ) {
        parent::__construct($wpdb);
    }

    /**
     * Returns one page of ledger entries matching the filter.
     *
     * @param SyncEventFilter $filter
     *
     * @return PaginatedResult Items are SyncEventProjectionDTO instances.
     */
    public function getFilteredEvents(SyncEventFilter $filter): PaginatedResult
    {
        $db = $this->db();
        $table = $this->escapeIdentifier($this->tableName(self::TABLE_NAME));
        [$where, $arguments] = $this->where($filter);
        $countSql = sprintf('SELECT COUNT(*) FROM `%s`%s', $table, $where === '' ? '' : ' WHERE ' . $where);
        $total = (int) $db->get_var($this->prepare($countSql, $arguments));
        $page = PaginatedResult::fromSlice([], $total, $filter->perPage, $filter->page);
        $currentPage = $page->isBeyondLastPage() ? $page->lastPage() : $page->currentPage();
        $offset = ($currentPage - 1) * $filter->perPage;
        $sql = sprintf(
            'SELECT `id`, `direction`, `provider`, `event_type`, `status`, `attempts`, `next_attempt_at`, `last_error`, `processed_at`, `created_at` FROM `%s`%s ORDER BY `created_at` DESC, `id` DESC LIMIT %%d OFFSET %%d',
            $table,
            $where === '' ? '' : ' WHERE ' . $where
        );
        $rows = $this->rows($this->prepare($sql, [...$arguments, $filter->perPage, $offset]));

        return PaginatedResult::fromSlice(
            array_map(fn (array $row): SyncEventProjectionDTO => $this->projection($row), $rows),
            $total,
            $filter->perPage,
            $currentPage
        );
    }

    /**
     * Finds the oldest exhausted event whose processing lease has expired.
     *
     * @param DateTimeImmutable $now
     *
     * @return int|null
     */
    public function findOldestExhaustedStaleEventId(DateTimeImmutable $now): ?int
    {
        $staleBefore = $now->setTimestamp($now->getTimestamp() - SyncRetryRules::LEASE_TIMEOUT_SECONDS)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $id = $this->db()->get_var($this->db()->prepare(
            sprintf(
                'SELECT `id` FROM `%s` WHERE `status` = %%s AND `attempts` >= %%d AND `locked_at` IS NOT NULL AND `locked_at` < %%s ORDER BY `locked_at` ASC, `id` ASC LIMIT 1',
                $this->escapeIdentifier($this->tableName(self::TABLE_NAME))
            ),
            SyncEventStatus::processing->value,
            SyncRetryRules::MAX_ATTEMPTS,
            $staleBefore
        ));

        if ($id === null && $this->db()->last_error !== '') {
            throw new RuntimeException('Failed to find an exhausted stale sync event: ' . $this->db()->last_error);
        }

        return $id === null ? null : (int) $id;
    }

    /**
     * Builds a projection from an explicitly selected row.
     *
     * @param array<string, mixed> $row
     *
     * @return SyncEventProjectionDTO
     */
    private function projection(array $row): SyncEventProjectionDTO
    {
        return new SyncEventProjectionDTO(
            id: (int) $row['id'],
            direction: (string) $row['direction'],
            provider: (string) $row['provider'],
            eventType: (string) $row['event_type'],
            status: (string) $row['status'],
            attempts: (int) $row['attempts'],
            nextAttemptAt: (string) $row['next_attempt_at'],
            lastError: $this->nullableString($row['last_error'] ?? null),
            processedAt: $this->nullableString($row['processed_at'] ?? null),
            createdAt: $this->nullableString($row['created_at'] ?? null),
        );
    }

    /**
     * Builds safe enum and text predicates.
     *
     * @param SyncEventFilter $filter
     *
     * @return array{string, list<mixed>}
     */
    private function where(SyncEventFilter $filter): array
    {
        $conditions = [];
        $arguments = [];

        if ($filter->direction !== null) {
            $conditions[] = '`direction` = %s';
            $arguments[] = $filter->direction->value;
        }
        if ($filter->provider !== null) {
            $conditions[] = '`provider` = %s';
            $arguments[] = $filter->provider->value;
        }
        if ($filter->status !== null) {
            $conditions[] = '`status` = %s';
            $arguments[] = $filter->status->value;
        }
        if ($filter->eventType !== null) {
            $conditions[] = '`event_type` = %s';
            $arguments[] = $filter->eventType;
        }

        return [implode(' AND ', $conditions), $arguments];
    }

    /**
     * Executes a prepared read with loud failure handling.
     *
     * @param string $sql
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $rows = $this->db()->get_results($sql, ARRAY_A);

        if ($this->db()->last_error !== '') {
            throw new RuntimeException('Failed to read the sync event list: ' . $this->db()->last_error);
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Binds parameters to a SQL statement.
     *
     * @param string $sql
     * @param list<mixed> $arguments
     *
     * @return string
     */
    private function prepare(string $sql, array $arguments): string
    {
        if ($arguments === []) {
            return $sql;
        }

        $prepared = $this->db()->prepare($sql, ...$arguments);

        if (! is_string($prepared) || $prepared === '') {
            throw new RuntimeException('Failed to prepare a sync event read.');
        }

        return $prepared;
    }

    /**
     * Reads an optional string column.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
