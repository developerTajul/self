<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\DTOs\Requests;

use Core\CrmSync\Domain\Enums\CrmProviderName;

/**
 * Carries one verified webhook and its immutable provider event identity.
 */
final readonly class WebhookEnqueueDTO
{
    /**
     * @param CrmProviderName       $provider
     * @param string                $eventType
     * @param string                $remoteId
     * @param array<string, mixed>  $payload
     * @param string                $source
     * @param string                $externalId
     */
    public function __construct(
        public CrmProviderName $provider,
        public string $eventType,
        public string $remoteId,
        public array $payload,
        public string $source,
        public string $externalId,
    ) {}
}
