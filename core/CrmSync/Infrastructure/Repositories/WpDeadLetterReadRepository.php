<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Repositories;

use Core\CrmSync\Application\Contracts\DeadLetterQueryContract;
use Core\CrmSync\Domain\Entities\DeadLetterEntity;
use Core\CrmSync\Domain\ReadModels\DeadLetterProjectionDTO;
use Core\CrmSync\Infrastructure\Mappers\DeadLetterEntityMapper;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use Core\Shared\Infrastructure\Repositories\AbstractWpRepository;
use Core\Shared\Infrastructure\Repositories\BaseReadRepository;
use RuntimeException;
use wpdb;

/**
 * `$wpdb` reads for dead letters. Inherits no write method.
 *
 * The single-record read returns a full Entity because the replay use case needs
 * the payload and the behaviour; the page read returns projections because the
 * inspector shows columns and never replays from the list.
 *
 * @implements DeadLetterQueryContract
 */
final class WpDeadLetterReadRepository extends BaseReadRepository implements DeadLetterQueryContract
{
    /** The table's name without the WordPress prefix. */
    private const TABLE_NAME = 'dead_letters';

    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * The columns the entity read selects, in a fixed order.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'sync_event_id', 'replayed_sync_event_id', 'provider', 'job_class',
        'payload_json', 'error_message', 'http_status_code', 'failed_at', 'retried_at',
    ];

    /**
     * The columns the inspector's page selects.
     *
     * `payload_json` is absent: the list shows no payload, and a `longText` column
     * per row would be read and discarded — the most expensive column in the table
     * for no visible reason. The scrubbed `error_message` IS selected, because it
     * is the field an operator reads to decide whether to replay.
     *
     * @var list<string>
     */
    private const PROJECTION_COLUMNS = [
        'id', 'provider', 'job_class', 'error_message', 'failed_at',
        'sync_event_id', 'replayed_sync_event_id', 'http_status_code', 'retried_at',
    ];

    /**
     * The order the list is always read in.
     *
     * A constant rather than a request value. `failed_at DESC` puts the newest
     * failure first, which is what an operator opening the inspector wants; `id`
     * is the tiebreaker so two failures in the same second cannot swap places
     * between two reads of one page.
     */
    private const ORDER_COLUMN = 'failed_at';

    /** The direction {@see self::ORDER_COLUMN} is read in. */
    private const ORDER_DIRECTION = 'DESC';

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
     * Reads one dead letter by id.
     *
     * @param int $deadLetterId
     *
     * @return DeadLetterEntity|null Null when no dead letter carries the id.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findById(int $deadLetterId): ?DeadLetterEntity
    {
        $row = $this->findRecordById($deadLetterId, self::TABLE_NAME);

        return $row === null ? null : $this->mapper->toEntity($row);
    }

    /**
     * Returns one page of dead letters for the inspector screen.
     *
     * The count and the slice share one `WHERE` body, built once from the filter,
     * so the total can never describe a different set from the rows beneath it.
     *
     * `replayed` is a three-state filter: true for replayed only, false for
     * pending only, null for both. It maps onto `retried_at IS NOT NULL` /
     * `IS NULL`, which the `dead_letters_provider_retried_idx` index can serve on
     * the second column once a provider narrows it — for the filter alone the
     * predicate is not index-leading, and the table is small by construction
     * because every row is a permanent failure someone must look at.
     *
     * @param int       $perPage
     * @param int       $page
     * @param bool|null $replayed True for replayed only, false for pending only, null for both.
     *
     * @return PaginatedResult Items are `DeadLetterProjectionDTO` instances.
     *
     * @throws RuntimeException When a read fails.
     */
    public function getDeadLetters(int $perPage, int $page, ?bool $replayed = null): PaginatedResult
    {
        $db = $this->db();
        $table = $this->escapeIdentifier($this->tableName(self::TABLE_NAME));

        $where = '';

        if ($replayed !== null) {
            $where = $replayed ? ' WHERE `retried_at` IS NOT NULL' : ' WHERE `retried_at` IS NULL';
        }

        $total = (int) $db->get_var(sprintf('SELECT COUNT(*) FROM `%s`%s', $table, $where));

        // The count settles the real bound, so a page past the end is clamped to
        // the last one before the offset is computed — otherwise the read selects
        // past the end and the pager shows a blank screen under a count that does
        // not admit the page.
        $requested = PaginatedResult::fromSlice(
            items: [],
            total: $total,
            perPage: $perPage,
            currentPage: $page
        );

        $page = $requested->isBeyondLastPage() ? $requested->lastPage() : $requested->currentPage();
        $offset = ($page - 1) * $perPage;

        $sql = sprintf(
            'SELECT `%s` FROM `%s`%s ORDER BY `%s` %s, `id` %s LIMIT %%d OFFSET %%d',
            implode('`, `', self::PROJECTION_COLUMNS),
            $table,
            $where,
            self::ORDER_COLUMN,
            self::ORDER_DIRECTION,
            self::ORDER_DIRECTION
        );

        $rows = $this->rows($this->prepare($sql, [$perPage, $offset]), 'read the dead-letter list');

        return PaginatedResult::fromSlice(
            items: array_map(fn (array $row): DeadLetterProjectionDTO => $this->toProjection($row), $rows),
            total: $total,
            perPage: $perPage,
            currentPage: $page
        );
    }

    // ------------------------------------------------------------------ internals

    /**
     * Flattens a row onto the columns the inspector shows.
     *
     * @param array<string, mixed> $row
     *
     * @return DeadLetterProjectionDTO
     */
    private function toProjection(array $row): DeadLetterProjectionDTO
    {
        return new DeadLetterProjectionDTO(
            id: (int) $row['id'],
            provider: (string) $row['provider'],
            jobClass: (string) $row['job_class'],
            errorMessage: (string) $row['error_message'],
            failedAt: (string) $row['failed_at'],
            syncEventId: $this->toNullableInt($row['sync_event_id'] ?? null),
            replayedSyncEventId: $this->toNullableInt($row['replayed_sync_event_id'] ?? null),
            httpStatusCode: $this->toNullableInt($row['http_status_code'] ?? null),
            retriedAt: $this->toNullableString($row['retried_at'] ?? null),
        );
    }

    /**
     * Reads a set of rows, failing loudly on a broken statement.
     *
     * **Not `$rows === false`.** WordPress's `get_results()` returns an empty
     * array on a failed statement rather than `false`, so a `=== false` test
     * never fires and every broken read would be reported as an ordinary empty
     * page. The failure is read from `last_error` instead.
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
     * nothing to bind to; a statement that still carries a placeholder would
     * otherwise be handed to the driver, where `%d` is literal SQL text that
     * matches nothing and reads as an empty result rather than a fault.
     *
     * @param string      $sql  A statement with `%d`/`%s` placeholders.
     * @param list<mixed> $args Values for those placeholders, in order.
     *
     * @return string A statement safe to execute.
     *
     * @throws RuntimeException When a statement with placeholders has no values, or `prepare()` fails.
     */
    private function prepare(string $sql, array $args): string
    {
        if ($args === []) {
            if (str_contains($sql, '%')) {
                throw new RuntimeException(sprintf(
                    'A dead-letter read was built with placeholders but no values to bind them. '
                    . 'The statement was: %s',
                    $sql
                ));
            }

            return $sql;
        }

        $prepared = $this->db()->prepare($sql, ...$args);

        if (! is_string($prepared) || $prepared === '') {
            throw new RuntimeException(sprintf(
                'Failed to bind the parameters of a dead-letter read. The statement was: %s',
                $sql
            ));
        }

        return $prepared;
    }

    /**
     * A column value as a nullable int.
     *
     * A zero is read as absence, matching how the rows are written: the replay
     * columns are nullable foreign keys and no row carries id zero.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }

        return (int) $value;
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
