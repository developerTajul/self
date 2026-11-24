<?php

declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

/**
 * PORT: generation of the non-sequential public identifier an entity carries.
 *
 * The identifier is made outside the Entity and passed in, so the Domain never
 * reaches for a random source of its own.
 */
interface UuidGeneratorContract
{
    /**
     * Returns a version-4 UUID in canonical 36-character form.
     *
     * @return string
     */
    public function generate(): string;
}
