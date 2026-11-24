<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a lead would be captured with neither an email nor a phone.
 *
 * A contact key is what makes duplicate detection possible at all, so a lead
 * with neither cannot be stored.
 */
final class LeadContactKeyMissingException extends RuntimeException
{
    /** Translations for this exception live under this path. */
    public const TRANSLATION_PREFIX = 'leads.errors.';

    /**
     * @param string $message        Untranslated default wording.
     * @param string $translationKey
     */
    public function __construct(
        string $message = 'A lead must carry at least one of an email address or a phone number.',
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'contact_key_missing',
    ) {
        parent::__construct($message);
    }

    /**
     * Reports a capture with neither contact key set.
     *
     * @return self
     */
    public static function forCapture(): self
    {
        return new self();
    }
}
