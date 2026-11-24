# Step 1.1 — Lead Value Objects (`EmailAddress`, `PhoneNumber`) (Lead)

**Status:** DONE_WITH_NOTES

**Summary.** Implemented the two lead Value Objects as `final readonly` classes with named
constructors, `fromNullable()` blank-to-null normalization, value equality, and the three exception
factories they need. `EmailAddress` trims and lowercases, validates the format, and enforces the
`email` column's 191-character ceiling; `PhoneNumber` normalizes to E.164 (separators removed, `00`
international prefix rewritten to `+`, digit count bounded to 2–15 per E.164) and enforces the `phone`
column's width. Both compare on their canonical value, so equality agrees with the case-insensitive
UNIQUE indexes the storage layer enforces. 52 functional assertions pass, including one real defect
found and fixed during self-check (D-037). All four files lint clean; T8 A and T8 B are zero.

**Inputs used.**
- Plan `docs/ai-plans/lead-module-action-plan.md` §5 Step 1.1 and §3 (the `leads` schema facts).
- `database/migrations/2026_09_29_232811_create_leads_table.php` — `email` varchar(191) unique,
  `phone` varchar(50) unique, and the migration's own normalization note ("lowercase+trim emails,
  format phones to E.164").
- Rules 01 P4/P6, 05 R1/R3 (VALUE OBJECTS paragraph) + R12.5, 06 T8; AGENTS §5, §7, §13.
- Structural model: `core/Company/Domain/ValueObjects/CompanyFilter.php` (validate-in-constructor) and
  `core/Company/Domain/Exceptions/InvalidCompanyException.php` (static factory + `TRANSLATION_PREFIX`
  + `replace` pattern, which the two lead exceptions already followed).

**Rules and skills applied.** AGENTS §1 (Core Domain zone), §5 (`declare(strict_types=1)`, `final`),
§7 (rich domain: rules live in the VO), §11 (no WordPress in Core), §13 (style); rules 01, 05 (R1, R3,
R12.5), 06 (T8). Tests paused per AGENTS §6. No skill governs a pure-Domain VO step.

---

## Pre-flight

- **CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries (D-034, D-035, D-036), the latest
  `PROJECT-LOG.md` entry (`Lead/0.3`), and the previous step's report (`24-crmsync-module-scaffold.md`).
  The developer's message approved **D-035** and **D-036** as recorded and declared Phase 0 complete.
  No decision binds this step beyond them; **D-034** (T8 A and port declarations) stays OPEN and is
  untouched here — these files declare no port and name no `now()`/`wp_*` symbol.
- **GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed
  nothing for any of the three (exit 1). **PASS** — no HALT.

## Files

**Modified — 4 files.** All four already existed as Step 0.2 skeletons at the paths plan §4 names, so
each was edited in place; no file was created, and the FILE EXISTENCE GUARD was satisfied by the paths
being the brief's own (the step names the VOs and their exceptions).

| File | Change |
| --- | --- |
| `core/Lead/Domain/ValueObjects/EmailAddress.php` | Implemented: `fromString()`, `fromNullable()`, `equals()`, `toString()`, `__toString()`, `MAX_LENGTH`. |
| `core/Lead/Domain/ValueObjects/PhoneNumber.php` | Implemented: `fromString()`, `fromNullable()`, `equals()`, `digits()`, `toString()`, `__toString()`, `MIN_DIGITS`/`MAX_DIGITS`/`MAX_LENGTH`, private `normalize()`/`isValid()`. |
| `core/Lead/Domain/Exceptions/InvalidEmailAddressException.php` | Added `invalidFormat()` and `tooLong()` factories; added the `EmailAddress` import. |
| `core/Lead/Domain/Exceptions/InvalidPhoneNumberException.php` | Added `invalidFormat()` factory; added the `PhoneNumber` import. |

**Not touched:** `LeadEntity`, the other two exceptions, every other module, `framework/`, `vendor/`,
`database/`, the providers. No language file was created (see Deferred).

## Design decisions

**`EmailAddress`.** `trim()` then `mb_strtolower()`, because a unique index on a case-insensitive
collation cannot distinguish `A@B.co` from `a@b.co` and the domain must not pretend it can.
`FILTER_VALIDATE_EMAIL` performs the format check. The 191-character ceiling is re-checked *after*
validation and raises its own key rather than being left to the database: a filter-valid address can
exceed 191 (verified: a 64/63/63 local/domain/TLD shape is 192 characters and passes the filter), so
the guard is reachable and necessary, not defensive decoration.

