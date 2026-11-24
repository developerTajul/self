# Step 0.3 — Scaffold module `CrmSync` (03, FULL-BASELINE) (Lead)

**Status:** DONE_WITH_NOTES

**Summary.** Completed the `core/CrmSync/` scaffold to the shape of plan §4. Step 0.2 had already
written the 30 Domain + Application files (three Entities and five enums fully mapped, everything else
a `// TODO:` skeleton); this run added the 12 files that were still missing — three `Infrastructure`
EntityMappers, six `$wpdb` repositories (write/read per table), the two cross-module adapters, and the
module composition root `CrmSyncServiceProvider`, which is now listed in `config/app.php`. All 42
CrmSync classes load, `php -l` is clean, `composer dump-autoload` ran (1911 → 1953 classes), and the
container graph resolves end to end: all 15 contracts across `CrmSync` and the two cross-module `Lead`
ports build, each to the intended implementation. Two rule readings had to be decided (D-035, D-036).

**Inputs used.**
- Plan `docs/ai-plans/lead-module-action-plan.md` §4 (target tree) and §5 Step 0.3.
- `database/migrations/2026_11_22_210000_create_crm_mappings_table.php`,
  `…_210100_create_sync_events_table.php`, `…_210200_create_dead_letters_table.php` (the schema the
  three Entities mirror).
- Rules 01, 02 (§2.3, §7), 03 (governing), 05, 06 (T8, T10).
- Structural model: the Step 0.2 `core/Lead/**` tree and `app/Providers/LeadServiceProvider.php`
  (same module shape, same provider layout).
- `core/Shared/Infrastructure/Repositories/{AbstractWpRepository,BaseWriteRepository,BaseReadRepository}.php`.
- Step 0.2's report (`23-lead-module-scaffold.md`) and `DECISIONS.md` D-028…D-034.

**Rules and skills applied.** AGENTS §1 (zones), §5 (`strict types`, `final` placement), §7 (rich
domain baseline), §9 (CQRS port split), §11 (ports/adapters), §13 (style); rules 01, 02, 03
(governing), 05, 06; 09 A4/A5/A6/A9/A11. Tests paused per AGENTS §6.

---

## Pre-flight

- **CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries (D-032, D-033, D-034), the latest
  `PROJECT-LOG.md` entry (`Lead/0.2`, DONE_WITH_NOTES), and the previous step's report
  (`23-lead-module-scaffold.md`). The only OPEN item is **D-034** (the `06` T8 A pattern flags Core port
  *declarations*); it does not touch this step's files, which declare no `now()`/`wp_*` symbol, so no
  binding decision had to be followed or departed from.
- **GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed
  nothing for any of the three (exit 1). **PASS** — no HALT.

## Files

**Created — 12 code files**

`core/CrmSync/Infrastructure/Mappers/` (3):
- `SyncEventEntityMapper.php` — declares `toEntity()`/`toPersistenceArray()`, both throw `LogicException`
  (filled in Step 2.3).
- `CrmMappingEntityMapper.php` — same shape, filled in Step 2.4.
- `DeadLetterEntityMapper.php` — same shape, filled in Step 3.5.

`core/CrmSync/Infrastructure/Repositories/` (6) — one write and one read adapter per table, each
extending the matching base and implementing the matching port:
- `WpSyncEventWriteRepository` (`BaseWriteRepository` + `SyncEventRepositoryContract`),
  `WpSyncEventReadRepository` (`BaseReadRepository` + `SyncEventQueryContract`).
- `WpCrmMappingWriteRepository` (+ `CrmMappingRepositoryContract`),
  `WpCrmMappingReadRepository` (+ `CrmMappingQueryContract`).
- `WpDeadLetterWriteRepository` (+ `DeadLetterRepositoryContract`),
  `WpDeadLetterReadRepository` (+ `DeadLetterQueryContract`).
- Every adapter carries `TABLE_NAME` (the un-prefixed table name) and a constructor injecting its
  EntityMapper plus an optional lazily-resolved `?wpdb`; no query method yet.

