<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\DTOs\Requests;

/**
 * Carries clamped sync-audit criteria from the request mapper to the query use case.
 */
final readonly class SyncEventFilterDTO
{
    /**
     * @param int         $perPage
     * @param int         $page
     * @param string|null $direction
     * @param string|null $provider
     * @param string|null $status
     * @param string|null $eventType
     */
    public function __construct(
        public int $perPage,
        public int $page,
        public ?string $direction = null,
        public ?string $provider = null,
        public ?string $status = null,
        public ?string $eventType = null,
    ) {}
}
