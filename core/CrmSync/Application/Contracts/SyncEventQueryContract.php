<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Contracts;

use Core\CrmSync\Application\DataObjects\SyncEventFilter;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use DateTimeImmutable;

/**
 * Read side of the sync ledger port.
 *
 * Carries no method that can change a row, and its implementations extend
 * `BaseReadRepository`, which inherits none.
 */
interface SyncEventQueryContract
{
    /**
     * Returns one page of ledger entries matching the filter.
     *
     * The audit screen's read: what was queued, what happened to it, and when.
     *
     * @param SyncEventFilter $filter
     *
     * @return PaginatedResult Items are `SyncEventProjectionDTO` instances.
     */
    public function getFilteredEvents(SyncEventFilter $filter): PaginatedResult;

    /**
     * Finds the oldest exhausted event whose processing lease has expired.
     *
     * The write-side stale-lease batch throws after encountering an exhausted
     * event, but its frozen exception carries no event id. This bounded
     * diagnostic read lets the reaper identify that row without adding a pure
     * read to the write port.
     *
     * @param DateTimeImmutable $now
     *
     * @return int|null Null when no exhausted stale lease remains.
     */
    public function findOldestExhaustedStaleEventId(DateTimeImmutable $now): ?int;
}