`core/CrmSync/Infrastructure/Adapters/` (2) — the cross-module pair (DP-1, DP-2):
- `LeadCaptureOutboxAdapter` implements `Lead\…\LeadCaptureOutboxContract`, injects
  `SyncEventRepositoryContract`.
- `LeadMappingRelinkAdapter` implements `Lead\…\LeadMappingRelinkContract`, injects
  `CrmMappingRepositoryContract`, `CrmMappingEntityMapper` and `LoggerInterface`.
- Both are skeletons: their Lead contracts are still empty interfaces (methods land in Step 1.6), so
  there is no signature to implement yet.

Delivery (1):
- `app/Providers/CrmSyncServiceProvider.php` — the module composition root; binds 21 abstract→concrete
  entries (see Wiring).

**Modified — 1 file**
- `config/app.php` — added `\App\Providers\CrmSyncServiceProvider::class` after `LeadServiceProvider`,
  with a comment naming the two cross-module ports it also binds.

**Not touched:** `framework/`, `vendor/`, `database/` (verified by mtime — every migration still reads
16:29–17:06, before this session; no migration created or edited), `core/Lead/**`,
`core/Shared/**`, every other provider.

## Wiring (composition root, P1)

`CrmSyncServiceProvider::register()` runs three private methods:

- `registerInfrastructure()` — three `singleton` EntityMappers; six concrete repository bindings
  (each mapper injected by `resolve()`); and six interface bindings that resolve to those concrete
  classes (`SyncEventRepositoryContract`/`SyncEventQueryContract`,
  `CrmMappingRepositoryContract`/`CrmMappingQueryContract`,
  `DeadLetterRepositoryContract`/`DeadLetterQueryContract`). Each table's write and read adapter are
  bound separately; neither knows the other.
- `registerCrossModulePorts()` — `LeadCaptureOutboxContract` → `LeadCaptureOutboxAdapter` and
  `LeadMappingRelinkContract` → `LeadMappingRelinkAdapter`. The ports belong to `Core\Lead\Application`,
  the adapters live here, so `LeadServiceProvider` never names a CrmSync class.
- `registerServices()` — `SyncResponseMapper` and `SyncMapperContract`→`SyncMapper` as singletons;
  `SyncCommandServiceContract`→`SyncCommandService` and `SyncQueryServiceContract`→`SyncQueryService`
  with the constructor arguments in the exact order both Services declare them.

