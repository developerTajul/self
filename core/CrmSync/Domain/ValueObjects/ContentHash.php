<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\ValueObjects;

use Core\CrmSync\Domain\Exceptions\InvalidContentHashException;
use JsonException;
use Stringable;

/**
 * The SHA-256 digest of a record's canonical normalized state.
 *
 * The hash answers one question: "is the state the CRM just told us about the
 * same state we last sent it?" If the answer is yes, the inbound webhook is an
 * echo of our own write and must not be applied — otherwise every push would
 * come back as a change and the two systems would loop forever.
 *
 * That question is only answerable if the same logical state always produces
 * the same digest, so the input is canonicalized first: keys are sorted
 * recursively, so a payload assembled in a different order still hashes the
 * same. Two states that differ only in key order are the same state.
 */
final readonly class ContentHash implements Stringable
{
    /**
     * The length of a SHA-256 digest in hexadecimal.
     *
     * Also the width of the `crm_mappings.last_synced_hash` column, which the
     * digest has to fit exactly.
     */
    public const LENGTH = 64;

    /**
     * @param string $value The lowercase hexadecimal digest.
     */
    private function __construct(
        public string $value,
    ) {}

    /**
     * Digests a record's state.
     *
     * @param array<string, mixed> $state The normalized state to hash.
     *
     * @return self
     *
     * @throws InvalidContentHashException When the state cannot be encoded for hashing.
     */
    public static function fromArray(array $state): self
    {
        $canonical = self::canonicalize($state);

        try {
            $encoded = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            // A state carrying a resource, a closure or malformed UTF-8 cannot
            // be hashed. Letting the encoder's failure surface as a type error
            // would report it as a programming fault rather than as the data
            // problem it is.
            throw InvalidContentHashException::notEncodable($exception->getMessage());
        }

        return new self(hash('sha256', $encoded));
    }

    /**
     * Rebuilds a digest already stored in hexadecimal form.
     *
     * Used by the EntityMapper, where the value came from the column. The
     * length and alphabet are still checked, so a corrupted row is reported
     * rather than trusted.
     *
     * @param string $value
     *
     * @return self
     *
     * @throws InvalidContentHashException When the value is not a SHA-256 digest.
     */
    public static function fromString(string $value): self
    {
        $trimmed = strtolower(trim($value));

        if (! preg_match('/^[0-9a-f]{64}$/', $trimmed)) {
            throw InvalidContentHashException::malformed($value);
        }

        return new self($trimmed);
    }

    /**
     * Whether two digests denote the same state.
     *
     * Values are already canonical lowercase hex, so a strict comparison is
     * both correct and constant-time-free of surprises.
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
     * The digest as stored.
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
     * Sorts a state's keys recursively so its encoding is order-independent.
     *
     * PHP preserves insertion order, so `['b' => 1, 'a' => 2]` and
     * `['a' => 2, 'b' => 1]` encode differently while denoting the same state.
     * Sorting by key — at every depth, because a nested object has the same
     * problem — makes the encoding depend on the state alone.
     *
     * A list keeps its order, because a list's order *is* part of its value:
     * reordering `[1, 2]` to `[2, 1]` changes what it means, unlike reordering
     * an associative array's keys.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private static function canonicalize(array $state): array
    {
        if (array_is_list($state)) {
            return array_map(
                static fn (mixed $value): mixed => is_array($value) ? self::canonicalize($value) : $value,
                $state
            );
        }

        ksort($state);

        foreach ($state as $key => $value) {
            if (is_array($value)) {
                $state[$key] = self::canonicalize($value);
            }
        }

        return $state;
    }
}
