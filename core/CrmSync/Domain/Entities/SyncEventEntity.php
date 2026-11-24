<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Entities;

use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\SyncDirection;
use Core\CrmSync\Domain\Enums\SyncEventStatus;
use Core\CrmSync\Domain\Exceptions\SyncEventTransitionException;
use Core\CrmSync\Domain\Rules\SyncRetryRules;
use Core\CrmSync\Domain\ValueObjects\SanitizedErrorMessage;
use DateTimeImmutable;

/**
 * Domain entity representing one entry in the idempotency ledger.
 *
 * The ledger carries BOTH directions: an outbound event records work a lead
 * capture owes a CRM, an inbound event records a verified webhook awaiting
 * processing. There is no lead column; the entity a payload concerns travels
 * inside `$payload` (`entity_type`, `local_entity_id`).
 *
 * It is also the lock: an event is claimed by moving it to `processing` and
 * stamping `locked_at`, and the reaper reclaims an event whose lease has gone
 * stale. Every transition is a method here, so no caller can move an event into
 * a state its history does not allow.
 */
readonly class SyncEventEntity
{
    /**
     * The `dead_letters.job_class` value for an event the outbound worker was
     * handling when it exhausted its retries.
     */
    public const HANDLER_OUTBOUND = 'sync.outbound';

    /**
     * The `dead_letters.job_class` value for an event the inbound worker was
     * handling when it exhausted its retries.
     */
    public const HANDLER_INBOUND = 'sync.inbound';

    /**
     * Marks a field {@see with()} should leave as it is.
     *
     * A private sentinel rather than `null`, because `null` is a value several
     * of these fields legitimately take: a finished event has no lease, and a
     * successful one has no error. Sharing one default would make "clear this"
     * and "do not touch this" indistinguishable, and the transitions that clear
     * a field would silently fail to.
     */
    private const KEEP = "\0__keep__";

    /**
     * The `dead_letters.job_class` value for this event's direction.
     *
     * Derived from the event itself rather than passed in, because the two
     * always agree: an inbound event is handled by the inbound worker, and a
     * caller that paired the wrong handler with a direction would file a dead
     * letter under an operator's wrong mental model.
     *
     * @return string
     */
    public function jobClass(): string
    {
        return $this->direction === SyncDirection::inbound
            ? self::HANDLER_INBOUND
            : self::HANDLER_OUTBOUND;
    }

    /**
     * @param int|null               $id
     * @param string                 $idempotencyKey  64-char hex, UNIQUE; never derived from mutable fields.
     * @param SyncDirection          $direction
     * @param CrmProviderName        $provider
     * @param string                 $eventType
     * @param array<string, mixed>   $payload         Carries `entity_type` and `local_entity_id`.
     * @param SyncEventStatus        $status
     * @param DateTimeImmutable      $nextAttemptAt   UTC; NOT NULL in storage.
     * @param int                    $attempts
     * @param DateTimeImmutable|null $lockedAt        UTC; the worker's lease.
     * @param string|null            $lastError
     * @param DateTimeImmutable|null $processedAt     UTC.
     * @param DateTimeImmutable|null $createdAt       UTC.
     * @param DateTimeImmutable|null $updatedAt       UTC.
     */
    public function __construct(
        public ?int $id,
        public string $idempotencyKey,
        public SyncDirection $direction,
        public CrmProviderName $provider,
        public string $eventType,
        public array $payload,
        public SyncEventStatus $status,
        public DateTimeImmutable $nextAttemptAt,
        public int $attempts = 0,
        public ?DateTimeImmutable $lockedAt = null,
        public ?string $lastError = null,
        public ?DateTimeImmutable $processedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    /**
     * Records a new event awaiting its first attempt.
     *
     * The event is due immediately: `next_attempt_at` is the instant it was
     * enqueued, so the next worker pass that selects `pending` rows due by now
     * picks it up with no artificial wait.
     *
     * @param string               $idempotencyKey
     * @param SyncDirection        $direction
     * @param CrmProviderName      $provider
     * @param string               $eventType
     * @param array<string, mixed> $payload
     *
     * @return self A new event in the `pending` state, not yet persisted.
     */
    public static function enqueue(
        string $idempotencyKey,
        SyncDirection $direction,
        CrmProviderName $provider,
        string $eventType,
        array $payload,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: null,
            idempotencyKey: $idempotencyKey,
            direction: $direction,
            provider: $provider,
            eventType: $eventType,
            payload: $payload,
            status: SyncEventStatus::pending,
            nextAttemptAt: $now,
            attempts: 0,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Takes the event for processing and starts its lease.
     *
     * Only a pending event can be claimed. A `processing` event is already held
     * by another worker, one past its lease is the reaper's to reclaim, and a
     * `succeeded` or `dead` event is finished: allowing a second claim on any of
     * them would let two workers write one event's outcome, or resurrect work
     * the ledger has already settled.
     *
     * @param DateTimeImmutable $now
     *
     * @return self A new instance in the `processing` state.
     *
     * @throws SyncEventTransitionException When the event is not pending.
     */
    public function claim(DateTimeImmutable $now): self
    {
        if ($this->status !== SyncEventStatus::pending) {
            throw SyncEventTransitionException::notClaimable($this->status);
        }

        return $this->with(
            status: SyncEventStatus::processing,
            lockedAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Marks the event done and clears its lease.
     *
     * @param DateTimeImmutable $now
     *
     * @return self A new instance in the `succeeded` state.
     *
     * @throws SyncEventTransitionException When the event is not processing.
     */
    public function markSucceeded(DateTimeImmutable $now): self
    {
        $this->assertProcessing('mark the event succeeded');

        return $this->with(
            status: SyncEventStatus::succeeded,
            lockedAt: null,
            processedAt: $now,
            lastError: null,
            updatedAt: $now,
        );
    }

    /**
     * Returns the event to the queue for another attempt.
     *
     * The delay is the exponential backoff for the attempts already made, plus
     * whatever jitter the caller supplies. Jitter arrives as a parameter because
     * spreading a retry storm requires randomness, and randomness in the Domain
     * would make the entity untestable and non-deterministic; the caller owns
     * the clock and the dice alike.
     *
     * The attempt counter is incremented HERE, not on the claim: an attempt
     * counts when it has been made and failed, and incrementing on the claim
     * would consume the budget for a worker that crashed before trying.
     *
     * @param DateTimeImmutable $now
     * @param int               $jitterSeconds Non-negative; the caller's spread.
     *
     * @return self A new instance, still `pending`, due after the backoff.
     *
     * @throws SyncEventTransitionException When the event is not processing, has no attempts left, or the jitter is negative.
     */
    public function scheduleRetry(DateTimeImmutable $now, int $jitterSeconds, ?string $lastError = null): self
    {
        $this->assertProcessing('schedule a retry for the event');

        if ($jitterSeconds < 0) {
            throw SyncEventTransitionException::invalidJitter($jitterSeconds);
        }

        $attempts = $this->attempts + 1;

        if (! SyncRetryRules::mayRetry($attempts)) {
            throw SyncEventTransitionException::exhausted($this->attempts);
        }

        $delay = SyncRetryRules::backoffSeconds($this->attempts) + $jitterSeconds;

        return $this->with(
            status: SyncEventStatus::pending,
            attempts: $attempts,
            lockedAt: null,
            lastError: $lastError,
            nextAttemptAt: $now->setTimestamp($now->getTimestamp() + $delay),
            updatedAt: $now,
        );
    }

    /**
     * Reclaims an event whose worker died holding its lease.
     *
     * The reaper's transition, and the only one that runs without the worker
     * that owned the event. The attempt counter is incremented here — rather
     * than waiting for a retry — because the abandoned attempt DID happen and
     * may well have reached the CRM before the worker died. Counting it is what
     * stops a repeatedly crashing worker from retrying forever, and it is why
     * `MAX_ATTEMPTS` is kept far below the column's ceiling: this increment
     * happens outside the ordinary path.
     *
     * An event that has already used every attempt is NOT released back to
     * `pending`. It has been reclaimed as many times as it is allowed, so a
     * further release would only feed it to the same crashing worker again —
     * and, because this is the one increment that runs unbounded, it would walk
     * `attempts` toward the `unsignedTinyInteger` ceiling until the column
     * refused the write and the row became unrecoverable. The caller is told by
     * name that this event's remaining move is {@see markDead()}.
     *
     * The event goes back to `pending` and is due immediately, so a healthy
     * worker picks it up on its next pass.
     *
     * @param DateTimeImmutable $now
     *
     * @return self A new instance, back in the `pending` state.
     *
     * @throws SyncEventTransitionException When the event is not processing, or has no attempts left.
     */
    public function releaseStaleLock(DateTimeImmutable $now): self
    {
        $this->assertProcessing('release the stale lease of the event');

        $attempts = $this->attempts + 1;

        if (! SyncRetryRules::mayRetry($attempts)) {
            throw SyncEventTransitionException::exhausted($this->attempts);
        }

        return $this->with(
            status: SyncEventStatus::pending,
            attempts: $attempts,
            lockedAt: null,
            nextAttemptAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Whether the event has used every attempt it is allowed.
     *
     * @return bool
     */
    public function isExhausted(): bool
    {
        return ! SyncRetryRules::mayRetry($this->attempts);
    }

    /**
     * Whether this event holds a lease that has gone stale.
     *
     * True only for a `processing` event whose `locked_at` is older than the
     * lease timeout — the state the reaper exists to find. A processing event
     * inside its lease is live work, and one with no `locked_at` cannot have its
     * age judged, so neither is reported as stale.
     *
     * @param DateTimeImmutable $now
     *
     * @return bool
     */
    public function hasStaleLease(DateTimeImmutable $now): bool
    {
        if ($this->status !== SyncEventStatus::processing || $this->lockedAt === null) {
            return false;
        }

        $expiresAt = $this->lockedAt->setTimestamp(
            $this->lockedAt->getTimestamp() + SyncRetryRules::LEASE_TIMEOUT_SECONDS
        );

        // Equal timestamps count as expired: a lease held for exactly the
        // timeout has run out, and the boundary is otherwise undecidable.
        return $expiresAt <= $now;
    }

    /**
     * Gives up on the event and records why.
     *
     * The terminal failure state. The reason arrives already scrubbed, because
     * scrubbing is a separate concern the caller owns and a raw provider
     * response can carry credentials; the entity stores what it is given.
     *
     * @param DateTimeImmutable     $now
     * @param SanitizedErrorMessage $error The failure, credential-free.
     *
     * @return self A new instance in the `dead` state.
     *
     * @throws SyncEventTransitionException When the event is not processing.
     */
    public function markDead(DateTimeImmutable $now, SanitizedErrorMessage $error): self
    {
        $this->assertProcessing('mark the event dead');

        return $this->with(
            status: SyncEventStatus::dead,
            lockedAt: null,
            lastError: $error->value,
            processedAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Copies this instance with the named fields replaced.
     *
     * Transitions differ only in which fields they set, so they share this
     * rather than repeating a fourteen-argument constructor each. Every field
     * not named keeps its current value, which is what makes a transition
     * additive: nothing is silently reset by being left out.
     *
     * Clearing a nullable field is a real transition here — a finished event
     * has no lease — so "left out" and "set to null" cannot be the same thing.
     * `?? $this->field` would conflate them, because `null ?? $x` is `$x`, and
     * `lockedAt` would then survive every transition that tried to clear it,
     * leaving finished events looking like live leases for the reaper to
     * reclaim. The nullable fields therefore take the sentinel
     * {@see self::KEEP} as their default, and only an explicit argument
     * replaces them.
     *
     * @param SyncEventStatus|null     $status
     * @param int|null                 $attempts
     * @param DateTimeImmutable|null   $nextAttemptAt
     * @param DateTimeImmutable|null|string $lockedAt  Pass an instance, or null to clear, or omit to keep.
     * @param string|null|string       $lastError      Pass a string, or null to clear, or omit to keep.
     * @param DateTimeImmutable|null|string $processedAt
     * @param DateTimeImmutable|null   $updatedAt
     *
     * @return self
     */
    private function with(
        ?SyncEventStatus $status = null,
        ?int $attempts = null,
        ?DateTimeImmutable $nextAttemptAt = null,
        mixed $lockedAt = self::KEEP,
        mixed $lastError = self::KEEP,
        mixed $processedAt = self::KEEP,
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        return new self(
            id: $this->id,
            idempotencyKey: $this->idempotencyKey,
            direction: $this->direction,
            provider: $this->provider,
            eventType: $this->eventType,
            payload: $this->payload,
            status: $status ?? $this->status,
            nextAttemptAt: $nextAttemptAt ?? $this->nextAttemptAt,
            attempts: $attempts ?? $this->attempts,
            lockedAt: $lockedAt === self::KEEP ? $this->lockedAt : $lockedAt,
            lastError: $lastError === self::KEEP ? $this->lastError : $lastError,
            processedAt: $processedAt === self::KEEP ? $this->processedAt : $processedAt,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt ?? $this->updatedAt,
        );
    }

    /**
     * Refuses a worker-side transition on an event no worker holds.
     *
     * @param string $operation Phrased for the failure message.
     *
     * @return void
     *
     * @throws SyncEventTransitionException When the event is not processing.
     */
    private function assertProcessing(string $operation): void
    {
        if ($this->status !== SyncEventStatus::processing) {
            throw SyncEventTransitionException::notProcessing($this->status, $operation);
        }
    }
}
