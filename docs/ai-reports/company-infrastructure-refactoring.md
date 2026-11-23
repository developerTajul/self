# Company Module Infrastructure Refactoring — Step Report

**Plan:** `docs/ai-plans/company_infrastructure_refactoring.md`
**Branch:** `wpdb`
**Date:** 2026-11-14
**Mode:** ADAPTER (all changed paths under `core/Shared/Infrastructure`, `core/Company/Infrastructure`), plus DELIVERY wiring in `app/Providers/` and `database/`.

No CORE-PURE step exists for this task: the plan touches no `Domain` or `Application` file. Every change is in `Infrastructure`, `database/`, `app/Providers/` or documentation.

---

## Rule sections applied (PROCESS GUARD, before coding)

- D7 WPDB OVERRIDE — `$wpdb` and raw SQL authorised in Infrastructure repositories, values bound via `prepare()`.
- D6 FLUENT DATABASE [PAUSED] — no longer binds the Company repositories.
- MODE DETECTION ADAPTER row — forbidden list: business rules; `App\` imports; any `Framework\` import except `Framework\Database\`.
- PORTS AND ADAPTERS MANDATE — ports unchanged; adapters replaced behind the same contracts.
- NO VENDOR EXCEPTIONS IN CORE — duplicate-key reaches Core only as a module conflict exception.
- ERROR HANDLING — uniqueness race-safe via a database UNIQUE index; silent failures forbidden.
- STRICT DOCBLOCK MANAGEMENT / ZERO DEAD CODE / `declare(strict_types=1)` / `final` on implementations.
- P1 COMPOSITION ROOT PER MODULE — module bindings live in `CompanyServiceProvider`.
- INFRASTRUCTURE STRING ENCAPSULATION — table names are constants.
- No unit tests written or run (UNIT TESTING POLICY, PAUSED).

---

## What shipped

### Step 1 — `core/Shared/Infrastructure/Repositories/BaseRepository.php`

Rewritten from the Fluent builder to `$wpdb`.

| Before | After |
| --- | --- |
| `__construct(QueryBuilderInterface $query)` | `__construct(?wpdb $wpdb = null)`, resolved lazily |
| `insert()` via `insertGetId()` | `$wpdb->insert()`, returns `insert_id`, refuses id ≤ 0 |
| `write()` via fluent `update()` | hand-built prepared `UPDATE … SET`, nulls as the `NULL` keyword |
| `rowExists()` via `count()` | `SELECT COUNT(*)`, `null` + `last_error` = failure |
| `duplicateException(UniqueConstraintException)` | `duplicateException(Throwable)`, matched from `last_error` |

Three decisions worth naming:

1. **Lazy `$wpdb`.** The handle is optional and resolved on first use. WordPress owns the object, and the container builds every repository during `register()`, before `$wpdb` is loaded — the same reasoning `LeadWriteRepository` and `LeadNoteWriteRepository` already document.

2. **`write()` does not call `$wpdb->update()`.** WordPress skips any column whose value is `null`, because it builds its `SET` body from the format list and no format means "the SQL keyword NULL". A cleared nullable field would be silently left at its old value while the caller was told the write succeeded. The statement is built explicitly so a null compiles to `NULL` and an empty string stays a string — mirroring the Fluent builder's own `compileAssignment()`. **This was found by a probe, not by inspection; see "Defects found" below.**

3. **Duplicate detection via `last_error`, not an error number.** WordPress reads `mysqli_errno()` into a local variable for its reconnect logic (`wp-includes/class-wpdb.php:2270-2273`) and never stores it on the object — verified in the WordPress source, not assumed. `last_error` is the only channel, so the check is a substring test for the SQLSTATE `23000` or MySQL's `Duplicate entry` phrase, localised to one method.

### Step 2 — `core/Company/Infrastructure/Repositories/WpCompanyWriteRepository.php`

- `QueryBuilderInterface` and `UniqueConstraintException` imports removed.
- Constructor is now `(CompanyEntityMapper $mapper, ?wpdb $wpdb = null)`.
- `duplicateException()` overrides the base hook and returns `CompanyDuplicateException`.
- `findByIdForUpdate()` uses a prepared `SELECT … LIMIT 1 FOR UPDATE`, distinguishing "no row" from "statement failed" through `last_error`.
- `delete()` reports a zero-row delete as `CompanyNotFoundException`, unchanged.
- `create()`/`update()` still read back after writing, so the caller gets stored timestamps.

### Step 3 — `core/Company/Infrastructure/Repositories/WpCompanyReadRepository.php`

- `QueryBuilderInterface` import and constructor argument removed: now `(CompanyEntityMapper $mapper, ?wpdb $wpdb = null)`.
- `getFilteredCompanies()`, `findById()`, `contactsFor()`, `dealsFor()`, `activitiesFor()`, `activitiesGroupedByDeal()` all rewritten as raw SQL.
- **Every value is bound through `$wpdb->prepare()`.** The two things that cannot be bound — the column list and the `ORDER BY` — come from class constants, never from a request, so no request text reaches a statement.
- `IN (…)` builds one `%d` placeholder per id and binds the ids; the placeholder count is derived from the id list, so the two cannot drift.
- Pagination count, the beyond-last-page clamp and the `LIMIT`/`OFFSET` behaviour are unchanged.
- A failed `get_results()` (`false`) and a failed `get_row()` (null with `last_error`) both raise, rather than being served as an empty list or a 404 — the distinction the builder previously provided by throwing.

### Migration — `database/migrations/2026_10_31_222317_make_companies_name_unique.php`

`companies.name` was declared `->index()` when the table was created, so
`CompanyDuplicateException` and the repository's duplicate translation had no
constraint behind them. This migration replaces the plain index with a unique
one, so the conflict path is now enforced by the database rather than only
detected.

**Ported, not written from scratch.** The file already existed in the working tree
as an untracked **Laravel** migration (anonymous `return new class extends
Migration`, `Illuminate\Support\Facades\Schema`, `DB::table()`), which cannot run
in this plugin — the migrator requires each file to declare an
`App\Database\Migrations` class, and `illuminate/*` is not installed. A first
attempt wrote a second, WordPress-shaped migration dated today; that duplicate was
deleted and the original was ported in place instead, keeping its timestamp, its
class name and its docblock reasoning. Two migrations making one schema change
would have collided on the second run.

Verified: `up()` flips `name` to `isUnique = true`, `down()` restores
`isUnique = false` and leaves `industry` untouched, and the derived class name
`MakeCompaniesNameUnique` matches the migrator's filename convention.

### `app/Providers/CompanyServiceProvider.php` (DELIVERY, inherent wiring)

Both bindings no longer resolve `Framework\Database\Query\QueryBuilderInterface`;
the mapper is injected and the handle resolves lazily. The class docblock's "use
the Fluent Database builder" sentence was corrected.

### `README.md`

The "Database & Query Builder" section named `WPFluentCompanyReadRepository` and
`WPFluentCompanyWriteRepository`, both deleted in `58e1ace`, and framed the builder
as the repositories' default. Corrected, and the `$wpdb` path documented, including
why it matches on message text where the builder can use error 1062. Its
"Verified by grep" claim was re-verified rather than reworded: outside
`framework/Database/Query/`, `QueryBuilderInterface` is now named only by
`LeadReadRepository`, its provider, and `config/app.php`.

---

## Defects found

**1. Blanked fields were not cleared (`write()` and null).** Found by
`probe-company-update-persistence`, which went from 0 failures to 4 the moment the
base was rewritten. The first version passed the values straight to
`$wpdb->update()`; both the test double and real WordPress render a null as an
empty string or skip the column. Fixed by building the `SET` clause explicitly so
a null is the `NULL` keyword. Probe is back to 40/40.

**2. Doubled failure messages.** `executeQuery()` prefixes the action, and the
closures were prefixing it a second time — `Failed to insert into companies:
Failed to insert into companies: …`. Fixed by having the closures throw only the
driver's text, so one place owns the wording. Verified both arms: an outage now
reports `Failed to insert into companies: MySQL server has gone away` as a
`RuntimeException`, while a duplicate still becomes `CompanyDuplicateException`.

**3. Every qualified column reference was error 1054 on a real server.** The
reader's JOINs qualified columns with the bare table name (`` `deals`.`id` ``)
while the `FROM` clause wrote the prefixed table (`` FROM `wp_deals` ``). MySQL
creates no alias from the prefix, so this is error 1054 — the trap
`WpFluentQueryBuilder` documents and my own notes on the fake record. Found by
exercising `findDetailById()` against a full multi-table fixture, which is the
only check that reaches those JOINs; every probe ran single-table reads and passed.
Fixed by qualifying with the prefixed name, which is the spelling that resolves.
The prefix is interpolated, which is safe: it is the site's own configured value,
never request input, and it goes through `escapeIdentifier()`.

**4. `job_title` was read from the wrong table.** In the same rewrite the contacts
projection read `c.job_title` (contacts) when the column belongs to the pivot
`company_contact` — `contacts` declares only `id, name, email, phone`. Since the
`id` selected is the pivot's, the projection must take `name/email/phone` from
`contacts` and `job_title/is_primary` from the pivot. Caught by the adversarial
review, then confirmed against the migrations. Verified live: `title='CTO'`.

**5. SEVERE — a failed multi-row read was reported as an ordinary empty result.**
`rows()` and `getFilteredCompanies()` tested `if ($rows === false)` after
`get_results()`. **Real WordPress never returns `false` there:** `get_results()`
calls `query()` but ignores its return value, then builds from `last_result`,
which `flush()` has already set to `array()` — verified in
`wp-includes/class-wpdb.php` (`get_results()` around :3205, `flush()` around
:1922). So the branch is unreachable in production, and a broken statement in
`contactsFor()`, `dealsFor()`, `activitiesFor()` or `activitiesGroupedByDeal()`
renders as "this company has no contacts" — a fault shown as a normal empty
result, on a page that should not have loaded.

Found by the adversarial review, not by a probe, because **`FakeWpdb` returns
`false`** where production returns `[]` (`tests/Support/FakeWpdb.php:707`) — so
the suite exercised a branch that cannot run, and hid the bug. Fixed by keying on
`last_error`, the same test `findRow()` and `BaseRepository::rowExists()` already
used, so all three reads in the module now agree. Verified: injecting a failure
into the contacts read now raises
`Failed to read the contacts of company 1: Unknown column xyz` instead of
returning an empty list.

---

## Verification (actual results, not claims)

| Check | Result |
| --- | --- |
| `php -l` on all 5 changed/created PHP files | no syntax errors |
| `probe-company-update-persistence` | 40 probes, 0 failed |
| `probe-company-update-flow` | 31 probes, 0 failed |
| `probe-company-delete-flow` | 21 checks, 0 failed |
| `probe-company-admin-urls` | 10 probes, 0 failed |
| `probe-company-pagination-clamp` | 7 failed — **pre-existing, see below** |
| `probe-company-index-view` | 4 failed — **pre-existing** (identical at HEAD) |
| `probe-company-store-validation` | 6 failed — **pre-existing** (identical at HEAD) |
| `probe-company-store-dispatch` | 2 failed — **pre-existing** (probe bug, see below) |
| Full suite | `Tests: 1495, Assertions: 2985, Errors: 32, Failures: 3` — **byte-identical to the HEAD baseline** |
| Detail read, full multi-table fixture | contacts (with pivot `job_title`), deals (stage name, probability, string amount), company activities and deal activities all resolve; `findById(999)` → null |
| Write path: create / update / delete / duplicate | id + stored timestamp returned; cleared fields stored as `NULL`; repeat delete → `CompanyNotFoundException`; duplicate → `CompanyDuplicateException` |
| Failed multi-row read | raises `Failed to read the contacts of company 1: Unknown column xyz` |
| Duplicate rejection (injected MySQL error) | translates to `CompanyDuplicateException`, `previous` preserved |
| Non-duplicate failure (injected outage) | stays a `RuntimeException`, not a false duplicate |
| Migration `up()`/`down()` | `isUnique` true → false, `industry` untouched |
| Leftover `Framework\Database` in `core/Company` | none |
| Leftover `QueryBuilderInterface` in Company + its provider | none |
| SQL injection | none found: every value bound; the only interpolated fragments are the table name (prefix + constant, `escapeIdentifier()`-ed) and the `ORDER BY` column/direction (class constants). `CompanyFilter` carries two ints and no column or sort field, so no request value can reach either. |

No test in the suite instantiates any class I changed; the only hit is a docblock
in `QueryBuilderExecutionTest`, so the suite cannot catch a regression here. The
manual probes are the real evidence.

### Two limits on what the probes prove

**The fake does not enforce unique indexes.** `FakeWpdb::runInsert()` hard-codes
its unique-key check to the `migration` table (`if (in_array('migration', …))`),
so inserting two companies with the same name is *accepted* by the test double.
A probe "proving" the duplicate path through an insert would pass on a broken
constraint. I therefore verified the duplicate path by injecting MySQL's exact
error string through `failOn()` and asserting the translation — which tests my
code, and does not test the constraint. **The constraint itself is unverified
until the migration runs on a real database.**

**`FOR UPDATE` cannot run against the fake at all.** `getRow()`'s SELECT-tail
parser has no clause for it, so `findByIdForUpdate()` raises
`FakeWpdb could not parse the SELECT tail: … LIMIT 1 FOR UPDATE`. This is
pre-existing and not mine: the shipped `LeadWriteRepository` uses the identical
`LIMIT 1 FOR UPDATE` spelling and fails the same way. My statement matches that
reference implementation. The lock read is therefore **verified by inspection and
by matching the established pattern, not by execution** — a fake limitation, not
a defect, and test support is outside this step's scope.

**The pagination probe's page source contradicts its own docblock.** Its header
says the page comes from `$_GET['paged']`, but `CompanyFilter` carries no page and
defaults `page` to 1; neither the old builder repository nor the new `$wpdb` one
reads `$_GET`. Driving the repository directly shows correct clamping for every
case (page 99 → last page 3 with rows; perPage 5 → single page of 5). The 7
failures predate this work and are the probe's, not the repository's.

---

## Not done, and why

- **Five other `BaseRepository` subclasses are left broken.**
  `ActivityWriteRepository`, `AuthWriteRepository`, `ContactWriteRepository`,
  `DealWriteRepository` and `PipelineWriteRepository` extend `BaseRepository` and
  call `parent::__construct($eloquentModel)`, which the new signature does not
  accept. They were already dead code — `app/Models/` does not exist, so none of
  them can load, and two import `Illuminate\*`. The plan names three Company
  files, and AGENTS.md forbids refactoring code the prompt did not name. Per the
  developer's decision they were left untouched. **This is a real, separate task
  (JOB C) and should not be mistaken for done.**
- **`2026_10_29_120000_keep_a_single_default_pipeline.php`** is a second untracked
  Laravel-shaped migration. It rewrites live `pipelines` data and is unrelated to
  this plan. Left untouched by decision.
- **The migration was not run.** `mini migrate` is a forbidden command under
  AGENTS.md. The developer runs it.
- **No unit tests written** (UNIT TESTING POLICY, PAUSED).
- The three pre-existing probe failures and the red suite were left as found.

---

## Handover

Before the duplicate path is real, run the migration. If `wp_companies` already
holds duplicate names the statement fails and the migrator reports it — by design,
since deleting customer rows is not the migration's decision. The index follows the
column collation (`utf8mb4_unicode_ci`), so `Acme` and `acme` collide.
