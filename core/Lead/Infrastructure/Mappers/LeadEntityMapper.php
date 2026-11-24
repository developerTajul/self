<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Mappers;

use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\Enums\LeadSource;
use Core\Lead\Domain\Enums\LeadStatus;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Maps `$wpdb` rows to `LeadEntity` and back to the lead table's columns.
 */
final class LeadEntityMapper
{
    /**
     * Builds a pure domain entity from a database row.
     *
     * `$wpdb` returns every column as a string, so each one is cast explicitly
     * rather than trusted: without that, an id would reach the entity as `'7'`
     * and a strict comparison or an `int` parameter would fail far from here.
     *
     * @param array<string, mixed> $data A row fetched with ARRAY_A.
     *
     * @return LeadEntity
     *
     * @throws RuntimeException When the row carries a status or source the enums do not know, or meta JSON that does not decode.
     */
    public function toEntity(array $data): LeadEntity
    {
        return new LeadEntity(
            id: (int) $data['id'],
            uuid: (string) $data['uuid'],
            name: $this->toNullableString($data['name'] ?? null),
            email: $this->toNullableString($data['email'] ?? null),
            phone: $this->toNullableString($data['phone'] ?? null),
            companyName: $this->toNullableString($data['company_name'] ?? null),
            websiteUrl: $this->toNullableString($data['website_url'] ?? null),
            status: $this->toStatus($data['status'] ?? null),
            source: $this->toSource($data['source'] ?? null),
            meta: $this->decodeMeta($data['meta_json'] ?? null),
            assignedTo: $this->toNullableInt($data['assigned_to'] ?? null),
            createdAt: $this->toNullableDate($data['created_at'] ?? null),
            updatedAt: $this->toNullableDate($data['updated_at'] ?? null),
        );
    }

    /**
     * Flattens the entity's own state onto the lead table's columns.
     *
     * The id and the timestamps are deliberately absent: the id addresses the row
     * rather than describing it, and the timestamps belong to the database, which
     * is why the write adapter adds them. `meta_json` is encoded here, so no
     * caller has to remember that the column is JSON.
     *
     * @param LeadEntity $lead
     *
     * @return array<string, mixed>
     */
    public function toPersistenceArray(LeadEntity $lead): array
    {
        return [
            'uuid' => $lead->uuid,
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'company_name' => $lead->companyName,
            'website_url' => $lead->websiteUrl,
            'status' => $lead->status->value,
            'source' => $lead->source->value,
            'meta_json' => $this->encodeMeta($lead->meta),
            'assigned_to' => $lead->assignedTo,
        ];
    }

    /**
     * The status column as an enum.
     *
     * An unknown value is raised rather than defaulted, because a stored status
     * the code no longer knows is a fact about the data — most likely a column
     * written by a newer release or edited by hand — and silently reading it as
     * `new` would report a lead in a state nobody chose.
     *
     * @param mixed $value
     *
     * @return LeadStatus
     *
     * @throws RuntimeException When the value is not a known status.
     */
    private function toStatus(mixed $value): LeadStatus
    {
        $status = is_string($value) ? LeadStatus::tryFrom($value) : null;

        if ($status === null) {
            throw new RuntimeException(sprintf(
                'The lead status column holds a value this release does not know: %s',
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }

        return $status;
    }

    /**
     * The source column as an enum.
     *
     * @param mixed $value
     *
     * @return LeadSource
     *
     * @throws RuntimeException When the value is not a known source.
     */
    private function toSource(mixed $value): LeadSource
    {
        $source = is_string($value) ? LeadSource::tryFrom($value) : null;

        if ($source === null) {
            throw new RuntimeException(sprintf(
                'The lead source column holds a value this release does not know: %s',
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }

        return $source;
    }

    /**
     * The `meta_json` column as a map.
     *
     * Invalid JSON raises instead of becoming an empty array (S7): "no metadata"
     * and "unreadable metadata" are different facts, and collapsing them would
     * let a later write erase the source-specific attributes a lead was believed
     * to have. A JSON value that decodes to something other than an object — a
     * bare string, a list — is likewise refused, because the entity's contract is
     * a keyed map.
     *
     * @param mixed $value
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the column is not a JSON object.
     */
    private function decodeMeta(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_string($value)) {
            throw new RuntimeException('The lead meta column does not hold text, so it cannot be JSON.');
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new RuntimeException(
                'The lead meta column does not hold a JSON object. Refusing to read it as an empty map, '
                . 'because a later write would then erase it.'
            );
        }

        return $decoded;
    }

    /**
     * The meta map as the JSON text the column stores.
     *
     * An empty map is written as SQL null rather than as `'[]'` or `'{}'`, so
     * "this lead carries no metadata" has one representation in storage instead
     * of two that compare unequal.
     *
     * @param array<string, mixed> $meta
     *
     * @return string|null
     */
    private function encodeMeta(array $meta): ?string
    {
        if ($meta === []) {
            return null;
        }

        $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // `json_encode()` fails on invalid UTF-8 and on recursion. A null here
        // would be stored as "no metadata" and the caller would never learn that
        // the attributes were dropped.
        if ($encoded === false) {
            throw new RuntimeException(
                'The lead metadata could not be encoded as JSON, so it was not written: '
                . json_last_error_msg()
            );
        }

        return $encoded;
    }

    /**
     * A datetime column as a UTC instant.
     *
     * The columns store UTC and the application writes UTC, so the zone is set
     * explicitly rather than inherited from the server's default — otherwise the
     * meaning of every stored timestamp would depend on the machine that read it.
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
                sprintf('The lead row holds a timestamp that cannot be read: %s', $text),
                0,
                $exception
            );
        }
    }

    /**
     * A column value as a nullable int.
     *
     * A zero id is read as absence: the column is a nullable foreign key, and no
     * real user carries id 0, so a stored zero is a placeholder rather than a
     * reference to be handed on.
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

    /**
     * A column value as a nullable string.
     *
     * An empty string is read as absence, matching what the write path stores: the
     * schema's nullable contact columns hold a value or NULL, never `''`, so the
     * two spellings of "nothing" are collapsed on the way in as they are on the
     * way out.
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
