<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Contracts;

use Core\CrmSync\Application\DTOs\Requests\DeadLetterFilterDTO;
use Core\CrmSync\Application\DTOs\Requests\SyncEventFilterDTO;
use Core\CrmSync\Application\DTOs\Requests\WebhookEnqueueDTO;

/**
 * PORT: turns a plain verified webhook submission into a sync request DTO.
 *
 * Delivery injects this contract, never the concrete mapper, so a controller
 * depends on the module's declared surface rather than its internals. The
 * mapper's signature takes a plain array rather than a framework Request, so
 * the mapper stays usable by a listener, a console command and a test alike.
 */
interface SyncMapperContract
{
    /**
     * Builds the enqueue DTO from verified webhook input.
     *
     * The caller has already verified the signature (DP-5); this method only
     * shapes the fields, normalizing blanks to null so the ledger's unique
     * idempotency key is computed from values rather than from their absence
     * being spelled two ways.
     *
     * @param array<string, mixed> $input The verified request body and its routing facts.
     *
     * @return WebhookEnqueueDTO
     */
    public function mapToWebhookEnqueueDTO(array $input): WebhookEnqueueDTO;

    /**
     * Builds bounded sync-audit criteria from plain request input.
     *
     * @param array<string, mixed> $input
     *
     * @return SyncEventFilterDTO
     */
    public function mapToSyncEventFilterDTO(array $input): SyncEventFilterDTO;

    /**
     * Builds bounded dead-letter criteria from plain request input.
     *
     * @param array<string, mixed> $input
     *
     * @return DeadLetterFilterDTO
     */
    public function mapToDeadLetterFilterDTO(array $input): DeadLetterFilterDTO;
}
