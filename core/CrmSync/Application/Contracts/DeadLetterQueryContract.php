<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Contracts;

use Core\CrmSync\Domain\Entities\DeadLetterEntity;
use Core\Shared\Domain\DataObjects\PaginatedResult;

/**
 * Read side of the dead-letter port.
 *
 * Carries no method that can change a row. The single-record read returns an
 * Entity rather than a projection because the replay use case needs its
 * behaviour (`markReplayed`), not just its columns.
 */
interface DeadLetterQueryContract
{
    /**
     * Reads one dead letter by id.
     *
     * @param int $deadLetterId
     *
     * @return DeadLetterEntity|null Null when no dead letter carries the id.
     */
    public function findById(int $deadLetterId): ?DeadLetterEntity;

    /**
     * Returns one page of dead letters for the inspector screen.
     *
     * @param int $perPage
     * @param int $page
     * @param bool|null $replayed True for replayed only, false for pending only, null for both.
     *
     * @return PaginatedResult Items are `DeadLetterProjectionDTO` instances.
     */
    public function getDeadLetters(int $perPage, int $page, ?bool $replayed = null): PaginatedResult;
}
