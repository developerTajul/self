<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\DataObjects;

use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\SyncDirection;
use Core\CrmSync\Domain\Enums\SyncEventStatus;
use Core\CrmSync\Domain\Exceptions\InvalidSyncFilterException;

/**
 * Filter criteria the sync audit log is read with.
 *
 * Validates itself, so the criteria reaching Infrastructure are always usable:
 * an out-of-bounds page size is refused rather than turned into an unbounded
 * read of the ledger, which on a busy site is the largest table in the plugin.
 */
final readonly class SyncEventFilter
{
    /**
     * The largest page size the audit log accepts.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * The smallest page size the audit log accepts.
     */
    private const MIN_PER_PAGE = 1;

    /**
     * The page size used when none was given.
     */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * The page to read, 1-based; anything lower is read as the first page.
     */
    public int $page;

    /**
     * @param int                $perPage
     * @param int                $page
     * @param SyncDirection|null $direction
     * @param CrmProviderName|null $provider
     * @param SyncEventStatus|null $status
     * @param string|null        $eventType
     *
     * @throws InvalidSyncFilterException When the page size falls outside its bounds.
     */
    public function __construct(
        public int $perPage = self::DEFAULT_PER_PAGE,
        int $page = 1,
        public ?SyncDirection $direction = null,
        public ?CrmProviderName $provider = null,
        public ?SyncEventStatus $status = null,
        public ?string $eventType = null,
    ) {
        if ($perPage < self::MIN_PER_PAGE || $perPage > self::MAX_PER_PAGE) {
            throw InvalidSyncFilterException::invalidPerPage(
                $perPage,
                self::MIN_PER_PAGE,
                self::MAX_PER_PAGE,
            );
        }

        $this->page = max(1, $page);

        $trimmed = $eventType === null ? null : trim($eventType);

        $this->eventType = $trimmed === '' ? null : $trimmed;
    }
}
