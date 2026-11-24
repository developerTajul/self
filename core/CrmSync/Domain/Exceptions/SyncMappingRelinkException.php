<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a CRM mapping cannot be re-pointed at a surviving local row.
 *
 * A re-link follows a lead merge, so each refusal here is a signal that the
 * caller's view of which row survived has gone stale — either it named a row
 * that cannot exist, or it tried to re-point a mapping at the row it already
 * names. Both would otherwise pass as a completed re-link.
 */
final class SyncMappingRelinkException extends RuntimeException
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
     * Reports a re-link target that is not a positive row id.
     *
     * @param int $localEntityId
     *
     * @return self
     */
    public static function invalidTargetId(int $localEntityId): self
    {
        return new self(
            message: sprintf(
                'A mapping cannot be re-linked to local row %d; the target must be a saved row id.',
                $localEntityId,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_relink_target',
            replace: ['id' => (string) $localEntityId],
        );
    }

    /**
     * Reports a re-link that would leave the mapping where it already is.
     *
     * @param int $localEntityId
     *
     * @return self
     */
    public static function alreadyLinked(int $localEntityId): self
    {
        return new self(
            message: sprintf(
                'The mapping already points at local row %d, so the re-link would change nothing.',
                $localEntityId,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'mapping_already_linked',
            replace: ['id' => (string) $localEntityId],
        );
    }
}
