<?php

declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

use DateTimeImmutable;

/**
 * PORT: the current time, supplied to entities rather than read by them.
 *
 * An Entity never reads the clock itself, so every time-dependent rule stays
 * testable by passing the instant in.
 */
interface ClockContract
{
    /**
     * Returns the current instant in UTC.
     *
     * @return DateTimeImmutable
     */
    public function now(): DateTimeImmutable;
}
