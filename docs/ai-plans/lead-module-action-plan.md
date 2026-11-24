# LeadBridge Multi-CRM Integration: Execution Plan (schema-verified, CONTINUOUS PHASE/MODULE EXECUTION)

**Project:** LeadBridge CRM Integration Plugin (`wp-mini-laravel` architecture)
**Paradigm:** Hexagonal Architecture, Domain-Driven Design with a **Rich Domain Model**, CQS
**Schema:** verified against the four migrations supplied by the developer (`leads`, `crm_mappings`, `sync_events`, `dead_letters`). Section 3 lists the facts this plan relies on.
**Target:** a production-ready multi-CRM WordPress plugin with bi-directional lead sync, an idempotent outbox queue and a recoverable Dead Letter Queue (DLQ).

> This plan is READ-ONLY for the agent (09 A2.5). Progress lives in `docs/ai-reports/PROJECT-LOG.md` and the step reports, never in this file.
> All work follows the **Rich Domain Model**: rules, invariants and state transitions live in Entities and Value Objects; Services only orchestrate. (AGENTS.md does not state this explicitly, so it is restated here on purpose.)

---

## 0. EXECUTION PROTOCOL: CONTINUOUS BY REQUESTED RANGE (binding for this plan)

1. **Requested Range Execution:** The developer specifies the execution scope—either a single step (`Do step 1.2`), an entire Phase (`Execute Phase 2`), a full Module (`Build module Lead`), or `"Continue the plan"` (which means **all remaining steps of the current active Phase or Module**).
2. **No Per-Step Stopping:** The agent executes every step inside the requested range sequentially in a continuous loop (`S0 -> S1 -> S2 -> S3 -> S4`), writing each step's report and updating `PROJECT-LOG.md` and `DECISIONS.md` along the way, **without stopping between steps**.
3. **When to Stop (STOP GATE):** The agent stops ONLY when:
   - The entire requested range (Phase / Module / Plan) is completed, OR
   - An **A10 HARD STOP (`H1`–`H13`)** occurs, OR
   - A `PARTIAL`, `BLOCKED`, or `FAILED` step occurs (which blocks subsequent dependent steps), OR
   - A critical architectural ambiguity requires an explicit developer decision not covered by A9.
4. At the end of the requested range (or on a halt), the agent writes a concise **STOP GATE** summary listing all completed steps, their report paths, any new `DECISIONS.md` entries, and the exact next Phase/Step to resume from.
5. **Context Hygiene:** Keep step reports concise and factual so multi-step Phase runs stay well within context limits.
6. Tests are paused (AGENTS §6): no tests, no `tests/`, no test claims. Verification is by `php -l`, the 06 T8 greps, the Definition of Done walk and (where a step says so) a manual check on the developer's running local site.
7. Before any step: AGENTS §0 (read the last 3 `DECISIONS.md` entries and the latest `PROJECT-LOG.md` entry, run the git tracking guard) and the FILE EXISTENCE GUARD for every file to create.
8. Schema is never guessed. Every column an Entity, mapper or repository uses must exist in `database/migrations`. A missing column is HALT H1, not an invention. No migration is created or edited by any step of this plan.
9. No existing Shared base class or contract is changed (H6). Additive Shared contracts and adapters are allowed (AGENTS A4).

### Standard step brief fields

`STEP ID | MODE | GOAL | INPUTS | RULES | CREATE | ACCEPTANCE | OUT OF SCOPE | HANDOFF`

---

## 1. ARCHITECTURAL VISION

LeadBridge connects WordPress ingestion sources (Contact Form 7, WooCommerce, external webhooks) with CRMs (HubSpot first; Salesforce, Zoho later) without coupling core rules to WordPress hooks or vendor SDKs.

