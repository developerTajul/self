<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\ValueObjects;

use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Exceptions\InvalidRemoteIdException;
use Stringable;

/**
 * One remote record's identifier, canonical for its provider.
 *
 * The identifier has to be canonical before it is stored, because the column it
 * lands in uses a case-insensitive collation: two ids that differ only in case
 * would collide on the unique index while comparing unequal in PHP, so a
 * provider whose 15-character id is case-sensitive (Salesforce) is expanded to
 * its 18-character case-insensitive form up front. Everything downstream then
 * compares with `===` on the canonical string and gets the answer the database
 * would give.
 */
final readonly class RemoteId implements Stringable
{
    /**
     * The length of a Salesforce id before its case-safe checksum is appended.
     */
    private const SALESFORCE_SHORT_LENGTH = 15;

    /**
     * The length of a Salesforce id once the checksum is appended.
     */
    private const SALESFORCE_LONG_LENGTH = 18;

    /**
     * The longest identifier the `crm_record_id` column can hold.
     */
    public const MAX_LENGTH = 100;

    /**
     * @param string $value The canonical identifier for the provider.
     */
    private function __construct(
        public string $value,
    ) {}

    /**
     * Reads an identifier from a provider's response.
     *
     * @param string           $value
     * @param CrmProviderName  $provider Decides the canonical form the id must take.
     *
     * @return self
     *
     * @throws InvalidRemoteIdException When the value is blank, too long, or cannot be canonicalized.
     */
    public static function fromString(string $value, CrmProviderName $provider): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidRemoteIdException::empty();
        }

        $canonical = self::canonicalize($trimmed, $provider);

        if (mb_strlen($canonical) > self::MAX_LENGTH) {
            throw InvalidRemoteIdException::tooLong(mb_strlen($canonical));
        }

        return new self($canonical);
    }

    /**
     * Rebuilds an identifier already stored in canonical form.
     *
     * Used by the EntityMapper, where the value came from the column that only
     * ever holds a canonical id, so re-deriving it would be wasted work.
     *
     * @param string $value
     *
     * @return self
     *
     * @throws InvalidRemoteIdException When the stored value is blank or too long.
     */
    public static function fromCanonical(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidRemoteIdException::empty();
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidRemoteIdException::tooLong(mb_strlen($value));
        }

        return new self($value);
    }

    /**
     * Whether two identifiers denote the same remote record.
     *
     * Strict comparison on the canonical string. Loose or case-insensitive
     * comparison would disagree with the unique index; strict comparison is
     * what makes the two agree.
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
     * The identifier as stored.
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
     * Rewrites an id into the canonical form its provider requires.
     *
     * A Salesforce id arrives in one of two shapes: the 15-character form, whose
     * characters are case-sensitive and therefore unsafe in a case-insensitive
     * column, and the 18-character form, which appends a three-character
     * checksum that encodes each character's case so the 18-character form is
     * safe under any collation. A 15-character alphanumeric id is therefore
     * expanded, and an 18-character id is left alone. Every other provider's id
     * is kept as sent, because nothing about it is known to need rewriting.
     *
     * @param string          $value
     * @param CrmProviderName $provider
     *
     * @return string
     *
     * @throws InvalidRemoteIdException When a Salesforce-shaped id carries characters the algorithm cannot encode.
     */
    private static function canonicalize(string $value, CrmProviderName $provider): string
    {
        // The checksum is a Salesforce concept, and the id shapes it recognises
        // are what select it: any 15-character alphanumeric id is expanded,
        // whatever provider passed it, because leaving it short would make it
        // unsafe in the case-insensitive column. HubSpot ids are numeric and
        // take neither branch.
        $length = strlen($value);
        $isSalesforceShaped = ctype_alnum($value)
            && ($length === self::SALESFORCE_SHORT_LENGTH || $length === self::SALESFORCE_LONG_LENGTH);

        if (! $isSalesforceShaped || $length === self::SALESFORCE_LONG_LENGTH) {
            return $value;
        }

        return $value . self::salesforceChecksum($value);
    }

    /**
     * The three-character checksum Salesforce appends to a 15-character id.
     *
     * The id is split into three five-character chunks. For each chunk, a bit is
     * set for every character that is an uppercase letter or a digit, giving a
     * five-bit number; that number selects a character from the suffix alphabet.
     * The result reproduces the id's case information in a form a
     * case-insensitive collation cannot destroy — which is the entire purpose of
     * the 18-character id.
     *
     * @param string $shortId A 15-character alphanumeric id.
     *
     * @return string Three characters.
     */
    private static function salesforceChecksum(string $shortId): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345';
        $checksum = '';

        for ($chunk = 0; $chunk < 3; $chunk++) {
            $bits = 0;

            for ($index = 0; $index < 5; $index++) {
                $character = $shortId[$chunk * 5 + $index];

                // Uppercase letters and digits set a bit; lowercase do not.
                // This is the whole point of the encoding: it records which
                // characters were uppercase, so the id survives a collation
                // that would otherwise fold case away.
                if (ctype_upper($character) || ctype_digit($character)) {
                    $bits |= 1 << (4 - $index);
                }
            }

            $checksum .= $alphabet[$bits];
        }

        return $checksum;
    }
}
