<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a write loses to the ledger's unique idempotency key.
 *
 * The write adapter translates the database's duplicate-key failure into this
 * exception (D-025), so no driver exception crosses into a Service. It is the
 * normal outcome of a redelivered webhook or a retried enqueue rather than an
 * error: the event it describes is already recorded, and the caller treats the
 * collision as a successful no-op (A7).
 */
final class SyncEventDuplicateException extends RuntimeException
{
    /** Translations for this exception live under this path. */
    public const TRANSLATION_PREFIX = 'crm_sync.errors.';

    /**
     * @param string         $message        Untranslated default wording.
     * @param string         $translationKey
     * @param Throwable|null $previous       The rejected database write.
     */
    public function __construct(
        string $message = 'A sync event with the same idempotency key already exists.',
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'duplicate',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