**Acceptance met:** every module contract plus the two cross-module Lead ports is bound **exactly once**;
`php -l` clean; `composer dump-autoload` run; no `Domain/Events`, no `Application/Jobs`.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files exist, no unexpected file | PASS | 12 files created at the paths plan §4 names; the other 30 predate this run. `git status --porcelain core/CrmSync app/Providers config/app.php` lists `core/CrmSync/` (untracked tree) and `CrmSyncServiceProvider.php`, plus `config/app.php` (edit). |
| C2 scope audit vs brief/§4 | PASS | Every created path appears in plan §4; nothing under `framework/`/`vendor/`/`database/`, no edit to `core/Lead` or `core/Shared`. |
| C3 `php -l` per file | PASS | 43 files linted (41 CrmSync + provider + `config/app.php`): all "No syntax errors detected". Sweep re-run over the whole `core/CrmSync` tree, not just the new files. |
| C4 `composer dump-autoload` | PASS | "Generated optimized autoload files containing 1953 classes" (was 1911 in Step 0.2). |
| C5 rule DoDs walked | PASS with notes | 01 P6, 05 R11, 06 T10 and rule 03 §7.1.1 walked below. |
| C6 06 T8 greps | T8 A **zero**, T8 B **zero** | T8 A over `core/CrmSync/{Domain,Application,Infrastructure}`: exit 1 (no match) — cleaner than the Lead tree, whose three D-034 matches are port *declarations*. T8 B: exit 1. T8 C: N/A. |
| C7 wiring | PASS | 21 bindings, 0 duplicated (scripted count); each Contract bound once; `config/app.php` lists the provider. |
| C8 schema fidelity of the 3 Entities | PASS | `SyncEventEntity` = the 13 `sync_events` columns + the two handler constants; `CrmMappingEntity` = the 9 `crm_mappings` columns; `DeadLetterEntity` = the 9 `dead_letters` columns. `id` is `?int` everywhere; enums/VOs replace their raw columns; datetimes are `?DateTimeImmutable` per D-032. Re-read against the three migrations; no column invented or omitted. |
| C9 leftovers | PASS | No `dd`/`dump`/`var_dump` (grep exit 1). Every `// TODO:` names the step that fills it. |
| C10 runtime check | PASS (beyond the brief) | The container was booted with the shared kernel stubbed and the full graph resolved: 15/15 contracts built, each to the intended class, 0 failures. Not a DB test — no query runs. |
| C11 tests | Paused per AGENTS.md §6 — none written, none run, none claimed. |
| C12 transactions | N/A | No Service method yet (skeleton); nothing writes. |
| C13 boundaries | PASS | `core/CrmSync/Domain` + `Application`: no `App\`/`Framework\` import, no `wpdb`, no `wp_*`, no helper (all four greps exit 1). Read adapters inherit no write method — checked by reflection: `insert`/`write`/`deleteRecord`/`findRecordForUpdate` absent on all three read classes, `findRecordById` absent on the write class. No `env()`, no `Config` outside `app/`. |
| C14 file existence | PASS | All 12 paths verified absent before writing (`find` over `core/CrmSync/Infrastructure` → "No such file or directory"; `app/Providers/CrmSyncServiceProvider.php` absent from the directory listing). No file overwritten. |
| C15 rule-to-skill sync | N/A | No AGENTS.md / rule / skill edit this step. |
| C16 repairs | N/A | No A6b round ran; the first self-check passed. |

### Rule 03 §7.1.1 / T10 / P6 / R11 walk

- **§7.1.1 rotation:** Entities fully mapped from the schema; Services, Contracts and Repositories are
  constructors-and-injections only; Entity behaviour stays `// TODO:`; no `Domain/Events/`,
  `Application/Jobs/`, `Domain/DataObjects/`; no `slug`/`image`/`file` column in the three migrations,
  so no schema-implied port is owed beyond what the Services already inject.
- **T10:** FROZEN files not hand-edited ✔; every outside capability is a Contract + adapter ✔; six
  persistence adapters ✔; no business logic in an adapter ✔; each Contract bound once ✔;
  duplicate-key → a module conflict exception is **deferred** (the write port declares no method yet, so
  `duplicateException()` has nothing to guard — Step 2.3/2.4); Delivery injects inbound contracts
  **N/A (no delivery yet)**; events/jobs after commit **N/A (no events)**.
- **01 P6:** strict types + PSR-4 ✔; Domain has zero framework imports ✔; all I/O behind ports ✔;
  Entities/DTOs immutable ✔; each port bound once ✔; "use cases return `Result`" **N/A**.
- **05 R11:** Entities `readonly class`, not `final`, no framework use, no event list ✔; each Service is
  Command or Query and implements its inbound contract ✔; the Query Service injects only read ports,
  the response mapper and the logger — no transaction manager, dispatcher, clock or write port ✔;
  no hardcoded text in Core (enums expose `translationKey()`) ✔.

## Commands run

`git check-ignore -v …` (guard, no output); `find`/`ls` (file-existence guard, 12 paths);
`php -l` over all 43 files; `composer dump-autoload`; the 06 T8 A/B greps and three boundary greps;
a read-only `php -r` class-load + `is_subclass_of` sweep (11 pairs, 0 mismatches); a reflection check
that no read adapter inherits a write method; a scripted provider audit (15 resolve targets all
existent, 21 bindings, 0 duplicated); and a container boot that resolved 15 contracts (0 failures).
No migration, no seeder, no test run, no git write, no DB query, no `framework/` edit. Both temporary
audit scripts were deleted; the worktree holds no stray file.

