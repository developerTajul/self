<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a write targets a lead that no longer exists.
 *
 * The repository adapter raises this instead of a bare RuntimeException, so the
 * Service can report the missing row as an ordinary not-found outcome rather
 * than as a write failure.
 */
final class LeadNotFoundException extends RuntimeException
{
    /** Translations for this exception live under this path. */
    public const TRANSLATION_PREFIX = 'leads.errors.';

    /**
     * @param int    $leadId
     * @param string $message        Untranslated default wording.
     * @param string $translationKey
     */
    public function __construct(
        public readonly int $leadId,
        string $message = '',
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'lead_not_found',
    ) {
        parent::__construct($message === ''
            ? sprintf('Lead with ID %d no longer exists.', $leadId)
            : $message);
    }
}
