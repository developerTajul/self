<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\ValueObjects\LeadFilter;
use Core\Shared\Domain\DataObjects\PaginatedResult;

/**
 * Read side of the lead port.
 *
 * Carries no method that can change a row, and its implementations extend
 * `BaseReadRepository`, which inherits none — so the write/read split holds by
 * inheritance rather than by discipline.
 *
 * The two lookups take already-normalized values: a caller reaching this port
 * has been through `EmailAddress`/`PhoneNumber`, and the stored column holds the
 * canonical form, so re-normalizing here would be wasted work and a second place
 * for the rule to drift.
 */
interface LeadQueryContract
{
    /**
     * Reads one lead by id.
     *
     * @param int $leadId
     *
     * @return LeadEntity|null Null when no lead carries the id.
     */
    public function findById(int $leadId): ?LeadEntity;

    /**
     * Reads the lead that holds its immutable public UUID.
     *
     * @param string $uuid
     *
     * @return LeadEntity|null Null when no lead carries the UUID.
     */
    public function findByUuid(string $uuid): ?LeadEntity;

    /**
     * Reads the lead that holds a normalized email address.
     *
     * @param string $email Normalized (trimmed, lowercased).
     *
     * @return LeadEntity|null Null when no lead carries the address.
     */
    public function findByEmail(string $email): ?LeadEntity;

    /**
     * Reads the lead that holds a normalized E.164 phone number.
     *
     * @param string $phone Normalized to E.164.
     *
     * @return LeadEntity|null Null when no lead carries the number.
     */
    public function findByPhone(string $phone): ?LeadEntity;

    /**
     * Returns one page of leads matching the filter.
     *
     * Items are read-model projections, not Entities: a list does not need
     * behaviour, and hydrating Entities to render a table would pay for
     * invariants no one asks about.
     *
     * @param LeadFilter $filter
     *
     * @return PaginatedResult Items are `LeadProjectionDTO` instances.
     */
    public function getFilteredLeads(LeadFilter $filter): PaginatedResult;
}
