# Decisions

Decisions taken while executing a plan that AGENTS.md and the rule files did not
cover. Each records what was decided, why (basis), its impact, and the proposed
AGENTS.md change.

> **Fresh trail (2026-11-23).** The previous `DECISIONS.md` (format `D-2026-11-14-NN`,
> last ID `D-2026`-series, 1090 lines — still in commit `065ab4e`) was purged from the
> working tree with the rest of `docs/ai-reports/` before this plan started. On the
> developer's instruction the trail **restarts at `D-001`**; no ID from the previous file
> is reused. Sections A–C below transcribe the approved decisions of
> `docs/ai-plans/lead-module-action-plan.md` (Step 0.1's mandate); section D records the
> gaps this audit found.

---

## A. Plan decisions — developer-approved (transcribed, Step 0.1)

| ID | Ref | Status | Decision | Basis |
| --- | --- | --- | --- | --- |
| D-001 | A1 | DECIDED | `LeadStatus` = `new`, `contacted`, `qualified`, `converted`, `lost`. `LeadSource` = `contact_form_7`, `woocommerce`, `hubspot`, `manual`. `SyncEventStatus` = `pending`, `processing`, `succeeded`, `dead`. | plan §2 A1; developer-confirmed |
| D-002 | A2 | DECIDED | `LeadEntity::capture()` requires at least one of `email` or `phone`; neither → domain exception. | plan §2 A2 |
| D-003 | A3 | DECIDED | Additive Shared port `UuidGeneratorContract` (`core/Shared/Domain/Contracts`) + adapter `WpUuidGenerator` (`wp_generate_uuid4()`), bound once in `AppServiceProvider`; the Entity receives the uuid as a parameter. | plan §2 A3 |
| D-004 | A4 | DECIDED | On a merge-mapping conflict the primary lead's mapping stays; the secondary's conflicting mapping is deleted and the removal logged. | plan §2 A4 |
| D-005 | A5 | DECIDED | `sync_events` has no lead column; the lead reference travels in `payload_json` (`entity_type`, `local_entity_id`). | plan §2 A5 |
| D-006 | A6 | DECIDED | `dead_letters.job_class` values are the Domain constants `SyncEventEntity::HANDLER_OUTBOUND` / `HANDLER_INBOUND`. | plan §2 A6 |
| D-007 | A7 | DECIDED | Inbound webhooks: the controller verifies signature + idempotency, enqueues a `sync_events` row (direction `inbound`) and returns `200` fast; the worker does the rest. | plan §2 A7 |

## B. Plan decisions — proposals, all confirmed by the developer (transcribed, Step 0.1)

| ID | Ref | Status | Decision | Basis |
| --- | --- | --- | --- | --- |
| D-008 | DP-1 | DECIDED | `Lead` owns port `LeadCaptureOutboxContract`; adapter in `core/CrmSync/Infrastructure` writes `sync_events` in the SAME transaction (transactional outbox). | plan §2 DP-1; developer-confirmed |
| D-009 | DP-2 | DECIDED | `Lead` owns port `LeadMappingRelinkContract`; adapter in `core/CrmSync/Infrastructure`. | plan §2 DP-2 |
| D-010 | DP-3 | DECIDED | The DB outbox is the durable queue; Action Scheduler / WP-Cron only TRIGGER worker + reaper. No second job system (`QueueManagerContract` unused). | plan §2 DP-3 |
| D-011 | DP-5 | DECIDED | Webhook authenticity verified in DELIVERY before any Service runs (raw body + headers, constant-time compare, timestamp window). | plan §2 DP-5 |
| D-012 | DP-6 | DECIDED | Idempotency key = `sha256(source + ':' + external id)` = 64 hex chars; never derived from mutable lead fields. | plan §2 DP-6 |
| D-013 | DP-7 | DECIDED | The Entity accepts backoff jitter seconds as a parameter. | plan §2 DP-7 |
| D-014 | DP-8 | DECIDED | First release scope: HubSpot adapter + CF7 + WooCommerce only. | plan §2 DP-8 |
| D-015 | DP-9 | DECIDED | `SyncDirection` = `outbound`, `inbound`. `CrmProviderName` = `hubspot`. `LocalEntityType` = `lead`, `order`. `CrmObjectType` = `contact`, `deal`. | plan §2 DP-9 |
| D-016 | DP-4 | DECIDED | No `LeadSnapshotContract`: `sync_events.payload_json` is a NOT NULL snapshot (resolved by the schema). | plan §2 (DP-4 note) |

## C. Schema facts and derived rules the plan depends on (verified against `database/migrations`, Step 0.1)

All four migrations were read and match plan §3. Status is `VERIFIED` (fact confirmed on disk), not `OPEN`.

| ID | Ref | Status | Fact / derived rule | Evidence |
| --- | --- | --- | --- | --- |
| D-017 | S1 | VERIFIED | The retry ceiling is a Domain constant (`SyncRetryRules::MAX_ATTEMPTS`), kept far below 255 so reaper increments cannot overflow the smallint column. | `database/migrations/2026_11_22_210100_create_sync_events_table.php:42` (`unsignedTinyInteger('attempts')`) |
| D-018 | S2 | VERIFIED | `CrmMappingEntity` carries `entityType`, `localEntityId`, `provider`, `crmObjectType`, `RemoteId`; remote lookups use the three-part key. | `..._create_crm_mappings_table.php:42-49` (both UNIQUE indexes) |
| D-019 | S3 | VERIFIED | Inbound events are written to `sync_events` (direction `inbound`) by the webhook step. | `..._create_sync_events_table.php:37` (`direction`) |
| D-020 | S4 | VERIFIED | The DLQ keeps the FULL `payload_json`; only `error_message` is scrubbed. A dead letter replays ONCE (`retried_at` set → second replay rejected). | `..._create_dead_letters_table.php:43-47` |
| D-021 | S5 | VERIFIED | Merge order respects both UNIQUE indexes: lock both leads ascending by id, delete the secondary FIRST, then fill the primary's blanks; a duplicate-key failure on concurrent capture is retried once. | `database/migrations/2026_09_29_232811_create_leads_table.php:43-46`; `..._create_crm_mappings_table.php:42-49` |
| D-022 | S6 | VERIFIED / FAILED-IN-PART | `assigned_to` references `users` and `dead_letters` FKs reference `sync_events`. Table prefix and FK-target resolution verified; the claimed `ENGINE=InnoDB` emission is **not implemented** — see D-026. | `leads` migration:53-56; `dead_letters` migration:32-40; see D-026 |
| D-023 | S7 | VERIFIED | `LeadEntity` holds `array $meta`; the EntityMapper encodes/decodes `meta_json`; invalid stored JSON raises a mapper `\RuntimeException`, never a silent empty array. | `leads` migration:51 (`longText('meta_json')->nullable()`) |

## D. Gaps found by the Step 0.1 audit (A9 Tier 2/3)

## D-024 — `ClockContract` does not exist, but the plan's guardrails require it

**Type:** GAP (plan assumes an artifact that is absent) · **Step:** 0.1 · **Status:** DECIDED (2026-11-23, developer-approved)

**Situation.** The plan's guardrail (plan §6) says "Time comes from `ClockContract` in Core and the base repository's UTC `now()` in Infrastructure". Step 1.2 requires `LeadEntity` behaviour methods to receive `$now`, and Step 3.1's flow needs a `DateTimeImmutable` inside the Service. No `ClockContract` exists anywhere: `core/Shared/Domain/Contracts/` holds only `CacheInterface`, `EventDispatcherContract`, `FileUploaderContract`, `PasswordHasherContract`, `RateLimiterContract`, `SlugCheckerContract`, `TokenGeneratorContract`, `TransactionManagerContract`, `TranslatorContract` (repo-wide grep for `ClockContract` → 0 hits).

**Options considered.**
1. Add an additive Shared port `ClockContract` + a `WpClock` adapter (mirrors A3's treatment of `UuidGeneratorContract`), bound in `AppServiceProvider`.
2. Have the Delivery layer construct `new \DateTimeImmutable('now', new \DateTimeZone('UTC'))` and pass it through the DTO (P14-style: delivery resolves ambient state and passes it in).
3. Have the base repository's `now()` (`BaseWriteRepository.php:313`, UTC string) be the only time source, with the Service re-parsing the string.

**Decision (developer-approved 2026-11-23).** Option 1 — additive `ClockContract` (`now(): DateTimeImmutable`) in `core/Shared/Domain/Contracts/` + UTC `WpClock` in `core/Shared/Infrastructure/Utilities/`, bound once in `AppServiceProvider` alongside `UuidGeneratorContract`. Implemented in Step 0.2.

**Basis.** plan §6 guardrail; AGENTS A4 ("additive Shared kernel additions (new Contract + adapter)"); plan §2 A3 (same shape).

**Impact (files, reversibility).** Step 0.2 would also create `core/Shared/Domain/Contracts/ClockContract.php` + `core/Shared/Infrastructure/Utilities/WpClock.php` and bind it in `AppServiceProvider`. Reversible; additive only.

**Proposed AGENTS.md change:** none.

## D-025 — `DuplicateKeyException` and `WpQueryExceptionTranslator` are named by AGENTS/plan but do not exist

**Type:** RULE ERROR (rule text names an artifact the codebase does not have) · **Step:** 0.1 · **Status:** DECIDED (2026-11-23, developer-approved)

**Situation.** AGENTS §11 says "Infrastructure translates a duplicate-key failure to `Core\Shared\Domain\Exceptions\DuplicateKeyException` (or the module's conflict exception)". The plan's Phase 2 preamble says "duplicate key only via `WpQueryExceptionTranslator`". Neither exists (repo-wide greps → 0 hits). The actual mechanism is the module-owned exception plus one shared hook: `BaseWriteRepository::duplicateException()` (`core/Shared/Infrastructure/Repositories/BaseWriteRepository.php:77`) returns the failure unchanged by default, and `throwIfDuplicate()` (`:408`) detects an integrity violation from `$wpdb->last_error`; each module overrides `duplicateException()` to name its own exception (e.g. `core/Company/Domain/Exceptions/CompanyDuplicateException.php`). `core/Shared/Exceptions/DomainValidationException.php:5` also sits in namespace `Core\Shared\Exceptions`, not the `Core\Shared\Domain\Exceptions` path AGENTS §11 spells.

**Options considered.** (1) Treat the module conflict exception as the sanctioned mechanism (the rule's own parenthetical) and have the Lead/CrmSync write repositories override `duplicateException()` with `LeadDuplicateException` / `SyncEventDuplicateException`. (2) Add an additive Shared `DuplicateKeyException` and a translator adapter.

**Decision (developer-approved 2026-11-23).** Option 1 — the existing `BaseWriteRepository::duplicateException()` mechanism throws module-specific conflict exceptions; **no global translator class is added**. The Lead module supplies its exception when the write repository lands (Step 2.1); the plan preamble's `WpQueryExceptionTranslator` and AGENTS §11's `DuplicateKeyException` remain stale text (proposed correction below).

**Basis.** AGENTS §11 parenthetical; `BaseWriteRepository.php:77,408`; plan §5 Step 2.1.

**Impact (files, reversibility).** No code change required to proceed; the module exception names are chosen in Steps 2.1/2.3. Reversible.

**Proposed AGENTS.md change:** in §11, replace "translates a duplicate-key failure to `Core\Shared\Domain\Exceptions\DuplicateKeyException` (or the module's conflict exception)" with "translates a duplicate-key failure to the module's conflict exception by overriding `BaseWriteRepository::duplicateException()`" and note the `Core\Shared\Exceptions` path for `DomainValidationException`.

## D-026 — the Schema builder does not emit `ENGINE=InnoDB`, though rule 02 and the plan's S6 require it

**Type:** RULE ERROR / plan-premise failure · **Step:** 0.1 · **Status:** DECIDED (2026-11-23, developer-confirmed)

**Situation.** `.claude/rules/02-framework-adapter-wordpress.txt:64` states "Transactional tables are InnoDB (verify the Schema builder emits `ENGINE=InnoDB`; MyISAM silently ignores transactions and `FOR UPDATE`)". The plan's S6 depends on the same. **No code emits it**: `Blueprint::toCreateSql()` (`framework/Database/Schema/Blueprint.php:1892`) renders `CREATE TABLE \`%s\` (...)` and appends only the charset clause; `Schema::create()` (`framework/Database/Schema/Schema.php:214`) passes `$adapter->charsetCollation()`; `WpDatabaseAdapter::charsetCollation()` (`framework/Database/Adapters/WpDatabaseAdapter.php:428`) returns `$wpdb->get_charset_collate()` (charset/collate only), and `createTable()` (`:215`) hands the statement to `dbDelta()` unchanged. A repo-wide search for `ENGINE=` outside `vendor/` finds no emitted clause. Conversely, README.md:2770 ("The schema builder emits InnoDB, so the engine half is satisfied by default") and `framework/Database/Query/QueryBuilderInterface.php:345` assert the emit — documentation that contradicts the code.

**Why it is not treated as a hard stop here.** The plan's requirement is InnoDB *semantics* (row locks in Step 2.2's reaper, `findByIdForUpdate`, transactional outbox/DLQ), and a table created without an engine clause inherits the server's `default_storage_engine`, which is InnoDB on every supported MySQL (5.5+) and MariaDB (10.2+). So the plan can be completed without a framework change; the missing clause is a robustness/portability gap plus a doc error, and its remedy is a `framework/` change (JOB F), which is out of this step's scope (`framework/` is never in a module step, H12).

**Options considered.** (1) Accept the server default and correct the two documents that claim an emit. (2) Have a JOB F task make `toCreateSql()`/the adapter emit `ENGINE=InnoDB` (framework change; separate task). (3) Declare the engine in a migration — **rejected**: the plan forbids creating or editing migrations, and it is the wrong layer for a framework-wide property.

**Decision (developer-confirmed 2026-11-23).** Option 1 — **the database storage engine is InnoDB**, verified by the developer from successful foreign-key creation across all four tables (`leads`, `crm_mappings`, `sync_events`, `dead_letters`). **No framework change is made**: the missing explicit `ENGINE=InnoDB` clause stays as-is, and the two documents that claim the builder emits it (`README.md:2770`, `framework/Database/Query/QueryBuilderInterface.php:345`) remain stale text (a JOB F documentation task, if the developer wants it).

**Basis.** `.claude/rules/02-framework-adapter-wordpress.txt:64`; plan §3 S6; AGENTS §6 (HALT only when a framework change is *needed*).

**Impact (files, reversibility).** No file changes now. Option 2, if chosen, touches `framework/Database/Schema/Blueprint.php` (JOB F). Reversible.

**Proposed AGENTS.md change:** none for option 1; for option 2, none (already mandated by rule 02:64).

## D-027 — documentation drift: `SlugGeneratorContract`, "empty" `BaseReadRepository`, and the `WpTransactionManager` location

**Type:** RULE ERROR (docs describe a different codebase than the one on disk) · **Step:** 0.1 · **Status:** DECIDED (2026-11-23, developer-approved)

**Situation.** Three smaller drifts, none affecting this plan's steps. (a) The plan's Step 0.1 checklist names `SlugGeneratorContract`; the port is actually `SlugCheckerContract` (`core/Shared/Domain/Contracts/SlugCheckerContract.php:11`) and `SlugGenerator` is a concrete class (`core/Shared/Infrastructure/Utilities/SlugGenerator.php:18`), not a contract. The plan marks this "not needed here, report only". (b) AGENTS D4 calls `BaseReadRepository` "currently empty, ready for shared read helpers"; it actually ships `findRecordById()` (`core/Shared/Infrastructure/Repositories/BaseReadRepository.php:33`) and `rowExists()` (`:62`). (c) AGENTS §10 places `WpTransactionManager` in `core/Shared/Infrastructure/Database/`; it lives at `app/Database/WpTransactionManager.php:22` (as does `app/Cache/WpCacheStore.php` and `app/Database/AtomicDatabaseRateLimiter.php`), which also rubs against AGENTS §1's "Adapters never live in `app/`".

**Options considered.** (1) Report and leave the code as-is (no plan step depends on these). (2) Correct the AGENTS.md/plan wording in a later documentation step.

**Decision (developer-approved 2026-11-23).** Follow the **actual codebase** names and paths — `TranslatorContract::translate()` (not `get()`), `WpTransactionManager` in `app/Database/`, the real `BaseReadRepository` helpers. No code is moved now; the documentation corrections are **logged for Step 5.3** (final audit and module summaries), which is where doc/rule drift is reported.

**Basis.** AGENTS D4, §10, §1; files cited above.

**Impact (files, reversibility).** None on this plan. Reversible.

**Proposed AGENTS.md change:** D4 — drop ", currently empty, ready for shared read helpers"; §10 — restate literally that `WpTransactionManager` lives at `app/Database/WpTransactionManager.php` until a later move, or move the file to `core/Shared/Infrastructure/Database/` under a JOB C/refactor task. (The move is a code change, so it is proposed here, not made.)

---

## E. Gaps found by the Step 0.2 audit (lead module scaffold)

## D-028 — how much of `core/Lead/` is "fully implemented" at scaffold time

**Type:** RULE CONFLICT (plan 0.2 vs rule 03 / module-scaffolder skill) · **Step:** 0.2 · **Status:** DECIDED

**Situation.** Rule 03's FULL-BASELINE MANDATE and the `module-scaffolder` skill say the EntityMapper and the ResponseDTO/ResponseMapper are fully implemented mechanical mappings at scaffold time, and that YAGNI never trims the baseline. Step 0.2 says the opposite: "the full `core/Lead/` tree of section 4 as **empty skeletons with `// TODO:`**", with a separate "**FULLY IMPLEMENTED (mechanical)**" list naming only `LeadEntity` and the two enums, and `OUT OF SCOPE: … DTOs, request mapper methods, service methods`.

**Options considered.** (1) Follow rule 03 (implement EntityMapper/ResponseDTO/ResponseMapper now). (2) Follow the brief (skeletons only; the use-case steps implement them).

**Decision.** Option 2 — the brief wins (`09` A9 precedence 1: the developer's explicit instruction in the current brief; the skil is precedence 4). Only `LeadEntity`, `LeadStatus`, `LeadSource` and the Shared uuid/clock/dispatcher artifacts are fully implemented; `LeadEntityMapper`, `LeadResponseMapper` and both DTOs are skeletons whose declared methods throw `\LogicException` with a `// TODO:`. Steps 2.1 and 3.1 implement them.

**Basis.** plan §5 Step 0.2 (CREATE + FULLY IMPLEMENTED + OUT OF SCOPE); 09 A9 precedence 1 (brief) over 4 (skill); rule 03 §7.1.1 (contradicted).

**Impact (files, reversibility).** Declared-but-throwing mapper methods; trivially reversible. Stepping 2.1/3.1 replace the bodies.

**Proposed AGENTS.md change:** none (the conflict is plan-vs-rule; the plan is authoritative for its own steps).

## D-029 — the request DTO, request mapper and `LeadMapperContract` are created at scaffold time

**Type:** RULE CONFLICT (plan 0.2 vs rule 03's just-in-time separation) · **Step:** 0.2 · **Status:** DECIDED

**Situation.** Rule 03 says "Do NOT generate Request DTOs (CreateDTO, UpdateDTO), the Request Mapper (`{ModuleName}Mapper`), or `{ModuleName}MapperContract`. They are built later with their matching features." Step 0.2 nevertheless names `LeadMapperContract` among the contracts bound in `LeadServiceProvider`, and `LeadCaptureDTO`/`LeadMapper` appear in plan §4's final tree.

**Options considered.** (1) Create the three as TODO-only skeletons. (2) Skip them; bind only the four module ports + response mapper; implement in Step 3.1.

**Decision.** Option 1 — the three files exist as skeletons (`LeadCaptureDTO` has no properties; `LeadMapper` implements the empty `LeadMapperContract` and holds nothing), so §4's tree is complete and the step's ACCEPTANCE ("… + `LeadMapperContract` bound once in `LeadServiceProvider`") is met. Their content lands in Step 3.1.

**Basis.** plan §5 Step 0.2 CREATE + ACCEPTANCE; rule 03 §7.1.1 (contradicted).

**Impact (files, reversibility).** Three extra skeleton files; no behaviour claimed. Reversible.

**Proposed AGENTS.md change:** in rule 03 §7.1.1, note that a plan step may explicitly request the Request DTO/Mapper/MapperContract at scaffold time, in which case they are created as empty skeletons and filled by their CRUD step.

## D-030 — Lead Command Service constructor composition at scaffold time

**Type:** GAP (the brief does not fix the ctor) · **Step:** 0.2 · **Status:** DECIDED

**Situation.** Rule 03 gives a Command Service ctor template (repository contract, response mapper, `TransactionManagerContract`, `EventDispatcherContract`, logger, plus schema-implied ports). The plan needs more (uuid, clock) and less (no queued work).

**Decision.** `LeadCommandService` injects, in order: `LeadRepositoryContract`, `LeadResponseMapper` (concrete — plan §4 declares no `LeadResponseMapperContract`), `TransactionManagerContract`, `EventDispatcherContract`, `UuidGeneratorContract`, `ClockContract`, `TranslatorContract`, `LoggerInterface`. `LeadQueryService` injects `LeadQueryContract`, `LeadResponseMapper`, `LoggerInterface` only (rule 03 R12.6). **Not injected:** `QueueManagerContract` (no queued work) and the two cross-module ports `LeadCaptureOutboxContract` / `LeadMappingRelinkContract`, whose adapters do not exist until Step 0.3 — injecting a port nothing binds would leave `LeadCommandServiceContract` unresolvable. They are added to the ctor in Step 3.1.

**Basis.** rule 03 §7.1.1 (ctor template, query-service restriction); AGENTS §11 ("create only the ports the task needs"); plan §2 A3, DP-1, DP-2.

**Impact (files, reversibility).** A ctor edit in Step 3.1; no behaviour change. Reversible.

**Proposed AGENTS.md change:** none.

## D-031 — `WpEventDispatcher` is created and bound although this plan raises no events yet

**Type:** RULE CONFLICT (rule 03's ctor template vs the plan's deferred events) · **Step:** 0.2 · **Status:** DECIDED

**Situation.** Rule 03 states three times that the Command Service ctor injects `EventDispatcherContract` and that a newly injected Shared port's adapter must be created ("ADAPTER CO-GENERATION … generate it fully, without asking"), exempting `WpEventDispatcher` from the ordinary scope rule. The plan defers events: step 1.2 adds `$domainEvents`/`releaseEvents()` "only if a method raises an event", and `08`'s header says events/jobs are created only when a use case needs them. The live `CompanyCommandService` injects no dispatcher.

**Options considered.** (1) Follow rule 03: inject the port, create `core/Shared/Infrastructure/Events/WpEventDispatcher.php`, bind it once. (2) Follow the live module and the plan: omit the port until an event exists.

**Decision.** Option 1 — rule 03 is the brief's named governing rule and the adapter's behaviour is fully specified by rule 02 §2.3 and `08` E5 (`do_action('<prefix>_core_event', $event)` plus a per-class action), so nothing is invented. Bound as a singleton in `AppServiceProvider`, prefix from `config('app.prefix')`.

**Basis.** rule 03 §7.1.1 + guardrail 3; rule 02 §2.3; `08` E5. Contradicted by `08`'s header and the live module.

**Impact (files, reversibility).** One new Shared adapter + one binding; `LeadCommandService` carries an unused dependency until Step 3.1. Reversible (remove the ctor parameter and the binding).

**Proposed AGENTS.md change:** in rule 03 §7.1.1, state whether the Command Service ctor carries `EventDispatcherContract` at scaffold time or only once a behaviour method raises an event — the two rules currently disagree.

## D-032 — `LeadEntity` types its datetime columns as `?DateTimeImmutable`, not `?string`

**Type:** GAP (rules vs the live module agree on nothing here) · **Step:** 0.2 · **Status:** DECIDED

**Situation.** The live `CompanyEntity` types `createdAt`/`updatedAt` as `?string`. Rule 05 §5 says time is "PASSED IN (`DateTimeImmutable $now`)" and its Entity template types a datetime column `?DateTimeImmutable`; rule 02 §2.3:66 lists `DateTimeImmutable` among the explicit casts `toEntity()` must apply. Rule 03 line 2 says the Entity design follows AGENTS.md, "not older shapes".

**Decision.** `?DateTimeImmutable $createdAt` / `?DateTimeImmutable $updatedAt` (UTC), following the rules. `LeadEntityMapper::toEntity()` (Step 2.1) parses `'Y-m-d H:i:s'` in UTC and `toPersistenceArray()` formats back — the same round trip `WpClock` supplies.

**Basis.** rule 05 §5; rule 02 §2.3:66; rule 03 line 2 (contradicted by `core/Company/Domain/Entities/CompanyEntity.php`).

**Impact (files, reversibility).** The Step 2.1 mapper must parse/format dates; a one-line type change to `?string` if the developer prefers the Company shape. Reversible.

**Proposed AGENTS.md change:** state the datetime column type once, in rule 05 §5 (Entity) and rule 02 §2.3:66 (cast list), so modules stop diverging — and note whether existing `?string` modules are to be migrated.

## D-033 — the clock adapter is named `WpClock`, not the rules' `SystemClock`

**Type:** RULE CONFLICT (developer instruction vs rule 02's adapter table) · **Step:** 0.2 · **Status:** DECIDED

**Situation.** Rule 02 §2.3's shared-adapter table names the `ClockContract` adapter **`SystemClock`** (UTC). The developer's D-024 instruction names it `WpClock` in `core/Shared/Infrastructure/Utilities/`.

**Decision.** `WpClock` — the developer's explicit instruction wins (`09` A9 precedence 1).

**Basis.** D-024 (developer-approved 2026-11-23); rule 02 §2.3 (contradicted).

**Impact (files, reversibility).** A rename is a two-line change. Reversible.

**Proposed AGENTS.md change:** rule 02 §2.3 — replace `SystemClock` with `WpClock` (or authorise either name), and add rows for the additive `UuidGeneratorContract` → `WpUuidGenerator` (A3), which the table does not list at all.

## D-034 — T8 A's pattern flags Core port *declarations*, not only helper calls

**Type:** RULE ERROR (a rule whose check cannot pass as written) · **Step:** 0.2 · **Status:** OPEN

**Situation.** `06` T8 A run over the Core trees returned 3 matches, **none of them a helper call**:
1. `core/Shared/Domain/Contracts/CacheInterface.php:46` — a docblock naming `wp_cache_get()` (pre-existing, untouched by this step).
2. `core/Shared/Domain/Contracts/EventDispatcherContract.php:15` — `public function dispatch(object $event): void;` (pre-existing; the port AGENTS §11 mandates).
3. `core/Shared/Domain/Contracts/ClockContract.php:22` — `public function now(): DateTimeImmutable;` (**new in this step**; the port rule 02 §2.3 mandates).

T8 A's second pattern matches `(^|[^>:[:alnum:]_])now\(`, which a method **declaration** also satisfies, and its first pattern matches `wp_*` in prose. So the check cannot reach zero while Core declares the ports the rules require.

**Options considered.** (1) Report the matches and keep the rule-prescribed names. (2) Rename `ClockContract::now()` (e.g. `current()`) to satisfy the regex, contradicting D-024 and rule 02 §2.3. (3) Propose a pattern refinement that excludes declarations (e.g. `(?<!function )(?<!->)now\(`).

**Decision.** Option 1 **+** Option 3 as a proposal. The names stay as approved; the check's *intent* (Core Domain/Application free of framework and ambient helpers) holds — verified by T8 B (zero) and by reading every new Core file, none of which calls a framework function. The matches are reported raw in the step report.

**Basis.** `06` T8 A + T10 ("T8 A/B zero matches") against `06`'s own FORBIDDEN list, which targets *calls*; `01` P6 ("Domain has zero framework imports"). Rule 06 is internally inconsistent here.

**Impact (files, reversibility).** None on code; the DoD item "T8 A zero matches" cannot be marked green and is reported as such.

**Proposed AGENTS.md change:** in `06` T8 A, anchor the helper alternatives so a declaration does not match — e.g. replace `now\(` with `(?<!function )\bnow\(` and require `wp_[a-z_]+\s*\(` to appear outside a comment, or document that port declarations are expected matches and are judged by reading.

---

## F. Gaps found by the Step 0.3 scaffold (CrmSync module)

## D-035 — the two cross-module adapters are created at scaffold time but carry no method

**Type:** GAP (plan 0.3 says "skeletons implementing the Lead contracts", but those contracts are empty) · **Step:** 0.3 · **Status:** DECIDED

**Situation.** Plan §5 Step 0.3: "`LeadCaptureOutboxAdapter` and `LeadMappingRelinkAdapter` are scaffolded as skeletons implementing the Lead contracts, bound in `CrmSyncServiceProvider`." But `LeadCaptureOutboxContract` and `LeadMappingRelinkContract` were created in Step 0.2 as **empty interfaces with a `// TODO:`** — plan §4 and D-029 defer every port method to Step 1.6. An adapter cannot implement a method that is not declared.

**Options considered.** (1) Create the two adapters now with constructor injection only, and declare their methods in Step 2.4 once Step 1.6 declares the ports. (2) Halt and ask the developer to move the port-method declaration forward into this step. (3) Skip the adapters until Step 2.4, leaving `CrmSyncServiceProvider` without the two bindings.

**Decision.** Option 1. Both adapters exist at the plan's paths, implement their Lead contracts (vacuously — the interfaces are empty), carry constructor injection of the ports they will use, and are bound in `CrmSyncServiceProvider`. Step 0.3's ACCEPTANCE — the contracts are bound once — is met, and Step 1.6 → 2.4 fill the bodies without moving a file.

**Basis.** plan §5 Step 0.3 (CREATE + ACCEPTANCE); plan §4 (the two paths); D-029 (port methods are deferred to the step that uses them); AGENTS §11 ("create only the ports the task needs").

**Impact (files, reversibility).** Two skeletal adapter files whose behaviour is one step later; no method signature is claimed as done. Reversible. **Flagged at the STOP GATE** because the brief's phrase "implementing the Lead contracts" reads more strongly than an empty interface allows.

**Proposed AGENTS.md change:** none (the tension is inside the plan).

## D-036 — `SyncResponseMapper` is bound by its concrete class, not through a contract

**Type:** GAP (rule 03 binds `<Module>MapperContract`; this module declares none) · **Step:** 0.3 · **Status:** DECIDED

**Situation.** Rule 03 §7.10's wiring template ends with `$this->container->bind(CouponMapperContract::class, CouponMapper::class);`. Plan §4's `core/CrmSync/Application/Contracts/` list is `{SyncEventQueryContract, CrmMappingQueryContract, DeadLetterQueryContract, CrmContactGatewayContract, WebhookSignatureVerifierContract, CrmProviderRegistryContract, SyncCommandServiceContract, SyncQueryServiceContract, SyncMapperContract}` — there is **no `SyncResponseMapperContract`**. `SyncMapperContract` is a different thing: the *request* mapper that turns a webhook body into `WebhookEnqueueDTO`.

**Options considered.** (1) Bind `SyncResponseMapper` by its concrete class as a singleton (the `Lead` module's pattern; plan §4 declares no contract for it). (2) Invent a `SyncResponseMapperContract` so the binding matches rule 03's template shape.

**Decision.** Option 1. `SyncResponseMapper` is a stateless, final mapper whose only caller is `SyncCommandService`/`SyncQueryService`, both of which already type-hint the concrete class in their constructors (written in Step 0.2). Binding it by class keeps the provider honest: it binds exactly the types the constructors ask for, and it invents no interface the plan does not name. Same reading as `LeadServiceProvider`.

**Basis.** plan §4 (no `SyncResponseMapperContract`); rule 03 §7.10 (template shape, contradicted); `app/Providers/LeadServiceProvider.php` (the module precedent); AGENTS §11 ("create only the ports the task needs").

**Impact (files, reversibility).** One `singleton()` on a concrete class instead of a contract→class pair; adding a contract later is a two-line change. Reversible.

**Proposed AGENTS.md change:** in rule 03 §7.10, state whether the **response** mapper is bound by contract or by concrete class — the template binds `MapperContract` (the request mapper) and is silent on the response mapper, which is what the two live modules resolved differently from the template.

---

## G. Gaps found by the Step 1.1 Value Objects (lead module)

## D-037 — `PhoneNumber` rejects a number with no international prefix, and a repeated `+` is malformed

**Type:** GAP + SELF-CHECK DEFECT · **Step:** 1.1 · **Status:** DECIDED

**Situation.** Plan §5 Step 1.1 says `PhoneNumber` is "E.164 normalization" and nothing more. Two questions it does not settle, which the implementation had to:
1. A number arriving as `5550100199` (no `+`, no `00`) cannot become E.164 without a country code. Should the VO assume the site's country, or reject it?
2. The first implementation kept a `+` only when it *led* and stripped every other non-digit, so `++1555` silently collapsed to the valid `+1555`. Found by the Step 1.1 self-check.

**Options considered.** (1) Reject a prefix-less number and a repeated `+`; require the caller to send a country code. (2) Assume a configured default country for a prefix-less number. (3) Accept the collapsed `++1555` form as a lenient convenience.

**Decision.** Option 1. `fromString()` throws `InvalidPhoneNumberException` when the value carries no `+`/`00` prefix, and `normalize()` returns the value untouched when more than one `+` is present, so validation rejects it. Assuming a country would silently invent a wrong number for every foreign contact — a data-integrity fault that surfaces only as a failed sync much later, and the plan's contacts arrive from international sources. Collapsing `++1555` accepts a number the caller never wrote, which contradicts the VO's whole purpose.

**Basis.** plan §3 (`leads.phone` varchar(50) unique) + §5 Step 1.1 (E.164); the `leads` migration's own note ("format phones to E.164"); `01` P6 (validated in the named constructor); AGENTS §7 (the rule lives in the VO); the Step 1.1 self-check finding.

**Impact (files, reversibility).** `PhoneNumber` rejects more inputs than a permissive reader would; widening later is a one-line change in `normalize()`/`isValid()`. Reversible.

**Proposed AGENTS.md change:** none. Worth a plan note: a lead captured from a source that omits a country code is a delivery-level concern (the Request Mapper may prepend one from a configured default), not a Domain one.

## D-038 — Domain Value Objects expose `equals(self): bool` over their canonical value

**Type:** GAP (the convention is unstated in the rules) · **Step:** 1.1 · **Status:** DECIDED

**Situation.** Plan §5 Step 1.1's ACCEPTANCE says the VOs are "compared by value". PHP gives no value equality for objects beyond `==` (loose and property-wise) or `===` (identity), and the module has several value-carrying types (`EmailAddress`, `PhoneNumber` here; `CrmSync\RemoteId`, `ContentHash`, `SanitizedErrorMessage` in Step 1.3). Rule 05 R3 says "compare by value" without naming the mechanism.

**Options considered.** (1) A public `equals(self $other): bool` on each VO, comparing the canonical value with `===`. (2) Rely on `==` at call sites. (3) A shared `ValueObject` interface with `equals()`.

**Decision.** Option 1. `equals(self $other): bool` compares the canonical value with `===` — strict, so it cannot be satisfied by two different types that happen to be loosely equal, and explicit at the call site. A shared interface was rejected as speculative: it would be a Shared port created before a use case needs it, which AGENTS §11 forbids, and `RemoteId` is already being written to the same shape independently (Step 1.3).

**Basis.** plan §5 Step 1.1 (ACCEPTANCE "compared by value"); rule 05 R3 ("compare by value"); AGENTS §7 / §11 (no speculative Shared port); the `CrmSync\RemoteId` docblock from Step 0.2 (strict `===` on the canonical string).

**Impact (files, reversibility).** One method per VO; adopting a shared interface later is additive. Reversible.

**Proposed AGENTS.md change:** in rule 05's VALUE OBJECTS paragraph, state the comparison mechanism — a public `equals(self): bool` using strict `===` on the canonical value — so modules stop each choosing their own.

---

## H. Gaps found by the Step 1.2 LeadEntity behaviour

## D-039 — `LeadEntity` stores canonical strings; the Value Objects stay the validated boundary

**Type:** GAP (the plan passes VOs in but never says what the entity stores) · **Step:** 1.2 · **Status:** DECIDED

**Situation.** Plan §5 Step 1.2 gives `capture(string $uuid, ?EmailAddress $email, ?PhoneNumber $phone, ...)` — Value Objects in. But the constructor written in Step 0.2 (and the `leads` migration behind it, and the Step 2.1 `LeadEntityMapper` that must fill it) declares `?string $email` / `?string $phone`. The step never says whether the entity should now hold the VOs instead.

**Options considered.** (1) `capture()` takes the VOs and stores their canonical strings; the fields stay `?string`. (2) Change the fields to `?EmailAddress` / `?PhoneNumber`, so the entity holds the VOs and the mapper constructs them on read. (3) Have `capture()` take raw strings and build the VOs itself.

**Decision.** Option 1. The VOs are the *input boundary*: they guarantee trim/lowercase/E.164 once, at the door. What survives into the entity is the normalized text the storage column holds, so entity and EntityMapper agree about what a field contains and the 13-parameter constructor is unchanged. Option 2 would move VO construction into the mapper on every read (paying validation repeatedly for values the database already normalized and the UNIQUE index already guarantees), and would put the constructor out of step with the schema for no gain. Option 3 would duplicate normalization into the entity, which `05` R1 puts in the VO.

**Basis.** plan §5 Step 1.2 (VO-typed parameters); plan §3 (`email`/`phone` columns, the migration's normalization note); Step 0.2's `LeadEntity` ctor; `05` R3 (VOs validate and normalize in the named constructor); `01` P6.

**Impact (files, reversibility).** Fields remain `?string` and the ctor unchanged; switching to Option 2 later is a retype plus a mapper change. Reversible. The mapper (Step 2.1) should treat stored values as already-normalized and not re-validate.

**Proposed AGENTS.md change:** none. Worth a plan note in Step 2.1 that the EntityMapper assumes normalized contact values.

---

## I. Gaps found by the Step 1.3 CrmSync Value Objects

## D-040 — `RemoteId` canonicalizes by id *shape*, not by a `CrmProviderName` branch

**Type:** GAP (the step says "provider-aware" without saying what the provider switches on) · **Step:** 1.3 · **Status:** DECIDED

**Situation.** Plan §5 Step 1.3: "`RemoteId`: provider-aware canonicalization (Salesforce 15 to 18 char)". The `CrmProviderName` enum holds one case (`hubspot`) today, and HubSpot ids are numeric — so a `match ($provider)` would have no Salesforce arm to write and nothing to test.

**Options considered.** (1) Expand any 15-character alphanumeric id to 18 characters, regardless of provider, and leave everything else untouched. (2) Add a `salesforce` case to `CrmProviderName` and branch on it. (3) Branch on the provider string when it equals `'salesforce'`.

**Decision.** Option 1. The migration note's requirement is that a case-sensitive 15-character id must not reach the case-insensitive column, and the shape *is* that condition: a 15- or 18-character alphanumeric id is the form the checksum applies to. Keying on shape means (a) a provider added later that uses the same id format is covered with no code change, (b) a mislabelled provider cannot defeat the rule, and (c) nothing is claimed about Salesforce that the enum cannot yet express. `$provider` stays a parameter — it is the documented contract and the place a genuinely provider-specific rule would go — but no branch depends on it today.

**Basis.** plan §5 Step 1.3; the `crm_mappings` migration's note on 15-character ids and case-insensitive collations; `CrmProviderName` (one case only); AGENTS §11 (no speculative surface).

**Impact (files, reversibility).** Any 15-char alphanumeric id from any provider expands; adding a provider-specific rule later is one branch inside `canonicalize()`. Reversible.

**Proposed AGENTS.md change:** none. The plan's wording "provider-aware" overstates what a one-provider enum can express; keying on shape satisfies the intent.

## D-041 — two orderings the Value Objects depend on, neither stated in the rules

**Type:** GAP · **Step:** 1.3 · **Status:** DECIDED

**Situation.** Two behaviours decide whether these VOs are correct and neither is specified anywhere:
1. `ContentHash` must hash the same state identically regardless of key order, but must **not** treat a reordered *list* as the same state.
2. `SanitizedErrorMessage` must remove credentials before capping the length, or a truncated message can end mid-token and leave a partial secret that no pattern then matches.

**Options considered.** (1) Sort keys recursively (`ksort`) while preserving list order (`array_is_list()`), and scrub fully before truncating. (2) Sort everything, including lists, for a simpler implementation. (3) Truncate first, on the theory that the cap bounds the work.

**Decision.** Option 1, both. A list's order *is* part of its value — sorting `[1,2]` to `[2,1]` would make two different states hash the same and silently suppress a real change as an "echo". And truncating before scrubbing is a security bug: the cap would cut a token, the patterns would no longer match the fragment, and the fragment would be stored and later read by an administrator.

**Basis.** plan §5 Step 1.3 (key-sorted hashing; S4 scrubbing); the `dead_letters.error_message` column (S4, "an administrator reads it"); AGENTS §11 (no silent failure).

**Impact (files, reversibility).** Both are internal to their VO and covered by tests (list-order sensitivity, secret-past-the-cap). Reversible.

**Proposed AGENTS.md change:** none; these are properties of these two VOs rather than module-wide rules.

---

## J. Gaps found by the Step 1.4 SyncEventEntity transitions

## D-042 — the attempt counter increments on the FAILURE, never on the claim; `with()` needs a KEEP sentinel

**Type:** GAP + SELF-CHECK DEFECT · **Step:** 1.4 · **Status:** DECIDED

**Situation.** Two questions Step 1.4 leaves open, the second found by the self-check:
1. `attempts` could be incremented when a worker claims the event or when the attempt fails. The step says only that `releaseStaleLock()` does `attempts + 1`.
2. The first implementation's private `with()` helper copied fields with `$field ?? $this->field`, so passing `null` to clear `locked_at` was indistinguishable from omitting it — and every clearing transition silently failed. Confirmed by execution: `markSucceeded()`, `scheduleRetry()` and `markDead()` all left `locked_at` at the claim instant.

**Options considered.** (1) Increment on failure, and give `with()` a private `KEEP` sentinel default so `null` means "clear". (2) Increment on the claim, and keep `??`. (3) Increment on the claim, and write each transition out longhand.

**Decision.** Option 1, both. Incrementing on the claim would consume the budget for a worker that crashed before calling the CRM, so a crash loop would exhaust the retries without a single real attempt — defeating the counter's purpose. And a stale `locked_at` on a finished event makes the reaper see a live lease on work the ledger has already settled, so it would resurrect succeeded or dead events; the sentinel is what makes "clear" expressible at all.

**Basis.** plan §5 Step 1.4 (`releaseStaleLock` does `attempts + 1`); `05` R1.2 (a behaviour method returns a NEW instance with the rule enforced); `01` P6; the `sync_events` schema (`locked_at` nullable = "no lease"); the Step 1.4 self-check.

**Impact (files, reversibility).** `with()`'s three nullable parameters take a sentinel; internal only. Reversible.

**Proposed AGENTS.md change:** none — but a general note is worth having: a copy-with-overrides helper on an immutable Entity must distinguish "omit" from "set to null", or every nullable field it can clear will silently fail to.

## D-043 — `releaseStaleLock()` refuses an exhausted event; the reaper must dead-letter instead

**Type:** SELF-CHECK DEFECT + RULE GAP · **Step:** 1.4 · **Status:** DECIDED

**Situation.** S1 says the retry ceiling is "kept far below 255 so reaper increments cannot overflow the tinyint". The first implementation bounded only `scheduleRetry()`; `releaseStaleLock()` incremented without limit. A 400-iteration reclaim loop drove `attempts` to exactly 255 — the `unsignedTinyInteger` ceiling — where the write is refused or truncated and the event is stuck in `processing` with a meaningless counter. S1's guarantee therefore held only if the reaper stops at the ceiling, which nothing enforced.

**Options considered.** (1) `releaseStaleLock()` throws `exhausted()` once `attempts` would pass `MAX_ATTEMPTS`; the reaper catches it and dead-letters the event. (2) Cap `attempts` at `MAX_ATTEMPTS` silently and keep releasing. (3) Let the reaper keep incrementing and rely on `MAX_ATTEMPTS` being small.

**Decision.** Option 1. Option 2 releases an event into a queue that will hand it to the same crashing worker forever, which is not recovery; option 3 is what produced the defect. Refusing by name makes the event's only remaining move explicit, and keeps `attempts` clear of the column limit from **every** starting point (verified across `0,1,2,3,4,5,250,254`: max observed 254).

**Consequence for Step 3.5:** the reaper must catch `SyncEventTransitionException` from `releaseStaleLock()` and call `markDead()` on that event, rather than letting the exception propagate and abort the reaper's pass. Recorded there as a required behaviour, not a suggestion.

**Basis.** plan §3 S1 (the ceiling exists so reaper increments cannot overflow); the `sync_events` schema (`attempts` `unsignedTinyInteger`); `01` P6 (no silent failure); the Step 1.4 self-check.

**Impact (files, reversibility).** One guard in `releaseStaleLock()`; one added responsibility in Step 3.5. Reversible.

**Proposed AGENTS.md change:** none. Rule 13 (DB Optimizer) already asks for the column's bounds to be respected; this is that rule applied to the Domain constant that guards the column.

---

## K. Gaps found by the Step 1.6 ports and their supporting types

## D-044 — a `readonly` Value Object cannot promote a property it must normalize

**Type:** SELF-CHECK DEFECT · **Step:** 1.6 · **Status:** DECIDED

**Situation.** `LeadFilter` is `final readonly` and must normalize one of its own inputs (a search term) inside the constructor. The first implementation declared `public ?string $search = null` as a promoted constructor property and reassigned it to the trimmed value in the body. PHP refuses a second write to a readonly property, so construction was a **fatal error on every call that passed a search term** — `Cannot modify readonly property LeadFilter::$search`.

**Options considered.** (1) Declare the property above the constructor, take it as a plain parameter, and assign it once. (2) Drop `readonly` from the class. (3) Normalize in a static named constructor instead, leaving the public constructor pure.

**Decision.** Option 1. Option 2 gives up the immutability that makes the filter safe to pass around and to cache, which is the whole reason the class is readonly. Option 3 hides the constructor and splits one rule across two entry points, when the plan reads the filter as a value object built from request input (Step 3.6 clamps it through `LeadRules` in the Request Mapper, which expects one obvious way to construct it).

**Basis.** plan §5 Step 1.6 (the filtered read takes a `LeadFilter`); `05` R1 (an immutable VO validates itself in its named constructor); the Step 1.6 self-check.

**Impact (files, reversibility).** One class; the promoted property becomes a plain parameter plus one assignment. Reversible.

**Proposed AGENTS.md change:** yes, and it is general — worth a line in rule 05: **a `readonly` class that normalizes its own input must declare that property above the constructor and assign it once; a promoted readonly property cannot be reassigned, and the failure is invisible until a non-null value is passed.** `EmailAddress` and `PhoneNumber` (Step 1.1) already follow this shape, so the rule would be recording existing practice rather than introducing a new one.

## D-045 — a blank search term is absence, not a term that matches everything

**Type:** GAP · **Step:** 1.6 · **Status:** DECIDED

**Situation.** A lead list arrives with a search box, and an empty or whitespace-only submission reaches the filter. The step does not say whether `''` is kept, dropped, or treated as a term. The second round of the self-check caught the first implementation keeping the trimmed-but-empty string, so `hasCriteria()` returned true for a filter that narrowed nothing.

**Options considered.** (1) Trim and collapse a blank result to `null`. (2) Keep `''` and let the adapter skip an empty term. (3) Reject a blank term as invalid input.

**Decision.** Option 1. Option 2 puts the decision in the adapter, which is where a business decision must not live (`AGENTS §11`), and leaves the risk that a `LIKE '%%'` reaches the query and scans every row to filter nothing. Option 3 punishes the ordinary case: pressing Search with an empty box is not an error.

**Basis.** plan §5 Step 1.6 (filtered pages); `AGENTS §11` (adapters hold no business logic); `05` R12 (list input is clamped through a Domain rules class); the Step 1.6 self-check Round 1.

**Impact (files, reversibility).** One statement in `LeadFilter::__construct`. Reversible.

**Proposed AGENTS.md change:** none; this is the standard `''`-to-`null` normalization the guardrail list already requires for `email`/`phone` (plan §6), applied to a search term.

---

## L. Gaps found by the Step 2.2 ledger adapter

## D-046 — a throwing reaper sweep commits its releases but returns no list

**Type:** GAP (contract cannot express the outcome) · **Step:** 2.2 · **Status:** DECIDED

**Situation.** `D-043` requires `releaseStaleLeases()` to refuse an exhausted event by throwing, so the reaper pass can dead-letter it. But the method is a **batch** operation returning `list<SyncEventEntity>`. When one row in the batch is exhausted, the throw discards the returned list even though the other rows in that batch have already been released and committed.

**Options considered.** (1) Drain the batch first, rethrow the first refusal afterwards, and let the caller re-sweep. (2) Rethrow in place as soon as a refusal is met. (3) Absorb the refusal and return the released rows, leaving the exhausted row for some later pass.

**Decision.** Option 1. Option 2 starves the queue: the scan is ordered `locked_at ASC`, so an exhausted row older than the rows behind it aborts the pass at the same position every time and nothing after it is ever reclaimed. Option 3 discards the only signal that the row needs dead-lettering, which is the thing `D-043` exists to preserve. Option 1 keeps both properties — every releasable row is released, and the refusal is still named — at the cost that the caller learns *which* rows were released only by re-sweeping, since the throw carries no list.

**Verified.** Against the real database: with an exhausted row ordered *before* a healthy stale row, the sweep throws, the healthy row ends `pending` with `attempts` incremented and its lease cleared, and the exhausted row is left `processing` at `MAX_ATTEMPTS`. The releases are committed; only the list is lost.

**Consequence for Step 3.5:** after catching the refusal and dead-lettering the named event, the reaper must call `releaseStaleLeases()` **again** to learn the remaining releases, and must tolerate a second throw. Recorded there as required behaviour.

**Basis.** `D-043`; `SyncEventRepositoryContract::releaseStaleLeases()` (the frozen signature returns a list); the `sync_events` schema (`attempts` `unsignedTinyInteger`); the Step 2.2 self-check.

**Impact (files, reversibility).** One `try`/`catch` inside the batch loop and one deferred rethrow; one added responsibility in Step 3.5. Reversible.

**Proposed AGENTS.md change:** none for the rule text. A general note is worth having in rule 05: **a batch port method that signals a per-item refusal by throwing cannot also return the items it succeeded on — decide explicitly which the caller needs, and if it needs both, say so in the contract before the signature is frozen.** This is the second decision in this plan (`D-042` was the first) where a "copy/collect" helper conflated two meanings; here the conflation is between *the batch failed* and *one item in the batch was refused*.

---

## M. Gap found by the Step 2.4 transactional outbox

## D-047 — the outbound idempotency key is derived from the lead's uuid, not from a mutable field

**Type:** GAP (DP-6 specifies a shape but names no external id for a capture) · **Step:** 2.4 · **Status:** DECIDED

**Situation.** DP-6 defines the idempotency key as `sha256(source + ':' + external submission/order/event id)`. For an outbound **capture** there is no external submission id at this layer: the capture arrives from Contact Form 7, WooCommerce or a manual entry, and by the time the outbox adapter runs, all that identifies the work is the lead it created.

**Options considered.** (1) `sha256('lead.captured' + ':' + $lead->uuid)`. (2) Derive from the lead's email or phone. (3) Derive from the lead's row id. (4) Let the caller pass the key in.

**Decision.** Option 1. Option 2 breaks the guarantee the key exists for: the key must be stable across a retry, and a lead enriched with a corrected email between the first attempt and the retry would compute a *different* key and queue a second event for work already owed — two CRM records for one lead. Option 3 is stable but not available at the moment the event is built inside the same transaction that assigns it, and it is a local surrogate rather than something the CRM can be told. Option 4 pushes the rule into every caller, which is exactly where `D-043`/`D-046` show a rule stops being enforced once it is duplicated. The uuid is minted once at capture (`A3`) and never changes, so it is the one field that is both stable and known before the insert completes.

**Verified.** Three consecutive `enqueueForCapturedLead()` calls for one lead produced exactly one `sync_events` row, and the key matched `hash('sha256', 'lead.captured:' . $uuid)`; a different lead produced its own row.

**Consequence for Steps 3.1 and 3.2:** the capture enqueues through this adapter and never computes a key itself; the worker never recomputes one. A second capture of the same lead is a no-op by the unique index rather than by a prior read, so it is race-safe.

**Basis.** plan §2 DP-6 (the shape) and DP-8 (HubSpot only); plan §2 A3 (the uuid is minted at capture and never changes); plan §5 Step 2.4; the `sync_events.idempotency_key` unique column; the Step 2.4 self-check.

**Impact (files, reversibility).** One private method on one adapter, plus the constant naming the event type. Reversible — changing the derivation changes which replays are treated as duplicates, and no stored key becomes invalid because the column only compares equality.

**Proposed AGENTS.md change:** none. Worth noting for the module README: an idempotency key is only idempotent if **every field it is derived from is immutable after creation**; a key built from any field a later step can edit is a duplicate generator, not a duplicate guard.

---

## N. Gap found by the Step 2.5 HubSpot adapter

## D-048 — a fetch that names one property does not return "the record"

**Type:** GAP · **Step:** 2.5 (closed in 3.4) · **Status:** CLOSED — widened in Step 3.4 on the developer's approval (2026-11-23)

**Situation.** `HubSpotAdapter::fetchRecord()` sends `?properties=leadbridge_external_id`, so HubSpot returns that one property plus its own metadata (`id`, `createdAt`, `updatedAt`). The port it implements documents the opposite: `CrmContactGatewayContract::fetchRecord()` returns "the record's properties, as the provider returns them". Step 3.4 is the first caller that needs actual contact fields — an inbound event must be applied to a lead and hashed, and one identifier cannot supply either.

**Options considered.** (1) Leave the filter, and let Step 3.4 name the property set it needs and widen this line. (2) Drop the query parameter and take HubSpot's default property set, which contains the standard contact fields but **not** the custom `leadbridge_external_id` this request deliberately asks for. (3) Write an explicit list now (`email`, `phone`, `firstname`, `lastname`...).

**Decision.** Option 1, deferred. Step 2.5's acceptance names no property set, so (3) would be this step inventing Step 3.4's requirements — and inventing a downstream requirement is precisely what `A9` forbids. Option 2 trades one gap for another (losing the identifier the request was built for) and would quietly break the inbound path's mapping check. The change is one query string, entirely reversible, and it is cheapest to make at the moment the inbound use case states what it reads.

**Basis.** plan §5 Step 2.5 (no property set specified); `09` A9 (GAP tier: do not invent business rules or downstream requirements); `AGENTS §11` (the adapter must serve the port it implements, and the port's own docblock is the contract it currently does not meet).

**Impact (files, reversibility).** One line — the query string in `fetchRecord()`, and the property list if the widening needs a constant. Reversible; no stored data depends on it.

**Proposed AGENTS.md change:** none. A note for Step 3.4 only: **a read port that says "the record" must be asked for the fields its caller uses; a filter written for one purpose (verifying an identifier) silently becomes a truncated read for the next.**

**Consequence for Step 3.4:** before the inbound path is written, state the property set the lead enrichment and the `ContentHash` consume, then widen `fetchRecord()` (or its call) to it. Until then the method is usable only for identifier checks.

**Closure (Step 3.4).** The property set was widened to the six contact fields plus the
external identifier — `leadbridge_external_id`, `email`, `firstname`, `lastname`, `phone`,
`company`, `website` — as one private constant beside `EXTERNAL_ID_PROPERTY`. It is exactly the set
the inbound enrichment DTO consumes and the fields the state hash compares, so the adapter's
request and the Core's expectations now share one definition.

---

## O. Gaps and defects found by Phase 3 (application services)

## D-049 — the outbound batch's three frames, and why the blueprint is recorded as a decision

**Type:** IMPLEMENTATION NOTE (plan-mandated shape) · **Step:** 3.2 · **Status:** CONFIRMED

**Situation.** Step 3.2 states the claim/HTTP/record split in one sentence. Phase 3 is the first
place the split exists in code, and the ordering is the whole guarantee: an HTTP call inside a
transaction would hold a row lock for the provider's response time (up to the adapter's 20 s
timeout against a 300 s lease), while a claim that committed only after the call would let the
reaper hand the same event to a second worker.

**Decision.** Claim in one short transaction; unwrap the claim result to a plain array before the
loop; call the gateway with **no** transaction open; record each outcome in its own transaction
that re-locks the row and verifies it is still `processing`. Implemented in
`SyncCommandService::processOutboundBatch()` / `recordOutcome()`.

**Basis.** plan §5 Step 3.2; plan §6 guardrails ("No HTTP call inside a transaction"); AGENTS §10.

**Impact.** One method pair; the call order is the contract. Reversible only by reintroducing the
lock-duration fault the split exists to prevent.

**Proposed AGENTS.md change:** none for the text; worth a line in rule 05 that a worker's
"claim → act → record" split is three transactions, and the middle one is not a transaction at all.

## D-050 — the reaper identifies its exhausted row through the READ port

**Type:** GAP (frozen exception carries no id) · **Step:** 3.5 · **Status:** DECIDED

**Situation.** `D-043` requires `releaseStaleLeases()` to throw on an exhausted event, and `D-046`
requires the releases it already made to commit despite that throw. The exception is
`SyncEventTransitionException::exhausted()` — frozen, and carrying only `attempts` and `MAX_ATTEMPTS`.
The reaper therefore knows *that* a row must be dead-lettered but not *which* one. Adding an id to
the exception would edit a frozen Domain exception (AGENTS §3).

**Options considered.** (1) Add a bounded read to `SyncEventQueryContract` that names the oldest
exhausted stale row. (2) Widen the frozen exception with an optional event id. (3) Have the reaper
scan the ledger through a new write-port method.

**Decision.** Option 1. The query selects `status = 'processing' AND attempts >= MAX_ATTEMPTS AND
locked_at < now - lease`, ordered by `locked_at, id`, `LIMIT 1` — the same boundary the write-side
sweep uses, so the two agree on which row is at fault. It is bounded, read-only and on the read
side; option 2 edits frozen code; option 3 puts a pure read on a write port, which §9 forbids.

**Basis.** `D-043`, `D-046`; AGENTS §3 (zero edits in FROZEN files); AGENTS §9; the
`sync_events_status_next_attempt_idx` and lease columns.

**Impact.** One additive read-port method plus its adapter method. Reversible.

**Proposed AGENTS.md change:** none. General note worth having: **when a batch port signals a
per-item refusal by throwing, the refusal should name the item; if the contract is frozen without
it, the identity must be recovered by a bounded read rather than by editing the frozen type.**

## D-051 — inbound enrichment crosses modules through a Lead-owned contract and DTO

**Type:** GAP (no inbound seam existed) · **Step:** 3.4 · **Status:** DECIDED

**Situation.** Step 3.4 requires applying an inbound change "through Lead's application
contract/port, keeping CrmSync decoupled from Lead internals", but no such port existed: Lead
exposed `LeadCommandServiceContract::captureLead()` only, and `LeadQueryContract::findById()` by a
local id the inbound payload does not carry (it carries the remote record id and the record's uuid).

**Options considered.** (1) A Lead-owned `LeadInboundEnrichmentContract` + `LeadCrmEnrichmentDTO`,
implemented by `LeadCommandService`. (2) CrmSync imports `LeadEntity` and `LeadRepositoryContract`
directly. (3) CrmSync maps the remote record onto `captureLead()`.

**Decision.** Option 1. Option 2 couples the modules' Domain/Infrastructure and breaks either one's
portability (AGENTS §1, §3); option 3 would run the create/enrich/merge decision again for a record
that is already mapped, and would mint a new lead when the mapping is stale rather than surfacing it.
The DTO carries the lead's uuid, the mapping's expected local id, and the five enrichable fields;
`LeadCommandService::enrichFromCrm()` re-reads the lead by uuid under `FOR UPDATE`, verifies the id,
and applies `enrichWith()`.

**Basis.** plan §5 Step 3.4; AGENTS §1 (zone table), §3 (port freeze), §11 (ports in Core,
implementations outside); AGENTS §8 (inbound contracts).

**Impact.** One port, one DTO, one service method, and an additive `findByUuid()` on
`LeadQueryContract` (no existing read could resolve a uuid). Reversible.

**Proposed AGENTS.md change:** none; this is §9's CQRS split applied to a cross-module use case.

## D-052 — `CrmProviderRegistryContract` is implemented by `HubSpotProviderRegistry`

**Type:** GAP (port with no implementation) · **Step:** 3.2 · **Status:** DECIDED

**Situation.** `CrmProviderRegistryContract` was declared in `Lead/1.6` and bound nowhere, so
`SyncCommandService` could not be constructed at all: its constructor takes the port. The first
release supports one provider (DP-8), so a registry with one arm could also have been skipped by
injecting `CrmContactGatewayContract` directly.

**Options considered.** (1) A thin `HubSpotProviderRegistry` in `Infrastructure/Adapters` that
returns the configured gateway and verifier for `hubspot`. (2) Drop the port from the service and
inject the gateway directly. (3) Delete the port.

**Decision.** Option 1. Option 2 would put provider selection inside a Service as an implicit "there
is only one" assumption, and the ledger stores a provider name that the worker must honour; option 3
removes a port the plan names. The registry takes both adapters by injection, so the provider
provider stays the only place that knows how a gateway is built.

**Basis.** plan §4 (the port is named); plan §2 DP-8; `CrmProviderRegistryContract`'s own docblock
("adding a provider adds a binding rather than a branch inside Core").

**Impact.** One class, one binding. Adding Salesforce later means one more binding and a lookup
inside `gatewayFor()`.

**Proposed AGENTS.md change:** none.

## D-053 — the per-event outcome is recorded through a `Result`, and the retry guard asks about the NEXT attempt

**Type:** SELF-CHECK DEFECT (two, one masking the other) · **Step:** 3.2 · **Status:** DECIDED

**Situation.** Two defects in the first draft of `recordOutcome()`:
1. The retry path was guarded by `! $locked->isExhausted()`, i.e. "the event has attempts left".
   `SyncEventEntity::scheduleRetry()` increments `attempts` and then refuses when the *incremented*
   count passes `MAX_ATTEMPTS` (`D-042`), so an event at `attempts = MAX_ATTEMPTS` passed the guard
   and threw inside the transaction — the final transient failure ended as a rolled-back recording
   instead of a dead letter.
2. The method returned `array{succeeded, retried, dead}`, so a failed recording was reported to
   `processOutboundBatch()` as `0/0/0` and the batch continued as if the event had vanished.

**Options considered.** (1) Guard with `SyncRetryRules::mayRetry($attempts + 1)` and return a
`Result`. (2) Keep `isExhausted()` and catch `SyncEventTransitionException` inside the closure. (3)
Count a failed recording as "dead".

**Decision.** Option 1. Option 2 hides the same off-by-one behind an exception and would need a
second dead-letter attempt for a row it just failed to write; option 3 reports a dead letter that does
not exist. The guard now asks the question the entity actually answers, and a failed recording is
returned as a failure that stops the batch — leaving the remaining claimed rows to the reaper.

**Basis.** `D-042` (attempt counting), `D-043` (ceiling), plan §5 Step 3.2 ("permanent failure or
exhausted → markDead + DeadLetterEntity"), AGENTS §10 ("a failure after a write is signalled by
throwing").

**Impact.** One guard, one return type, one call-site unwrap. Reversible.

**Proposed AGENTS.md change:** none. General note: **a guard that decides whether a transition is
allowed must ask the same question the entity's guard asks — "will the next attempt be allowed" is
not the same as "has this attempt been used".**

## D-054 — echo detection hashes one canonical provider-shaped map in both directions

**Type:** SELF-CHECK DEFECT · **Step:** 3.4 · **Status:** DECIDED

**Situation.** The outbound path hashed the properties it was about to send; the inbound path hashed
a differently-shaped map (aliases `external_id`/`company_name`/`website_url`) and then stripped the
aliases. Even after stripping, the two differed on values the provider normalizes: an email returned
with different casing, or a phone returned without its leading `+`, produced a different digest — so
`isEcho()` returned false for our own write, the change was applied again, and a fresh outbound event
followed.

**Options considered.** (1) Hash one canonical map in both directions: email case-folded, phone
reduced to `+` + digits, empty strings to `null`, keys fixed. (2) Hash the vendor's raw body. (3)
Compare field by field instead of hashing.

**Decision.** Option 1. Option 2 hashes provider metadata (`updatedAt`, `hs_object_id`) that changes
on every write, so nothing would ever look like an echo; option 3 replaces one stored digest with a
per-field comparison against a snapshot the schema does not keep.

**Basis.** plan §5 Step 3.4 (`isEcho()` suppression); `CrmMappingEntity::isEcho()`; `D-048` (the
property set); the `crm_mappings.last_synced_hash` column.

**Impact.** One private canonicalization used by both paths; the harness asserts the two shapes
produce the same canonical array. Reversible, and changing it only affects future comparisons.

**Proposed AGENTS.md change:** none. Worth a note: **an echo hash is only meaningful if both sides
are canonicalized by the same function — two "equivalent" normalizations written independently will
disagree on exactly the inputs the provider rewrites.**

## D-055 — the sync query service depends only on the ports it reads

**Type:** SELF-CHECK DEFECT (unused dependency) · **Step:** 3.6 · **Status:** DECIDED

**Situation.** `SyncQueryService` was drafted with `CrmMappingQueryContract` in its constructor and
never used it, while the provider resolved it anyway. The dependency advertised a read the service
does not perform, and `QueryService` is the layer where "which read ports does this use case need"
is supposed to be answerable from the signature.

**Options considered.** (1) Remove the parameter and the provider argument. (2) Keep it for a future
mapping lookup. (3) Add a mapping lookup so the dependency becomes true.

**Decision.** Option 1. Option 2 is an unused dependency that will be trusted by the next reader;
option 3 adds a read no requirement asks for (YAGNI, AGENTS §11).

**Basis.** plan §5 Step 3.6 ("read ports only"); rule 05 R12 (Query Service depends only on the read
contracts it uses); AGENTS §11.

**Impact.** Two-line removal; the constructor now matches the provider exactly (harness check).

**Proposed AGENTS.md change:** none.
