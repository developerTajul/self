<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Rules;

/**
 * The retry bounds the sync ledger obeys.
 *
 * These are the numbers that decide how hard the plugin tries before it gives
 * up on a CRM call, so they live in one place in the Domain rather than as
 * literals at the call sites — a Service that knew the ceiling would be holding
 * a business rule, and two callers that each knew it would eventually disagree.
 *
 * Every method here is pure: the same input yields the same number, with no
 * clock read and no randomness. Jitter is the caller's to supply, so a test can
 * pin it and a worker can spread its load without this class guessing.
 */
final class SyncRetryRules
{
    /**
     * How many attempts an event gets before it is declared dead.
     *
     * Deliberately far below the `attempts` column's ceiling of 255: the column
     * is an `unsignedTinyInteger`, and the reaper increments `attempts` when it
     * reclaims a stale lease outside the normal worker path. A ceiling near 255
     * would let a reaper increment overflow the column — MySQL would refuse the
     * write under strict mode and truncate it otherwise, so either way the row's
     * own counter would stop meaning what it says. Five attempts is also about
     * where a CRM outage stops looking transient and starts looking real.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * The first retry delay, in seconds.
     *
     * Long enough for a momentary blip — a dropped connection, a rate limiter's
     * short window — to clear before the second try.
     */
    public const BACKOFF_BASE_SECONDS = 30;

    /**
     * The ceiling on a computed retry delay, in seconds.
     *
     * One hour. Exponential growth reaches the cap by the fourth attempt, so
     * later attempts do not drift further out; without a cap the delay would
     * grow past any window in which the event still matters.
     */
    public const BACKOFF_CAP_SECONDS = 3600;

    /**
     * How long a claim may be held before the reaper reclaims it.
     *
     * A worker that dies mid-flight leaves its row in `processing` with
     * `locked_at` set and nothing to release it. The reaper treats a lease older
     * than this as abandoned. It has to exceed the slowest legitimate CRM call —
     * a timeout plus a retry inside one attempt — or the reaper would steal work
     * from a worker that is still running it, and two workers would then write
     * the same event's outcome.
     */
    public const LEASE_TIMEOUT_SECONDS = 300;

    /**
     * How many events one worker pass may claim.
     *
     * Bounds the work done per pass so a backlog is drained over several passes
     * instead of one long run that could outlast its own lease on the earliest
     * rows it claimed.
     */
    public const BATCH_SIZE = 50;

    /**
     * The delay before attempt number $attempts + 1, before jitter.
     *
     * Doubles per completed attempt from the base, then stops at the cap:
     * attempt 1 has not been retried yet, so a retry scheduled after it waits
     * one base interval; after attempt 2, two; and so on.
     *
     * @param int $attempts How many attempts have already been made (>= 0).
     *
     * @return int Seconds to wait before the next attempt.
     */
    public static function backoffSeconds(int $attempts): int
    {
        // Negative input means a caller counted something other than attempts.
        // Treating it as "no attempts yet" is the safe reading: it schedules the
        // earliest retry rather than an earlier-than-base delay or a shift that
        // PHP would reject.
        if ($attempts < 0) {
            $attempts = 0;
        }

        // The exponent is clamped BEFORE the shift: shifting by more than the
        // integer width is undefined in PHP, and the cap makes anything past a
        // handful of attempts identical anyway.
        $exponent = min($attempts, self::MAX_EXPONENT);

        $delay = self::BACKOFF_BASE_SECONDS * (2 ** $exponent);

        return min($delay, self::BACKOFF_CAP_SECONDS);
    }

    /**
     * Whether an event that has made $attempts attempts may still be retried.
     *
     * @param int $attempts
     *
     * @return bool
     */
    public static function mayRetry(int $attempts): bool
    {
        return $attempts < self::MAX_ATTEMPTS;
    }

    /**
     * The largest exponent {@see backoffSeconds()} will evaluate.
     *
     * The cap is reached at 2^7 (30s -> 3840s, capped to 3600s), so nothing
     * beyond this changes the result; the bound exists only to keep the shift
     * inside PHP's integer width.
     */
    private const MAX_EXPONENT = 16;
}
