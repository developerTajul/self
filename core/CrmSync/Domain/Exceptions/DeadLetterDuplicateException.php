<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a dead letter loses to the unique index on its event.
 *
 * `dead_letters.sync_event_id` is UNIQUE, so one failed event has one dead
 * letter. A second attempt to file the same event arrives here rather than as a
 * row.
 *
 * The adapter catches this internally for
 * {@see \Core\CrmSync\Domain\Contracts\DeadLetterRepositoryContract::createIfAbsentForEvent()},
 * which is an idempotent operation; it escapes only from `create()` and
 * `update()`, where a collision means the caller's own bookkeeping is wrong.
 */
final class DeadLetterDuplicateException extends RuntimeException
{
    /** Translations for this exception live under this path. */
    public const TRANSLATION_PREFIX = 'crm_sync.errors.';

    /**
     * @param string         $message        Untranslated default wording.
     * @param string         $translationKey
     * @param Throwable|null $previous       The rejected database write.
     */
    public function __construct(
        string $message = 'A dead letter for this sync event already exists.',
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'dead_letter_duplicate',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
