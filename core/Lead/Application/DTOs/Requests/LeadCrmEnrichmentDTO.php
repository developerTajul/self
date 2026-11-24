<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Requests;

/**
 * Carries a verified CRM contact into the Lead enrichment use case.
 */
final readonly class LeadCrmEnrichmentDTO
{
    /**
     * @param string      $uuid           The local lead UUID carried by the CRM.
     * @param int|null    $expectedLeadId The id an existing CRM mapping must still reference.
     * @param string|null $name
     * @param string|null $email
     * @param string|null $phone
     * @param string|null $companyName
     * @param string|null $websiteUrl
     */
    public function __construct(
        public string $uuid,
        public ?int $expectedLeadId,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $companyName,
        public ?string $websiteUrl,
    ) {}
}
