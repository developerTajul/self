<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Contracts;

use Core\Lead\Domain\Entities\LeadEntity;

/**
 * PORT: persistence for leads (write side).
 *
 * Exchanges whole Entities, never rows, arrays or DTOs: a Service that received
 * a row would be holding a persistence shape, and the mapper that owns the
 * column mapping lives in Infrastructure precisely so it does not leak upward.
 *
 * Declares no pure read. The one read here — {@see findByIdForUpdate()} — exists
 * because a write needs it: the row must be locked before its next state is
 * computed, or two writers would each act on a state the other is about to
 * replace. Listings and lookups belong on the read port.
 */
interface LeadRepositoryContract
{
    /**
     * Inserts a lead that has no id yet.
     *
     * @param LeadEntity $lead
     *
     * @return LeadEntity The stored lead, carrying its new id and the timestamps the database holds.
     */
    public function create(LeadEntity $lead): LeadEntity;

    /**
     * Writes a stored lead's current state back to its row.
     *
     * @param LeadEntity $lead
     *
     * @return LeadEntity The stored lead.
     */
    public function update(LeadEntity $lead): LeadEntity;

    /**
     * Deletes a lead.
     *
     * @param int $leadId
     *
     * @return void
     */
    public function delete(int $leadId): void;

    /**
     * Reads a lead while holding a row lock, to be called inside a transaction.
     *
     * @param int $leadId
     *
     * @return LeadEntity|null Null when no lead carries the id.
     */
    public function findByIdForUpdate(int $leadId): ?LeadEntity;
}
