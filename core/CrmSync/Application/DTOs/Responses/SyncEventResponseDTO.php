<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\DTOs\Responses;

/**
 * Presentation-safe shape of one sync ledger entry.
 */
final readonly class SyncEventResponseDTO
{
    /**
     * @param int         $id
     * @param string      $direction
     * @param string      $provider
     * @param string      $eventType
     * @param string      $status
     * @param int         $attempts
     * @param string      $nextAttemptAt
     * @param string|null $lastError
     * @param string|null $processedAt
     * @param string|null $createdAt
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
