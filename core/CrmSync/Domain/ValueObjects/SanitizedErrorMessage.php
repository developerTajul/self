<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\ValueObjects;

use Stringable;

/**
 * An upstream failure message with every credential removed.
 *
 * A dead letter is read by an administrator and, on the developer's machine,
 * often pasted into a bug report — so the message kept for a failed sync is the
 * one place a leaked token is most likely to travel. CRM error responses very
 * regularly quote the request that failed, headers and all, which is how an
 * `Authorization: Bearer ...` value ends up in a log. Scrubbing therefore
 * happens before the message is stored, not when it is displayed, because by
 * display time it has already been copied somewhere.
 *
 * Nothing here needs to be reversible: a truncated, credential-free message
 * still says what failed, and that is all a dead letter is for.
 */
final readonly class SanitizedErrorMessage implements Stringable
{
    /**
     * The cap on a stored message.
     *
     * `error_message` is a `mediumText` (16 MiB), so the column is not the
     * limit — the point is that an upstream response can be arbitrarily large
     * (a full HTML error page is routine) while what an operator needs is the
     * first paragraph. 4000 characters holds a stack of API error JSON and
     * still fits comfortably in an admin table cell.
     */
    public const MAX_LENGTH = 4000;

    /**
     * The text written in place of a redacted credential.
     */
    private const REDACTION = '[redacted]';

    /**
     * Header and parameter names whose value is always a credential.
     *
     * Matched case-insensitively before a `:` or `=`, so
     * `Authorization: Bearer x`, `"api_key": "x"` and `access_token=x` are all
     * caught. The list holds the names the CRMs this release talks to use,
     * plus the generic ones any HTTP error body may quote.
     */
    private const SECRET_NAMES = [
        'authorization',
        'proxy-authorization',
        'api[-_]?key',
        'apikey',
        'access[-_]?token',
        'refresh[-_]?token',
        'auth[-_]?token',
        'id[-_]?token',
        'bearer',
        'client[-_]?secret',
        'client[-_]?id',
        'private[-_]?key',
        'secret',
        'password',
        'passwd',
        'session[-_]?id',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-auth-token',
    ];

    /**
     * @param string $value The scrubbed, truncated message.
     */
    private function __construct(
        public string $value,
    ) {}

    /**
     * Scrubs a raw upstream message.
     *
     * Order matters: credentials are removed first and truncation happens last,
     * because truncating first could cut a credential in half — leaving a
     * partial secret that no pattern would then match.
     *
     * @param string $message The raw message, straight from the transport.
     *
     * @return self
     */
    public static function fromString(string $message): self
    {
        $scrubbed = self::redactQuerySecrets($message);
        $scrubbed = self::redactNamedSecrets($scrubbed);
        $scrubbed = self::redactBearerTokens($scrubbed);
        $scrubbed = self::redactUrlCredentials($scrubbed);

        return new self(self::truncate(trim($scrubbed)));
    }

    /**
     * Builds a message that says nothing was recorded.
     *
     * Used when the upstream failure carried no readable text at all, so a
     * dead-letter row never holds an empty string that reads as a bug.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self('The provider reported a failure without a message.');
    }

    /**
     * Whether two messages are the same text.
     *
     * @param self $other
     *
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Whether scrubbing left nothing worth storing.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    /**
     * The message as stored.
     *
     * @return string
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Blanks the value of any query-string parameter whose name is a secret.
     *
     * Covers the `?access_token=...&foo=bar` shape, which appears both in a
     * URL quoted by an error body and in a `Payload:` line, and is the commonest
     * way a token reaches a log without a header ever being printed.
     *
     * @param string $message
     *
     * @return string
     */
    private static function redactQuerySecrets(string $message): string
    {
        $names = implode('|', self::SECRET_NAMES);

        return (string) preg_replace(
            '/([?&](?:' . $names . ')=)[^&\s"\'>]*/i',
            '$1' . self::REDACTION,
            $message
        );
    }

    /**
     * Blanks the value after a secret-looking name and its separator.
     *
     * Handles `Name: value` (a header, or a JSON key with a colon),
     * `"name": "value"` (JSON) and `name=value` (a form body or a config dump).
     * The value runs to the end of the line, the closing quote or the next
     * separator, whichever comes first.
     *
     * @param string $message
     *
     * @return string
     */
    private static function redactNamedSecrets(string $message): string
    {
        $names = implode('|', self::SECRET_NAMES);

        // JSON and header form: the name, an optional closing quote, then the
        // separator, then the value.
        $message = (string) preg_replace(
            '/(["\']?(?:' . $names . ')["\']?\s*[:=]\s*["\']?)([^"\'\r\n,;&]+)/i',
            '$1' . self::REDACTION,
            $message
        );

        // A bare `Bearer <token>`, with no header name in front of it.
        return (string) preg_replace(
            '/(Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/i',
            '$1' . self::REDACTION,
            $message
        );
    }

    /**
     * Blanks any standalone bearer token that survived the named pass.
     *
     * A token printed with no label is still recognisable by its shape, so two
     * shapes are caught: a JWT (three base64url segments) and a long opaque run.
     *
     * The opaque run must look like a token rather than merely be long —
     * it has to mix character classes (an uppercase, a lowercase and a digit
     * somewhere) before it is redacted. Length alone would swallow anything
     * unbroken and lengthy, which in a CRM error body is as likely to be a
     * record id, a URL path segment or a quoted stack frame as it is to be a
     * secret, and redacting those removes the diagnostic value the message
     * exists to carry. A real token — a random base64 or hex string — mixes
     * classes essentially always.
     *
     * @param string $message
     *
     * @return string
     */
    private static function redactBearerTokens(string $message): string
    {
        // A JWT is three base64url segments. The `eyJ` prefix is the base64 of
        // `{"`, so every JWT header starts with it — a strong enough signal to
        // redact on shape alone.
        $message = (string) preg_replace(
            '/\beyJ[A-Za-z0-9\-_]*\.[A-Za-z0-9\-_]*\.[A-Za-z0-9\-_]*/',
            self::REDACTION,
            $message
        );

        // A long run that mixes case and digits.
        return (string) preg_replace_callback(
            '/\b(?=[A-Za-z0-9\-_]*[a-z])(?=[A-Za-z0-9\-_]*[A-Z])(?=[A-Za-z0-9\-_]*\d)[A-Za-z0-9\-_]{32,}\b/',
            static fn (): string => self::REDACTION,
            $message
        );
    }

    /**
     * Blanks credentials embedded in a URL's user-info section.
     *
     * A URL like `https://user:token@api.example.com/` carries a credential
     * before the host, where none of the other patterns look.
     *
     * @param string $message
     *
     * @return string
     */
    private static function redactUrlCredentials(string $message): string
    {
        return (string) preg_replace(
            '#([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s@]+@#i',
            '$1' . self::REDACTION . '@',
            $message
        );
    }

    /**
     * Caps the message at the length a dead letter should hold.
     *
     * Multi-byte safe, so a truncated message never ends mid-character and
     * becomes invalid UTF-8 — which would fail the insert under strict mode.
     * A visible marker is appended, so a reader can tell truncation from a
     * complete short message.
     *
     * @param string $message
     *
     * @return string
     */
    private static function truncate(string $message): string
    {
        if (mb_strlen($message) <= self::MAX_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::MAX_LENGTH) . '…[truncated]';
    }
}
