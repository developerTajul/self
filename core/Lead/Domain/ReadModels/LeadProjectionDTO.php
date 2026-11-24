<?php

declare(strict_types=1);

namespace Core\Lead\Domain\ReadModels;

/**
 * A lead row as the list read returns it: the columns the list shows and
 * nothing else. Hydrating a full Entity for a table row is what this read model
 * exists to avoid, and the meta map is deliberately absent — the list never
 * renders it, and decoding it per row would pay for JSON no one reads.
 */
final readonly class LeadProjectionDTO
{
    /**
     * @param int         $id
     * @param string      $uuid
     * @param string|null $name
     * @param string|null $email
     * @param string|null $phone
     * @param string|null $companyName
     * @param string      $status   The raw enum value, for the view's own translation lookup.
     * @param string      $source   The raw enum value.
     * @param string|null $createdAt UTC, as stored.
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $companyName,
        public string $status,
        public string $source,
        public ?string $createdAt = null,
    ) {}
}
