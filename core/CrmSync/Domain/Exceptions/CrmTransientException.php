<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a CRM call failed in a way that is worth retrying.
 *
 * A 429 or a 5xx means the provider is busy or briefly broken, not that the
 * request was wrong: the same payload has a real chance of succeeding later.
 * The worker retries these, so the distinction from the permanent case is a
 * control-flow decision and belongs in the type rather than in a status code the
 * Core would have to interpret.
 *
 * Carries the scrubbed failure text and the response status, because both are
 * what a later dead letter needs to explain itself.
 */
final class CrmTransientException extends RuntimeException
{
    /**
     * @param string          $message
     * @param int|null        $httpStatusCode
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatusCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
