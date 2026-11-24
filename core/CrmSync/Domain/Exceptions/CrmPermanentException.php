<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a CRM call was rejected and retrying cannot help.
 *
 * A 400, 401, 403, 404 or 422 means the request itself was unacceptable —
 * malformed, unauthorized, or aimed at a record that is not there. Retrying the
 * identical payload would fail identically, so the worker dead-letters these
 * immediately rather than spending the retry budget on them.
 */
final class CrmPermanentException extends RuntimeException
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
