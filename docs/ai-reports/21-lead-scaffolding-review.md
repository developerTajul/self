# Step 02 - Lead module scaffolding review (Review)

**Status:** BLOCKED

## Summary

The Lead module scaffolded on 2026-11-23 (08:29-08:48) does not load, and cannot be loaded:
three class references point at namespaces that do not exist, both services omit `implements`,
the provider binds two contracts twice and resolves two contracts that were never written, and
six entity/DTO fields have no visibility so they are never properties at all. Nothing was edited:
two blockers stop the step before repair (no VCS anywhere in the tree, and a gutted framework
package that makes `vendor/autoload.php` fatal), and neither blocker can be cleared by a command
this project allows. The review itself is complete and the fix plan below is ready to execute.

## Inputs used

- `AGENTS.md`, `.claude/rules/03-module-scaffolding.txt`, `09-autonomous_execution.txt`
- `docs/ai-reports/PROJECT-LOG.md`, `docs/ai-reports/DECISIONS.md` (D-2026-11-16-11 OPEN)
- `docs/ai-reports/20-full-project-review.md` (the review that flagged Lead as unwired)
- `memory/lead-module-scaffolding.md`, `memory/module-cleanup.md` (the step's own notes)
- All 13 `core/Lead/**` files, `app/Providers/{Lead,Repository}ServiceProvider.php`,
  `core/Shared/**` base classes and contracts, `database/migrations/2026_09_29_232811_create_leads_table.php`

## Section 0 pre-flight

| Check | Result | Evidence |
| --- | --- | --- |
| Context hydration | PASS | Last 3 `DECISIONS.md` entries read (D-2026-11-16-09/10/11); `PROJECT-LOG.md` read to `Review/01`; previous report `20-full-project-review.md` read |
| Git tracking guard | **FAIL** | `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` -> `fatal: not a git repository (or any of the parent directories): .git`. `find H:/laragon/www/leadbridge-crm -maxdepth 6 -name .git` -> no output. No `.hg`, no `.svn` |

The guard fails structurally rather than by an ignore rule: there is no repository to ignore
anything in, so `.claude/rules/`, `.claude/skills/` and `docs/ai-reports/` are untracked, and so
is every other file. `AGENTS.md` §0 makes this a HALT.

## Blockers

### B1 - no version control in the tree (AGENTS.md §0)

The tracking guard cannot pass and no repair of it is in scope: `git init` plus a first commit is
a repository decision (and `.env` at the plugin root holds credentials, so the ignore strategy is
the developer's call, not mine). With no history there is no undo for the repairs below.

### B2 - `vendor/` framework package is empty, so the plugin cannot boot (09 A10 H12 / A7)

```
$ ls vendor/devlinkstudios/wp-mini-laravel-framework/     # real directory, 0 entries
$ php -r 'require "vendor/autoload.php";'
Warning: require(.../vendor/composer/../devlinkstudios/wp-mini-laravel-framework/Support/helpers.php):
         Failed to open stream: No such file or directory in .../vendor/composer/autoload_real.php on line 41
Fatal error: Uncaught Error: Failed opening required
         '.../vendor/devlinkstudios/wp-mini-laravel-framework/Support/helpers.php'
```

`framework/Support/helpers.php` exists (19063 bytes) at the path-repository source, but
`vendor/composer/autoload_psr4.php:18` maps `Framework\` to the empty vendor copy and
`vendor/composer/autoload_files.php:10` requires the missing file. Every `Framework\` class is
therefore unresolvable too. `wp plugin list` shows `wp-mini-laravel inactive`, which is the only
reason the fatal is latent rather than live.

Restoring it needs `composer install` (A7 forbidden command) or a write inside `vendor/` (H12).
This is also why the review is static: with no autoloader, no runtime check of any fix is possible.

## Findings

Line numbers verified by reading the files. "Rule" cites the artifact that makes each a defect.

### P0 - the module does not work

| # | File:line | Defect | Rule |
| --- | --- | --- | --- |
| F1 | `app/Providers/RepositoryServiceProvider.php:94,103,116,123` | Binds `Core\Lead\Infrastructure\Repositories\LeadWriteRepository` and `LeadReadRepository`; neither class exists (the module has `WpLeadWriteRepository` / `WpLeadReadRepository`). Resolving `LeadRepositoryContract` or `LeadQueryContract` fails | §9 adapter naming (D4 `Wp` prefix) |
| F2 | `RepositoryServiceProvider.php:113-125` vs `app/Providers/LeadServiceProvider.php:71-83` | `LeadRepositoryContract` and `LeadQueryContract` are each bound **twice**; last registration wins, so the stale bindings in F1 are dead weight that only look wired | §14 "Each Contract bound once" |
| F3 | `LeadServiceProvider.php:8,11,94-101` | Imports and binds `LeadMapperContract` and `LeadResponseMapperContract`; neither interface exists (`core/Lead/Application/Contracts/` holds exactly three files) | §8 inbound contracts |
| F4 | `core/Lead/Application/Services/LeadCommandService.php:59,60,62` | Type-hints `Core\Shared\Domain\Exceptions\Result`, `Core\Shared\Infrastructure\EventDispatcherContract`, `Core\Shared\Infrastructure\TransactionManagerContract`. Real names are `Core\Shared\Application\DTOs\Result`, `Core\Shared\Domain\Contracts\EventDispatcherContract`, `Core\Shared\Domain\Contracts\TransactionManagerContract` | §11 ports live under `Domain\Contracts` |
| F5 | `LeadCommandService.php:14` / `LeadQueryService.php:15` | Neither class has `implements`, though both docblocks claim `@implements` and the provider binds them to the contracts -> container hands back an object that is not the requested type -> `TypeError` | §8 inbound contracts |
| F6 | `LeadCommandService.php:57-62` vs `LeadServiceProvider.php:105-112` | Argument list disagrees in arity, order and type: provider passes (repo, mapper, `TransactionManagerContract`, `TranslatorContract`, logger, `Infrastructure\TransactionManagerContract`); constructor expects (repo, mapper, `Result`, `EventDispatcherContract`, logger, `TransactionManagerContract`) | §8 |
| F7 | `LeadQueryService.php:18,40` | Query service injects the **write** port `LeadRepositoryContract`; the provider passes `LeadQueryContract` -> `TypeError`. Violates the CQRS split regardless | §9 |
| F8 | `core/Lead/Domain/Entities/LeadEntity.php:31-34,37-38` | Promoted parameters without visibility (`?string $email`, `$phone`, `$companyName`, `$websiteUrl`, `$metaJson`, `$assignedTo`) are **not properties**. `LeadEntityMapper.php:58-65` and `LeadResponseMapper.php:28-35` read them -> "Undefined property" warnings and silent `null` writes for six columns | §7 entity is the only writer of its state |
| F9 | `core/Lead/Application/DTOs/Responses/LeadResponseDTO.php:34-41` | Same mistake: six fields are constructor parameters, not properties, so the DTO carries only 7 of 13 values | §8 ResponseDTO is a projection |
| F10 | `LeadEntityMapper.php:29-40` + `LeadEntity.php:72,83` | `toEntity()` rebuilds through `LeadEntity::create()`, which hardcodes `id: null` and `createdAt/updatedAt: null`. Every loaded lead has `id = 0` and no timestamps | §7 "rebuild from storage in the EntityMapper (`reconstitute()`)" |
| F11 | `LeadResponseMapper.php:36-37` | Armours over F10 by fabricating `new \DateTimeImmutable('now')`, so a 2024 row reports today's date. Core also reads the wall clock, which is `ClockContract`'s job | §11 "Core never uses `now()`" |
| F12 | `LeadEntityMapper.php:27` + `LeadEntity.php:44,37` | `meta_json` is `json_decode`d to an array (line 27) and then passed to `?string $metaJson` -> `TypeError` on any row with meta. `toPersistenceArray()` line 64 then `json_encode()`s it again, and turns `null` into the string `"null"` | §7 types are explicit |
| F13 | `WpLeadWriteRepository.php:15`, `WpLeadReadRepository.php:15` | `extends \Core\Shared\Infrastructure\BaseWriteRepository` / `BaseReadRepository`; actual namespace is `Core\Shared\Infrastructure\Repositories\...` -> class not found, fatal on load | §9 |
| F14 | both repositories, line 30 | `parent::__construct($entityMapper, $wpdb)` against `AbstractWpRepository::__construct(protected ?wpdb $wpdb = null)` -> `$wpdb` receives the mapper -> `TypeError` | 02 s2.3 |
| F15 | both repositories, line 20 | `private const TABLE_SUFFIX = 'lead'` -> table `{prefix}lead`; the migration creates `leads` (`2026_09_29_232811_create_leads_table.php:36`) -> every query misses. Company uses plural `companies` | 02 s7 |
| F16 | both repositories, line 15 | Neither class `implements` its port (`LeadRepositoryContract` / `LeadQueryContract`) although the docblock says it does | §9 |
| F17 | `core/Lead/Domain/Exceptions` | A **0-byte regular file**, not a directory (`-rw-r--r-- 1 ... 0 Nov 23 08:29`). Blocks the module's exception namespace entirely | 03 module layout |

### P1 - does not work once P0 is fixed

| # | File:line | Defect | Rule |
| --- | --- | --- | --- |
| F18 | `LeadCommandService.php:16-47`, `LeadQueryService.php:17-38` | Property declarations plus assignments in the constructor body instead of promotion | §13 |
| F19 | `LeadEntityMapper.php:16` | `class` not `final` | §5 |
| F20 | `LeadResponseMapper.php:11` | `@implements \Core\Lead\Application\Contracts\LeadQueryContract` on a mapper | §13 |
| F21 | `RepositoryServiceProvider.php:102-109` | Lead read repository resolves `Framework\Database\Query\QueryBuilderInterface` -> `Framework\Database\*` reachable from a module repository while Fluent DB is paused | D6 |
| F22 | `core/Lead/Domain/Contracts/LeadRepositoryContract.php:11` | Empty write port: no `findByIdForUpdate()`, which §9 requires of every write port | §9 |
| F23 | `app/Http/Controllers/Api/LeadController.php:58-78` | Returns hardcoded sample rows; never calls `LeadQueryServiceContract`. Delivery is not wired to the module (already recorded in `20-full-project-review.md`) | §8 delivery thin |
| F24 | `WpLeadWriteRepository.php:20`, `WpLeadReadRepository.php:20` | `private const TABLE_SUFFIX` on each; Company exposes `public const TABLE_SUFFIX` on the read repository, which the mapper-side code reads | §13 one definition |

### P2 - hygiene

| # | File:line | Defect |
| --- | --- | --- |
| F25 | `wp-content/plugins/wp-mini-laravel/LeadRepositoryContract.php` | Stray 9-byte file at the plugin root containing `test` (UTF-8 BOM + CRLF), created 08:31 during the scaffolding. Junk at a path no autoloader maps |
| F26 | `memory/lead-module-scaffolding.md` | Records "COMPLETE AND VERIFIED" with a green table for code that cannot be class-loaded. The verification claimed there (`toPersistenceArray()` skips id/timestamps, query service has no write-side port) is contradicted by F8, F10, F12 and F7 |
| F27 | `core/Shared/Infrastructure/{Database/LaravelTransactionManager.php,Events/LaravelEventDispatcher.php,Localization/LaravelTranslator.php,Queues/LaravelQueueManager.php,Storage/LaravelFileUploader.php,Security/*}` | Eloquent-era adapters still in the tree; `DECISIONS.md` D-2026-11-16-11 keeps the port-or-prune question OPEN |

## Fix plan (ready, not executed)

Ordered, Core-pure first. Every step is inside `core/Lead/**`, `app/Providers/**` and
`config/app.php`; none needs `framework/` or `vendor/`.

1. Delete the 0-byte `core/Lead/Domain/Exceptions` file, create the directory, add
   `LeadNotFoundException` (F17) - and delete the stray root `LeadRepositoryContract.php` (F25).
2. `LeadEntity`: make all 13 constructor parameters `public`; add
   `reconstitute(?int $id, ..., ?\DateTimeImmutable $createdAt, ?\DateTimeImmutable $updatedAt)`
   that assigns ids and timestamps; keep `create()` for new records (F8, F10).
3. `LeadResponseDTO`: promote all 13 fields; make `createdAt`/`updatedAt` nullable (F9).
4. `LeadEntityMapper`: rebuild through `reconstitute()`; keep `meta_json` as the raw string so
   entity type, column type and DTO type agree; drop the double `json_encode` (F10, F12).
5. `LeadResponseMapper`: drop `new \DateTimeImmutable('now')`; pass the entity's values through
   (F11). Record the resulting nullability in `DECISIONS.md` (A9 Tier 2).
6. `LeadCommandService` / `LeadQueryService`: add `implements`; fix the three Shared FQCNs;
   query service takes `LeadQueryContract`; constructor promotion (F4, F5, F7, F18).
7. Both repositories: correct base-class namespaces, `implements` their ports,
   `TABLE_SUFFIX = 'leads'`, `parent::__construct($wpdb)` only, mapper kept as a property (F13-F16, F24).
8. `LeadServiceProvider`: drop the two nonexistent mapper contracts (bind `LeadResponseMapper` by
   concrete class like `CompanyServiceProvider` does); pass the exact constructor arguments (F3, F6).
9. `RepositoryServiceProvider`: delete `registerLeadRepository()` and the lead imports - the lead
   bindings belong to `LeadServiceProvider` alone (F1, F2, F21).
10. `LeadRepositoryContract`: declare `findByIdForUpdate(int $id): ?LeadEntity` (F22).

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | Review created only this report |
| C2 scope | PASS | Nothing edited; no file created outside `docs/ai-reports/` |
| C3 syntax | PASS | `php -l` run per file on all 13 Lead files + provider: "No syntax errors detected" for each |
| C4 autoload | **FAIL (blocked)** | `require vendor/autoload.php` fatals on the missing framework helpers (B2) |
| C5 module DoD | **FAIL** | F1-F27 above |
| C6 `core/` greps | **FAIL** | `Core\Shared\Infrastructure\BaseWriteRepository` (F13), `Framework\Database\Query\QueryBuilderInterface` (F21) under `core/Lead/Infrastructure/` |
| C7 wiring | **FAIL** | F2 (double binding), F3 (nonexistent contracts) |
| C8 schema fidelity | **FAIL** | F8 (six columns unreachable), F15 (wrong table) |
| C9 leftovers | **FAIL** | F17 (0-byte file), F25 (root stray), F26 (false "verified" note) |
| C10 runtime | NOT RUN | Plugin inactive; blocked by B2 |
| C11 tests | PASS | "Tests: paused per AGENTS.md" - none written, none run |
| C12 transactions | N/A | No write path implemented at this stage |
| C13 boundaries | **FAIL** | F7, F13, F21 |
| C14 file existence | PASS | `ls`/`find` run before this report; report path did not exist |
| C15 skill sweep | N/A | No rule, AGENTS.md or skill edited |

## Commands run

`git check-ignore -v ...`, `git rev-parse --show-toplevel`, `find ... -name .git`,
`php -l` (one file per command, 14 files), `php -r 'require "vendor/autoload.php"; ...'`,
`wp plugin list`, `grep`/`sed`/`cat -n` reads. No migration, no install, no test run, no git write.

## Decisions

None taken. `D-2026-11-16-11` (port or prune the unported modules, and `Core\User`) stays OPEN and
directly overlaps this module: Lead is now half-scaffolded on the `Company` template, which is
option (a) of that decision started without the decision being taken.

## Deferred

- The `meta_json` type choice (raw string vs decoded array) is a Tier 2 decision to record when
  fix 4 runs.
- `LeadController` wiring (F23) is delivery work, not part of repairing the scaffold.

## Tests

Paused per AGENTS.md: none written, none run, none claimed.

## Next step

Unblock B1 (a repository) and B2 (restore the framework package in `vendor/`), then execute the
fix plan above as a normal step.
