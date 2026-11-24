<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Rules;

/**
 * Bounds the CRM audit and dead-letter list pages.
 */
final class SyncReadRules
{
    public const DEFAULT_PER_PAGE = 25;
    public const MIN_PER_PAGE = 1;
    public const MAX_PER_PAGE = 100;

    /**
     * Clamps raw request page sizes to a bounded value.
     *
     * @param int $perPage
     *
     * @return int
     */
    public static function clampPerPage(int $perPage): int
    {
        if ($perPage < self::MIN_PER_PAGE) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
