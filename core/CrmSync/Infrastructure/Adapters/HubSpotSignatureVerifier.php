<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Adapters;

use Core\CrmSync\Application\Contracts\WebhookSignatureVerifierContract;
use Psr\Log\LoggerInterface;

/**
 * Verifies a HubSpot webhook's `X-HubSpot-Signature-v3` header.
 *
 * HubSpot signs `method + uri + body + timestamp` with the app's client secret
 * and sends the timestamp beside the signature. Verification is two checks that
 * must BOTH hold: the signature matches in constant time, and the timestamp is
 * inside a five-minute window. Either one alone is insufficient — a valid
 * signature with no freshness check is a captured request that can be replayed
 * forever, and a fresh timestamp with no signature check is no check at all.
 *
 * @implements WebhookSignatureVerifierContract
 */
final class HubSpotSignatureVerifier implements WebhookSignatureVerifierContract
{
    /** The header carrying the hex signature. */
    private const SIGNATURE_HEADER = 'x-hubspot-signature-v3';

    /** The header carrying the request's unix-seconds timestamp. */
    private const TIMESTAMP_HEADER = 'x-hubspot-request-timestamp';

    /** The header carrying the HTTP method the signature covers. */
    private const METHOD_HEADER = 'x-hubspot-request-method';

    /**
     * How far from now a timestamp may be, in seconds.
     *
     * Five minutes each way. It has to exceed the worst clock skew between
     * HubSpot and this server plus the delivery delay, or genuine webhooks would
     * be rejected during a slow retry; and it has to be short enough that a
     * captured request is useless by the time an attacker can replay it.
     */
    private const MAX_TIMESTAMP_AGE_SECONDS = 300;

    /**
     * How long the HMAC input may be, in bytes.
     *
     * A guard against a hostile body being hashed before it is rejected: HMAC
     * input is cheap but not free, and a webhook endpoint is unauthenticated
     * until this check passes.
     */
    private const MAX_SIGNED_BODY_BYTES = 1048576;

    /**
     * @param string          $clientSecret The HubSpot app's client secret.
     * @param string          $requestUri   The absolute URL HubSpot posted to, as it signed it.
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly string $clientSecret,
        private readonly string $requestUri,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Whether a webhook is authentic and fresh.
     *
     * @param string                $rawBody The request body exactly as received.
     * @param array<string, string> $headers The request headers, keyed case-insensitively.
     *
     * @return bool True only when the signature is valid, current and known.
     */
    public function verify(string $rawBody, array $headers): bool
    {
        if ($this->clientSecret === '') {
            // No secret configured means no webhook can be trusted, and saying so
            // is better than comparing against a secret that does not exist —
            // an empty secret would make every signature forgeable by anyone who
            // reads this file.
            $this->logger->error('A HubSpot webhook arrived but no client secret is configured, so it was rejected.');

            return false;
        }

        if (strlen($rawBody) > self::MAX_SIGNED_BODY_BYTES) {
            $this->logger->warning('A HubSpot webhook was rejected for exceeding the signed-body size limit.', [
                'bytes' => strlen($rawBody),
            ]);

            return false;
        }

        $headers = $this->normalizeHeaders($headers);

        $signature = $headers[self::SIGNATURE_HEADER] ?? '';
        $timestamp = $headers[self::TIMESTAMP_HEADER] ?? '';
        $method = strtoupper($headers[self::METHOD_HEADER] ?? 'POST');

        if ($signature === '' || $timestamp === '') {
            $this->logger->warning('A HubSpot webhook was rejected: it carries no signature or no timestamp.');

            return false;
        }

        if (! $this->isFresh($timestamp)) {
            $this->logger->warning('A HubSpot webhook was rejected for being outside the freshness window.', [
                'timestamp' => $timestamp,
            ]);

            return false;
        }

        $expected = $this->expectedSignature($method, $timestamp, $rawBody);

        // `hash_equals()` compares in constant time. `===` would stop at the
        // first differing byte, and the time that takes is enough to recover a
        // signature byte by byte across repeated requests.
        if (! hash_equals($expected, strtolower($signature))) {
            $this->logger->warning('A HubSpot webhook was rejected: the signature did not match.');

            return false;
        }

        return true;
    }

    /**
     * Whether a request's timestamp is inside the freshness window.
     *
     * Both directions are checked: a timestamp far in the past is a replay, and
     * one far in the future is a forgery attempt or a badly skewed clock — either
     * way it is not a request sent now.
     *
     * @param string $timestamp Unix seconds, as sent.
     *
     * @return bool
     */
    private function isFresh(string $timestamp): bool
    {
        // A non-numeric or non-integer timestamp is refused rather than coerced:
        // `(int) 'abc'` is 0, which is decades outside the window, so the
        // coercion would reject it anyway — but only by accident, and a value
        // like `1e9` or `0x10` would coerce to something plausible.
        if (! preg_match('/^\d{1,10}$/', $timestamp)) {
            return false;
        }

        $sentAt = (int) $timestamp;
        $now = time();

        return abs($now - $sentAt) <= self::MAX_TIMESTAMP_AGE_SECONDS;
    }

    /**
     * The signature HubSpot should have sent for this request.
     *
     * The signed string is `method + uri + body + timestamp`, concatenated with
     * no separators — that is HubSpot's own construction, and the reason the raw
     * body must be used rather than a decoded array: re-encoding a decoded array
     * produces different bytes and therefore a different signature.
     *
     * @param string $method    Upper-case HTTP method.
     * @param string $timestamp Unix seconds as sent.
     * @param string $rawBody
     *
     * @return string Lower-case hex.
     */
    private function expectedSignature(string $method, string $timestamp, string $rawBody): string
    {
        $signed = $method . $this->requestUri . $rawBody . $timestamp;

        return hash_hmac('sha256', $signed, $this->clientSecret);
    }

    /**
     * Header names lower-cased, so lookup does not depend on the sender's casing.
     *
     * HTTP header names are case-insensitive, and different servers and proxies
     * pass them through with differing capitalization, so a lookup keyed on one
     * exact spelling would reject genuine webhooks depending on the stack in
     * front of it.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_string($value) ? $value : '';
        }

        return $normalized;
    }
}
