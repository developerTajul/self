# Step 0.2 — Scaffold module `Lead` (03, FULL-BASELINE) + `UuidGeneratorContract` (Lead)

**Status:** DONE_WITH_NOTES

**Summary.** Scaffolded the complete `core/Lead/` tree of plan §4, the two additive Shared ports the
plan's A3 and D-024 call for (`UuidGeneratorContract`/`WpUuidGenerator`, `ClockContract`/`WpClock`),
plus `WpEventDispatcher` (rule 03's Command Service ctor template), and the module composition root
`LeadServiceProvider` listed in `config/app.php`. `LeadEntity`, `LeadStatus` and `LeadSource` are
fully implemented from the `leads` migration; every other file is a skeleton with a `// TODO:` naming
the step that fills it. All 31 new classes load, `php -l` is clean, and `composer dump-autoload` ran.
Seven scaffold readings had to be decided (D-028…D-034) because rule 03 and the step brief disagree
in four places.

**Inputs used.**
- Plan `docs/ai-plans/lead-module-action-plan.md` §4 (target tree) and §5 Step 0.2.
- `database/migrations/2026_09_29_232811_create_leads_table.php` (the schema the Entity mirrors).
- `.claude/rules/03-module-scaffolding.txt` (governing), `01`, `02` (§2.3 adapter table), `05` (§5, R11, R12.5), `06` (T8, T10), `08` (E5).
- `.claude/skills/module-scaffolder/SKILL.md` (reference).
- Structural model: `core/Company/**`, `core/Shared/Infrastructure/Utilities/SlugGenerator.php`, `core/Shared/Domain/Contracts/SlugCheckerContract.php`.
- `DECISIONS.md` D-024…D-027 (the developer's confirmations, recorded before this step).

**Rules and skills applied.** AGENTS §1 (zones), §5 (strict types, `final` placement), §7 (rich domain baseline), §9 (port split), §11 (ports/adapters), §13 (style); rules 01, 02, 03 (governing), 05, 06; skill `module-scaffolder`; 09 A4/A5/A6/A9/A11. Tests paused per AGENTS §6.

---

## Files

**Created — 31 code files**

Additive Shared (A3, D-024, D-031):
- `core/Shared/Domain/Contracts/UuidGeneratorContract.php` — `generate(): string`.
- `core/Shared/Domain/Contracts/ClockContract.php` — `now(): DateTimeImmutable`.
- `core/Shared/Infrastructure/Utilities/WpUuidGenerator.php` — `wp_generate_uuid4()`, fully implemented.
- `core/Shared/Infrastructure/Utilities/WpClock.php` — UTC instant, fully implemented.
- `core/Shared/Infrastructure/Events/WpEventDispatcher.php` — two `do_action()` calls per `02` §2.3 / `08` E5, fully implemented.

`core/Lead/Domain/` (10):
- `Entities/LeadEntity.php` — **fully implemented**: `readonly class`, promoted ctor, one property per `leads` column; no behaviour methods.
- `Enums/LeadStatus.php`, `Enums/LeadSource.php` — **fully implemented**: cases per A1, `translationKey()`, `values()`.
- `ValueObjects/EmailAddress.php`, `ValueObjects/PhoneNumber.php` — skeletons.
- `Contracts/LeadRepositoryContract.php` — WRITE port, empty.
- `Exceptions/{LeadNotFoundException,LeadContactKeyMissingException,InvalidEmailAddressException,InvalidPhoneNumberException}.php` — `final … extends RuntimeException` with `TRANSLATION_PREFIX` + `translationKey`.

`core/Lead/Application/` (12):
- `Contracts/{LeadQueryContract,LeadCommandServiceContract,LeadQueryServiceContract,LeadMapperContract,LeadCaptureOutboxContract,LeadMappingRelinkContract}.php` — all empty skeletons.
- `DTOs/Requests/LeadCaptureDTO.php`, `DTOs/Responses/LeadResponseDTO.php` — skeletons (no properties yet).
- `Mappers/LeadMapper.php` (implements `LeadMapperContract`), `Mappers/LeadResponseMapper.php` (declares `toResponseDTO()`, throws `LogicException`).
- `Services/LeadCommandService.php`, `Services/LeadQueryService.php` — constructors and injections only.

`core/Lead/Infrastructure/` (3):
- `Mappers/LeadEntityMapper.php` — declares `toEntity()`/`toPersistenceArray()`, both throw `LogicException`.
- `Repositories/WpLeadWriteRepository.php` extends `BaseWriteRepository`; `Repositories/WpLeadReadRepository.php` extends `BaseReadRepository`.

Delivery (1):
- `app/Providers/LeadServiceProvider.php` — binds 5 module contracts (see below).

**Modified — 2 files**
- `app/Providers/AppServiceProvider.php` — added three shared bindings: `UuidGeneratorContract` → `WpUuidGenerator`, `ClockContract` → `WpClock` (both `bind`), `EventDispatcherContract` → `WpEventDispatcher` (singleton, prefix from `config('app.prefix')`). The placeholder `register()` comment was replaced; no existing binding changed (`register()` was empty before).
- `config/app.php` — added `\App\Providers\LeadServiceProvider::class` after `CompanyServiceProvider`, with a comment.

**Not touched:** `framework/`, `vendor/`, `database/` (no migration created or edited), `RepositoryServiceProvider.php` (its "modified" status in `git status` predates this session — the shared adapters were already bound there), every other module.

## Wiring (composition root, P1)

`LeadServiceProvider::register()` binds, each contract exactly once:
`LeadRepositoryContract` → `WpLeadWriteRepository`; `LeadQueryContract` → `WpLeadReadRepository`;
`LeadCommandServiceContract` → `LeadCommandService`; `LeadQueryServiceContract` → `LeadQueryService`;
`LeadMapperContract` → `LeadMapper`. Singletons: `LeadEntityMapper`, `LeadResponseMapper`.
**Deliberately not bound here:** `LeadCaptureOutboxContract` and `LeadMappingRelinkContract` — their
adapters live in `core/CrmSync/Infrastructure` and are bound by `CrmSyncServiceProvider` in Step 0.3
(plan §5 Step 0.3); injecting them now would leave `LeadCommandServiceContract` unresolvable. See D-030.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files exist, no unexpected file | PASS | 31 code files created; `git status --porcelain core/Lead core/Shared app/Providers config/app.php` lists only planned paths. |
| C2 scope audit vs brief/A4 | PASS | Plan §4 tree + A4 files (`LeadServiceProvider`, `config/app.php`, `AppServiceProvider`, additive Shared). Nothing under `framework/`/`vendor/`/`database/`. |
| C3 `php -l` per file | PASS | Every created/modified file: "No syntax errors detected" (31 + `AppServiceProvider.php` + `LeadServiceProvider.php` + `config/app.php`). The only `php -l` failures in the sweep were the **pre-existing** deleted `Laravel*`/`Eloquent*` shared adapters (absent from the worktree, still in the index) — not files of this step. |
| C4 `composer dump-autoload` | PASS | "Generated optimized autoload files containing 1911 classes". |
| C5 rule DoDs walked | PASS with notes | 01 P6, 05 R11, 06 T10 and the skill checklist walked below. |
| C6 06 T8 greps | **NOT zero — 3 documented matches** | T8 A: `CacheInterface.php:46` (pre-existing docblock naming `wp_cache_get()`), `EventDispatcherContract.php:15` (pre-existing `dispatch()` declaration), `ClockContract.php:22` (**new** `now()` declaration). T8 B: **zero** (exit 1). T8 C: N/A (no port). Analysis and proposal in D-034. |
| C7 wiring | PASS | Each new Contract bound exactly once; no existing binding changed; `config/app.php` lists the provider. |
| C8 schema fidelity | PASS | `LeadEntity` has exactly the 13 `leads` columns, `id` is `?int`, `uuid` is non-nullable `string`, `status`/`source` are the Domain enums, `meta_json` → `array $meta`, `created_at`/`updated_at` → `?DateTimeImmutable` (D-032). No column invented or omitted. |
| C9 leftovers | PASS | No `dd`/`dump`/`var_dump`. Intentional `// TODO:` markers only; every one names the step that fills it (listed under Deferred). |
| C10 runtime check | N/A | The brief calls no runtime check; the plugin was not exercised. |
| C11 tests | Paused per AGENTS.md §6 — none written, none run, none claimed. |
| C12 transactions | N/A | No Service method yet (skeleton); nothing writes. |
| C13 boundaries | PASS | `core/Lead/Domain` + `core/Lead/Application` import only Core types, PSR `LoggerInterface` and PHP built-ins; no `App\`/`Framework\`/`$wpdb`/WP function. Query Service injects no write-side port. Read adapter extends `BaseReadRepository` (inherits no write method); write adapter extends `BaseWriteRepository`. No persistence exception in Core. |
| C14 file existence | PASS | Every path checked before writing (`core/Lead`, the Shared ports, `LeadServiceProvider` all absent on disk). |
| C15 rule-to-skill sync | N/A | No AGENTS.md / rule / skill edit. |
| C16 repairs | N/A | No A6b round ran. |

### Skill `module-scaffolder` checklist

| Item | Result | Note |
| --- | --- | --- |
| Every schema column mapped, types exact | PASS | 13/13 columns; evidence above. |
| Entity `readonly class`, not `final`, no behaviour, no framework import, no `$domainEvents`/`releaseEvents()` | PASS | `LeadEntity.php` — ctor + one `// TODO:` marker only. |
| Enum has `translationKey()` + `values()`, no `label()` | PASS | Both enums; executed: `values()` → `new,contacted,qualified,converted,lost` and `contact_form_7,woocommerce,hubspot,manual`; `LeadStatus::new->translationKey()` → `lead.status.new`. |
| No `DataObjects/`, `Events/`, `Jobs/` | PASS | None created. |
| No Request DTO / Request Mapper / MapperContract | **N/A — deliberate deviation** | `LeadCaptureDTO`, `LeadMapper`, `LeadMapperContract` were created because plan §4 lists them and Step 0.2's ACCEPTANCE binds `LeadMapperContract`. See D-029. |
| Repository contract blank; Services/Repositories ctor-only | PASS | All ports are empty interfaces; both services and both repositories carry constructors and injections only. |
| Both adapters extend the right base, neither extends the other | PASS | `extends BaseWriteRepository` / `extends BaseReadRepository`. |
| Command/Query services separate; Query injects no write-side port | PASS | `LeadQueryService` ctor: `LeadQueryContract`, `LeadResponseMapper`, `LoggerInterface`. |
| `toPersistenceArray()` skips PK/timestamps, unwraps enums | **N/A** | The mapper is a skeleton (§5 Step 2.1 implements it); its declared body throws `LogicException`. See D-028. |
| `declare(strict_types=1);` first statement, PSR-4 namespaces | PASS | Every file; enforced by `php -l` + the class-load sweep. |
| No file overwritten; provider created, listed, no existing binding changed | PASS | See C1/C14/C7. |
| New Shared port → adapter + one binding | PASS | `UuidGeneratorContract`→`WpUuidGenerator`, `ClockContract`→`WpClock`, `EventDispatcherContract`→`WpEventDispatcher`, each bound once in `AppServiceProvider`. |
| `composer dump-autoload` run | PASS | See C4. |

### Rule 06 T10 / rule 01 P6 / rule 05 R11

- T10: FROZEN files not hand-edited ✔; every new outside capability = Contract + adapter ✔; two persistence adapters ✔; no business logic in adapters ✔; each Contract bound once ✔ (the two cross-module Lead ports are created but bound in Step 0.3 — D-030); duplicate-key → module conflict exception **deferred to 2.1**; Delivery injects inbound contracts **N/A (no delivery yet)**; events/jobs after commit **N/A (no events)**; "T8 A/B zero matches" **not met — 3 documented matches, D-034**.
- 01 P6: strict types + PSR-4 ✔; Domain has zero framework imports ✔; all I/O behind ports ✔; Entities/DTOs immutable ✔; each port bound once ✔; "use cases return `Result`" **N/A (no use case yet)**.
- 05 R11: Entity `readonly class`, not `final`, no framework use ✔; event-less Entity carries neither list nor `releaseEvents()` ✔; Service is Command or Query and implements its inbound contract ✔; "every method returns `Result`" **N/A (no method yet)**; no hardcoded text in Core, enums expose `translationKey()` not `label()` ✔; list input/`FilterDTO`/projection/uniqueness **deferred to Steps 2.1/3.6**.

## Commands run

`git check-ignore -v .claude/rules .claude/skills docs/ai-reports` (guard, no output);
`git ls-files` + `ls`/`find` (file-existence guard); `php -l <file>` over every created/modified file;
`composer dump-autoload`; the 06 T8 A/B greps; a read-only `php -r` class-load sweep (31 class names,
`missing=0`) plus enum/entity execution checks. No migration, no seeder, no test run, no git write
operation, no DB query, no `framework/` read-write.

## Decisions

Recorded in `DECISIONS.md`. D-024…D-027 (from Step 0.1) were marked **DECIDED** with the developer's
resolutions. New this step: **D-028** scaffold depth (skeletons vs rule 03's mechanical baseline),
**D-029** request DTO/mapper created at scaffold time, **D-030** Command Service ctor composition,
**D-031** `WpEventDispatcher` created although no events exist yet, **D-032** datetime columns as
`?DateTimeImmutable`, **D-033** adapter named `WpClock` not the rules' `SystemClock`, **D-034** T8 A
flags port declarations (OPEN).

## Deviations

1. **`LeadCaptureOutboxContract` / `LeadMappingRelinkContract` created but unbound** pending Step 0.3 (D-030). Step 0.2's ACCEPTANCE line "the four Lead ports + two service contracts + `LeadMapperContract` bound once" was read as rule 03 §7.10's five bindings (repository, query, both services, mapper contract); the phrase "four Lead ports" is ambiguous and matches no exact set. Flagged at the STOP GATE for the developer.
2. **`LeadCaptureDTO` / `LeadMapper` / `LeadMapperContract` created** against rule 03's just-in-time separation (D-029), to satisfy plan §4 + the ACCEPTANCE.
3. **`WpEventDispatcher` added** (D-031) — one Shared adapter beyond the plan's Step 0.2 CREATE list, mandated by rule 03's Command Service ctor template.
4. **T8 A is not zero** (3 matches, none a framework call); reported raw under C6 and analysed in D-034. The step is therefore `DONE_WITH_NOTES`, never `DONE` with a hidden unmet DoD item.
5. **Two pre-existing `git status` entries were left alone**: `app/Providers/RepositoryServiceProvider.php` (modified before this session) and the deleted `Laravel*`/`Eloquent*` shared adapters. Not touched, not restored.

## Deferred and open items

- Steps 2.1 / 3.1 fill `LeadEntityMapper`, `LeadResponseMapper`, both DTOs and `LeadMapper`.
- Steps 1.1 / 1.2 fill the Value Objects, the Entity's behaviour and the four exceptions' factories.
- Step 1.6 declares the port methods (all ports are empty by design).
- Step 2.1 adds the lead module's conflict exception and the `duplicateException()` override (D-025).
- Step 0.3 binds `LeadCaptureOutboxContract` / `LeadMappingRelinkContract` via `CrmSyncServiceProvider`.
- **No language file exists for the new keys**: `LeadStatus`/`LeadSource` emit `lead.status.*` and `lead.source.*`, but `lang/` holds only `en_US/companies.php`. A `lang/en_US/leads.php` is needed before any channel renders these keys (not in Step 0.2's scope).
- **D-034 remains OPEN**: the `06` T8 A pattern refinement, or an explicit statement that Core port declarations are expected matches.
- `LeadStatus::new` uses a PHP reserved word as an enum case name; verified legal on PHP 8.3.30 by execution before it was written.

## Tests

Paused per AGENTS.md §6.

## Next step

**Step 0.3 — Scaffold module `CrmSync` (03, FULL-BASELINE)**, with the three Entities and five enums
from the CRM migrations, `SyncEventEntity::HANDLER_OUTBOUND`/`HANDLER_INBOUND`, and the two cross-module
adapters bound in `CrmSyncServiceProvider`.

---

### STOP GATE

- **Step:** `Lead/0.2` — **Status: DONE_WITH_NOTES**
- **Report:** `docs/ai-reports/23-lead-module-scaffold.md`
- **Modules:** `core/Lead/` scaffolded (31 files); `UuidGeneratorContract`/`ClockContract` + adapters bound in `AppServiceProvider`; `LeadServiceProvider` listed in `config/app.php`.
- **Needs the developer's eye (not blockers):** the ACCEPTANCE reading in Deviation 1; the rule-vs-brief conflicts D-028…D-031, D-033; and **D-034** (T8 A cannot reach zero while Core declares the ports the rules require).
- **Next step:** `0.3` — Scaffold the `core/CrmSync/` tree + `CrmSyncServiceProvider`, and bind the lead module's two cross-module adapters.
- **Awaiting:** the developer's go-ahead. Nothing is started until then.
