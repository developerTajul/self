<?php

declare(strict_types=1);

namespace Core\CrmSync\Application\Contracts;

use Core\CrmSync\Application\DTOs\Requests\WebhookEnqueueDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles CRM sync write use cases.
 *
 * The worker, the reaper and the webhook controller all enter through this one
 * contract, so no caller needs the concrete Service or a second inbound port.
 * Every method returns a `Result` — a batch pass, an enqueue, a replay and a
 * reaper sweep each have ordinary failure outcomes the delivery layer renders.
 */
interface SyncCommandServiceContract
{
    /**
     * Processes one batch of due outbound events.
     *
     * Claims in its own transaction, calls the CRM OUTSIDE any transaction, then
     * records each outcome in a short transaction. The split is what keeps an
     * HTTP call from holding a row lock.
     *
     * @return Result Carries the counts processed, succeeded, retried and dead.
     */
    public function processOutboundBatch(): Result;

    /**
     * Enqueues an already-verified inbound webhook for background processing.
     *
     * Returns as soon as the ledger row is written, so the HTTP response is
     * fast. A duplicate idempotency key is a successful no-op, not an error:
     * a provider that redelivers the same webhook must not create a second
     * event.
     *
     * @param WebhookEnqueueDTO $webhookEnqueueDTO
     *
     * @return Result Carries the ledger entry's id.
     */
    public function enqueueInboundWebhook(WebhookEnqueueDTO $webhookEnqueueDTO): Result;

    /**
     * Processes one claimed inbound event.
     *
     * @param int $syncEventId
     *
     * @return Result
     */
    public function processInboundEvent(int $syncEventId): Result;

    /**
     * Releases the leases of events abandoned by a dead worker.
     *
     * An event that has no attempts left cannot be released, so it is
     * dead-lettered by this pass instead — the reaper is the only thing left
     * looking at it (D-043).
     *
     * @return Result Carries the counts released and dead-lettered.
     */
    public function releaseStaleLeases(): Result;

    /**
     * Replays a dead letter once.
     *
     * Creates a fresh event carrying the stored payload and records the replay
     * trail. A dead letter that has already been replayed is refused (S4).
     *
     * @param int $deadLetterId
     *
     * @return Result Carries the new event's id.
     */
    public function replayDeadLetter(int $deadLetterId): Result;
}