**`PhoneNumber`.** Normalization removes separators (spaces, dashes, dots, parentheses) and rewrites a
leading `00` international prefix to `+`, then validation requires `^\+[1-9]\d*$` with 2–15 digits.
A number without an international prefix is **rejected, not guessed at**: inferring a country code
would silently invent a wrong number for every foreign contact, which is worse than an error the
caller can act on. `digits()` is exposed for CRM adapters that need the bare digit string.

**Equality.** `equals(self $other): bool` compares canonical values with `===`. This mirrors
`CrmSync\RemoteId`'s rule (D-038 records the shared shape) and is what the plan's "compared by value"
requires.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files exist, no unexpected file | PASS | Four paths edited in place; `git status --porcelain core/Lead/Domain` shows `ValueObjects/` (untracked dir, its two files modified) and the two exceptions. No new file. |
| C2 scope audit vs brief | PASS | Only the two VOs the step names and the two exceptions they call; nothing else. mtimes 19:46–19:47 for those four; every other Domain file still 19:01 or earlier. `framework/`/`vendor/` clean. |
| C3 `php -l` per file | PASS | All four: "No syntax errors detected". |
| C4 `composer dump-autoload` | PASS | "Generated optimized autoload files containing 1953 classes" (unchanged — the classes already existed; only bodies changed). |
| C5 rule DoDs walked | PASS | 01 P6, 05 R3/R12.5, 06 T8 walked below. |
| C6 06 T8 greps | T8 A **zero**, T8 B **zero** | Both exit 1 over `core/Lead/Domain`. |
| C7 readonly / validated / by-value | PASS | Both classes `final readonly`, ctor `private`, validation in `fromString()`; `equals()` present on both. Verified by reflection that no public property is writable. |
| C8 normalization correctness | PASS | 52/52 assertions: 6 email cases, 6 phone-format cases, 4 exception payload checks, boundary cases at 191/192 chars and 15/16 digits, and the `++` regression. |
| C9 leftovers | PASS | No `dd`/`dump`/`var_dump` (grep exit 1). No `// TODO:` left in either VO. |
| C10 no hardcoded user-facing text | PASS | The only strings are exception *messages* (untranslated defaults, per the module pattern) and keys via `TRANSLATION_PREFIX`; no `__()`, no `translate()`, no `label()`. Greps for `__\\(` in the two VOs: exit 1. |
| C11 tests | Paused per AGENTS.md §6 — the 52 assertions were an ad-hoc verification script, deleted; no `tests/`, no test file, nothing claimed as a suite. |
| C12 transactions | N/A | Pure Domain, no I/O. |
| C13 boundaries | PASS | T8 A/B zero; no `env()`, no `Config`, no `wpdb`, no WordPress function; PHP built-ins (`filter_var`, `preg_match`, `mb_*`) only. |
| C14 file existence | PASS | No file created this step, so no guard to trip; the four edited paths are the step's own. |
| C15 rule-to-skill sync | N/A | No AGENTS.md / rule / skill edit. |
| C16 repairs | **1 round, 1 distinct signature** | See below — within the A6b limit of two. |

### Repair round (09 A6b)

- **Signature:** `InvalidPhoneNumberException` not thrown + `PhoneNumber::fromString('++1555')` + the
  `normalize()` return in `core/Lead/Domain/ValueObjects/PhoneNumber.php`.
- **Diagnosis:** `normalize()` kept a `+` only when it *led* and stripped every other non-digit, so
  `++1555` collapsed to the valid `+1555` and validation never saw the malformed input. A second `+`
  is not punctuation to discard — collapsing it accepts a number the caller never wrote.
- **Bounded repair:** one early-return statement added at the top of `normalize()` (`substr_count($working, '+') > 1` → return untouched). No class-wide rewrite, no contract change, so no tree-wide
  scan was owed.
- **Outcome:** the same signature did not return; `++1555`, `+++1555`, `+1+555` and a trailing-`+`
  number are all rejected, and every single-`+` / `00` case still normalizes. Round 1 only.
- Two *test-fixture* errors were also corrected in the same pass (a 191-char fixture used where 192
  was needed, making `tooLong` look unreachable when it is not) — those were the harness's fault, not
  the code's, and are not repair rounds.

