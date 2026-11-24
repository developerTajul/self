<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Contracts;

use Core\CrmSync\Application\DTOs\Responses\CrmPushResultDTO;
use Core\CrmSync\Domain\Enums\CrmObjectType;
use Core\CrmSync\Domain\ValueObjects\RemoteId;

/**
 * PORT: the CRM operations the sync worker performs.
 *
 * Failures must arrive as a typed exception rather than a status code the Core
 * would have to interpret: 429 and 5xx are transient and worth retrying, other
 * 4xx are permanent and are not. That distinction is the provider adapter's to
 * make — it is the only layer that can read the response — so the Core receives
 * either a result or a `CrmTransientException` / `CrmPermanentException`.
 *
 * No HTTP type appears in any signature: a response object, a header map or a
 * status code reaching Core would put the transport inside the Domain.
 */
interface CrmContactGatewayContract
{
    /**
     * Creates a remote record for a local entity.
     *
     * @param CrmObjectType        $objectType
     * @param array<string, mixed> $properties The CRM-facing field map, built by the caller.
     *
     * @return CrmPushResultDTO The created record's canonical id.
     */
    public function createRecord(CrmObjectType $objectType, array $properties): CrmPushResultDTO;

    /**
     * Updates an existing remote record.
     *
     * @param CrmObjectType        $objectType
     * @param RemoteId             $remoteId
     * @param array<string, mixed> $properties
     *
     * @return CrmPushResultDTO The updated record's canonical id.
     */
    public function updateRecord(CrmObjectType $objectType, RemoteId $remoteId, array $properties): CrmPushResultDTO;

    /**
     * Reads one remote record.
     *
     * @param CrmObjectType $objectType
     * @param RemoteId      $remoteId
     *
     * @return array<string, mixed> The record's properties, as the provider returns them.
     */
    public function fetchRecord(CrmObjectType $objectType, RemoteId $remoteId): array;
}
