<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Entities;

use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Exceptions\DeadLetterAlreadyReplayedException;
use Core\CrmSync\Domain\ValueObjects\SanitizedErrorMessage;
use Core\CrmSync\Domain\Entities\SyncEventEntity;
use DateTimeImmutable;

/**
 * Domain entity representing one permanently failed CRM sync event.
 *
 * The FULL payload is kept, because a replay has to be able to redo exactly
 * what failed; only the error message is scrubbed of credentials.
 */
readonly class DeadLetterEntity
{
    /**
     * @param int|null                    $id
     * @param CrmProviderName             $provider
     * @param string                      $jobClass       One of the SyncEventEntity handler constants.
     * @param array<string, mixed>        $payload        The full failed payload, kept for replay.
     * @param SanitizedErrorMessage       $errorMessage   Scrubbed: no tokens, no Authorization values.
     * @param DateTimeImmutable           $failedAt       UTC; NOT NULL in storage.
     * @param int|null                    $syncEventId    The dead event, UNIQUE in storage.
     * @param int|null                    $replayedSyncEventId The fresh event a replay created.
     * @param int|null                    $httpStatusCode
     * @param DateTimeImmutable|null      $retriedAt      UTC; set once a replay happened.
     */
    public function __construct(
        public ?int $id,
        public CrmProviderName $provider,
        public string $jobClass,
        public array $payload,
        public SanitizedErrorMessage $errorMessage,
        public DateTimeImmutable $failedAt,
        public ?int $syncEventId = null,
        public ?int $replayedSyncEventId = null,
        public ?int $httpStatusCode = null,
        public ?DateTimeImmutable $retriedAt = null,
    ) {}

    /**
     * Files the dead letter for an event that has exhausted its retries.
     *
     * The provider and job class are taken from the failed event rather than
     * passed separately, so a dead letter can never name a provider or handler
     * the event did not actually use — the mistake that would send an operator
     * looking in the wrong CRM.
     *
     * The message arrives already scrubbed; the payload does not, because a
     * replay needs the genuine bytes that failed and the payload is our own
     * data rather than an upstream echo. `http_status_code` is kept when the
     * failure was a response, since it is the one fact that separates a
     * rejection from an outage at a glance.
     *
     * @param SyncEventEntity       $event          The event that died.
     * @param SanitizedErrorMessage $errorMessage   The scrubbed failure.
     * @param DateTimeImmutable     $now
     * @param int|null              $httpStatusCode The response status, when there was one.
     *
     * @return self A new, unpersisted dead letter.
     */
    public static function fromDeadEvent(
        SyncEventEntity $event,
        SanitizedErrorMessage $errorMessage,
        DateTimeImmutable $now,
        ?int $httpStatusCode = null,
    ): self {
        return new self(
            id: null,
            provider: $event->provider,
            jobClass: $event->jobClass(),
            payload: $event->payload,
            errorMessage: $errorMessage,
            failedAt: $now,
            syncEventId: $event->id,
            httpStatusCode: $httpStatusCode,
        );
    }

    /**
     * Records that this dead letter has been replayed.
     *
     * A dead letter may be replayed ONCE (S4). The guard is what makes the rule
     * real: replaying twice would re-run a payload that already failed and then
     * already succeeded, and the second run's outcome would overwrite the first
     * one's trail — leaving no record of what the first replay did.
     *
     * @param int               $newSyncEventId The fresh event the replay created.
     * @param DateTimeImmutable $now
     *
     * @return self A new instance carrying the replay trail.
     *
     * @throws DeadLetterAlreadyReplayedException When `retried_at` is already set.
     */
    public function markReplayed(int $newSyncEventId, DateTimeImmutable $now): self
    {
        if ($this->retriedAt !== null) {
            throw DeadLetterAlreadyReplayedException::alreadyReplayed($this->id, $this->retriedAt);
        }

        return new self(
            id: $this->id,
            provider: $this->provider,
            jobClass: $this->jobClass,
            payload: $this->payload,
            errorMessage: $this->errorMessage,
            failedAt: $this->failedAt,
            syncEventId: $this->syncEventId,
            replayedSyncEventId: $newSyncEventId,
            httpStatusCode: $this->httpStatusCode,
            retriedAt: $now,
        );
    }

    /**
     * Whether this dead letter has already been replayed.
     *
     * @return bool
     */
    public function hasBeenReplayed(): bool
    {
        return $this->retriedAt !== null;
    }
}
