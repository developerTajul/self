<?php

declare(strict_types=1);

namespace Core\Company\Infrastructure\Repositories;

use Core\Company\Application\Contracts\CompanyQueryContract;
use Core\Company\Domain\DataObjects\CompanyDetailData;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\ReadModels\CompanyProjectionDTO;
use Core\Company\Domain\ValueObjects\CompanyFilter;
use Core\Company\Infrastructure\Mappers\CompanyEntityMapper;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use Core\Shared\Infrastructure\Repositories\BaseReadRepository;
use RuntimeException;
use wpdb;

/**
 * `$wpdb` reads for the company list, detail and single lookup. Returns read
 * models only.
 *
 * Every statement binds its values through `$wpdb->prepare()`. The two things
 * that cannot be bound — a column list and the `ORDER BY` — are settled in code
 * from class constants, never from a request, so no value reaches a statement as
 * text.
 *
 * @implements CompanyQueryContract
 */
final class WpCompanyReadRepository extends BaseReadRepository implements CompanyQueryContract
{
    /** The table's name without the WordPress prefix. */
    public const TABLE_SUFFIX = 'companies';

    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * The columns the reads select, spelled out rather than `*`.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'name', 'website', 'industry', 'phone', 'address', 'created_at', 'updated_at',
    ];

    /**
     * The order the list is always read in.
     *
     * A constant rather than a request value: the list offers no sorting control,
     * so there is no untrusted text to whitelist and no direction to normalise.
     * Both are settled here in code, which is also what keeps the `ORDER BY`
     * clause free of anything a URL could reach.
     */
    private const ORDER_COLUMN = 'created_at';

    /** The direction {@see self::ORDER_COLUMN} is read in. */
    private const ORDER_DIRECTION = 'DESC';

    /**
     * @param CompanyEntityMapper $mapper
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily; see
     *                        {@see AbstractWpRepository::db()}.
     */
    public function __construct(
        private readonly CompanyEntityMapper $mapper,
        ?wpdb $wpdb = null,
    ) {
        parent::__construct($wpdb);
    }

    /**
     * Returns one page of companies in the list's own order.
     *
     * The count and the slice read the same table, so the total and the rows
     * cannot describe different sets — the failure that shows up as a page of
     * rows under a count that does not admit them.
     *
     * @param CompanyFilter $filter
     *
     * @return PaginatedResult Items are CompanyProjectionDTO instances.
     */
    public function getFilteredCompanies(CompanyFilter $filter): PaginatedResult
    {
        $db = $this->db();
        $table = $this->escapeIdentifier($this->tableName(self::TABLE_SUFFIX));

        $perPage = $filter->perPage;
        $page = $filter->page;

        $total = (int) $db->get_var(sprintf('SELECT COUNT(*) FROM `%s`', $table));

        // The count settles the real bound, so a page past the end is clamped to
        // the last one before the offset is computed — otherwise the read selects
        // past the end and the pager shows a blank screen under a count that does
        // not admit the page. PaginatedResult owns that arithmetic, so the clamp
        // asks it rather than re-deriving the bounds.
        $requested = PaginatedResult::fromSlice(
            items: [],
            total: $total,
            perPage: $perPage,
            currentPage: $page
        );

        $page = $requested->isBeyondLastPage() ? $requested->lastPage() : $requested->currentPage();
        $offset = ($page - 1) * $perPage;

        // LIMIT and OFFSET are ints this class computed from a validated page
        // size. `%d` is left unquoted by `prepare()`, so they reach the clause as
        // bare integers, which is what MySQL's `LIMIT` requires.
        $rows = $this->rows(
            $db->prepare(
                sprintf(
                    'SELECT `%s` FROM `%s` ORDER BY `%s` %s, `id` DESC LIMIT %%d OFFSET %%d',
                    implode('`, `', self::COLUMNS),
                    $table,
                    self::ORDER_COLUMN,
                    self::ORDER_DIRECTION
                ),
                $perPage,
                $offset
            ),
            'read the company list'
        );

        return PaginatedResult::fromSlice(
            items: array_map(fn (array $row): CompanyProjectionDTO => $this->toProjection($row), $rows),
            total: $total,
            perPage: $perPage,
            currentPage: $page
        );
    }

    /**
     * Returns one company on its own, without the related records the detail
     * read drags in.
     *
     * @param int $companyId
     *
     * @return CompanyEntity|null Null when no company carries the id.
     */
    public function findById(int $companyId): ?CompanyEntity
    {
        $row = $this->findRecordById($companyId, self::TABLE_SUFFIX);

        return $row === null ? null : $this->mapper->toEntity($row);
    }

    /**
     * Returns one company as a detail read model.
     *
     * @param int $companyId
     *
     * @return CompanyDetailData|null Null when no company carries the id.
     */
    public function findDetailById(int $companyId): ?CompanyDetailData
    {
        $company = $this->findById($companyId);

        if ($company === null) {
            return null;
        }

        return new CompanyDetailData(
            id: $company->id ?? $companyId,
            name: $company->name,
            website: $company->website,
            industry: $company->industry,
            phone: $company->phone,
            address: $company->address,
            createdAt: $company->createdAt,
            updatedAt: $company->updatedAt,
        );
    }

    // ------------------------------------------------------------------ mapping

    /**
     * Flattens a row onto the columns the list shows.
     *
     * @param array<string, mixed> $row
     *
     * @return CompanyProjectionDTO
     */
    private function toProjection(array $row): CompanyProjectionDTO
    {
        return new CompanyProjectionDTO(
            id: (int) $row['id'],
            name: (string) $row['name'],
            website: $this->toNullableString($row['website'] ?? null),
            industry: $this->toNullableString($row['industry'] ?? null),
            phone: $this->toNullableString($row['phone'] ?? null),
            address: $this->toNullableString($row['address'] ?? null),
            createdAt: $this->toNullableString($row['created_at'] ?? null),
            updatedAt: $this->toNullableString($row['updated_at'] ?? null),
        );
    }

    // ------------------------------------------------------------------ internals

    /**
     * Reads one row, or null when there is genuinely no such row.
     *
     * `$wpdb` reports a failed statement and an empty result identically — both
     * are a null from `get_row()` — so the two are told apart by `last_error`. A
     * database fault must not be served to a caller as absence, because a caller
     * reads null as "no such company" and answers 404.
     *
     * @param string $sql A statement with `%d`/`%s` placeholders.
     * @param list<mixed> $args Values for those placeholders, in order.
     * @param string $action Phrased for the failure message.
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException When the statement fails.
     */
    private function findRow(string $sql, array $args, string $action): ?array
    {
        $db = $this->db();

        $row = $db->get_row($this->prepare($sql, $args), self::OUTPUT_ASSOC);

        if ($row === null && $db->last_error !== '') {
            throw new RuntimeException(sprintf('Failed to %s: %s', $action, $db->last_error));
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Reads a set of rows, failing loudly on a broken statement.
     *
     * **Not `$rows === false`.** WordPress's `get_results()` returns an empty
     * array on a failed statement, not `false`: it calls `query()` but ignores the
     * return value, then builds its result from `last_result`, which `flush()` has
     * already set to `array()` (see `wp-includes/class-wpdb.php`, `get_results()`
     * and `flush()`). So a `=== false` test never fires in production, and every
     * broken multi-row read would be reported as an ordinary empty result —
     * a fault rendered as absence.
     *
     * The failure is read from `last_error` instead, which `flush()` clears at the
     * start of each statement and the driver sets on error. This is the same test
     * {@see findRow()} and `BaseWriteRepository::rowExists()` use, so all three reads in
     * the module now agree on how a fault is recognised.
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
     * not an error but literal SQL text — `WHERE id = %d` matches nothing, and
     * `%s` inside a quoted string is read as a value. That reads as an ordinary
     * empty result rather than a fault, which is the worst way for it to fail.
     *
     * The two conditions are therefore checked together, and the mismatch is
     * raised rather than served. Every caller here builds its `WHERE` body
     * alongside its own arguments, so a caller falling out of step is a coding
     * error in this file, not something a request can provoke.
     *
     * WordPress's own `prepare()` warns when the query contains no `%` at all
     * (`wp-includes/class-wpdb.php`), which is why the empty-argument path exists
     * at all.
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
                    'A company read was built with placeholders but no values to bind them. '
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
                'Failed to bind the parameters of a company read. The statement was: %s',
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
