<?php

declare(strict_types=1);

namespace Core\Company\Domain\DataObjects;

/**
 * Read model for the company detail view. The company's own columns as a typed
 * read model rather than a loose column array, so a renamed column fails in the
 * repository.
 *
 * The company stays a flat projection rather than a CompanyEntity: the detail
 * read shows the values, it never calls the company's behaviour.
 *
 * The CompanyResponseMapper turns this into the response DTO.
 */
final readonly class CompanyDetailData
{
    /**
     * @param int         $id
     * @param string      $name
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $phone
     * @param string|null $address
     * @param string|null $createdAt
     * @param string|null $updatedAt
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $website,
        public ?string $industry,
        public ?string $phone,
        public ?string $address,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
