<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a write loses to a lead's unique index.
 *
 * The write adapter translates the database's duplicate-key failure into this
 * exception (D-025), so no driver exception crosses into a Service. The Service
 * catches it and reports the conflict as a failure Result.
 *
 * The `leads` table carries three unique indexes — `uuid`, `email` and `phone` —
 * and all three arrive here as the same integrity violation. They are not
 * distinguished, because the caller's remedy is the same in every case: the
 * lookup that precedes a capture is what decides between create, enrich and
 * merge, so a duplicate reaching this exception means two writers raced, and the
 * recovery is to retry from the lookup (S5), whichever column won.
 */
final class LeadDuplicateException extends RuntimeException
{
    /** Translations for this exception live under this path. */
    public const TRANSLATION_PREFIX = 'leads.errors.';

    /**
     * @param string         $message        Untranslated default wording.
     * @param string         $translationKey
     * @param Throwable|null $previous       The rejected database write.
     */
    public function __construct(
        string $message = 'A lead with the same identifier already exists.',
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'duplicate',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
