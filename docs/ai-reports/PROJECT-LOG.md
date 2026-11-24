# Project log

One line per completed step. Statuses: `DONE` | `DONE_WITH_NOTES` | `PARTIAL` | `BLOCKED` | `FAILED`.

> **Fresh trail (2026-11-23).** The previous `PROJECT-LOG.md` (entries to `Review/02`, commit
> `065ab4e`) was purged from the working tree with the rest of `docs/ai-reports/` before this plan
> started. The log restarts here. Report numbering **continues** the project sequence at `22`
> (reports `01`–`21` remain readable in git history); step reports live in `docs/ai-reports/`.
> Active plan: `docs/ai-plans/lead-module-action-plan.md`.

| Step | Title | Status | Decisions | Report |
| --- | --- | --- | --- | --- |
| `Lead/0.1` | Kernel and framework audit, decision recording (read-only) | DONE_WITH_NOTES | 27 | `docs/ai-reports/22-lead-kernel-framework-audit.md` |
| `Lead/0.2` | Scaffold module `Lead` (FULL-BASELINE) + `UuidGeneratorContract`/`WpUuidGenerator` + `ClockContract`/`WpClock` | DONE_WITH_NOTES | 34 | `docs/ai-reports/23-lead-module-scaffold.md` |
| `Lead/0.3` | Scaffold module `CrmSync` (FULL-BASELINE) + `CrmSyncServiceProvider` + the two cross-module Lead adapters | DONE_WITH_NOTES | 36 | `docs/ai-reports/24-crmsync-module-scaffold.md` |
| `Lead/1.1` | Lead Value Objects `EmailAddress` + `PhoneNumber` (normalization, `fromNullable()`, `equals()`, exceptions) | DONE_WITH_NOTES | 38 | `docs/ai-reports/25-lead-value-objects.md` |
| `Lead/1.2` | `LeadEntity` behaviour `capture()` + `enrichWith()` + `absorb()` (immutable, pure, A2/S5 rules) | DONE_WITH_NOTES | 39 | `docs/ai-reports/26-lead-entity-behaviour.md` |
| `Lead/1.3` | CrmSync Value Objects `RemoteId` + `ContentHash` + `SanitizedErrorMessage` (canonicalization, SHA-256, credential scrubbing) | DONE_WITH_NOTES | 41 | `docs/ai-reports/27-crmsync-value-objects.md` |
| `Lead/1.4` | `SyncEventEntity` transitions (claim/retry/reap/dead) + `SyncRetryRules` retry bounds | DONE_WITH_NOTES | 43 | `docs/ai-reports/28-syncevent-transitions.md` |
| `Lead/1.5` | `CrmMappingEntity` (link/recordSync/isEcho/relinkTo) + `DeadLetterEntity` (fromDeadEvent/markReplayed) | DONE | 43 | `docs/ai-reports/29-crmsync-entities.md` |
| `Lead/1.6` | Ports for both modules (write / read / I-O / service contracts) + `LeadFilter`/`LeadRules`/projections | DONE_WITH_NOTES | 45 | `docs/ai-reports/30-ports-contracts.md` |
| `Lead/2.1` | Lead write + read repositories and `LeadEntityMapper` | DONE | 45 | `docs/ai-reports/31-lead-repositories.md` |
| `Lead/2.2` | SyncEvent write repository (atomic claim, reaper) + `SyncEventEntityMapper` | DONE_WITH_NOTES | 46 | `docs/ai-reports/32-syncevent-repositories.md` |
| `Lead/2.3` | CrmMapping + DeadLetter repositories and mappers (both UNIQUE indexes, DLQ replay) | DONE | 46 | `docs/ai-reports/33-crmmapping-deadletter-repositories.md` |
| `Lead/2.4` | Cross-module adapters: `LeadCaptureOutboxAdapter` (transactional outbox) + `LeadMappingRelinkAdapter` (A4) | DONE_WITH_NOTES | 47 | `docs/ai-reports/34-cross-module-adapters.md` |
| `Lead/2.5` | HubSpot gateway adapter (`wp_remote_request`, 429/5xx transient) + `X-HubSpot-Signature-v3` verifier | DONE_WITH_NOTES | 48 | `docs/ai-reports/35-hubspot-adapter.md` |
| `Lead/3.1` | `LeadCommandService::captureLead` (create/enrich/merge, S5 order, transactional outbox, one duplicate retry) | DONE_WITH_NOTES | 48 | `docs/ai-reports/36-lead-capture.md` |
| `Lead/3.2` | `SyncCommandService::processOutboundBatch` (claim → CRM outside the transaction → per-event record, mapping + hash) | DONE_WITH_NOTES | 51 | `docs/ai-reports/37-sync-outbound-batch.md` |
| `Lead/3.3` | `SyncCommandService::enqueueInboundWebhook` (A7: verified DTO, DP-6 key, inbound ledger row, duplicate = no-op) | DONE_WITH_NOTES | 51 | `docs/ai-reports/38-webhook-enqueue.md` |
| `Lead/3.4` | `SyncCommandService::processInboundEvent` (remote id, echo suppression, Lead-owned enrichment port; `D-048` closed) | DONE_WITH_NOTES | 53 | `docs/ai-reports/39-inbound-processing.md` |
| `Lead/3.5` | Reaper sweep (D-043/D-046) + one-shot DLQ replay + bounded exhausted-row read | DONE_WITH_NOTES | 54 | `docs/ai-reports/40-reaper-and-replay.md` |
| `Lead/3.6` | Query services: leads list, sync audit, dead-letter inspector (read ports only, clamped filters) | DONE_WITH_NOTES | 55 | `docs/ai-reports/41-query-services.md` |
| `Lead/3.x` | Phase 3 closeout: wiring parity, 104-file `php -l`, 126-check structural harness, 8 defects repaired | DONE_WITH_NOTES | 55 | `docs/ai-reports/42-phase-3-closeout.md` |
