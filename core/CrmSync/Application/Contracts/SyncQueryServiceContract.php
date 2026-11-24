<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Contracts;

use Core\Shared\Application\DTOs\Result;

/**
 * Handles CRM sync read use cases. Never changes state.
 */
interface SyncQueryServiceContract
{
    /**
     * Returns one page of the sync audit log.
     *
     * @param int         $perPage
     * @param int         $page
     * @param string|null $direction
     * @param string|null $provider
     * @param string|null $status
     * @param string|null $eventType
     *
     * @return Result Carries a `PaginatedResult` of `SyncEventResponseDTO` instances.
     */
    public function getSyncEvents(
        int $perPage = 25,
        int $page = 1,
        ?string $direction = null,
        ?string $provider = null,
        ?string $status = null,
        ?string $eventType = null,
    ): Result;

    /**
     * Returns one page of the dead-letter inspector.
     *
     * @param int       $perPage
     * @param int       $page
     * @param bool|null $replayed True for replayed only, false for pending only, null for both.
     *
     * @return Result Carries a `PaginatedResult` of `DeadLetterResponseDTO` instances.
     */
    public function getDeadLetters(int $perPage = 25, int $page = 1, ?bool $replayed = null): Result;
}
