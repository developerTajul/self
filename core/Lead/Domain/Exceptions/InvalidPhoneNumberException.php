<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Exceptions;

use Core\Lead\Domain\ValueObjects\PhoneNumber;
use RuntimeException;

/**
 * Raised when a value cannot be read as an E.164 phone number.
 *
 * Carries the translation key for the rule alongside its message; delivery
 * translates the key, so the Domain never holds presentation wording.
 */
final class InvalidPhoneNumberException extends RuntimeException
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
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'invalid_phone_number',
        public readonly array $replace = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Reports a value that cannot be read as an E.164 number.
     *
     * Covers both a malformed number and one whose digit count falls outside
     * the range E.164 defines; the two are not worth separate keys, because the
     * remedy for a caller is the same — send the number with its country code.
     *
     * @param string $value The raw input, as it arrived.
     *
     * @return self
     */
    public static function invalidFormat(string $value): self
    {
        return new self(
            message: sprintf(
                '[%s] is not a valid E.164 phone number; it must carry a country code, e.g. +15550100199.',
                $value,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_phone_number',
            replace: [
                'value' => $value,
                'min' => (string) PhoneNumber::MIN_DIGITS,
                'max' => (string) PhoneNumber::MAX_DIGITS,
            ],
        );
    }
}
