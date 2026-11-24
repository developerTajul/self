<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Contracts;

use Core\CrmSync\Domain\Enums\CrmProviderName;

/**
 * PORT: resolution of the adapters a provider name selects.
 *
 * The worker knows only a `CrmProviderName` — it comes from a ledger row, which
 * stores the provider as a string. This port turns that name into the adapter
 * that talks to that CRM, so adding a provider adds a binding rather than a
 * branch inside Core.
 */
interface CrmProviderRegistryContract
{
    /**
     * Returns the gateway for a provider.
     *
     * @param CrmProviderName $provider
     *
     * @return CrmContactGatewayContract
     */
    public function gatewayFor(CrmProviderName $provider): CrmContactGatewayContract;

    /**
     * Returns the webhook verifier for a provider.
     *
     * @param CrmProviderName $provider
     *
     * @return WebhookSignatureVerifierContract
     */
    public function verifierFor(CrmProviderName $provider): WebhookSignatureVerifierContract;
}
