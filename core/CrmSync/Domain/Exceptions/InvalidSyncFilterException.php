<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a sync list would be read with criteria outside their bounds.
 *
 * Carries the translation key for the rule alongside its message; delivery
 * translates the key, so the Domain never holds presentation wording.
 */
final class InvalidSyncFilterException extends RuntimeException
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
     * Reports a page size outside the bounds the list accepts.
     *
     * @param int $perPage
     * @param int $min
     * @param int $max
     *
     * @return self
     */
    public static function invalidPerPage(int $perPage, int $min, int $max): self
    {
        return new self(
            message: sprintf('Page size must be between %d and %d, %d given.', $min, $max, $perPage),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_per_page',
            replace: [
                'min' => (string) $min,
                'max' => (string) $max,
                'given' => (string) $perPage,
            ],
        );
    }
}
