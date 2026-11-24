<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Exceptions;

use Core\Lead\Domain\ValueObjects\EmailAddress;
use RuntimeException;

/**
 * Raised when a value cannot be read as an email address.
 *
 * Carries the translation key for the rule alongside its message; delivery
 * translates the key, so the Domain never holds presentation wording.
 */
final class InvalidEmailAddressException extends RuntimeException
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
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'invalid_email_address',
        public readonly array $replace = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Reports a value that is not a well-formed email address.
     *
     * The rejected value is carried as a replacement so the message names what
     * arrived, but the value itself is never the translation key's subject.
     *
     * @param string $value The raw input, as it arrived.
     *
     * @return self
     */
    public static function invalidFormat(string $value): self
    {
        return new self(
            message: sprintf('[%s] is not a valid email address.', $value),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_email_address',
            replace: ['value' => $value],
        );
    }

    /**
     * Reports an address longer than the email column accepts.
     *
     * @param int $length
     *
     * @return self
     */
    public static function tooLong(int $length): self
    {
        return new self(
            message: sprintf(
                'An email address cannot exceed %d characters, %d given.',
                EmailAddress::MAX_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'email_too_long',
            replace: [
                'max' => (string) EmailAddress::MAX_LENGTH,
                'given' => (string) $length,
            ],
        );
    }
}
