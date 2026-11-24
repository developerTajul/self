<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use Core\CrmSync\Domain\ValueObjects\RemoteId;
use RuntimeException;

/**
 * Raised when a value cannot be read as a remote record identifier.
 *
 * Carries the translation key for the rule alongside its message; delivery
 * translates the key, so the Domain never holds presentation wording.
 */
final class InvalidRemoteIdException extends RuntimeException
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
     * Reports an identifier that was absent or blank.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self(
            message: 'A remote record identifier cannot be empty.',
            translationKey: self::TRANSLATION_PREFIX . 'empty_remote_id',
        );
    }

    /**
     * Reports an identifier longer than the remote-id column accepts.
     *
     * @param int $length
     *
     * @return self
     */
    public static function tooLong(int $length): self
    {
        return new self(
            message: sprintf(
                'A remote record identifier cannot exceed %d characters, %d given.',
                RemoteId::MAX_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'remote_id_too_long',
            replace: [
                'max' => (string) RemoteId::MAX_LENGTH,
                'given' => (string) $length,
            ],
        );
    }
}
