<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\DTOs\Requests;

/**
 * Carries clamped dead-letter criteria from the request mapper to the query use case.
 */
final readonly class DeadLetterFilterDTO
{
    /**
     * @param int       $perPage
     * @param int       $page
     * @param bool|null $replayed True for replayed only, false for pending only, null for both.
     */
    public function __construct(
        public int $perPage,
        public int $page,
        public ?bool $replayed = null,
    ) {}
}
