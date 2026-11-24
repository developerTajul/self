<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use DateTimeImmutable;
use RuntimeException;

/**
 * Raised when a dead letter is replayed a second time.
 *
 * A dead letter may be replayed once (S4). The second attempt is refused rather
 * than absorbed because replaying re-runs a payload that already failed and then
 * already succeeded; letting it through would overwrite the first replay's trail
 * with the second one's, and nothing would record what the first replay did.
 */
final class DeadLetterAlreadyReplayedException extends RuntimeException
{
    /** Translations for this exception live under this path. */
    public const TRANSLATION_PREFIX = 'crm_sync.errors.';

    /**
     * @param int|null          $deadLetterId
     * @param DateTimeImmutable $retriedAt    When the first replay happened.
     */
    public function __construct(
        public readonly ?int $deadLetterId,
        public readonly DateTimeImmutable $retriedAt,
        ?string $message = null,
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'dead_letter_already_replayed',
    ) {
        parent::__construct($message ?? sprintf(
            'Dead letter %s was already replayed at %s; a dead letter can be replayed only once.',
            $deadLetterId === null ? '(unsaved)' : (string) $deadLetterId,
            $retriedAt->format(DATE_ATOM),
        ));
    }

    /**
     * Reports a second replay of a dead letter.
     *
     * @param int|null          $deadLetterId
     * @param DateTimeImmutable $retriedAt
     *
     * @return self
     */
    public static function alreadyReplayed(?int $deadLetterId, DateTimeImmutable $retriedAt): self
    {
        return new self($deadLetterId, $retriedAt);
    }
}
