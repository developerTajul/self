<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Mappers;

use Core\CrmSync\Domain\Entities\SyncEventEntity;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\SyncDirection;
use Core\CrmSync\Domain\Enums\SyncEventStatus;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Maps `$wpdb` rows to `SyncEventEntity` and back to the sync ledger's columns.
 */
final class SyncEventEntityMapper
{
    /**
     * Builds a pure domain entity from a database row.
     *
     * `$wpdb` returns every column as a string, so each is cast explicitly rather
     * than trusted: without that, `attempts` would reach the entity as `'0'` and
     * the retry comparisons against `MAX_ATTEMPTS` would compare a string to an
     * int.
     *
     * @param array<string, mixed> $data A row fetched with ARRAY_A.
     *
     * @return SyncEventEntity
     *
     * @throws RuntimeException When the row carries an enum value this release does not know, or payload JSON that does not decode.
     */
    public function toEntity(array $data): SyncEventEntity
    {
        return new SyncEventEntity(
            id: (int) $data['id'],
            idempotencyKey: (string) $data['idempotency_key'],
            direction: $this->toDirection($data['direction'] ?? null),
            provider: $this->toProvider($data['provider'] ?? null),
            eventType: (string) $data['event_type'],
            payload: $this->decodePayload($data['payload_json'] ?? null),
            status: $this->toStatus($data['status'] ?? null),
            nextAttemptAt: $this->toRequiredDate($data['next_attempt_at'] ?? null, 'next_attempt_at'),
            attempts: (int) ($data['attempts'] ?? 0),
            lockedAt: $this->toNullableDate($data['locked_at'] ?? null),
            lastError: $this->toNullableString($data['last_error'] ?? null),
            processedAt: $this->toNullableDate($data['processed_at'] ?? null),
            createdAt: $this->toNullableDate($data['created_at'] ?? null),
            updatedAt: $this->toNullableDate($data['updated_at'] ?? null),
        );
    }

    /**
     * Flattens the entity's own state onto the ledger's columns.
     *
     * The id is deliberately absent — it addresses the row rather than describing
     * it — and `created_at` is absent too, because the insert sets it from the
     * database's clock and an update never re-dates a row.
     *
     * Every nullable field is written explicitly, including the nulls: the shared
     * `write()` helper compiles a null to the SQL `NULL` keyword, so a lease
     * cleared by {@see SyncEventEntity::markSucceeded()} actually reaches storage
     * as absent rather than being skipped.
     *
     * @param SyncEventEntity $syncEvent
     *
     * @return array<string, mixed>
     */
    public function toPersistenceArray(SyncEventEntity $syncEvent): array
    {
        return [
            'idempotency_key' => $syncEvent->idempotencyKey,
            'direction' => $syncEvent->direction->value,
            'provider' => $syncEvent->provider->value,
            'event_type' => $syncEvent->eventType,
            'payload_json' => $this->encodePayload($syncEvent->payload),
            'status' => $syncEvent->status->value,
            'attempts' => $syncEvent->attempts,
            'next_attempt_at' => $this->toStorageDate($syncEvent->nextAttemptAt),
            'locked_at' => $syncEvent->lockedAt === null ? null : $this->toStorageDate($syncEvent->lockedAt),
            'last_error' => $syncEvent->lastError,
            'processed_at' => $syncEvent->processedAt === null ? null : $this->toStorageDate($syncEvent->processedAt),
            'updated_at' => $syncEvent->updatedAt === null ? null : $this->toStorageDate($syncEvent->updatedAt),
        ];
    }

    /**
     * The direction column as an enum.
     *
     * @param mixed $value
     *
     * @return SyncDirection
     *
     * @throws RuntimeException When the value is not a known direction.
     */
    private function toDirection(mixed $value): SyncDirection
    {
        $direction = is_string($value) ? SyncDirection::tryFrom($value) : null;

        if ($direction === null) {
            throw new RuntimeException(sprintf(
                'The sync event direction column holds a value this release does not know: %s',
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }

        return $direction;
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
                'The sync event provider column holds a value this release does not know: %s',
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }

        return $provider;
    }

    /**
     * The status column as an enum.
     *
     * @param mixed $value
     *
     * @return SyncEventStatus
     *
     * @throws RuntimeException When the value is not a known status.
     */
    private function toStatus(mixed $value): SyncEventStatus
    {
        $status = is_string($value) ? SyncEventStatus::tryFrom($value) : null;

        if ($status === null) {
            throw new RuntimeException(sprintf(
                'The sync event status column holds a value this release does not know: %s',
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }

        return $status;
    }

    /**
     * The `payload_json` column as a map.
     *
     * The column is NOT NULL in storage, so an absent or unreadable payload is a
     * fault rather than an empty event: a replay of a dead letter is built from
     * this snapshot (S4), and a payload read as `[]` would replay an event that
     * tells the CRM nothing while reporting success.
     *
     * @param mixed $value
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the column is missing, empty, or not a JSON object.
     */
    private function decodePayload(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException(
                'The sync event row carries no payload. The column is NOT NULL in storage, so this '
                . 'is a fault rather than an empty event, and replaying it would report success '
                . 'without doing anything.'
            );
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException(
                'The sync event payload column does not hold a JSON object. Refusing to read it as '
                . 'an empty payload, because a replay would then send the CRM nothing.'
            );
        }

        return $decoded;
    }

    /**
     * The payload map as the JSON text the column stores.
     *
     * @param array<string, mixed> $payload
     *
     * @return string
     *
     * @throws RuntimeException When the payload cannot be encoded.
     */
    private function encodePayload(array $payload): string
    {
        // `JSON_UNESCAPED_SLASHES` keeps a URL readable in the audit log rather
        // than as `https:\/\/`, which is the whole point of storing a snapshot an
        // administrator may have to read.
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new RuntimeException(
                'The sync event payload could not be encoded as JSON, so it was not written: '
                . json_last_error_msg()
            );
        }

        return $encoded;
    }

    /**
     * A datetime column that storage guarantees, as a UTC instant.
     *
     * @param mixed  $value
     * @param string $column Named so the failure says which column was unreadable.
     *
     * @return DateTimeImmutable
     *
     * @throws RuntimeException When the column is missing or unreadable.
     */
    private function toRequiredDate(mixed $value, string $column): DateTimeImmutable
    {
        $date = $this->toNullableDate($value);

        if ($date === null) {
            throw new RuntimeException(sprintf(
                'The sync event row carries no %s. The column is NOT NULL in storage, so this is a '
                . 'fault rather than an event with no due time.',
                $column
            ));
        }

        return $date;
    }

    /**
     * A datetime column as a UTC instant.
     *
     * The columns store UTC and the application writes UTC, so the zone is set
     * explicitly rather than inherited from the server's default — otherwise the
     * meaning of every stored timestamp would depend on the machine that read it,
     * and a lease's age would change with it.
     *
     * @param mixed $value
     *
     * @return DateTimeImmutable|null
     *
     * @throws RuntimeException When the column is not a readable timestamp.
     */
    private function toNullableDate(mixed $value): ?DateTimeImmutable
    {
        $text = $this->toNullableString($value);

        if ($text === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($text, new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw new RuntimeException(
                sprintf('The sync event row holds a timestamp that cannot be read: %s', $text),
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

    /**
     * A column value as a nullable string.
     *
     * An empty string is read as absence, matching what the write path stores.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function toNullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
