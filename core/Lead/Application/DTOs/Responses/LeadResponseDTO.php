<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Responses;

/**
 * Response DTO for lead data.
 *
 * Final readonly class with promoted constructor properties.
 * One DTO, one casing: wire format is snake_case.
 */
final readonly class LeadResponseDTO
{
    /**
     * @param int $id
     * @param string $uuid
     * @param string $name
     * @param ?string $email
     * @param ?string $phone
     * @param ?string $companyName
     * @param ?string $websiteUrl
     * @param string $status
     * @param string $source
     * @param ?string $metaJson
     * @param ?int $assignedTo
     * @param \DateTimeImmutable $createdAt
     * @param \DateTimeImmutable $updatedAt
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public string $name,
        ?string $email,
        ?string $phone,
        ?string $companyName,
        ?string $websiteUrl,
        public string $status,
        public string $source,
        ?string $metaJson,
        ?int $assignedTo,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}
}