### Rule 05 R3 (VALUE OBJECTS) / R12.5 / 01 P6 walk

- **R3:** own rule, no identity, `readonly`, validated in the named constructor, compared by value ✔.
  Lives in the module's Domain (not Shared — `01` P4's admission rule reserves Shared for
  cross-module primitives; these are lead-specific) ✔.
- **R12.5:** no hardcoded user-facing text; keys are exposed for delivery to translate ✔.
- **01 P6:** strict types + PSR-4 ✔; zero framework imports ✔; immutable ✔.

## Commands run

`git check-ignore -v …` (guard, no output); `php -l` over the four files; `composer dump-autoload`;
the 06 T8 A/B and boundary greps; a read-only `php -r`/scripted verification of 52 assertions covering
normalization, rejection, `fromNullable`, equality, length boundaries and exception payloads, plus a
fixture-length computation to prove `tooLong` is reachable. No migration, no seeder, no test run,
no git write, no DB query, no `framework/` edit. The temporary verification script was deleted.

## Decisions

Recorded in `DECISIONS.md`:

- **D-037** — `PhoneNumber` rejects a number with no international prefix instead of assuming one, and
  treats a repeated `+` as malformed rather than collapsing it (the self-check finding).
- **D-038** — both VOs expose `equals(self): bool` comparing canonical values; this is the module-wide
  convention for Domain value comparison, matching `CrmSync\RemoteId`.

## Deviations

1. **A real defect was found by the self-check** (`++1555` silently normalized to a valid number) and
   fixed in one bounded round. Recorded as D-037. This is the reason the status is `DONE_WITH_NOTES`
   rather than `DONE` — not an unmet DoD item, but a change made after the first draft.
2. **`EmailAddress` enforces 191 characters although plan §5 Step 1.1 does not mention a length rule.**
   Justified by the migration's `email` varchar(191) and by `01` P6's "validated in the named
   constructor": a value the domain calls valid but the column rejects fails at the wrong layer.
   Same reasoning for `PhoneNumber::MAX_LENGTH`. Flagged for the developer's eye.

## Deferred and open items

- **No language file exists for the new keys.** These VOs (with Step 0.2's enums) now emit
  `leads.errors.invalid_email_address`, `leads.errors.email_too_long` and
  `leads.errors.invalid_phone_number`. `lang/` still holds only `en_US/companies.php`; a
  `lang/en_US/leads.php` is needed before any channel renders them. Unchanged from the Step 0.2/0.3
  reports — still outside the steps' scope.
- `LeadEntity::$email` / `$phone` remain `?string`. Step 1.2 (`capture()`, `enrichWith()`, `absorb()`)
  takes `?EmailAddress` / `?PhoneNumber` per plan §5; whether the Entity *stores* the VO or its string
  is that step's decision (the schema and `LeadEntityMapper` currently expect strings).
- **D-034 remains OPEN** (inherited): the `06` T8 A pattern vs Core port declarations.
- `fromString()` on both VOs is public and validated independently; Step 1.6's read port lookups
  (`findByEmail`, `findByPhone`) will build VOs from already-normalized storage values, so they use
  `fromString()` and rely on the stored value being canonical — worth confirming when that port is
  written.

## Tests

Paused per AGENTS.md §6. The 52 assertions were a deleted ad-hoc script, not a test suite.

## Next step

**Step 1.2 — `LeadEntity` behaviour**: `capture()`, `enrichWith()`, `absorb()`, each returning a NEW
instance with time and uuid passed in, and `LeadContactKeyMissingException` when both contact keys are
null (A2).

---

### STOP GATE

- **Step:** `Lead/1.1` — **Status: DONE_WITH_NOTES**
- **Report:** `docs/ai-reports/25-lead-value-objects.md`
- **Deliverable:** `EmailAddress` and `PhoneNumber` implemented (normalization, `fromNullable()`,
  `equals()`, column-width guards) plus the three exception factories; 52/52 behavioural assertions
  pass; T8 A/B zero; `php -l` clean.
- **Needs the developer's eye (not blockers):** Deviation 2 (the 191/50 length guards are my reading of
  `01` P6 + the migration, not a line in Step 1.1), and **D-037**'s decision to reject rather than
  assume a missing country code.
- **Next step:** `1.2` — `LeadEntity` behaviour methods (`capture()`, `enrichWith()`, `absorb()`).
- **Awaiting:** the developer's go-ahead. Nothing is started until then.
