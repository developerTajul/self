<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Repositories;

use RuntimeException;
use wpdb;

/**
 * The connection and identifier layer shared by every `$wpdb` repository.
 *
 * Holds no read and no write method: it resolves the handle, qualifies table
 * names, and quotes identifiers. Write helpers live on
 * {@see BaseWriteRepository}, shared read helpers on {@see BaseReadRepository},
 * so a read adapter inherits nothing that can modify a row.
 *
 * Infrastructure only; entities are mapped by each module's own mapper.
 *
 * The handle is optional and resolved lazily, because a repository is built by
 * its provider during `register()` — before WordPress has loaded the global — and
 * a constructor that demanded one would make every repository unbuildable at that
 * point. See {@see db()}.
 */
abstract class AbstractWpRepository
{
    /**
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily; see
     *                        {@see db()}. Optional so the container can build a
     *                        repository during `register()`.
     */
    public function __construct(
        protected ?wpdb $wpdb = null,
    ) {}

    /**
     * The fully-qualified table name.
     *
     * @param string $table The table name, without the WordPress prefix.
     *
     * @return string
     */
    protected function tableName(string $table): string
    {
        return $this->db()->prefix . $table;
    }

    /**
     * Wrap a table name in backticks, escaping any backtick inside it.
     *
     * @param string $identifier
     *
     * @return string
     */
    protected function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * The `$wpdb` handle, resolved on first use.
     *
     * Lazy rather than constructor-injected, because WordPress owns this object: a
     * constructor type-hint on `wpdb` would resolve through the container's
     * auto-wiring to a freshly constructed, unconnected instance rather than the
     * site's global one. Resolving here also means a repository built during
     * `register()` is safe, which is when every provider builds one.
     *
     * @return wpdb
     *
     * @throws RuntimeException When there is no handle to resolve.
     */
    protected function db(): wpdb
    {
        if (isset($this->wpdb) && $this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (! isset($GLOBALS['wpdb']) || ! $GLOBALS['wpdb'] instanceof wpdb) {
            throw new RuntimeException(
                'No $wpdb instance is available. A repository cannot run outside WordPress, and it '
                . 'cannot run during a hook that fires before the database is loaded.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
