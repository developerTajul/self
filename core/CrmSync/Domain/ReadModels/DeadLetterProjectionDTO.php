<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\ReadModels;

/**
 * One dead letter as the inspector list returns it.
 *
 * The full payload is absent for the same reason as the audit log's, but the
 * scrubbed error message IS present: it is the one field an operator reads to
 * decide whether a replay is worth attempting.
 */
final readonly class DeadLetterProjectionDTO
{
    /**
     * @param int         $id
     * @param string      $provider       The raw enum value.
     * @param string      $jobClass
     * @param string      $errorMessage   Already scrubbed when it was stored.
     * @param string      $failedAt       UTC, as stored.
     * @param int|null    $syncEventId
     * @param int|null    $replayedSyncEventId
     * @param int|null    $httpStatusCode
     * @param string|null $retriedAt      UTC, as stored; null until replayed.
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
    ) {}

    /**
     * Whether a replay is still available for this entry.
     *
     * @return bool
     */
    public function isReplayable(): bool
    {
        return $this->retriedAt === null;
    }
}
