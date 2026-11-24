<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Utilities;

use Core\Shared\Domain\Contracts\ClockContract;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The system clock, always read in UTC.
 *
 * @implements ClockContract
 */
final class WpClock implements ClockContract
{
    /** Every timestamp the modules store is UTC, whatever the site's timezone. */
    private const TIMEZONE = 'UTC';

    /**
     * Returns the current instant in UTC.
     *
     * @return DateTimeImmutable
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }
}
