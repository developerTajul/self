<?php
declare(strict_types=1);

namespace Core\CrmSync\Application\Mappers;

use Core\CrmSync\Application\DTOs\Responses\DeadLetterResponseDTO;
use Core\CrmSync\Application\DTOs\Responses\SyncEventResponseDTO;
use Core\CrmSync\Domain\Entities\DeadLetterEntity;
use Core\CrmSync\Domain\Entities\SyncEventEntity;
use Core\CrmSync\Domain\ReadModels\DeadLetterProjectionDTO;
use Core\CrmSync\Domain\ReadModels\SyncEventProjectionDTO;
use DateTimeZone;

/**
 * Builds presentation-safe responses from sync domain data.
 */
final class SyncResponseMapper
{
    /**
     * Builds the response DTO from a sync event entity.
     *
     * @param SyncEventEntity $syncEvent
     *
     * @return SyncEventResponseDTO
     */
    public function toResponseDTO(SyncEventEntity $syncEvent): SyncEventResponseDTO
    {
        $format = static fn ($date): ?string => $date?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return new SyncEventResponseDTO(
            id: $syncEvent->id ?? 0,
            direction: $syncEvent->direction->value,
            provider: $syncEvent->provider->value,
            eventType: $syncEvent->eventType,
            status: $syncEvent->status->value,
            attempts: $syncEvent->attempts,
            nextAttemptAt: $format($syncEvent->nextAttemptAt) ?? '',
            lastError: $syncEvent->lastError,
            processedAt: $format($syncEvent->processedAt),
            createdAt: $format($syncEvent->createdAt),
        );
    }

    /**
     * Builds the response DTO from an audit projection.
     *
     * @param SyncEventProjectionDTO $event
     *
     * @return SyncEventResponseDTO
     */
    public function toProjectionResponseDTO(SyncEventProjectionDTO $event): SyncEventResponseDTO
    {
        return new SyncEventResponseDTO(
            id: $event->id,
            direction: $event->direction,
            provider: $event->provider,
            eventType: $event->eventType,
            status: $event->status,
            attempts: $event->attempts,
            nextAttemptAt: $event->nextAttemptAt,
            lastError: $event->lastError,
            processedAt: $event->processedAt,
            createdAt: $event->createdAt,
        );
    }

    /**
     * Builds a dead-letter response from a full entity.
     *
     * @param DeadLetterEntity $deadLetter
     *
     * @return DeadLetterResponseDTO
     */
    public function deadLetterToResponseDTO(DeadLetterEntity $deadLetter): DeadLetterResponseDTO
    {
        return new DeadLetterResponseDTO(
            id: $deadLetter->id ?? 0,
            provider: $deadLetter->provider->value,
            jobClass: $deadLetter->jobClass,
            errorMessage: $deadLetter->errorMessage->toString(),
            failedAt: $deadLetter->failedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            syncEventId: $deadLetter->syncEventId,
            replayedSyncEventId: $deadLetter->replayedSyncEventId,
            httpStatusCode: $deadLetter->httpStatusCode,
            retriedAt: $deadLetter->retriedAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            isReplayable: ! $deadLetter->hasBeenReplayed(),
        );
    }

    /**
     * Builds a dead-letter response from an inspector projection.
     *
     * @param DeadLetterProjectionDTO $deadLetter
     *
     * @return DeadLetterResponseDTO
     */
    public function deadLetterProjectionToResponseDTO(DeadLetterProjectionDTO $deadLetter): DeadLetterResponseDTO
    {
        return new DeadLetterResponseDTO(
            id: $deadLetter->id,
            provider: $deadLetter->provider,
            jobClass: $deadLetter->jobClass,
            errorMessage: $deadLetter->errorMessage,
            failedAt: $deadLetter->failedAt,
            syncEventId: $deadLetter->syncEventId,
            replayedSyncEventId: $deadLetter->replayedSyncEventId,
            httpStatusCode: $deadLetter->httpStatusCode,
            retriedAt: $deadLetter->retriedAt,
            isReplayable: $deadLetter->isReplayable(),
        );
    }
}
