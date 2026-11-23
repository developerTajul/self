<?php

declare(strict_types=1);

namespace Core\Lead\Application\Mappers;

/**
 * Maps LeadEntity to LeadResponseDTO.
 *
 * Mechanical schema mapping: entity properties -> DTO properties.
 * @implements \Core\Lead\Application\Contracts\LeadQueryContract
 */
final class LeadResponseMapper
{
    /**
     * Convert a LeadEntity to a LeadResponseDTO.
     *
     * @param \Core\Lead\Domain\Entities\LeadEntity $entity
     *
     * @return \Core\Lead\Application\DTOs\Responses\LeadResponseDTO
     */
    public function toResponseDTO(\Core\Lead\Domain\Entities\LeadEntity $entity): \Core\Lead\Application\DTOs\Responses\LeadResponseDTO
    {
        return new \Core\Lead\Application\DTOs\Responses\LeadResponseDTO(
            id: (int) $entity->id,
            uuid: $entity->uuid,
            name: $entity->name,
            email: $entity->email,
            phone: $entity->phone,
            companyName: $entity->companyName,
            websiteUrl: $entity->websiteUrl,
            status: $entity->status,
            source: $entity->source,
            metaJson: $entity->metaJson,
            assignedTo: $entity->assignedTo,
            createdAt: $entity->createdAt ?? new \DateTimeImmutable('now'),
            updatedAt: $entity->updatedAt ?? new \DateTimeImmutable('now'),
        );
    }
}