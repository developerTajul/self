# Step 1.2 — `LeadEntity` behaviour (`capture`, `enrichWith`, `absorb`) (Lead)

**Status:** DONE_WITH_NOTES

**Summary.** Implemented the three behaviour methods on `LeadEntity`, each returning a NEW instance and
taking time and uuid as arguments, so the entity stays immutable, pure and clock-free. `capture()`
enforces the A2 contact-key rule and starts the lead in `new`; `enrichWith()` fills only blanks from a
later submission and merges `meta`; `absorb()` folds a duplicate in while the survivor keeps its
identity — id, uuid, status, source and both contact keys — and only its blanks are filled (S5). A
`hasContactKey()` predicate exposes the capture invariant without duplicating the rule, and
`LeadContactKeyMissingException` gained a `forCapture()` factory matching the module's exception
pattern. 66 functional assertions pass. No events are scaffolded (`08` E3: nothing raises one yet).

**Inputs used.**
- Plan `docs/ai-plans/lead-module-action-plan.md` §5 Step 1.2, plus A2, A9, S5 and S7 from §2/§3.
- `core/Lead/Domain/ValueObjects/{EmailAddress,PhoneNumber}.php` (Step 1.1) — the validated inputs.
- Rules 01 P6, 05 R1 (items 2–7) + R3, 06 T8, 08 E3/E4/E8; AGENTS §5, §7, §13.
- Structural model: rule 05 R2's Entity template (`create()` + a behaviour method returning `self`).

