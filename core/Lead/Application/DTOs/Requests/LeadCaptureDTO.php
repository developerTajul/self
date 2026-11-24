<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Requests;

use Core\Lead\Domain\Enums\LeadSource;

/**
 * Carries one normalized lead submission into the capture use case.
 */
final readonly class LeadCaptureDTO
{
    /**
     * @param string|null          $name
     * @param string|null          $email
     * @param string|null          $phone
     * @param string|null          $companyName
     * @param string|null          $websiteUrl
     * @param LeadSource           $source
     * @param array<string, mixed> $meta
     * @param string               $idempotencyKey The source event key; the outbox derives its stable lead key per D-047.
     * @param int|null             $assignedTo
     */
    public function __construct(
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $companyName,
        public ?string $websiteUrl,
        public LeadSource $source,
        public array $meta,
        public string $idempotencyKey,
        public ?int $assignedTo = null,
    ) {}
}
