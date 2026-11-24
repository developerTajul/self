<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Requests;

/**
 * Carries clamped lead-list criteria from the request mapper to the query use case.
 */
final readonly class LeadFilterDTO
{
    /**
     * @param int         $perPage
     * @param int         $page
     * @param string|null $status
     * @param string|null $source
     * @param string|null $search
     */
    public function __construct(
        public int $perPage,
        public int $page,
        public ?string $status = null,
        public ?string $source = null,
        public ?string $search = null,
    ) {}
}
