<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\ReadModels;

/**
 * One ledger row as the audit screen returns it.
 *
 * The raw `payload_json` is deliberately absent: the audit list shows what an
 * event was and what became of it, and shipping every payload to a table view
 * would move more bytes than the page it renders.
 */
final readonly class SyncEventProjectionDTO
{
    /**
     * @param int         $id
     * @param string      $direction   The raw enum value.
     * @param string      $provider    The raw enum value.
     * @param string      $eventType
     * @param string      $status      The raw enum value.
     * @param int         $attempts
     * @param string      $nextAttemptAt UTC, as stored.
     * @param string|null $lastError
     * @param string|null $processedAt   UTC, as stored.
     * @param string|null $createdAt     UTC, as stored.
     */
    public function __construct(
        public int $id,
        public string $direction,
        public string $provider,
        public string $eventType,
        public string $status,
        public int $attempts,
        public string $nextAttemptAt,
        public ?string $lastError = null,
        public ?string $processedAt = null,
        public ?string $createdAt = null,
    ) {}
}
