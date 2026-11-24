<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Mappers;

use Core\CrmSync\Domain\Entities\CrmMappingEntity;
use Core\CrmSync\Domain\Enums\CrmObjectType;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\LocalEntityType;
use Core\CrmSync\Domain\ValueObjects\ContentHash;
use Core\CrmSync\Domain\ValueObjects\RemoteId;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Maps `$wpdb` rows to `CrmMappingEntity` and back to the mapping table's columns.
 */
final class CrmMappingEntityMapper
{
    /**
     * Builds a pure domain entity from a database row.
     *
     * `$wpdb` returns every column as a string, so `local_entity_id` is cast
     * rather than trusted; and the remote id is rebuilt through
     * {@see RemoteId::fromCanonical()} rather than {@see RemoteId::fromString()},
     * because the column only ever holds a canonical id — re-canonicalizing would
     * be wasted work and, for a provider whose canonical form is derived, a second
     * place for that derivation to disagree.
     *
     * @param array<string, mixed> $data A row fetched with ARRAY_A.
     *
     * @return CrmMappingEntity
     *
     * @throws RuntimeException When a column holds an enum value or a hash this release does not know.
     */
    public function toEntity(array $data): CrmMappingEntity
    {
        return new CrmMappingEntity(
            id: (int) $data['id'],
            entityType: $this->toLocalEntityType($data['entity_type'] ?? null),
            localEntityId: (int) $data['local_entity_id'],
            provider: $this->toProvider($data['provider'] ?? null),
            crmObjectType: $this->toCrmObjectType($data['crm_object_type'] ?? null),
            remoteId: RemoteId::fromCanonical((string) $data['crm_record_id']),
            lastSyncedHash: $this->toHash($data['last_synced_hash'] ?? null),
            lastSyncedAt: $this->toNullableDate($data['last_synced_at'] ?? null),
            createdAt: $this->toNullableDate($data['created_at'] ?? null),
            updatedAt: $this->toNullableDate($data['updated_at'] ?? null),
        );
    }

    /**
     * Flattens the entity's own state onto the mapping table's columns.
     *
     * The id is absent because it addresses the row rather than describing it.
     * `created_at` is written, unlike on the ledger: `link()` stamps it from the
     * caller's clock and an update must preserve it, so the value belongs to the
     * entity here rather than to the database.
     *
     * @param CrmMappingEntity $mapping
     *
     * @return array<string, mixed>
     */
    public function toPersistenceArray(CrmMappingEntity $mapping): array
    {
        return [
            'entity_type' => $mapping->entityType->value,
            'local_entity_id' => $mapping->localEntityId,
            'provider' => $mapping->provider->value,
            'crm_object_type' => $mapping->crmObjectType->value,
            'crm_record_id' => $mapping->remoteId->toString(),
            'last_synced_hash' => $mapping->lastSyncedHash?->toString(),
            'last_synced_at' => $mapping->lastSyncedAt === null ? null : $this->toStorageDate($mapping->lastSyncedAt),
            'created_at' => $mapping->createdAt === null ? null : $this->toStorageDate($mapping->createdAt),
            'updated_at' => $mapping->updatedAt === null ? null : $this->toStorageDate($mapping->updatedAt),
        ];
    }

    /**
     * The `last_synced_hash` column as a digest.
     *
     * A null column means nothing has been pushed yet, which is a real state
     * rather than a fault. A non-null value that is not a SHA-256 digest is a
     * fault and is raised: reading a corrupted hash as "no hash recorded" would
     * make the echo detector treat our own write as a genuine remote change.
     *
     * @param mixed $value
     *
     * @return ContentHash|null
     *
     * @throws RuntimeException When the value is present but not a digest.
     */
    private function toHash(mixed $value): ?ContentHash
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException('The mapping hash column does not hold text, so it cannot be a digest.');
        }

        return ContentHash::fromString($value);
    }

    /**
     * The local entity type column as an enum.
     *
     * @param mixed $value
     *
     * @return LocalEntityType
     *
     * @throws RuntimeException When the value is not a known entity type.
     */
    private function toLocalEntityType(mixed $value): LocalEntityType
    {
        $type = is_string($value) ? LocalEntityType::tryFrom($value) : null;

        if ($type === null) {
            throw new RuntimeException(sprintf(
                'The mapping entity_type column holds a value this release does not know: %s',
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }

        return $type;
    }

    /**
     * The provider column as an enum.
     *
     * @param mixed $value
     *
     * @return CrmProviderName
     *
     * @throws RuntimeException When the value is not a known provider.
     */
    private function toProvider(mixed $value): CrmProviderName
    {
        $provider = is_string($value) ? CrmProviderName::tryFrom($value) : null;

        if ($provider === null) {
            throw new RuntimeException(sprintf(
                'The mapping provider column holds a value this release does not know: %s',
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }

        return $provider;
    }

    /**
     * The CRM object type column as an enum.
     *
     * @param mixed $value
     *
     * @return CrmObjectType
     *
     * @throws RuntimeException When the value is not a known object type.
     */
    private function toCrmObjectType(mixed $value): CrmObjectType
    {
        $type = is_string($value) ? CrmObjectType::tryFrom($value) : null;

        if ($type === null) {
            throw new RuntimeException(sprintf(
                'The mapping crm_object_type column holds a value this release does not know: %s',
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }

        return $type;
    }

    /**
     * A datetime column as a UTC instant.
     *
     * @param mixed $value
     *
     * @return DateTimeImmutable|null
     *
     * @throws RuntimeException When the column is not a readable timestamp.
     */
    private function toNullableDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = (string) $value;

        try {
            return new DateTimeImmutable($text, new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw new RuntimeException(
                sprintf('The mapping row holds a timestamp that cannot be read: %s', $text),
                0,
                $exception
            );
        }
    }

    /**
     * A datetime as the UTC string the columns store.
     *
     * @param DateTimeImmutable $date
     *
     * @return string
     */
    private function toStorageDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
