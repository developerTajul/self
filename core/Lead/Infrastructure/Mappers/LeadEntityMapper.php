<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Mappers;

/**
 * Maps between LeadEntity and database row arrays.
 *
 * Mechanical schema mapping:
 * - toEntity: $wpdb ARRAY_A row -> LeadEntity properties
 * - toPersistenceArray: LeadEntity -> table array, skip id/timestamps, unwrap enums with ->value
 *
 * @implements \Core\Lead\Domain\Contracts\LeadRepositoryContract
 */
class LeadEntityMapper
{
    /**
     * Convert a $wpdb ARRAY_A row to a LeadEntity.
     *
     * @param array $row The ARRAY_A row from $wpdb
     *
     * @return \Core\Lead\Domain\Entities\LeadEntity
     */
    public function toEntity(array $row): \Core\Lead\Domain\Entities\LeadEntity
    {
        $metaJson = ! empty($row['meta_json']) ? json_decode($row['meta_json'], true) : null;

        return \Core\Lead\Domain\Entities\LeadEntity::create(
            uuid: $row['uuid'],
            name: $row['name'],
            email: $row['email'] ?? null,
            phone: $row['phone'] ?? null,
            companyName: $row['company_name'] ?? null,
            websiteUrl: $row['website_url'] ?? null,
            status: $row['status'],
            source: $row['source'],
            metaJson: $metaJson,
            assignedTo: $row['assigned_to'] ?? null,
        );
    }

    /**
     * Convert a LeadEntity to a persistence array for database insert/update.
     *
     * Skips the primary key and auto-managed timestamps.
     * Unwraps enum properties with ->value.
     *
     * @param \Core\Lead\Domain\Entities\LeadEntity $entity
     *
     * @return array The persistence array (ready for $wpdb->insert() or ->update())
     */
    public function toPersistenceArray(\Core\Lead\Domain\Entities\LeadEntity $entity): array
    {
        return [
            'uuid' => $entity->uuid,
            'name' => $entity->name,
            'email' => $entity->email,
            'phone' => $entity->phone,
            'company_name' => $entity->companyName,
            'website_url' => $entity->websiteUrl,
            'status' => $entity->status,
            'source' => $entity->source,
            'meta_json' => json_encode($entity->metaJson),
            'assigned_to' => $entity->assignedTo,
        ];
    }
}