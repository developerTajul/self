<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use Core\CrmSync\Domain\Enums\SyncEventStatus;
use Core\CrmSync\Domain\Rules\SyncRetryRules;
use RuntimeException;

/**
 * Raised when a sync event is asked to make a transition its state forbids.
 *
 * A ledger row moves through `pending` -> `processing` -> `succeeded` or
 * `dead`, and every move that does not fit that shape is a defect in the caller
 * rather than something to absorb: two workers claiming one event, a worker
 * finishing an event it lost the lease on, or a retry scheduled for a row that
 * already succeeded. Silently tolerating any of those would corrupt the ledger
 * that the whole idempotency scheme rests on, so each is refused by name.
 *
 * Carries the translation key for the rule alongside its message; delivery
 * translates the key, so the Domain never holds presentation wording.
 */
final class SyncEventTransitionException extends RuntimeException
{
    /** Translations for this exception live under this path. */
    public const TRANSLATION_PREFIX = 'crm_sync.errors.';

    /**
     * @param string                $message        Untranslated default wording.
     * @param string                $translationKey
     * @param array<string, string> $replace        Replacement values for the key.
     */
    public function __construct(
        string $message,
        public readonly string $translationKey,
        public readonly array $replace = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Reports a claim on an event that is not awaiting a worker.
     *
     * @param SyncEventStatus $current The status the event actually holds.
     *
     * @return self
     */
    public static function notClaimable(SyncEventStatus $current): self
    {
        return new self(
            message: sprintf(
                'A sync event in state [%s] cannot be claimed; only a pending event can.',
                $current->value,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'event_not_claimable',
            replace: ['status' => $current->value],
        );
    }

    /**
     * Reports a transition attempted on an event no worker is processing.
     *
     * @param SyncEventStatus $current
     * @param string          $operation The transition that was attempted.
     *
     * @return self
     */
    public static function notProcessing(SyncEventStatus $current, string $operation): self
    {
        return new self(
            message: sprintf(
                'Cannot %s a sync event in state [%s]; it must be processing first.',
                $operation,
                $current->value,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'event_not_processing',
            replace: ['status' => $current->value, 'operation' => $operation],
        );
    }

    /**
     * Reports a claim on an event whose retry delay has not elapsed.
     *
     * @param int $attempts
     *
     * @return self
     */
    public static function exhausted(int $attempts): self
    {
        return new self(
            message: sprintf(
                'A sync event that has used all %d attempts cannot be retried.',
                SyncRetryRules::MAX_ATTEMPTS,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'event_attempts_exhausted',
            replace: [
                'max' => (string) SyncRetryRules::MAX_ATTEMPTS,
                'attempts' => (string) $attempts,
            ],
        );
    }

    /**
     * Reports a negative jitter, which would move a retry into the past.
     *
     * @param int $jitterSeconds
     *
     * @return self
     */
    public static function invalidJitter(int $jitterSeconds): self
    {
        return new self(
            message: sprintf('Retry jitter cannot be negative, %d given.', $jitterSeconds),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_jitter',
            replace: ['given' => (string) $jitterSeconds],
        );
    }
}
