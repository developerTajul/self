<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Exceptions;

use Core\Lead\Domain\Rules\LeadRules;
use RuntimeException;

/**
 * Raised when a lead list would be read with criteria outside their bounds.
 *
 * Carries the translation key for the rule alongside its message; delivery
 * translates the key, so the Domain never holds presentation wording.
 */
final class InvalidLeadFilterException extends RuntimeException
{
    /** Translations for this exception live under this path. */
    public const TRANSLATION_PREFIX = 'leads.errors.';

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
     * Reports a page size outside the bounds the list accepts.
     *
     * @param int $perPage
     *
     * @return self
     */
    public static function invalidPerPage(int $perPage): self
    {
        return new self(
            message: sprintf(
                'Page size must be between %d and %d, %d given.',
                LeadRules::MIN_PER_PAGE,
                LeadRules::MAX_PER_PAGE,
                $perPage,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_per_page',
            replace: [
                'min' => (string) LeadRules::MIN_PER_PAGE,
                'max' => (string) LeadRules::MAX_PER_PAGE,
                'given' => (string) $perPage,
            ],
        );
    }
}