| Zone                                             | Namespace | Contains                                                                                                            |
| ------------------------------------------------ | --------- | ------------------------------------------------------------------------------------------------------------------- |
| `core/Lead`, `core/CrmSync` Domain + Application | `Core\`   | Entities, VOs, enums, contracts, DTOs, Services. No WordPress, no `Framework\`, no `App\`. FROZEN when porting.     |
| `core/*/Infrastructure`                          | `Core\`   | `Wp...` repositories (`$wpdb` via `BaseWriteRepository` / `BaseReadRepository`), CRM HTTP adapters, entity mappers. |
| `app/`, `config/`, `routes/`, `views/`           | `App\`    | CF7 / WooCommerce listeners, REST webhook controller, admin controllers, providers, worker schedules.               |

---

## 2. APPROVED DECISIONS (final, from the developer)

| ID  | Decision                                                                                                                                                                                                                      |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A1  | **Enums.** `LeadStatus`: `new`, `contacted`, `qualified`, `converted`, `lost`. `LeadSource`: `contact_form_7`, `woocommerce`, `hubspot`, `manual`. `SyncEventStatus`: `pending`, `processing`, `succeeded`, `dead`.           |
| A2  | **Contact key rule.** `LeadEntity::capture()` requires at least one of `email` or `phone`; otherwise it throws a domain exception (no duplicate detection is possible without one).                                           |
| A3  | **UUID.** Additive Shared port `UuidGeneratorContract` (`core/Shared/Domain/Contracts`) + adapter `WpUuidGenerator` (`wp_generate_uuid4()`), bound once in `AppServiceProvider`. The Entity receives the uuid as a parameter. |
| A4  | **Merge mapping conflict.** The primary lead's mapping stays; the secondary lead's conflicting mapping is deleted and the removal is logged.                                                                                  |
| A5  | **Lead reference on events.** `sync_events` has no lead column; `payload_json` carries `entity_type` and `local_entity_id`.                                                                                                   |
| A6  | **`dead_letters.job_class`.** Domain constants `SyncEventEntity::HANDLER_OUTBOUND` and `HANDLER_INBOUND`.                                                                                                                     |
| A7  | **Inbound webhooks.** The controller verifies the signature and idempotency, enqueues a `sync_events` row (direction inbound) and returns `200` fast. The background worker does the rest.                                    |

### Confirmed Architectural Decisions (recorded as `D-NNN` in `DECISIONS.md`)

| ID   | Proposal                                                             | Confirmed Decision                                                                                                                                                        |
| ---- | -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DP-1 | `Lead` capture enqueues the outbound event without importing CrmSync | `Lead` owns port `LeadCaptureOutboxContract`; its adapter lives in `core/CrmSync/Infrastructure` and writes `sync_events` in the SAME transaction (transactional outbox). |
| DP-2 | Cross-lead merge re-links `crm_mappings`                             | `Lead` owns port `LeadMappingRelinkContract`; adapter lives in `core/CrmSync/Infrastructure`.                                                                             |
| DP-3 | Outbox vs `QueueManagerContract`                                     | The DB outbox is the durable queue. Action Scheduler / WP-Cron only TRIGGERS the worker and reaper; no second job system.                                                 |
| DP-5 | Webhook authenticity                                                 | Verified in DELIVERY before any Service runs (raw body + headers, constant-time compare, timestamp window).                                                               |
| DP-6 | Idempotency key                                                      | `sha256(source + ':' + external submission/order/event id)` = 64 hex chars (fits the `varchar(191)` unique column). Never derived from mutable lead fields.               |
| DP-7 | Backoff jitter                                                       | The Entity accepts jitter seconds as a parameter.                                                                                                                         |
| DP-8 | First release scope                                                  | HubSpot adapter + CF7 + WooCommerce only.                                                                                                                                 |
| DP-9 | Enum values not covered by A1                                        | `SyncDirection`: `outbound`, `inbound`. `CrmProviderName`: `hubspot`. `LocalEntityType`: `lead`, `order`. `CrmObjectType`: `contact`, `deal`.                             |

(DP-4 is resolved by the schema: `sync_events.payload_json` is a NOT NULL snapshot, so no `LeadSnapshotContract` is needed.)

---

## 3. SCHEMA FACTS THIS PLAN DEPENDS ON (from the migrations)

| Table          | Facts that shape the design                                                                                                                                                                                                                                                                                                                                                                           |
| -------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `leads`        | `uuid` varchar(36) unique (the HubSpot `leadbridge_external_id`); `name`, `email`(191, unique), `phone`(50, unique), `company_name`, `website_url`, `assigned_to` (FK to `users`, null on delete), `meta_json` longText nullable are all nullable; `status` default `new`; `source` NOT NULL; `created_at` / `updated_at` nullable UTC; index `leads_status_source_idx`.                              |
| `crm_mappings` | Local key `(entity_type, local_entity_id, provider, crm_object_type)` unique; remote key **`(provider, crm_object_type, crm_record_id)`** unique (three parts); `last_synced_hash` varchar(64) nullable; `last_synced_at` nullable.                                                                                                                                                                   |
| `sync_events`  | `idempotency_key` varchar(191) unique; `direction`, `provider`, `event_type`, `payload_json` NOT NULL; `status` default `pending`; **`attempts` is `unsignedTinyInteger` (0-255)**; `next_attempt_at` NOT NULL; `locked_at`, `processed_at`, `last_error`(mediumText) nullable. **There is NO `max_attempts` column and NO lead reference column.** It is the ledger for outbound AND inbound events. |
| `dead_letters` | `sync_event_id` nullable unique FK; `replayed_sync_event_id` nullable FK; `provider`, `job_class`(255) NOT NULL; `payload_json` NOT NULL; `error_message` mediumText NOT NULL; `http_status_code` nullable; `failed_at` NOT NULL; `retried_at` nullable. No `created_at` / `updated_at`.                                                                                                              |

Derived rules:

- **S1** The retry ceiling is a Domain constant (`SyncRetryRules::MAX_ATTEMPTS`), kept far below 255 so reaper increments cannot overflow the tinyint.
- **S2** `CrmMappingEntity` carries `entityType`, `localEntityId`, `provider`, `crmObjectType`, `RemoteId`; lookups by remote record use the three-part key.
- **S3** Inbound events are written to `sync_events` (direction `inbound`) by the webhook step (A7).
- **S4** The DLQ keeps the FULL `payload_json` (replay needs it). Only `error_message` is scrubbed of tokens and Authorization values. A dead letter can be replayed ONCE: `retried_at` set means a second replay is rejected by the Entity.
- **S5** Merge order respects both UNIQUE indexes: lock both leads in ascending id order, delete the secondary FIRST, then fill the primary's blanks. A duplicate-key failure on concurrent capture is retried once from the lookup.
- **S6** `assigned_to` references the WordPress `users` table and `dead_letters` FKs reference `sync_events`: Step 0.1 verifies the Schema builder applies the table prefix and `ENGINE=InnoDB`.
- **S7** `LeadEntity` holds `array $meta`; the EntityMapper encodes and decodes `meta_json` (invalid JSON in storage raises a mapper `\RuntimeException`, never a silent empty array).

---

## 4. TARGET TREE (names are final; paths per 03)

```
core/Shared/Domain/Contracts/UuidGeneratorContract.php        (additive, A3)
core/Shared/Infrastructure/Utilities/WpUuidGenerator.php      (additive, A3)
core/Lead/
  Domain/{Entities/LeadEntity.php, Enums/{LeadSource,LeadStatus}.php, ValueObjects/{EmailAddress,PhoneNumber}.php,
          Contracts/LeadRepositoryContract.php (WRITE), Exceptions/{LeadNotFoundException,LeadContactKeyMissingException,InvalidEmailAddressException,InvalidPhoneNumberException}.php}
  Application/{Contracts/{LeadQueryContract (READ), LeadCommandServiceContract, LeadQueryServiceContract, LeadMapperContract,
                          LeadCaptureOutboxContract, LeadMappingRelinkContract}.php,
               DTOs/Requests/LeadCaptureDTO.php, DTOs/Responses/LeadResponseDTO.php,
               Mappers/{LeadMapper,LeadResponseMapper}.php, Services/{LeadCommandService,LeadQueryService}.php}
  Infrastructure/{Repositories/{WpLeadWriteRepository,WpLeadReadRepository}.php, Mappers/LeadEntityMapper.php}
core/CrmSync/
  Domain/{Entities/{SyncEventEntity,CrmMappingEntity,DeadLetterEntity}.php,
          Enums/{SyncDirection,SyncEventStatus,CrmProviderName,CrmObjectType,LocalEntityType}.php,
          ValueObjects/{RemoteId,ContentHash,SanitizedErrorMessage}.php, Rules/SyncRetryRules.php,
          Contracts/{SyncEventRepositoryContract,CrmMappingRepositoryContract,DeadLetterRepositoryContract}.php (WRITE)}
  Application/{Contracts/{SyncEventQueryContract,CrmMappingQueryContract,DeadLetterQueryContract (READ),
                          CrmContactGatewayContract,WebhookSignatureVerifierContract,CrmProviderRegistryContract,
                          SyncCommandServiceContract,SyncQueryServiceContract,SyncMapperContract}.php, DTOs, Mappers, Services}
  Infrastructure/{Repositories/Wp{SyncEvent,CrmMapping,DeadLetter}{Write,Read}Repository.php, Mappers/, Adapters/HubSpotAdapter.php,
                  Adapters/{LeadCaptureOutboxAdapter,LeadMappingRelinkAdapter}.php}
app/Providers/{LeadServiceProvider,CrmSyncServiceProvider}.php   (shared ports stay in AppServiceProvider)
app/Integrations/{ContactForm7,WooCommerce}/ , app/Http/Controllers/{Api/WebhookController,Admin/...}.php
```

---

## 5. THE STEPS (28 steps — execute continuously across requested Phase/Module)

### PHASE 0: PRECONDITIONS AND SCAFFOLD

#### STEP 0.1 Kernel and framework audit, decision recording (READ-ONLY)

- **MODE:** analysis only. **GOAL:** prove the Shared kernel and the framework Schema builder support this plan; record every decision.
- **INPUTS:** `database/migrations/*` (already supplied), `core/Shared/**`, `framework/Database/Schema`, `framework/Routing`, `config/app.php`, `docs/ai-reports/*`.
- **DO:**
  1. Confirm presence and exact method names of: `Result`, `PaginatedResult`, `TransactionManagerContract`, `EventDispatcherContract`, `QueueManagerContract`, `TranslatorContract` (`translate()` or `get()`), `ClockContract`, the `LoggerInterface` binding, `AbstractWpRepository`, `BaseWriteRepository`, `BaseReadRepository`, `WpQueryExceptionTranslator`, `DuplicateKeyException`, `SlugGeneratorContract` (not needed here, report only).
  2. Verify S6 by READING the Schema builder: table prefix applied to foreign-key targets, `ENGINE=InnoDB` emitted, `foreignId()->constrained()` target resolution for `users` and `sync_events`. Report FOUND / MISSING with `path:line`.
  3. Check whether `UuidGeneratorContract` or any uuid utility already exists (A3).
  4. Record A1-A7, DP-1..DP-9 and S1-S7 in `DECISIONS.md` as `D-NNN`.
- **ACCEPTANCE:** a report with the kernel checklist, the Schema-builder findings and the decision entries. No code, no migration, no file under `core/` or `app/`.
- **OUT OF SCOPE:** any edit to `framework/`. A MISSING framework capability is reported as HALT H12, never patched.
- **HANDOFF:** proceed to 0.2 (or pause only if an open kernel decision requires developer input).

#### STEP 0.2 Scaffold module `Lead` (03, FULL-BASELINE) + `UuidGeneratorContract`

- **MODE:** CORE-PURE, then ADAPTER, then DELIVERY wiring (in that order). **RULES:** 01, 02, 03, 06.
- **CREATE:** `UuidGeneratorContract` + `WpUuidGenerator` (fully implemented, bound once in `AppServiceProvider`); the full `core/Lead/` tree of section 4 as empty skeletons with `// TODO:`; `LeadServiceProvider` listed in `config/app.php`.
- **FULLY IMPLEMENTED (mechanical):** `LeadEntity` (promoted readonly constructor: `?int $id`, `string $uuid`, `?string $name`, `?string $email`... typed per the schema and S7, enums `LeadStatus` / `LeadSource` with `translationKey()` and `values()` from A1). Behaviour methods stay `// TODO:`.
- **ACCEPTANCE:** `php -l` clean per file; `composer dump-autoload`; the four Lead ports + two service contracts + `LeadMapperContract` bound once in `LeadServiceProvider`; no `Domain/Events`, no `Application/Jobs`.
- **OUT OF SCOPE:** business logic, DTOs, request mapper methods, service methods.
- **HANDOFF:** proceed immediately to 0.3.

#### STEP 0.3 Scaffold module `CrmSync` (03, FULL-BASELINE)

- Same shape as 0.2 for `core/CrmSync/` and `CrmSyncServiceProvider`. The three Entities and the five enums (A1, DP-9) are fully mapped from the schema (S1, S2, S4); transitions stay `// TODO:`. `SyncEventEntity` declares the constants `HANDLER_OUTBOUND` / `HANDLER_INBOUND` (A6).
- `LeadCaptureOutboxAdapter` and `LeadMappingRelinkAdapter` are scaffolded as skeletons implementing the Lead contracts, bound in `CrmSyncServiceProvider`.
- **ACCEPTANCE:** as 0.2. **HANDOFF:** Phase 0 complete; proceed to 1.1 if in requested range.

---

### PHASE 1: CORE-PURE DOMAIN (execute 1.1 -> 1.6 continuously)

#### STEP 1.1 Lead Value Objects

- `EmailAddress` (trim, lowercase, validate; `fromNullable(?string): ?self` turns `''` / whitespace into `null`), `PhoneNumber` (E.164 normalization, same `fromNullable`), domain exceptions.
- **ACCEPTANCE:** readonly, validated in the named constructor, compared by value, zero framework symbols (T8 A zero), no hardcoded user-facing text. **HANDOFF:** proceed immediately to 1.2.

#### STEP 1.2 `LeadEntity` behaviour

- Each method returns a NEW instance; time and uuid are passed in.
- `capture(string $uuid, ?EmailAddress $email, ?PhoneNumber $phone, ..., LeadSource $source, array $meta, DateTimeImmutable $now)`: throws `LeadContactKeyMissingException` when both email and phone are null (A2); status starts `new`.
- `enrichWith(...)`: non-destructive; existing non-null fields win, blanks are filled, `meta` merged, `updatedAt` set.
- `absorb(LeadEntity $secondary, DateTimeImmutable $now)`: the cross-lead merge rule (S5): primary retained, blanks (including email / phone) filled from the secondary, meta merged.
- Status transitions between the A1 values only where the developer's brief states a rule; none are invented (A9). Add `$domainEvents` + `releaseEvents()` only if a method raises an event (08 E3).
- **ACCEPTANCE:** no setter, no mutation, no clock call, no DTO import; T8 A + B zero. **HANDOFF:** proceed immediately to 1.3.

#### STEP 1.3 CrmSync `RemoteId`, `ContentHash`, `SanitizedErrorMessage`

- `RemoteId`: provider-aware canonicalization (Salesforce 15 to 18 char), strict `===` on the canonical string (migration note). `ContentHash`: SHA-256 over canonical key-sorted state. `SanitizedErrorMessage`: strips tokens, `Authorization` / bearer values, query-string secrets and caps length (S4).
- **ACCEPTANCE:** pure PHP, readonly, no I/O. **HANDOFF:** proceed immediately to 1.4.

#### STEP 1.4 `SyncEventEntity` transitions

- Fields per schema (payload as `array`, containing `entity_type` and `local_entity_id` per A5). NEW instance each; `$now` and jitter passed in; limits from `SyncRetryRules` (`MAX_ATTEMPTS` well below 255, backoff base/cap, lease timeout; pure `backoffSeconds(int $attempts): int`).
- `enqueue(...)` (`next_attempt_at = $now`), `claim($now)`, `markSucceeded($now)`, `scheduleRetry($now, $jitterSeconds)`, `releaseStaleLock($now)` (processing to pending AND `attempts + 1`), `isExhausted()`, `markDead($now, SanitizedErrorMessage)`. Illegal transitions throw `SyncEvent<Reason>Exception`.
- **ACCEPTANCE:** every transition rule lives here; `attempts` can never pass the ceiling. **HANDOFF:** proceed immediately to 1.5.

#### STEP 1.5 `CrmMappingEntity` and `DeadLetterEntity`

- `CrmMappingEntity`: `link(...)`, `recordSync(ContentHash, $now)`, `isEcho(ContentHash): bool`, `relinkTo(int $leadId)`.
- `DeadLetterEntity`: `fromDeadEvent(...)` (full payload, scrubbed message, `job_class` = handler constant per A6, `failed_at`), `markReplayed(int $newSyncEventId, $now)` throws `DeadLetterAlreadyReplayedException` when `retried_at` is already set (S4).
- **HANDOFF:** proceed immediately to 1.6.

#### STEP 1.6 Ports (contracts) for both modules

- WRITE ports (`Domain/Contracts/`): Entities in and out; only state changes + `findByIdForUpdate()`. `SyncEventRepositoryContract` also declares `claimDueEvents(DateTimeImmutable $now, int $batchSize): array` and `releaseStaleLeases(...)` as state-changing operations.
- READ ports (`Application/Contracts/`): `LeadQueryContract` (lookup by normalized email and phone, filtered pages), `SyncEventQueryContract`, `CrmMappingQueryContract` (lookup by the three-part remote key), `DeadLetterQueryContract`.
- I/O ports: `CrmContactGatewayContract`, `WebhookSignatureVerifierContract`, `CrmProviderRegistryContract`, `LeadCaptureOutboxContract`, `LeadMappingRelinkContract`.
- **ACCEPTANCE:** no pure read on a write port; no WordPress type in any signature; names describe the domain operation. **HANDOFF:** Phase 1 complete; proceed to 2.1 if in requested range.

---

### PHASE 2: INFRASTRUCTURE ADAPTERS (`$wpdb`, `Wp` prefix — execute 2.1 -> 2.5 continuously)

> Whole phase: SQL only through the base helpers and adapter code; every value prepared; identifiers are constants; UTC; `ARRAY_A` rows cast explicitly in the EntityMapper; duplicate key only via `BaseWriteRepository::duplicateException()`; no change to an existing Shared base class. Where a base helper does not fit, the adapter uses the existing protected `db()` / `executeQuery()`.

#### STEP 2.1 Lead write + read repositories and `LeadEntityMapper`

- Write: `create`, `update`, `delete`, `findByIdForUpdate`. Read: `findById`, `findByEmail`, `findByPhone` (normalized), filtered paginated projections using `leads_status_source_idx`.
- **ACCEPTANCE:** `toEntity()` casts every column explicitly and decodes `meta_json` (S7); `null` is stored as SQL `NULL`; a UNIQUE collision becomes the module's conflict exception. **HANDOFF:** proceed immediately to 2.2.

#### STEP 2.2 SyncEvent write + read repositories (atomic claim, reaper)

- **CLAIM:** candidate ids by the pure range scan (`status = 'pending' AND next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT ?`), then per id ONE conditional `UPDATE ... SET status='processing', locked_at=? WHERE id=? AND status='pending'`; keep the row only if affected rows is exactly 1. Applies to both directions. The claim is its own short `run()` that commits BEFORE any HTTP call.
- **REAPER:** inside a transaction, lock a bounded batch of stale `processing` rows (`locked_at < ?`) with `FOR UPDATE`, call `releaseStaleLock()` on each, persist (catching exhausted events to dead-letter per D-043).
- **ACCEPTANCE:** no SELECT-then-blind-UPDATE; transition rules are not re-implemented in SQL; `attempts` ceiling respected. **HANDOFF:** proceed immediately to 2.3.

#### STEP 2.3 CrmMapping + DeadLetter repositories

- CrmMapping: lookup by the three-part remote key and the four-part local key, hash persistence, both UNIQUE indexes honoured.
- DeadLetter: `mediumText` message (already scrubbed by the Entity), `sync_event_id` unique, replay columns per schema; no `created_at` / `updated_at` columns are written.
- **HANDOFF:** proceed immediately to 2.4.

#### STEP 2.4 Cross-module adapters

- `LeadCaptureOutboxAdapter`: inserts the `sync_events` row (payload includes `entity_type` and `local_entity_id`) inside the caller's transaction; the unique idempotency key makes a replay a duplicate-key conflict exception.
- `LeadMappingRelinkAdapter`: re-links the secondary's mappings to the primary; on a UNIQUE collision keeps the primary's mapping, deletes the secondary's conflicting one and logs it (A4). No transaction is opened here.
- **HANDOFF:** proceed immediately to 2.5.

#### STEP 2.5 HubSpot adapter and signature verifier

- `HubSpotAdapter implements CrmContactGatewayContract` (Contacts / Deals v3; `lead.uuid` sent as `leadbridge_external_id`), `HubSpotSignatureVerifier implements WebhookSignatureVerifierContract` (`X-HubSpot-Signature-v3`, constant-time compare, 5-minute timestamp window).
- HTTP via `wp_remote_request` with timeouts; 429 / 5xx map to a transient exception subtype, other 4xx to a permanent one. Secrets arrive as scalars by contextual binding (D2, D8) and are never logged.
- **PREREQUISITE (developer):** the custom property `leadbridge_external_id` exists in HubSpot.
- **HANDOFF:** Phase 2 complete; proceed to 3.1 if in requested range.

---

### PHASE 3: APPLICATION SERVICES (CQS, `Result` + ResponseDTO — execute 3.1 -> 3.6 continuously)

#### STEP 3.1 `LeadCommandService::captureLead`

- **CREATE:** `LeadCaptureDTO` (plain readonly: contact fields, `source`, `meta`, `idempotencyKey`), `LeadMapper` (plain array to DTO, empty-to-null), `LeadResponseDTO`, `LeadResponseMapper`.
- **FLOW (one `executeInTransaction()`):** build VOs; look up by email and phone; none → `capture()` with a uuid from `UuidGeneratorContract`; one → `enrichWith()`; two distinct → lock both ascending, `absorb()`, relink mappings, delete the secondary FIRST, then persist the primary (S5). Persist, then enqueue the outbound event via `LeadCaptureOutboxContract` using the persisted lead id. A duplicate idempotency key returns success with the existing lead. A duplicate-key failure on a concurrent capture is retried once from the lookup.
- **ACCEPTANCE:** no business rule in the Service; domain exceptions become `Result::failure` with domain keys; no persistence exception imported. **HANDOFF:** proceed immediately to 3.2.

#### STEP 3.2 `SyncCommandService::processOutboundBatch`

- **FLOW:** (a) claim in its own transaction; (b) OUTSIDE any transaction call the gateway per event; (c) per event a short transaction: success → `markSucceeded` + `CrmMappingEntity` link/`recordSync`; transient failure → `scheduleRetry`; permanent failure or exhausted → `markDead` + `DeadLetterEntity`. Events dispatched after commit only.
- **ACCEPTANCE:** no HTTP inside a transaction; a crash after the claim is recovered by the reaper; no swallowed Throwable after a write. **HANDOFF:** proceed immediately to 3.3.

#### STEP 3.3 `SyncCommandService::enqueueInboundWebhook` (A7)

- Input is an already verified DTO (DP-5). Compute the idempotency key (DP-6), enqueue a `sync_events` row (direction inbound, handler `HANDLER_INBOUND`) in one transaction. A duplicate key is a successful no-op. Returns a `Result` the controller turns into `200`.
- **HANDOFF:** proceed immediately to 3.4.

#### STEP 3.4 `SyncCommandService::processInboundEvent`

- Worker side for claimed inbound events: normalize `RemoteId`, compute `ContentHash`, `CrmMappingEntity::isEcho()` true → mark the event succeeded without touching the lead; otherwise apply the change through `LeadEntity::enrichWith()` (via Lead's application contract/port, keeping CrmSync decoupled from Lead internals) and record the hash. Failures follow the same retry / dead path as 3.2.
- **HANDOFF:** proceed immediately to 3.5.

#### STEP 3.5 Reaper command and DLQ replay

- `releaseStaleLeases()` for the scheduled reaper (catching `SyncEventTransitionException` when `attempts` reaches `MAX_ATTEMPTS` and moving the event to `dead_letters` per D-043). `replayDeadLetter(int $deadLetterId)`: lock the dead letter, create a NEW `SyncEventEntity` with a fresh idempotency key and the stored payload, `markReplayed()` (single replay, S4), one transaction.
- **HANDOFF:** proceed immediately to 3.6.

#### STEP 3.6 Query services

- `LeadQueryService` and `SyncQueryService` (leads list, sync audit log, DLQ list); filter DTOs clamped through `<Module>Rules`; projections; read ports only.
- **HANDOFF:** Phase 3 complete; proceed to 4.1 if in requested range.

---

### PHASE 4: DELIVERY (`app/` — execute 4.1 -> 4.5 continuously)

#### STEP 4.1 Provider wiring and config

- Finish bindings in the two module providers; shared ports in `AppServiceProvider`; scalars (API base URL, timeouts, batch size, webhook secret) via `config/app.php` and contextual binding. `register()` binds only; hooks go in `boot()`. **HANDOFF:** proceed immediately to 4.2.

#### STEP 4.2 Contact Form 7 listener

- `Cf7SubmissionListener` (`wpcf7_mail_sent`) + `Cf7PayloadMapper` producing a plain array, then `LeadMapperContract`, then ONE service call. Any failure is caught and logged; the visitor's submission is never interrupted. **HANDOFF:** proceed immediately to 4.3.

#### STEP 4.3 WooCommerce listener

- `WcOrderCompletedListener` (`woocommerce_order_status_completed`) + `WcOrderPayloadMapper`, same isolation; idempotency key from the order id (DP-6). **HANDOFF:** proceed immediately to 4.4.

#### STEP 4.4 HubSpot webhook endpoint

- `POST /wp-json/leadbridge/v1/webhooks/hubspot` through the `Route` facade. The controller verifies the signature on the RAW body, then calls `enqueueInboundWebhook` and returns `200` (also for a duplicate); failures return generic codes with no detail. **HANDOFF:** proceed immediately to 4.5.

#### STEP 4.5 Worker and reaper schedule

- Action Scheduler if present, else WP-Cron, registered in provider `boot()`; handlers resolved through `$container->call()`. One worker processes both directions; per-run batch cap and runtime budget. **HANDOFF:** Phase 4 complete; proceed to 5.1 if in requested range.

---

### PHASE 5: ADMIN AND CLOSE-OUT (execute 5.1 -> 5.3 continuously)

#### STEP 5.1 Leads list screen

- Controller + view; filters by source / status; output escaped; capability + nonce before the Service. **HANDOFF:** proceed immediately to 5.2.

#### STEP 5.2 Sync audit log and DLQ screen with Replay

- Audit list, DLQ inspector, one-click Replay (POST, nonce + capability) calling `replayDeadLetter`. Lead filtering inside the audit log is limited to what `payload_json` allows (A5); no new index or column. **HANDOFF:** proceed immediately to 5.3.

#### STEP 5.3 Final audit and module summaries

- Run the 06 T8 greps (A and B) and report real output; walk every applicable Definition of Done; write `00-module-summary.md` for `Lead` and `CrmSync`; list intentional TODOs and proposed AGENTS.md changes. No code changes.
- **STOP GATE:** end of plan; write `NEXT-SESSION.md`.

---

## 6. CRITICAL GUARDRAILS (checked in every step's self-check)

- [ ] `core/*/Domain` and `core/*/Application` contain no WordPress, `Framework\`, `App\`, helpers or facades (T8 A/B zero matches).
- [ ] Time comes from `ClockContract` in Core and the base repository's UTC `now()` in Infrastructure; uuids from `UuidGeneratorContract`; neither is created inside an Entity.
- [ ] `''` and whitespace become `null` before any unique-index lookup on `email` / `phone`; a lead with neither is rejected (A2).
- [ ] Queue claiming is a single conditional `UPDATE` per row with an affected-row check.
- [ ] Stale leases are released through the Entity and increment `attempts`, which can never exceed the Domain ceiling (tinyint column).
- [ ] No HTTP call inside a transaction; no event, job or file work before commit.
- [ ] Every write runs in `executeInTransaction()`; no manual transaction call in a Service.
- [ ] Entities are `readonly class`, not `final`, and never mutated by callers.
- [ ] Secrets only via `.env` → `config/`; never in logs, never in a DLQ error message.
- [ ] No migration, no `framework/` edit, no existing Shared artifact changed.
- [ ] No test files created or claimed (tests paused).

## 7. IMMEDIATE NEXT ACTION

Continue from the latest completed step in `docs/ai-reports/PROJECT-LOG.md` across the developer's requested Phase/Module range.

## 8. DEFERRED

Facebook Lead Ads and WhatsApp Cloud API endpoints (each needs its own signature scheme and verification handshake), Salesforce and Zoho adapters, deal sync beyond contacts, a lead-reference column or index on `sync_events` (a future migration), and automated tests (when the developer re-enables them).
