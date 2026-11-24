<?php

declare(strict_types=1);

namespace Core\Lead\Application\Mappers;

use Core\Lead\Application\DTOs\Responses\LeadResponseDTO;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\ReadModels\LeadProjectionDTO;
use DateTimeZone;

/**
 * Builds the presentation-safe lead shape from lead domain data.
 */
final class LeadResponseMapper
{
    /**
     * Builds the response DTO from a lead entity.
     *
     * @param LeadEntity $lead
     *
     * @return LeadResponseDTO
     */
    public function toResponseDTO(LeadEntity $lead): LeadResponseDTO
    {
        return new LeadResponseDTO(
            id: $lead->id ?? 0,
            uuid: $lead->uuid,
            name: $lead->name,
            email: $lead->email,
            phone: $lead->phone,
            companyName: $lead->companyName,
            websiteUrl: $lead->websiteUrl,
            status: $lead->status->value,
            source: $lead->source->value,
            assignedTo: $lead->assignedTo,
            createdAt: $lead->createdAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            updatedAt: $lead->updatedAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Builds the response shape from a lead list projection.
     *
     * @param LeadProjectionDTO $lead
     *
     * @return LeadResponseDTO
     */
    public function toProjectionResponseDTO(LeadProjectionDTO $lead): LeadResponseDTO
    {
        return new LeadResponseDTO(
            id: $lead->id,
            uuid: $lead->uuid,
            name: $lead->name,
            email: $lead->email,
            phone: $lead->phone,
            companyName: $lead->companyName,
            websiteUrl: null,
            status: $lead->status,
            source: $lead->source,
            assignedTo: null,
            createdAt: $lead->createdAt,
            updatedAt: null,
        );
    }
}
