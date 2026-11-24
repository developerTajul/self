<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Rules;

/**
 * The lead module's shared numeric rules.
 *
 * One place for the list's page bounds and the column-derived length limits, so
 * a Service, a mapper and a filter cannot each carry their own copy of a number
 * that has to agree.
 */
final class LeadRules
{
    /**
     * The largest page size a lead list may be asked for.
     */
    public const MAX_PER_PAGE = 100;

    /**
     * The smallest page size a lead list may be asked for.
     */
    public const MIN_PER_PAGE = 1;

    /**
     * The page size a list uses when none was given.
     */
    public const DEFAULT_PER_PAGE = 15;

    /**
     * The smallest page number a lead list may be asked for.
     *
     * Page numbers are 1-based. Anything lower is clamped rather than rejected:
     * a mistyped page in a URL is someone editing the address bar.
     */
    public const MIN_PAGE = 1;

    /**
     * The largest page size that may be clamped to.
     *
     * Kept for callers that clamp a raw request value before building a
     * FilterDTO (R12.2): zero, negative and non-numeric fall back to the
     * default, and anything above the maximum is capped, so a request cannot ask
     * for an unbounded read.
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