**Rules and skills applied.** AGENTS §1 (Core Domain zone), §5 (`declare(strict_types=1)`, `final`
placement), §7 (rich domain: rules in the Entity, Tell-Don't-Ask), §11 (no WordPress in Core), §13;
rules 01, 05 (R1, R3, R11), 06 (T8), 08 (E3, E4, E8). Tests paused per AGENTS §6.

---

## Pre-flight

- **CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries (D-036, D-037, D-038), the latest
  `PROJECT-LOG.md` entry (`Lead/1.1`), and the previous step's report (`25-lead-value-objects.md`).
  The developer's message approved D-037, D-038 and the column-length / E.164 rules. **D-038** did
  bind this step: `absorb()` and `enrichWith()` compare through the Value Objects, not by raw strings,
  and the entity follows the same canonical-value convention. **D-034** stays OPEN and untouched.
- **GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed
  nothing for any of the three (exit 1). **PASS** — no HALT.

## Files

**Modified — 2 files.** Both already existed from Step 0.2; the step names them, so editing is in scope.
No file was created.

| File | Change |
| --- | --- |
| `core/Lead/Domain/Entities/LeadEntity.php` | Added imports for the two VOs and the contact-key exception; replaced the scaffold docblock; added `capture()`, `enrichWith()`, `absorb()`, `hasContactKey()`. Constructor and properties unchanged (still 13 params). |
| `core/Lead/Domain/Exceptions/LeadContactKeyMissingException.php` | Added the `forCapture()` static factory. |

**Not touched:** the VOs (Step 1.1), the other three exceptions, the enums, every other module,
`framework/`, `vendor/`, `database/`, the providers, the mappers. No language file created.

## Design decisions

**The entity stores canonical strings, not the Value Objects.** `capture()` accepts
`?EmailAddress`/`?PhoneNumber` — the validated boundary — and stores `$email?->value`, so what the
entity holds is the normalized text the column stores. The alternative (holding the VOs) would have
changed the 13-parameter constructor and put the Step 2.1 `LeadEntityMapper` out of step with the
schema for no gain; the VOs' job is to guarantee normalization at the door, and that guarantee is
already spent by the time a value reaches a field. Recorded as D-039.

**No events.** `08` E3 adds `$domainEvents`/`releaseEvents()` only with the first behaviour method that
*raises* an event, and Step 1.2 raises none: a capture is not a fact another module needs to hear
about yet (the outbound sync event is raised through the repository path in Step 3.1, not by the
entity). Neither member was added; verified by reflection that neither exists.

**`enrichWith()` is non-destructive.** An existing non-null field wins over an incoming one, because
the stored value is the one a human or a CRM may already have corrected, and a repeated webhook
carrying an old value must not undo it. `meta` is the deliberate exception: it is *merged*, incoming
keys winning on overlap, because a partial submission legitimately carries new attributes and there is
no sense in which one map supersedes the other.

**`absorb()` keeps the survivor's identity.** Per S5 the primary is retained: its `id`, `uuid`,
`status`, `source` and — critically — both contact keys stand, because those are what the CRM and any
existing `crm_mappings` rows already reference. Only blanks are filled. This is also why `absorb()`
differs from "merge the two": a merge that let the secondary's email win would break the very mapping
the caller is about to re-point at this lead. The caller deletes the secondary and re-links its
mappings after this returns; nothing here touches storage.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files exist, no unexpected file | PASS | Two paths edited in place; no file created. `git status --porcelain core/Lead/Domain` shows only the tracked modifications. |
| C2 scope audit vs brief | PASS | Only the entity and the exception the step names. mtimes 19:56 for both; the Step 1.1 VOs still 19:46–19:47; every other Domain file 19:01 or earlier. `framework/`/`vendor/` clean. |
| C3 `php -l` per file | PASS | Both files: "No syntax errors detected". |
| C4 `composer dump-autoload` | PASS | "Generating optimized autoload files containing 1953 classes" (unchanged — the classes already existed). |
| C5 rule DoDs walked | PASS | 05 R1/R3, 08 E3/E8, 06 T8 walked below. |
| C6 06 T8 greps | T8 A **zero**, T8 B **zero** | Both exit 1 over `core/Lead/Domain`. |
| C7 no setter / no mutation | PASS | No `set*()` method, no `: void` behaviour method; all four public methods return `self`/`bool`. Receiver state unchanged after both behaviour calls (asserted). `readonly class`, not `final`. |
| C8 no clock call / no DTO import | PASS | Grep for `new DateTimeImmutable`/`date(`/`time()` and for `LeadCaptureDTO`/`Application\` in the entity: exit 1 both. `$now` arrives as a parameter in all three methods. |
| C9 A2 invariant | PASS | Both keys null throws `LeadContactKeyMissingException`; email-only and phone-only both construct; `hasContactKey()` mirrors the rule. |
| C10 S5 merge semantics | PASS | Survivor's id/uuid/status/source/name/email/phone all retained; only blanks filled; `meta` merged; receiver and argument both unchanged. |
| C11 behaviour correctness | PASS | 66/66 assertions across capture, immutability, non-destructive enrich, merge order, `assignedTo` retention, and the exception factory. |
| C12 no hardcoded user-facing text | PASS | The only string is the exception's untranslated default; keys come from `TRANSLATION_PREFIX`. No `__()`, no `translate()`. |
| C13 tests | Paused per AGENTS.md §6 — the 66 assertions were a deleted ad-hoc script; no `tests/`. |
| C14 transactions | N/A | Pure Domain, no I/O. |
| C15 boundaries | PASS | T8 A/B zero; no `env()`/`Config`/`wpdb`/WordPress symbol; PHP built-ins only. |
| C16 file existence | PASS | No file created, so no guard to trip. |
| C17 events | PASS | No `$domainEvents` property, no `releaseEvents()` (reflection-verified) — correct for an entity that raises none (`08` E3/E8). |
| C18 repairs | **0 A6b rounds** | The one red assertion was a test-fixture error, not a code defect — see below. |

### On the single red assertion

One assertion ("blank name filled") failed on the first run. Diagnosis: the fixture's entity had been
captured with name `'N'` — a non-null value — so `enrichWith()` correctly kept it, and the assertion
had expected the incoming name to win. That is the **plan's own rule** ("existing non-null fields
win"), so the code was right and the test was wrong. Verified directly (`'N'` retained when non-null;
`'New'` applied when the stored name is null), the fixture was corrected, and a dedicated case for a
genuinely blank field was added. **No code changed, so no A6b repair round ran.**

### Rule 05 R1 / R11, 08 E4/E8 walk

- **R1:** rules live in the entity, not a Service ✔ (2, 4, 5, 6, 7); `readonly class`, not `final` ✔;
  one named constructor (`capture()`) that cannot produce an invalid state ✔; nothing outside the
  entity assigns its properties ✔; time and ids passed in ✔; the violation raises
  `LeadContactKeyMissingException` for the Service to convert ✔.
- **R11:** every rule is an entity/VO method ✔; the entity is `readonly` and returns new instances ✔;
  no framework use ✔; an event-less entity carries neither events nor `releaseEvents()` ✔; no
  hardcoded user-facing text ✔.
- **E4/E8:** nothing to release or dispatch (no events) ✔; the entity does not dispatch ✔.
- Rule 05 says "a ctor-and-public-fields Entity is anemic: a defect **once the module has any rule**."
  With A2 and the merge rule now implemented, that condition is met — the three methods are the
  treatment, and `hasContactKey()`/`enrichWith()`/`absorb()` mean no rule was left for a Service.

## Commands run

`git check-ignore -v …` (guard, no output); `php -l` over both files; `composer dump-autoload`; the
06 T8 A/B and boundary greps (including `set*`/`: void`/clock/DTO greps); a scripted verification of
66 assertions plus reflection checks (readonly, not-final, method list, absence of
`domainEvents`/`releaseEvents`); a direct PHP run to diagnose the one red assertion. No migration, no
seeder, no test run, no git write, no DB query, no `framework/` edit. The temporary script was deleted.

## Decisions

Recorded in `DECISIONS.md`: **D-039** — the entity stores canonical strings while the Value Objects
stay the validated boundary (`capture()` takes VOs, fields hold text).

## Deviations

1. **`hasContactKey()` is one method beyond the step's three.** The step names `capture()`,
   `enrichWith()` and `absorb()`. `hasContactKey()` exposes the A2 rule that `capture()` enforces, so
   a caller (the Step 3.1 lookup path, and later the read side) can ask the question without
   re-implementing the rule — which `05` R1.1 forbids putting anywhere but the entity. It adds no
   behaviour and no state. Flagged for the developer's eye.
2. **`$now` is used for both `createdAt` and `updatedAt` in `capture()`.** The step says time is passed
   in; a new row's two timestamps are the same instant, and the mapper persists them once. `enrichWith()`
   and `absorb()` set only `updatedAt`, leaving `createdAt` as the original.

## Deferred and open items

- **No language file for the new key** — `leads.errors.contact_key_missing` joins the keys Step 1.1 and
  0.2 already emit. `lang/` still holds only `en_US/companies.php`; `lang/en_US/leads.php` is needed
  before any channel renders them. Unchanged.
- Step 3.1 decides whether the Service converts the exception into `Result::failure` with this key.
- The `LeadEntityMapper` (Step 2.1) must map the 13 columns to this unchanged constructor; the email/
  phone fields are now documented as already-normalized, which the mapper should assume rather than
  re-normalize.
- **D-034 remains OPEN** (inherited).
- No status transitions were implemented: Step 1.2's brief says transitions are added "only where the
  developer's brief states a rule; none are invented (A9)", and A1 defines the *values* without
  stating which transition is legal. The status-changing method is therefore deferred to whichever
  step defines the rule — recorded here so it is not mistaken for an oversight.

## Tests

Paused per AGENTS.md §6. The 66 assertions were a deleted ad-hoc script, not a suite.

## Next step

**Step 1.3 — CrmSync `RemoteId`, `ContentHash`, `SanitizedErrorMessage`**: provider-aware remote-id
canonicalization with strict `===` comparison, SHA-256 over canonical key-sorted state, and credential
scrubbing with a length cap (S4).

---

### STOP GATE

- **Step:** `Lead/1.2` — **Status: DONE_WITH_NOTES**
- **Report:** `docs/ai-reports/26-lead-entity-behaviour.md`
- **Deliverable:** `capture()`, `enrichWith()`, `absorb()` and `hasContactKey()` on `LeadEntity`, plus
  `LeadContactKeyMissingException::forCapture()`; entity immutable, pure, clock-free, event-free;
  66/66 assertions pass; T8 A/B zero; `php -l` clean.
- **Needs the developer's eye (not blockers):** Deviation 1 (`hasContactKey()` is a fourth method),
  and the deferred status-transition question — A1 lists the status values but states no transition
  rule, so none was invented; say the word if you want them defined here rather than in a later step.
- **Next step:** `1.3` — CrmSync Value Objects `RemoteId`, `ContentHash`, `SanitizedErrorMessage`.
- **Awaiting:** the developer's go-ahead. Nothing is started until then.
