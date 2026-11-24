<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\DTOs\Responses;

/**
 * The presentation-safe shape of one dead letter.
 *
 * `http_status_code` and `retried_at` are both present because they are what an
 * operator reads to decide whether a replay is worth attempting: a 422 will fail
 * again, and a replay timestamp means the one attempt is spent.
 */
final readonly class DeadLetterResponseDTO
{
    /**
     * @param int         $id
     * @param string      $provider
     * @param string      $jobClass
     * @param string      $errorMessage  Already scrubbed when it was stored.
     * @param string      $failedAt
     * @param int|null    $syncEventId
     * @param int|null    $replayedSyncEventId
     * @param int|null    $httpStatusCode
     * @param string|null $retriedAt
     * @param bool        $isReplayable
     */
    public function __construct(
        public int $id,
        public string $provider,
        public string $jobClass,
        public string $errorMessage,
        public string $failedAt,
        public ?int $syncEventId = null,
        public ?int $replayedSyncEventId = null,
        public ?int $httpStatusCode = null,
        public ?string $retriedAt = null,
        public bool $isReplayable = true,
    ) {}
}
