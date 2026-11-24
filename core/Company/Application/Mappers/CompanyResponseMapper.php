<?php

declare(strict_types=1);

namespace Core\Company\Application\Mappers;

use Core\Company\Application\Contracts\CompanyResponseMapperContract;
use Core\Company\Application\DTOs\Responses\CompanyDetailResponseDTO;
use Core\Company\Application\DTOs\Responses\CompanyEditResponseDTO;
use Core\Company\Application\DTOs\Responses\CompanyResponseDTO;
use Core\Company\Domain\DataObjects\CompanyDetailData;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\ReadModels\CompanyProjectionDTO;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Maps a company entity to CompanyResponseDTO.
 *
 * @implements CompanyResponseMapperContract
 */
final class CompanyResponseMapper implements CompanyResponseMapperContract
{
    /**
     * Builds the response DTO from the company entity. Timestamps are stored as
     * UTC wall-clock strings and leave on the same ISO-8601 wire format the
     * other modules use, so a client parses every module's dates alike.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyResponseDTO
     */
    public function toResponseDTO(CompanyEntity $company): CompanyResponseDTO
    {
        return new CompanyResponseDTO(
            id: (int) $company->id,
            name: $company->name,
            website: $company->website,
            industry: $company->industry,
            phone: $company->phone,
            address: $company->address,
            created_at: $this->toAtom($company->createdAt),
            updated_at: $this->toAtom($company->updatedAt),
        );
    }

    /**
     * Builds the list response DTO from a projected row. The list read never
     * hydrates an entity, so the same wire shape is built from the projection.
     *
     * @param CompanyProjectionDTO $company
     *
     * @return CompanyResponseDTO
     */
    public function toResponseDTOFromProjection(CompanyProjectionDTO $company): CompanyResponseDTO
    {
        return new CompanyResponseDTO(
            id: $company->id,
            name: $company->name,
            website: $company->website,
            industry: $company->industry,
            phone: $company->phone,
            address: $company->address,
            created_at: $this->toAtom($company->createdAt),
            updated_at: $this->toAtom($company->updatedAt),
        );
    }

    /**
     * Builds the edit-form DTO from the company entity. Only the editable
     * fields travel, since a form has nothing to do with the timestamps the
     * full response DTO carries.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyEditResponseDTO
     */
    public function toEditResponseDTO(CompanyEntity $company): CompanyEditResponseDTO
    {
        return new CompanyEditResponseDTO(
            id: (int) $company->id,
            name: $company->name,
            website: $company->website,
            industry: $company->industry,
            phone: $company->phone,
            address: $company->address,
        );
    }

    /**
     * Builds the profile DTO from the detail read model.
     *
     * @param CompanyDetailData $companyDetail
     *
     * @return CompanyDetailResponseDTO
     */
    public function toDetailResponseDTO(CompanyDetailData $companyDetail): CompanyDetailResponseDTO
    {
        return new CompanyDetailResponseDTO(
            id: $companyDetail->id,
            name: $companyDetail->name,
            website: $companyDetail->website,
            industry: $companyDetail->industry,
            phone: $companyDetail->phone,
            address: $companyDetail->address,
            created_at: $this->toAtom($companyDetail->createdAt),
            updated_at: $this->toAtom($companyDetail->updatedAt),
        );
    }

    /**
     * Formats a stored UTC timestamp as ISO-8601. Parsing it in the application
     * timezone would shift the instant on every round trip.
     *
     * @param string|null $value
     *
     * @return string|null
     */
    private function toAtom(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }
}
