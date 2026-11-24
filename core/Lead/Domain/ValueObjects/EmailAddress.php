<?php

declare(strict_types=1);

namespace Core\Lead\Domain\ValueObjects;

use Core\Lead\Domain\Exceptions\InvalidEmailAddressException;
use Stringable;

/**
 * A validated, normalized email address.
 *
 * Two leads that differ only in the case or the surrounding whitespace of an
 * address are the same lead, so normalization happens once here and every
 * comparison downstream — a unique-index lookup, a merge — is made against the
 * canonical form. The stored value is lowercased and trimmed.
 */
final readonly class EmailAddress implements Stringable
{
    /**
     * The longest address the `leads.email` column can hold.
     *
     * Enforced here rather than left to the database: a value that passes
     * validation and then fails the column's length is a write that throws at
     * the wrong layer, after the domain has already called the address valid.
     */
    public const MAX_LENGTH = 191;

    /**
     * @param string $value The normalized address: trimmed and lowercased.
     */
    private function __construct(
        public string $value,
    ) {}

    /**
     * Reads an address from raw input, rejecting one that is not an email.
     *
     * @param string $value
     *
     * @return self
     *
     * @throws InvalidEmailAddressException When the value is not a valid address or is too long.
     */
    public static function fromString(string $value): self
    {
        $normalized = mb_strtolower(trim($value));

        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidEmailAddressException::invalidFormat($value);
        }

        $length = mb_strlen($normalized);

        if ($length > self::MAX_LENGTH) {
            throw InvalidEmailAddressException::tooLong($length);
        }

        return new self($normalized);
    }

    /**
     * Reads an address from a field that may be absent or blank.
     *
     * A blank string and a run of whitespace both mean "no address given", not
     * "an invalid address": partial-contact sources send them constantly, and
     * the unique index treats every blank as the same absent value. Only a
     * non-blank value that fails validation is an error.
     *
     * @param string|null $value
     *
     * @return self|null Null when the input is null, empty or whitespace only.
     *
     * @throws InvalidEmailAddressException When a non-blank value is not a valid address.
     */
    public static function fromNullable(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::fromString($value);
    }

    /**
     * Whether two addresses denote the same mailbox.
     *
     * Comparison is on the normalized value, so it agrees with the
     * case-insensitive unique index the storage layer enforces.
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
     * The address as stored.
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
}
