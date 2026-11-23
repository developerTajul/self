<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Entities;

/**
 * Domain entity representing a lead.
 */
readonly class LeadEntity
{
    /**
     * @param int|null    $id
     * @param string      $uuid
     * @param string      $name
     * @param ?string     $email
     * @param ?string     $phone
     * @param ?string     $companyName
     * @param ?string     $websiteUrl
     * @param string      $status
     * @param string      $source
     * @param ?string     $metaJson
     * @param ?int        $assignedTo
     * @param \DateTimeImmutable|null $createdAt
     * @param \DateTimeImmutable|null $updatedAt
     */
    public function __construct(
        public ?int $id,
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
        public ?\DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $updatedAt,
    ) {}

    /**
     * TODO: Add business rule create() method - added with use cases.
     *
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
     *
     * @return self
     */
    public static function create(
        string $uuid,
        string $name,
        ?string $email,
        ?string $phone,
        ?string $companyName,
        ?string $websiteUrl,
        string $status,
        string $source,
        ?string $metaJson,
        ?int $assignedTo,
    ): self {
        return new self(
            id: null,
            uuid: $uuid,
            name: $name,
            email: $email,
            phone: $phone,
            companyName: $companyName,
            websiteUrl: $websiteUrl,
            status: $status,
            source: $source,
            metaJson: $metaJson,
            assignedTo: $assignedTo,
            createdAt: null,
            updatedAt: null,
        );
    }

    /**
     * TODO: Add behaviour methods - added with use cases.
     *
     * @return \DateTimeImmutable|null
     */
    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * TODO: Add behaviour methods - added with use cases.
     *
     * @return \DateTimeImmutable|null
     */
    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * TODO: Add domain events support - added with first event-raising use case.
     *
     * @return array
     */
    public function domainEvents(): array
    {
        return [];
    }

    /**
     * TODO: Add releaseEvents() - never clears the events list (03, 7.1.1).
     *
     * @return array
     */
    public function releaseEvents(): array
    {
        return [];
    }
}