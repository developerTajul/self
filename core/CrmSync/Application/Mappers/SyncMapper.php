<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Mappers;

use Core\CrmSync\Application\Contracts\SyncMapperContract;
use Core\CrmSync\Application\DTOs\Requests\DeadLetterFilterDTO;
use Core\CrmSync\Application\DTOs\Requests\SyncEventFilterDTO;
use Core\CrmSync\Application\DTOs\Requests\WebhookEnqueueDTO;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Enums\SyncDirection;
use Core\CrmSync\Domain\Enums\SyncEventStatus;
use Core\CrmSync\Domain\Rules\SyncReadRules;
use InvalidArgumentException;

/**
 * Turns verified webhook and read-filter arrays into sync DTOs.
 *
 * @implements SyncMapperContract
 */
final class SyncMapper implements SyncMapperContract
{
    /**
     * Builds an enqueue DTO from verified webhook input.
     *
     * @param array<string, mixed> $input
     *
     * @return WebhookEnqueueDTO
     */
    public function mapToWebhookEnqueueDTO(array $input): WebhookEnqueueDTO
    {
        $provider = $input['provider'] ?? null;
        $eventType = $input['event_type'] ?? null;
        $remoteId = $input['remote_id'] ?? null;
        $payload = $input['payload'] ?? null;
        $source = $input['source'] ?? null;
        $externalId = $input['external_id'] ?? null;

        if (! is_string($provider) || CrmProviderName::tryFrom($provider) === null
            || ! is_string($eventType) || trim($eventType) === ''
            || ! is_string($remoteId) || trim($remoteId) === ''
            || ! is_array($payload) || ($payload !== [] && array_is_list($payload))
            || ! is_string($source) || trim($source) === ''
            || ! is_string($externalId) || trim($externalId) === '') {
            throw new InvalidArgumentException('The verified webhook payload is incomplete.');
        }

        return new WebhookEnqueueDTO(
            provider: CrmProviderName::from($provider),
            eventType: trim($eventType),
            remoteId: trim($remoteId),
            payload: $payload,
            source: trim($source),
            externalId: trim($externalId),
        );
    }

    /**
     * Builds bounded sync-audit criteria from plain request input.
     *
     * @param array<string, mixed> $input
     *
     * @return SyncEventFilterDTO
     */
    public function mapToSyncEventFilterDTO(array $input): SyncEventFilterDTO
    {
        return new SyncEventFilterDTO(
            perPage: SyncReadRules::clampPerPage($this->integerValue($input['per_page'] ?? null, SyncReadRules::DEFAULT_PER_PAGE)),
            page: max(1, $this->integerValue($input['page'] ?? null, 1)),
            direction: $this->enumValue($input['direction'] ?? null, SyncDirection::class),
            provider: $this->enumValue($input['provider'] ?? null, CrmProviderName::class),
            status: $this->enumValue($input['status'] ?? null, SyncEventStatus::class),
            eventType: $this->nullableString($input['event_type'] ?? null),
        );
    }

    /**
     * Builds bounded dead-letter criteria from plain request input.
     *
     * @param array<string, mixed> $input
     *
     * @return DeadLetterFilterDTO
     */
    public function mapToDeadLetterFilterDTO(array $input): DeadLetterFilterDTO
    {
        $replayed = $input['replayed'] ?? null;

        if ($replayed !== null && ! is_bool($replayed)) {
            throw new InvalidArgumentException('The dead-letter replayed filter must be boolean or null.');
        }

        return new DeadLetterFilterDTO(
            perPage: SyncReadRules::clampPerPage($this->integerValue($input['per_page'] ?? null, SyncReadRules::DEFAULT_PER_PAGE)),
            page: max(1, $this->integerValue($input['page'] ?? null, 1)),
            replayed: $replayed,
        );
    }

    /**
     * Parses an optional enum-backed filter.
     *
     * @param mixed $value
     * @param class-string<SyncDirection|CrmProviderName|SyncEventStatus> $enumClass
     *
     * @return string|null
     */
    private function enumValue(mixed $value, string $enumClass): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || $enumClass::tryFrom($value) === null) {
            throw new InvalidArgumentException('A sync filter contains an unsupported value.');
        }

        return $value;
    }

    /**
     * Converts blank text to null and rejects other types.
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
            throw new InvalidArgumentException('A sync filter text must be a string or null.');
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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
