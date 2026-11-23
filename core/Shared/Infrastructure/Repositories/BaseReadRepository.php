<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Repositories;

/**
 * Shared base for module read adapters that talk to `$wpdb`.
 *
 * Extends {@see AbstractWpRepository} for the handle and identifier quoting, so a
 * read adapter shares the connection layer without inheriting a single method that
 * can modify a row — the boundary that keeps the CQRS port split enforceable by
 * inheritance rather than by discipline alone.
 *
 * Provides shared, read-only helpers for fetching a row by id and checking row
 * existence. It inherits no write method, preserving the CQRS boundary by
 * inheritance rather than by discipline alone.
 *
 * Infrastructure only; entities are mapped by each module's own mapper.
 */
abstract class BaseReadRepository extends AbstractWpRepository
{
    /**
     * Reads a row by its id.
     *
     * @param int $id
     * @param string $table The table name, without the WordPress prefix.
     *
     * @return array<string, mixed>|null
     *
     * @throws \RuntimeException When the read fails.
     */
    protected function findRecordById(int $id, string $table): ?array
    {
        $db = $this->db();

        $row = $db->get_row($db->prepare(
            sprintf(
                'SELECT * FROM `%s` WHERE `id` = %%d LIMIT 1',
                $this->escapeIdentifier($this->tableName($table))
            ),
            $id
        ), ARRAY_A);

        if ($row === null && $db->last_error !== '') {
            throw new \RuntimeException('Failed to read a database row.');
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Checks whether a row with the given id exists.
     *
     * @param int $id
     * @param string $table The table name, without the WordPress prefix.
     *
     * @return bool
     *
     * @throws \RuntimeException When the query fails.
     */
    protected function rowExists(int $id, string $table): bool
    {
        $db = $this->db();

        $count = $db->get_var($db->prepare(
            sprintf(
                'SELECT COUNT(*) FROM `%s` WHERE `id` = %%d',
                $this->escapeIdentifier($this->tableName($table))
            ),
            $id
        ));

        if ($count === null && $db->last_error !== '') {
            throw new \RuntimeException('Failed to check whether a database row exists.');
        }

        return (int) $count > 0;
    }
}
