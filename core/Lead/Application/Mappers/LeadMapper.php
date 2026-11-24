<?php

declare(strict_types=1);

namespace Core\Lead\Application\Mappers;

use Core\Lead\Application\Contracts\LeadMapperContract;
use Core\Lead\Application\DTOs\Requests\LeadCaptureDTO;
use Core\Lead\Application\DTOs\Requests\LeadFilterDTO;
use Core\Lead\Domain\Enums\LeadSource;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Rules\LeadRules;
use InvalidArgumentException;

/**
 * Turns plain delivery arrays into lead request DTOs.
 *
 * @implements LeadMapperContract
 */
final class LeadMapper implements LeadMapperContract
{
    /**
     * Builds a capture DTO and normalizes blank contact fields to null.
     *
     * @param array<string, mixed> $input
     *
     * @return LeadCaptureDTO
     */
    public function mapToCaptureDTO(array $input): LeadCaptureDTO
    {
        $source = is_string($input['source'] ?? null)
            ? LeadSource::tryFrom($input['source'])
            : LeadSource::manual;
        $meta = $input['meta'] ?? [];
        $idempotencyKey = $input['idempotency_key'] ?? '';

        if ($source === null || ! is_array($meta) || ($meta !== [] && array_is_list($meta))) {
            throw new InvalidArgumentException('The lead capture input is invalid.');
        }

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('A lead capture idempotency key is required.');
        }

        return new LeadCaptureDTO(
            name: $this->nullableString($input['name'] ?? null),
            email: $this->nullableString($input['email'] ?? null),
            phone: $this->nullableString($input['phone'] ?? null),
            companyName: $this->nullableString($input['company_name'] ?? null),
            websiteUrl: $this->nullableString($input['website_url'] ?? null),
            source: $source,
            meta: $meta,
            idempotencyKey: trim($idempotencyKey),
            assignedTo: $this->nullableInt($input['assigned_to'] ?? null),
        );
    }

    /**
     * Builds clamped lead-list criteria from a plain request array.
     *
     * @param array<string, mixed> $input
     *
     * @return LeadFilterDTO
     */
    public function mapToFilterDTO(array $input): LeadFilterDTO
    {
        $perPage = $this->integerValue($input['per_page'] ?? null, LeadRules::DEFAULT_PER_PAGE);
        $page = max(LeadRules::MIN_PAGE, $this->integerValue($input['page'] ?? null, LeadRules::MIN_PAGE));
        $status = $this->enumValue($input['status'] ?? null, LeadStatus::class);
        $source = $this->enumValue($input['source'] ?? null, LeadSource::class);

        return new LeadFilterDTO(
            perPage: LeadRules::clampPerPage($perPage),
            page: $page,
            status: $status,
            source: $source,
            search: $this->nullableString($input['search'] ?? null),
        );
    }

    /**
     * Converts blank text to null and rejects other input types.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('A lead text field must be a string or null.');
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Parses an optional positive user id.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            throw new InvalidArgumentException('The assigned user id must be a positive integer or null.');
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * Parses an optional enum-backed filter.
     *
     * @param mixed $value
     * @param class-string<LeadStatus|LeadSource> $enumClass
     *
     * @return string|null
     */
    private function enumValue(mixed $value, string $enumClass): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || $enumClass::tryFrom($value) === null) {
            throw new InvalidArgumentException('A lead filter contains an unsupported value.');
        }

        return $value;
    }

    /**
     * Parses an integer or falls back to its bounded default.
     *
     * @param mixed $value
     * @param int $default
     *
     * @return int
     */
    private function integerValue(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1
            ? (int) $value
            : $default;
    }
}