## Decisions

Recorded in `DECISIONS.md`. New this step: **D-035** (the two cross-module adapters are created here but
their methods land in Step 2.4, because the Lead ports are still empty interfaces) and **D-036**
(the response mapper is bound by its concrete class, not a contract — plan §4 declares no
`SyncResponseMapperContract`, matching the `Lead` module).

## Deviations

1. **The two cross-module adapters have no method body.** Plan §5 Step 0.3 says they are "scaffolded as
   skeletons implementing the Lead contracts"; the Lead contracts are empty interfaces until Step 1.6,
   so the skeletons can only carry constructor injection. Their behaviour is Step 2.4's. Recorded as
   D-035; flagged at the STOP GATE.
2. **Six repositories, not two.** Rule 03's §7.1 tree shows one write + one read adapter per module;
   this module owns three tables, and plan §4 names six (`Wp{SyncEvent,CrmMapping,DeadLetter}{Write,Read}Repository`).
   The plan's tree is followed.
3. **`SyncResponseMapper` bound as a concrete singleton**, matching `LeadServiceProvider` and plan §4
   (which lists no `SyncResponseMapperContract`). Recorded as D-036.
4. **T8 A is zero here** while the `Lead` tree keeps three documented matches (D-034). The asymmetry is
   expected: this step added no new Core port *declaration* — the CrmSync ports were written in Step 0.2
   and none of them is named `now()` or shaped like a helper call.

## Deferred and open items

- Steps 2.3 / 2.4 / 3.5 fill the three EntityMappers; Steps 2.3/2.4/3.2/3.5 fill the six repositories'
  query methods; Step 2.4 fills the two cross-module adapters (after Step 1.6 declares their port methods).
- Step 1.3 fills `RemoteId`, `ContentHash`, `SanitizedErrorMessage`; Step 1.4 fills `SyncRetryRules` and
  the `SyncEventEntity` transitions; Step 1.5 fills the mapping/dead-letter behaviour methods.
- Step 1.6 declares every port method (all CrmSync ports are empty by design).
- The `duplicateException()` override on the two write adapters that can hit a UNIQUE index
  (`sync_events.idempotency_key`, both `crm_mappings` indexes) lands with their write methods.
- **No language file exists for the new keys**: the five enums emit `crm_sync.{status,direction,provider,entity_type,object_type}.*`,
  but `lang/` holds only `en_US/companies.php`. A `lang/en_US/crm_sync.php` is needed before any channel
  renders these keys (not in Step 0.3's scope) — same gap Step 0.2 recorded for `lead.*`.
- **D-034 remains OPEN** (inherited from Step 0.2): the `06` T8 A pattern refinement, or an explicit
  statement that Core port declarations are expected matches. It does not affect this module.

## Tests

Paused per AGENTS.md §6.

## Next step

**Step 1.1 — Lead Value Objects** (`EmailAddress`, `PhoneNumber`; `fromNullable(?string): ?self`), the
first CORE-PURE step of Phase 1.

---

### STOP GATE

- **Step:** `Lead/0.3` — **Status: DONE_WITH_NOTES**
- **Report:** `docs/ai-reports/24-crmsync-module-scaffold.md`
- **Module:** `core/CrmSync/` complete (42 files: 3 Entities + 5 enums fully mapped, Domain/Application
  skeletons, 3 mappers, 6 repositories, 2 cross-module adapters); `CrmSyncServiceProvider` listed in
  `config/app.php`; all 15 contracts resolve (container boot, 0 failures).
- **Needs the developer's eye (not blockers):** Deviation 1 / **D-035** (the two cross-module adapters
  can carry no method until Step 1.6 declares the Lead ports) and **D-036** (response mapper bound by
  concrete class). Plus the inherited **D-034** (T8 A vs Core port declarations).
- **Next step:** `1.1` — Lead Value Objects `EmailAddress` and `PhoneNumber`, with their domain
  exceptions and the `fromNullable()` normalization.
- **Awaiting:** the developer's go-ahead. Nothing is started until then.
