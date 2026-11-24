<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\DTOs\Responses;

/**
 * Summarizes one bounded sync-worker or stale-lease reaper pass.
 */
final readonly class SyncBatchResponseDTO
{
    /**
     * @param int $claimed
     * @param int $succeeded
     * @param int $retried
     * @param int $dead
     */
    public function __construct(
        public int $claimed,
        public int $succeeded,
        public int $retried,
        public int $dead,
    ) {}
}
