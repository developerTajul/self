<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Adapters;

use Core\CrmSync\Application\Contracts\CrmContactGatewayContract;
use Core\CrmSync\Application\Contracts\CrmProviderRegistryContract;
use Core\CrmSync\Application\Contracts\WebhookSignatureVerifierContract;
use Core\CrmSync\Domain\Enums\CrmProviderName;

/**
 * Resolves the configured first-release HubSpot gateway and verifier.
 *
 * @implements CrmProviderRegistryContract
 */
final class HubSpotProviderRegistry implements CrmProviderRegistryContract
{
    /**
     * @param CrmContactGatewayContract $gateway
     * @param WebhookSignatureVerifierContract $verifier
     */
    public function __construct(
        private readonly CrmContactGatewayContract $gateway,
        private readonly WebhookSignatureVerifierContract $verifier,
    ) {}

    /**
     * Returns the gateway for the supported provider.
     *
     * @param CrmProviderName $provider
     *
     * @return CrmContactGatewayContract
     */
    public function gatewayFor(CrmProviderName $provider): CrmContactGatewayContract
    {
        return $this->gateway;
    }

    /**
     * Returns the webhook verifier for the supported provider.
     *
     * @param CrmProviderName $provider
     *
     * @return WebhookSignatureVerifierContract
     */
    public function verifierFor(CrmProviderName $provider): WebhookSignatureVerifierContract
    {
        return $this->verifier;
    }
}
