<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\ReadModels\LeadProjectionDTO;
use Core\Lead\Domain\ValueObjects\LeadFilter;
use Core\Lead\Infrastructure\Mappers\LeadEntityMapper;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use Core\Shared\Infrastructure\Repositories\BaseReadRepository;
use RuntimeException;
use wpdb;

/**
 * `$wpdb` reads for leads. Inherits no write method.
 *
 * Every statement binds its values through `$wpdb->prepare()`. The two things
 * that cannot be bound — a column list and the `ORDER BY` — are settled in code
 * from class constants, never from a request, so no value reaches a statement as
 * text.
 *
 * @implements LeadQueryContract
 */
final class WpLeadReadRepository extends BaseReadRepository implements LeadQueryContract
{
    /** The table's name without the WordPress prefix. */
    private const TABLE_NAME = 'leads';

    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * The columns the entity reads select, in a fixed order.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'uuid', 'name', 'email', 'phone', 'company_name', 'website_url',
        'status', 'source', 'meta_json', 'assigned_to', 'created_at', 'updated_at',
    ];

    /**
     * The columns the list read selects — the projection's own fields only.
     *
     * `meta_json` is deliberately absent: the list never renders it, and decoding
     * JSON per row would pay for a parse no one reads (see the read model).
     *
     * @var list<string>
     */
    private const PROJECTION_COLUMNS = [
        'id', 'uuid', 'name', 'email', 'phone', 'company_name', 'status', 'source', 'created_at',
    ];

    /**
     * The order the list is always read in.
     *
     * A constant rather than a request value: the list offers no sorting control,
     * so there is no untrusted text to whitelist and no direction to normalise.
     * Both are settled here in code, which is also what keeps the `ORDER BY`
     * clause free of anything a URL could reach.
     *
     * `id` is the tiebreaker rather than the primary key alone being relied on:
     * leads captured in the same second share a `created_at`, and without a
     * second key MySQL is free to order them differently between two reads of the
     * same page — which shows up as a row appearing twice and another vanishing.
     */
    private const ORDER_COLUMN = 'created_at';

    /** The direction {@see self::ORDER_COLUMN} is read in. */
    private const ORDER_DIRECTION = 'DESC';

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
     * Reads one lead by id.
     *
     * @param int $leadId
     *
     * @return LeadEntity|null Null when no lead carries the id.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findById(int $leadId): ?LeadEntity
    {
        $row = $this->findRecordById($leadId, self::TABLE_NAME);

        return $row === null ? null : $this->mapper->toEntity($row);
    }

    /**
     * Reads the lead that holds its immutable public UUID.
     *
     * @param string $uuid
     *
     * @return LeadEntity|null Null when no lead carries the UUID.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByUuid(string $uuid): ?LeadEntity
    {
        return $this->findByColumn('uuid', $uuid);
    }

    /**
     * Reads the lead that holds a normalized email address.
     *
     * The comparison is exact and the caller has already normalized: `EmailAddress`
     * lowercased and trimmed on the way in, and the write path stores that same
     * form, so the unique index on the column is what serves this read. Lowercasing
     * here would make the predicate unindexable — `LOWER(email) = ?` cannot use a
     * plain index — and turn a point lookup into a scan of every lead.
     *
     * @param string $email Normalized (trimmed, lowercased).
     *
     * @return LeadEntity|null Null when no lead carries the address.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByEmail(string $email): ?LeadEntity
    {
        return $this->findByColumn('email', $email);
    }

    /**
     * Reads the lead that holds a normalized E.164 phone number.
     *
     * @param string $phone Normalized to E.164.
     *
     * @return LeadEntity|null Null when no lead carries the number.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByPhone(string $phone): ?LeadEntity
    {
        return $this->findByColumn('phone', $phone);
    }

    /**
     * Returns one page of leads matching the filter.
     *
     * The count and the slice share one `WHERE` body, built once from the filter
     * and used by both, so the total can never describe a different set from the
     * rows beneath it.
     *
     * `status` and `source` are filtered together, which is the column order of
     * `leads_status_source_idx` — the index can therefore serve both a
     * status-and-source filter and a status-only one, while a source-only filter
     * cannot use it and scans. That is a deliberate trade: the list screen offers
     * both controls, the index is what the schema provides, and adding a
     * source-first index is a schema change this step must not make.
     *
     * @param LeadFilter $filter
     *
     * @return PaginatedResult Items are `LeadProjectionDTO` instances.
     *
     * @throws RuntimeException When a read fails.
     */
    public function getFilteredLeads(LeadFilter $filter): PaginatedResult
    {
        $db = $this->db();
        $table = $this->escapeIdentifier($this->tableName(self::TABLE_NAME));

        [$where, $args] = $this->buildFilterClause($filter);

        $total = (int) $db->get_var(
            $args === []
                ? sprintf('SELECT COUNT(*) FROM `%s`', $table)
                : $this->prepare(sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $table, $where), $args)
        );

        // The count settles the real bound, so a page past the end is clamped to
        // the last one before the offset is computed — otherwise the read selects
        // past the end and the pager shows a blank screen under a count that does
        // not admit the page. PaginatedResult owns that arithmetic, so the clamp
        // asks it rather than re-deriving the bounds.
        $requested = PaginatedResult::fromSlice(
            items: [],
            total: $total,
            perPage: $filter->perPage,
            currentPage: $filter->page
        );

        $page = $requested->isBeyondLastPage() ? $requested->lastPage() : $requested->currentPage();
        $offset = ($page - 1) * $filter->perPage;

        $sql = sprintf(
            'SELECT `%s` FROM `%s`%s ORDER BY `%s` %s, `id` %s LIMIT %%d OFFSET %%d',
            implode('`, `', self::PROJECTION_COLUMNS),
            $table,
            $where === '' ? '' : ' WHERE ' . $where,
            self::ORDER_COLUMN,
            self::ORDER_DIRECTION,
            self::ORDER_DIRECTION
        );

        // LIMIT and OFFSET are ints this class computed from a validated page
        // size. `%d` is left unquoted by `prepare()`, so they reach the clause as
        // bare integers, which is what MySQL's `LIMIT` requires.
        $rows = $this->rows(
            $this->prepare($sql, [...$args, $filter->perPage, $offset]),
            'read the lead list'
        );

        return PaginatedResult::fromSlice(
            items: array_map(fn (array $row): LeadProjectionDTO => $this->toProjection($row), $rows),
            total: $total,
            perPage: $filter->perPage,
            currentPage: $page
        );
    }

    // ------------------------------------------------------------------ internals

    /**
     * Reads one row by an indexed unique column.
     *
     * The column name is a constant supplied by this class's own methods, so it
     * is quoted rather than bound — a column identifier cannot be a placeholder,
     * and the value is bound separately.
     *
     * @param string $column Either `email` or `phone`.
     * @param string $value  An already-normalized value.
     *
     * @return LeadEntity|null
     *
     * @throws RuntimeException When the read fails.
     */
    private function findByColumn(string $column, string $value): ?LeadEntity
    {
        $db = $this->db();

        $row = $db->get_row($db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `%s` = %%s LIMIT 1',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->tableName(self::TABLE_NAME)),
                $this->escapeIdentifier($column)
            ),
            $value
        ), self::OUTPUT_ASSOC);

        if ($row === null && $db->last_error !== '') {
            throw new RuntimeException(sprintf(
                'Failed to read a lead by %s: %s',
                $column,
                $db->last_error
            ));
        }

        return is_array($row) ? $this->mapper->toEntity($row) : null;
    }

    /**
     * The `WHERE` body and its arguments for a filter.
     *
     * Built once and used by both the count and the slice. An empty filter yields
     * an empty string and no arguments rather than a clause that matches
     * everything, so the unfiltered read stays a statement with nothing to bind.
     *
     * @param LeadFilter $filter
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildFilterClause(LeadFilter $filter): array
    {
        $conditions = [];
        $args = [];

        if ($filter->status !== null) {
            $conditions[] = '`status` = %s';
            $args[] = $filter->status->value;
        }

        if ($filter->source !== null) {
            $conditions[] = '`source` = %s';
            $args[] = $filter->source->value;
        }

        if ($filter->search !== null) {
            // The term is escaped for `LIKE` before it is bound: `%` and `_` are
            // wildcards to MySQL, so a visitor typing `%` would otherwise match
            // every lead. `$wpdb->esc_like()` escapes them, and the wildcards that
            // make this a contains-search are added afterwards, deliberately.
            $conditions[] = '(`name` LIKE %s OR `email` LIKE %s)';

            $term = '%' . $this->db()->esc_like($filter->search) . '%';

            $args[] = $term;
            $args[] = $term;
        }

        return [implode(' AND ', $conditions), $args];
    }

    /**
     * Flattens a row onto the columns the list shows.
     *
     * @param array<string, mixed> $row
     *
     * @return LeadProjectionDTO
     */
    private function toProjection(array $row): LeadProjectionDTO
    {
        return new LeadProjectionDTO(
            id: (int) $row['id'],
            uuid: (string) $row['uuid'],
            name: $this->toNullableString($row['name'] ?? null),
            email: $this->toNullableString($row['email'] ?? null),
            phone: $this->toNullableString($row['phone'] ?? null),
            companyName: $this->toNullableString($row['company_name'] ?? null),
            status: (string) $row['status'],
            source: (string) $row['source'],
            createdAt: $this->toNullableString($row['created_at'] ?? null),
        );
    }

    /**
     * Reads a set of rows, failing loudly on a broken statement.
     *
     * **Not `$rows === false`.** WordPress's `get_results()` returns an empty
     * array on a failed statement, not `false`: it calls `query()` but ignores the
     * return value, then builds its result from `last_result`, which `flush()` has
     * already set to `array()`. So a `=== false` test never fires in production,
     * and every broken multi-row read would be reported as an ordinary empty
     * result — a fault rendered as absence.
     *
     * The failure is read from `last_error` instead, which `flush()` clears at the
     * start of each statement and the driver sets on error.
     *
     * @param string $sql An already-prepared statement.
     * @param string $action Phrased for the failure message.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException When the statement fails.
     */
    private function rows(string $sql, string $action): array
    {
        $db = $this->db();

        $rows = $db->get_results($sql, self::OUTPUT_ASSOC);

        if ($db->last_error !== '') {
            throw new RuntimeException(sprintf('Failed to %s: %s', $action, $db->last_error));
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Binds a statement's placeholders, and guarantees the result is executable.
     *
     * `prepare()` is skipped only when the statement has nothing to bind *and*
     * nothing to bind to. Skipping it on the argument count alone is what makes
     * this dangerous: a statement that still carries a `%d` or `%s` would be
     * returned unbound and handed straight to the driver, where a placeholder is
     * not an error but literal SQL text — `WHERE id = %d` matches nothing. That
     * reads as an ordinary empty result rather than a fault.
     *
     * The two conditions are therefore checked together, and the mismatch is
     * raised rather than served.
     *
     * @param string $sql A statement with `%d`/`%s` placeholders.
     * @param list<mixed> $args Values for those placeholders, in order.
     *
     * @return string A statement safe to execute.
     *
     * @throws RuntimeException When a statement with placeholders has no values.
     */
    private function prepare(string $sql, array $args): string
    {
        if ($args === []) {
            if (str_contains($sql, '%')) {
                throw new RuntimeException(sprintf(
                    'A lead read was built with placeholders but no values to bind them. '
                    . 'The statement was: %s',
                    $sql
                ));
            }

            return $sql;
        }

        $prepared = $this->db()->prepare($sql, ...$args);

        // `prepare()` returns null on a malformed statement. Returning the
        // unbound statement instead would hand `$wpdb` a query with literal `%d`
        // in it, so the failure is raised where it can still name the statement.
        if (! is_string($prepared) || $prepared === '') {
            throw new RuntimeException(sprintf(
                'Failed to bind the parameters of a lead read. The statement was: %s',
                $sql
            ));
        }

        return $prepared;
    }

    /**
     * A column value that is absent when null or empty.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function toNullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
