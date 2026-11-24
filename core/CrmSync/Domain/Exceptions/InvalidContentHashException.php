<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use Core\CrmSync\Domain\ValueObjects\ContentHash;
use RuntimeException;

/**
 * Raised when a content hash cannot be built or read back.
 *
 * Carries the translation key for the rule alongside its message; delivery
 * translates the key, so the Domain never holds presentation wording.
 */
final class InvalidContentHashException extends RuntimeException
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
     * Reports a stored digest that is not a SHA-256 hexadecimal string.
     *
     * A stored value reaches this only if the column was written by something
     * other than this Value Object, so the failure is reported rather than
     * silently repaired into a different hash than the one that was stored.
     *
     * @param string $value The value as read.
     *
     * @return self
     */
    public static function malformed(string $value): self
    {
        return new self(
            message: sprintf(
                '[%s] is not a SHA-256 content hash (%d hexadecimal characters expected).',
                $value,
                ContentHash::LENGTH,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'malformed_content_hash',
            replace: ['length' => (string) ContentHash::LENGTH],
        );
    }

    /**
     * Reports a state that cannot be encoded for hashing.
     *
     * @param string $reason The encoder's own message.
     *
     * @return self
     */
    public static function notEncodable(string $reason): self
    {
        return new self(
            message: sprintf('The record state cannot be encoded for hashing: %s', $reason),
            translationKey: self::TRANSLATION_PREFIX . 'unhashable_state',
        );
    }
}
