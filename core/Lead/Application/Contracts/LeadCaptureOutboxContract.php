<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Domain\Entities\LeadEntity;

/**
 * PORT: records a lead's capture as an outbound sync event.
 *
 * The lead module owns this port but not its implementation: the adapter lives
 * in `core/CrmSync/Infrastructure` and writes the `sync_events` row inside the
 * caller's transaction, so a captured lead and its outbound event commit or
 * roll back together. That is what makes the ledger a transactional outbox
 * rather than a second queue that can drift from the leads it describes.
 *
 * The port takes the persisted Entity, not an id or an array: the payload is
 * built from the lead's own state, and a caller assembling that map would be
 * holding a business decision about what a CRM is told.
 */
interface LeadCaptureOutboxContract
{
    /**
     * Enqueues the outbound event owed to the CRM for a captured lead.
     *
     * Must be called inside the transaction that persists the lead. A second
     * call for the same lead and attempt is idempotent by the ledger's unique
     * idempotency key, so a retried capture does not queue two events.
     *
     * @param LeadEntity $lead The persisted lead, carrying its id.
     *
     * @return void
     */
    public function enqueueForCapturedLead(LeadEntity $lead): void;
}
