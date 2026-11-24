<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Responses;

/**
 * The presentation-safe shape of a lead.
 */
final readonly class LeadResponseDTO
{
    /**
     * @param int         $id
     * @param string      $uuid
     * @param string|null $name
     * @param string|null $email
     * @param string|null $phone
     * @param string|null $companyName
     * @param string|null $websiteUrl
     * @param string      $status
     * @param string      $source
     * @param int|null    $assignedTo
     * @param string|null $createdAt
     * @param string|null $updatedAt
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $companyName,
        public ?string $websiteUrl,
        public string $status,
        public string $source,
        public ?int $assignedTo,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}
}
