<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Mappers;

use Core\CrmSync\Domain\Entities\DeadLetterEntity;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\ValueObjects\SanitizedErrorMessage;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Maps `$wpdb` rows to `DeadLetterEntity` and back to the dead-letter table's columns.
 */
final class DeadLetterEntityMapper
{
    /**
     * Builds a pure domain entity from a database row.
     *
     * The error message is rebuilt through {@see SanitizedErrorMessage::fromString()},
     * which scrubs again on the way in. That is deliberate rather than redundant:
     * scrubbing is idempotent, and a row written before a scrubbing rule existed —
     * or edited by hand — would otherwise be handed to an administrator unscrubbed.
     *
     * @param array<string, mixed> $data A row fetched with ARRAY_A.
     *
     * @return DeadLetterEntity
     *
     * @throws RuntimeException When the row carries an unknown provider or payload JSON that does not decode.
     */
    public function toEntity(array $data): DeadLetterEntity
    {
        return new DeadLetterEntity(
            id: (int) $data['id'],
            provider: $this->toProvider($data['provider'] ?? null),
            jobClass: (string) $data['job_class'],
            payload: $this->decodePayload($data['payload_json'] ?? null),
            errorMessage: SanitizedErrorMessage::fromString((string) $data['error_message']),
            failedAt: $this->toRequiredDate($data['failed_at'] ?? null, 'failed_at'),
            syncEventId: $this->toNullableInt($data['sync_event_id'] ?? null),
            replayedSyncEventId: $this->toNullableInt($data['replayed_sync_event_id'] ?? null),
            httpStatusCode: $this->toNullableInt($data['http_status_code'] ?? null),
            retriedAt: $this->toNullableDate($data['retried_at'] ?? null),
        );
    }

    /**
     * Flattens the entity's own state onto the dead-letter table's columns.
     *
     * **No `created_at` or `updated_at`.** The table has neither column (the
     * migration says so explicitly), and writing them would make `$wpdb->insert()`
     * fail on an unknown column. The row's own timestamps are `failed_at` and
     * `retried_at`, which the entity owns.
     *
     * The error message written is the scrubbed one the entity holds: the column
     * is `mediumText` precisely so a full upstream response survives, but what
     * survives is still credential-free.
     *
     * @param DeadLetterEntity $deadLetter
     *
     * @return array<string, mixed>
     */
    public function toPersistenceArray(DeadLetterEntity $deadLetter): array
    {
        return [
            'sync_event_id' => $deadLetter->syncEventId,
            'replayed_sync_event_id' => $deadLetter->replayedSyncEventId,
            'provider' => $deadLetter->provider->value,
            'job_class' => $deadLetter->jobClass,
            'payload_json' => $this->encodePayload($deadLetter->payload),
            'error_message' => $deadLetter->errorMessage->toString(),
            'http_status_code' => $deadLetter->httpStatusCode,
            'failed_at' => $this->toStorageDate($deadLetter->failedAt),
            'retried_at' => $deadLetter->retriedAt === null ? null : $this->toStorageDate($deadLetter->retriedAt),
        ];
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
                'The dead letter provider column holds a value this release does not know: %s',
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }

        return $provider;
    }

    /**
     * The `payload_json` column as a map.
     *
     * The column is NOT NULL and S4 requires the FULL payload to be kept, because
     * a replay re-runs exactly what failed. An absent or unreadable payload is
     * therefore raised rather than read as empty: an empty replay would report
     * success while sending the CRM nothing.
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
                'The dead letter row carries no payload. The column is NOT NULL in storage and the '
                . 'payload is what a replay re-runs, so this is a fault rather than an empty job.'
            );
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException(
                'The dead letter payload column does not hold a JSON object. Refusing to read it as an '
                . 'empty payload, because a replay would then send the CRM nothing.'
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
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new RuntimeException(
                'The dead letter payload could not be encoded as JSON, so it was not written: '
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
                'The dead letter row carries no %s. The column is NOT NULL in storage, so this is a '
                . 'fault rather than a dead letter that never failed.',
                $column
            ));
        }

        return $date;
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
                sprintf('The dead letter row holds a timestamp that cannot be read: %s', $text),
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
     * A column value as a nullable int.
     *
     * A zero is read as absence. The replay columns are nullable foreign keys,
     * and row id zero does not exist, so a stored zero is a placeholder rather
     * than a reference to hand on.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }

        return (int) $value;
    }
}
