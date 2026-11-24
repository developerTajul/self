<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a write loses to one of the mapping table's UNIQUE indexes.
 *
 * Two indexes can reject a mapping, and they mean different collisions:
 * `crm_mappings_local_provider_object_unique` says this local entity already
 * points at a remote record for that provider and object, while
 * `crm_mappings_remote_record_unique` says that remote record is already claimed
 * by another local row. The caller's remedy differs — the first is an ordinary
 * "already linked", the second is the merge conflict A4 exists to resolve — so
 * the two are named rather than collapsed.
 *
 * The index is not read back from the driver's message, which is localised and
 * not reliably parseable. The caller decides which case it is holding, because
 * it is the layer that knows what it just tried to write.
 */
final class CrmMappingDuplicateException extends RuntimeException
{
    /** Translations for this exception live under this path. */
    public const TRANSLATION_PREFIX = 'crm_sync.errors.';

    /**
     * @param string         $message        Untranslated default wording.
     * @param string         $translationKey
     * @param Throwable|null $previous       The rejected database write.
     */
    public function __construct(
        string $message = 'A CRM mapping for this entity and remote record already exists.',
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'mapping_duplicate',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The collision on the four-part local key.
     *
     * @param Throwable $previous
     *
     * @return self
     */
    public static function forLocalKey(Throwable $previous): self
    {
        return new self(
            message: 'A CRM mapping already exists for this local entity, provider and object.',
            translationKey: self::TRANSLATION_PREFIX . 'mapping_local_key_duplicate',
            previous: $previous,
        );
    }

    /**
     * The collision on the three-part remote key.
     *
     * The case A4 is about: a remote record can be claimed by only one local
     * row, so a second claim is the merge conflict the relink adapter resolves.
     *
     * @param Throwable $previous
     *
     * @return self
     */
    public static function forRemoteKey(Throwable $previous): self
    {
        return new self(
            message: 'Another local entity is already mapped to this remote CRM record.',
            translationKey: self::TRANSLATION_PREFIX . 'mapping_remote_key_duplicate',
            previous: $previous,
        );
    }
}
