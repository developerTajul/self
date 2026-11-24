<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\DTOs\Responses;

use Core\CrmSync\Domain\ValueObjects\RemoteId;

/**
 * What a CRM returns after a record is created or updated.
 *
 * Carries the canonical `RemoteId` rather than the provider's raw id string, so
 * the canonicalization happens at the boundary where the provider's answer is
 * known and every layer above compares the same form. The status code is kept
 * beside it because it is what tells an operator, reading a later dead letter,
 * whether the provider accepted the write or merely echoed it.
 */
final readonly class CrmPushResultDTO
{
    /**
     * @param RemoteId $remoteId
     * @param int      $httpStatusCode
     * @param bool     $created        True when the record did not exist before this call.
     */
    public function __construct(
        public RemoteId $remoteId,
        public int $httpStatusCode,
        public bool $created = false,
    ) {}
}
