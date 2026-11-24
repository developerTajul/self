<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Contracts;

/**
 * PORT: verification of an inbound webhook's authenticity.
 *
 * Called in DELIVERY against the raw body and the request headers before any
 * Service runs, so an unverified payload never reaches the Domain. The raw body
 * is required rather than a decoded array: the signature is computed over the
 * exact bytes that arrived, and re-encoding a decoded array would produce a
 * different byte sequence and therefore a false rejection.
 */
interface WebhookSignatureVerifierContract
{
    /**
     * Whether a webhook is authentic and fresh.
     *
     * Compares the signature in constant time and rejects a timestamp outside
     * its window, so a captured request cannot be replayed later.
     *
     * @param string                $rawBody  The request body exactly as received.
     * @param array<string, string> $headers  The request headers, keyed case-insensitively.
     *
     * @return bool True only when the signature is valid, current and known.
     */
    public function verify(string $rawBody, array $headers): bool;
}
