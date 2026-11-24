<?php

declare(strict_types=1);

namespace Core\Lead\Domain\ValueObjects;

use Core\Lead\Domain\Exceptions\InvalidPhoneNumberException;
use Stringable;

/**
 * A validated phone number, normalized to E.164.
 *
 * E.164 is the only form in which two numbers from different sources can be
 * compared at all: the same line arrives as `(555) 010-0199`, `555.010.0199`
 * and `+15550100199`. Normalizing once here means the unique index, the CRM
 * payload and every merge decision all agree on what "the same number" is.
 */
final readonly class PhoneNumber implements Stringable
{
    /**
     * The longest number E.164 permits, excluding the leading `+`.
     *
     * The country code plus the subscriber number cannot exceed 15 digits;
     * the channel has no user extension and could carry none anyway, because
     * the form is fixed-length and unambiguous.
     */
    public const MAX_DIGITS = 15;

    /**
     * The shortest number that can carry a country code and a subscriber line.
     *
     * A single-digit number has no country code, so it cannot be E.164.
     */
    public const MIN_DIGITS = 2;

    /**
     * The longest value the `leads.phone` column can hold.
     */
    public const MAX_LENGTH = 50;

    /**
     * @param string $value The canonical form: `+` followed by digits, no separators.
     */
    private function __construct(
        public string $value,
    ) {}

    /**
     * Reads a number from raw input, rejecting one that cannot be E.164.
     *
     * Separators people actually type — spaces, dashes, dots, parentheses and
     * a leading `00` international prefix — are removed or rewritten, because
     * they carry no meaning. What survives must still be `+` followed by a
     * country code and a subscriber number, so a number with no international
     * prefix is rejected rather than guessed at: assuming a country would
     * silently invent a wrong number for every foreign contact.
     *
     * @param string $value
     *
     * @return self
     *
     * @throws InvalidPhoneNumberException When the value is not a normalizable number.
     */
    public static function fromString(string $value): self
    {
        $normalized = self::normalize($value);

        if (! self::isValid($normalized)) {
            throw InvalidPhoneNumberException::invalidFormat($value);
        }

        return new self($normalized);
    }

    /**
     * Reads a number from a field that may be absent or blank.
     *
     * A blank string and a run of whitespace both mean "no number given", not
     * "an invalid number": partial-contact sources send them constantly, and
     * the unique index treats every blank as the same absent value. Only a
     * non-blank value that fails validation is an error.
     *
     * @param string|null $value
     *
     * @return self|null Null when the input is null, empty or whitespace only.
     *
     * @throws InvalidPhoneNumberException When a non-blank value is not a normalizable number.
     */
    public static function fromNullable(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::fromString($value);
    }

    /**
     * Whether two numbers denote the same line.
     *
     * Comparison is on the canonical E.164 string, so it agrees with the
     * unique index the storage layer enforces.
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
     * The number without its leading `+`.
     *
     * Provided for callers that need the bare digits a CRM API expects in its
     * own field shape, rather than re-deriving them from the canonical form.
     *
     * @return string
     */
    public function digits(): string
    {
        return substr($this->value, 1);
    }

    /**
     * The number as stored.
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
     * Rewrites a human-entered number into its canonical E.164 form.
     *
     * Deliberately permissive about punctuation and strict about structure:
     * the shape is checked afterwards by {@see isValid()}, so this step only
     * removes what cannot be significant.
     *
     * @param string $value
     *
     * @return string
     */
    private static function normalize(string $value): string
    {
        $working = trim($value);

        // A `+` is meaningful only where it leads, so a second one is a
        // malformed input rather than punctuation to discard. Collapsing
        // `++1555` into a valid `+1555` would accept a number the caller never
        // wrote, so the value is returned untouched and validation rejects it.
        if (substr_count($working, '+') > 1) {
            return $working;
        }

        // A leading `00` is the international access prefix most of the world
        // dials; E.164 writes the same number with a leading `+`.
        if (str_starts_with($working, '00')) {
            $working = '+' . substr($working, 2);
        }

        $hasPlus = str_starts_with($working, '+');
        $digits = preg_replace('/\D+/', '', $working) ?? '';

        return $hasPlus ? '+' . $digits : $digits;
    }

    /**
     * Whether a normalized value is a well-formed E.164 number.
     *
     * Enforces the `+` structural marker the schema's own note requires, the
     * digit-count bounds E.164 defines, and the phone column's length.
     *
     * @param string $normalized
     *
     * @return bool
     */
    private static function isValid(string $normalized): bool
    {
        if (! preg_match('/^\+[1-9]\d*$/', $normalized)) {
            return false;
        }

        $digits = strlen($normalized) - 1;

        if ($digits < self::MIN_DIGITS || $digits > self::MAX_DIGITS) {
            return false;
        }

        return mb_strlen($normalized) <= self::MAX_LENGTH;
    }
}
