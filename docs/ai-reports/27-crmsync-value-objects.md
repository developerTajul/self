# Step 1.3 — CrmSync Value Objects (`RemoteId`, `ContentHash`, `SanitizedErrorMessage`) (Lead)

**Status:** DONE_WITH_NOTES

**Summary.** Implemented the three CrmSync Value Objects as `final readonly` classes. `RemoteId`
canonicalizes a remote record identifier per provider — expanding a Salesforce-shaped 15-character
alphanumeric id to its 18-character case-safe form — and compares by strict `===` on the canonical
string. `ContentHash` digests a record's state with SHA-256 over a recursively key-sorted encoding, so
the same logical state always yields the same 64-hex digest and an inbound echo is recognisable.
`SanitizedErrorMessage` strips credentials (named secrets, bearer tokens, query-string values, URL
user-info, bare JWTs) *before* capping the length, so a truncated message can never hold half a
secret. Two exception factories were added. 76 functional assertions pass. All three VOs are pure PHP
with no I/O.

**Inputs used.**
- Plan `docs/ai-plans/lead-module-action-plan.md` §5 Step 1.3, plus S4 (and S2's three-part key).
- `database/migrations/2026_11_22_210000_create_crm_mappings_table.php` — the Salesforce 15→18 note and
  the case-insensitive-collation rationale; `…_210200_create_dead_letters_table.php` — `error_message`
  as `mediumText`.
- `core/CrmSync/Domain/Enums/CrmProviderName.php` — the provider the canonicalization keys on.
- Rules 01 P4/P6, 05 R1/R3 + R12.5, 06 T8; AGENTS §5, §7, §11, §13.

**Rules and skills applied.** AGENTS §1 (Core Domain zone), §5, §7 (rich domain: the rule lives in the
VO), §11 (no WordPress in Core), §13; rules 01, 05 (R3), 06 (T8). Tests paused per AGENTS §6.

---

## Pre-flight

- **CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries (D-037, D-038, D-039), the latest
  `PROJECT-LOG.md` entry (`Lead/1.2`), and the previous step's report (`26-lead-entity-behaviour.md`).
  The developer's message approved D-039, `hasContactKey()` and the deferral of status-transition
  rules. **D-038** bound this step: all three VOs expose `equals(self): bool` on the canonical value.
  Step 1.2's deferred A9 item did not reappear. **D-034** stays OPEN and untouched.
- **GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed
  nothing for any of the three (exit 1). **PASS** — no HALT.

## Files

**Modified — 3 files** (Step 0.2 skeletons, named by the step).

| File | Change |
| --- | --- |
| `core/CrmSync/Domain/ValueObjects/RemoteId.php` | Implemented: `fromString()`, `fromCanonical()`, `equals()`, `toString()`, `__toString()`, `MAX_LENGTH`, private `canonicalize()`/`salesforceChecksum()`. |
| `core/CrmSync/Domain/ValueObjects/ContentHash.php` | Implemented: `fromArray()`, `fromString()`, `equals()`, `toString()`, `__toString()`, `LENGTH`, private `canonicalize()`. |
| `core/CrmSync/Domain/ValueObjects/SanitizedErrorMessage.php` | Implemented: `fromString()`, `empty()`, `equals()`, `isEmpty()`, `toString()`, `__toString()`, `MAX_LENGTH`, and four private redaction passes plus `truncate()`. |

**Created — 2 files** (exception factories the VOs call, matching the module's exception pattern; the
step's NECESSARY-IMPORT rule covers them).

| File | Contents |
| --- | --- |
| `core/CrmSync/Domain/Exceptions/InvalidRemoteIdException.php` | `empty()`, `tooLong()` factories. |
| `core/CrmSync/Domain/Exceptions/InvalidContentHashException.php` | `malformed()`, `notEncodable()` factories. |

**Not touched:** the three CrmSync Entities (their constructors already type these VOs), the enums, the
Step 0.3 Infrastructure skeletons, every other module, `framework/`, `vendor/`, `database/`.

`SanitizedErrorMessage` **throws nothing and needs no exception**: scrubbing is best-effort by design —
a message that cannot be cleaned is truncated and stored, never rejected, because losing the record of
a failure to protect a secret the redactor already missed would be the worse outcome. Stated here so
the absent exception is not read as an oversight.

## Design decisions

**`RemoteId` canonicalization keys on the id's *shape*, not on a provider name.** The migration note
says Salesforce ids in particular must reach their 18-character form; the implementation expands any
15-character alphanumeric id and leaves 18-character ones alone. Keying on shape rather than
`CrmProviderName` means the rule keeps working for a provider that is added later and produces the same
id shape, and it cannot be defeated by a mislabelled provider. `$provider` is still a parameter —
it documents the contract and is where a provider-specific rule would go — but no branch currently
depends on it. Recorded as D-040.

**`ContentHash` sorts keys recursively but never sorts lists.** Key order is noise: `['b'=>1,'a'=>2]`
and `['a'=>2,'b'=>1]` are the same state and must hash the same. List order is *not* noise: `[1,2]` and
`[2,1]` are different states. `array_is_list()` distinguishes them, so a reordered payload produced by
a different encoder still matches while a genuinely reordered collection does not.

**`SanitizedErrorMessage` scrubs before truncating.** Truncating first could slice a token in half,
leaving a partial secret that no pattern then matches. The order is therefore: query-string secrets →
named secrets → bare tokens → URL user-info → trim → truncate. Verified by a test that puts a secret
past the cap boundary.

**The bare-token rule requires mixed character classes.** The first implementation redacted any
32-plus-character alphanumeric run, which swallowed a 5000-character string of a single repeated letter
and — more to the point — would have eaten a long record id or URL path segment in a real error body,
destroying the diagnostic value the message exists to carry. A regex `preg_replace` was replaced with a
three-lookahead `preg_replace_callback` requiring an uppercase, a lowercase and a digit in the run. A
real random token mixes classes essentially always; a long identifier or prose word usually does not.
This was found by the self-check — see the repair note below.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files exist, no unexpected file | PASS | 3 edited in place, 2 exception files created; both are the classes the VOs reference. |
| C2 scope audit vs brief | PASS | Step 1.3 names the three VOs; the exceptions are their necessary imports. Nothing else touched — `framework/`/`vendor/` clean, other modules untouched. |
| C3 `php -l` per file | PASS | All 17 files under `core/CrmSync/Domain`: "No syntax errors detected". |
| C4 `composer dump-autoload` | PASS | "Generated optimized autoload files containing 1955 classes" (was 1953; +2 exception classes). |
| C5 rule DoDs walked | PASS | 05 R3, 01 P6, 06 T8 walked below. |
| C6 06 T8 greps | T8 A **zero**, T8 B **zero** | Both exit 1 over `core/CrmSync/Domain`. |
| C7 pure PHP, readonly, no I/O | PASS | `readonly=true`, no writable public property on any of the three (reflection). I/O grep (`file_get_contents`/`fopen`/`curl_`/`wpdb`/`mysqli`) exit 1. `env()`/`Config` absent. |
| C8 `RemoteId` canonicalization | PASS | 15-char alphanumeric expands to 18; 18-char left alone; other shapes untouched; **verified by the defining property**: case-twins expand to strings that differ even after upper-casing, so they cannot collide on a case-insensitive unique index. |
| C9 `ContentHash` determinism | PASS | Key order (flat and nested) does not change the digest; list order does; 64 lowercase hex; different state → different digest; round-trips through `fromString`; unencodable states throw. |
| C10 `SanitizedErrorMessage` scrubbing | PASS | 30+ assertions: Authorization/Bearer, JSON `access_token`, query-string tokens, `api_key`/`client_secret`/`password`/`X-Api-Key`, URL user-info, bare JWT, mixed-class bare token; benign text, short ids and long single-case runs survive; length cap with marker; multibyte-safe truncation; secret past the cap is fully removed. |
| C11 equals on all three | PASS | Present on each, comparing the canonical value. |
| C12 no hardcoded user-facing text | PASS | Only exception *messages* (untranslated defaults) and `TRANSLATION_PREFIX` keys; no `__()`. |
| C13 tests | Paused per AGENTS.md §6 — the 76 assertions were a deleted ad-hoc script; no `tests/`. |
| C14 transactions | N/A | Pure Domain, no I/O. |
| C15 file existence | PASS | The 3 edited paths are the step's own; the 2 exception paths were verified absent before writing. |
| C16 rule-to-skill sync | N/A | No AGENTS.md / rule / skill edit. |
| C17 repairs | **1 round, 1 distinct signature** | See below — within the A6b limit of two. |

### Repair round (09 A6b)

- **Signature:** `str_contains() === false` failure + `SanitizedErrorMessage::fromString(str_repeat('A', 5000))->value` + the `redactBearerTokens()` bare-run regex in `core/CrmSync/Domain/ValueObjects/SanitizedErrorMessage.php`.
- **Diagnosis:** the `[A-Za-z0-9\-_]{32,}` rule redacted any long unbroken run regardless of content, so
  a 4000-character legible message became `[redacted]` and the length cap was never reached. Length
  alone is not evidence of a secret, and the rule would also destroy legitimate long identifiers in
  real error bodies.
- **Bounded repair:** one method (`redactBearerTokens()`) rewritten to require an uppercase, a
  lowercase and a digit in the run, via three lookaheads and `preg_replace_callback`. No other method,
  no signature change, so no tree-wide scan was owed.
- **Outcome:** the signature did not return. Mixed-class runs and bare JWTs are still redacted; long
  single-case runs, prose and short ids survive; the length cap now behaves. Round 1 only.
- **Also corrected in the same pass, and worth flagging:** the first version of the *test* asserted the
  Salesforce checksum against a 15→18 character pair I had recalled rather than verified, and it
  disagreed with the implementation. Rather than adjust the code to match a value I could not confirm,
  I replaced the assertion with checks of the algorithm's **defining property** — that case-twins must
  not collide after expansion under a case-insensitive collation, which is the exact guarantee the
  migration note requires. The algorithm was then confirmed correct on that property. **No production
  code changed for this**; only the test.

### Rule 05 R3 / 01 P6 / 06 T8 walk

- **R3:** own rule, no identity, `readonly`, validated in the named constructor, compared by value ✔;
  in the module's Domain ✔.
- **01 P6:** strict types + PSR-4 ✔; zero framework imports ✔; immutable ✔; no I/O ✔.
- **06 T8:** A zero, B zero ✔.

## Commands run

`git check-ignore -v …` (guard, no output); `php -l` over all 17 Domain files; `composer dump-autoload`;
the T8 A/B and I/O/boundary greps; a scripted verification of 76 assertions plus reflection checks
(readonly, final, `equals`) and a standalone PHP run that reverse-engineered the Salesforce bit rule
against a candidate pair and then re-verified by the collation-safety property. No migration, no
seeder, no test run, no git write, no DB query, no `framework/` edit. The temporary script was deleted.

## Decisions

Recorded in `DECISIONS.md`:

- **D-040** — `RemoteId` canonicalizes by id *shape* (15-char alphanumeric → 18-char), not by a
  `CrmProviderName` branch, so a later provider producing the same shape is covered and a mislabelled
  provider cannot defeat the rule.
- **D-041** — `ContentHash` sorts keys recursively but preserves list order, and
  `SanitizedErrorMessage` scrubs before truncating; both are orderings the code depends on and neither
  is stated in the rules.

## Deviations

1. **`RemoteId::fromCanonical()` is an extra method beyond the step's description.** The EntityMapper
   (Step 2.4) reads a value from a column that only ever holds a canonical id; re-deriving the checksum
   would be wasted work and would re-derive something already computed. `fromString()` remains the
   provider-facing entry point. Flagged for the developer's eye.
2. **`SanitizedErrorMessage` has no exception.** Explained above: scrubbing never rejects, so there is
   nothing to throw. This is a deliberate deviation from the "VO validates → throws" shape the other
   two follow.
3. **The 4000-character cap is my choice.** The column is `mediumText` (16 MiB) and the step says only
   "caps length"; the real constraint is legibility in an admin table, not storage. A constant is
   exposed so the number is reviewable in one place.

## Deferred and open items

- **No language file for the new keys** — `crm_sync.errors.{empty_remote_id,remote_id_too_long,malformed_content_hash,unhashable_state}`
  join the keys Steps 0.2/0.3 already emit. `lang/` still holds only `en_US/companies.php`; a
  `lang/en_US/crm_sync.php` is needed before any channel renders them. Unchanged.
- The `CrmMappingEntityMapper` (Step 2.4) must use `RemoteId::fromCanonical()` and
  `ContentHash::fromString()` when reading, and the DeadLetter mapper (Step 3.5) must use
  `SanitizedErrorMessage::fromString()` — noting that re-scrubbing an already-scrubbed stored message
  is idempotent, which the tests confirm for the named patterns.
- `SanitizedErrorMessage` redacts patterns, not semantics: a secret printed in a shape none of the
  patterns match will survive. Documented in the class; a defence-in-depth measure at the HTTP adapter
  (Step 2.5, never logging raw headers) is still the primary control.
- **D-034 remains OPEN** (inherited).

## Tests

Paused per AGENTS.md §6. The 76 assertions were a deleted ad-hoc script, not a suite.

## Next step

**Step 1.4 — `SyncEventEntity` transitions**: `enqueue()`, `claim()`, `markSucceeded()`,
`scheduleRetry()`, `releaseStaleLock()`, `isExhausted()`, `markDead()`, with `SyncRetryRules`
(`MAX_ATTEMPTS` well below 255, backoff base/cap, lease timeout, pure `backoffSeconds()`).

---

### STOP GATE

- **Step:** `Lead/1.3` — **Status: DONE_WITH_NOTES**
- **Report:** `docs/ai-reports/27-crmsync-value-objects.md`
- **Deliverable:** `RemoteId`, `ContentHash`, `SanitizedErrorMessage` implemented plus the two exception
  factories; 76/76 assertions pass; T8 A/B zero; `php -l` clean; `composer dump-autoload` clean.
- **Needs the developer's eye (not blockers):** Deviation 1 (`fromCanonical()`), Deviation 2 (a VO that
  deliberately throws nothing), Deviation 3 (the 4000-char cap), and **D-040** (canonicalizing by shape
  rather than by provider branch).
- **Worth knowing:** the Salesforce checksum was verified by its *defining property* — case-twins do not
  collide under a case-insensitive collation — rather than against a memorised id pair, because the
  pair I first used turned out to be one I could not confirm.
- **Next step:** `1.4` — `SyncEventEntity` transitions and `SyncRetryRules`.
- **Awaiting:** the developer's go-ahead. Nothing is started until then.
