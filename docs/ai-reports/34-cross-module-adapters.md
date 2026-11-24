# Report 34 — Step `Lead/2.4`: Cross-module adapters

**Step:** `Lead/2.4` | **Mode:** ADAPTER | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 2.4 (line 219)
**Predecessor:** report `33` (Step 2.3)

---

## 1. §0 PRE-FLIGHT

**CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries (`D-045`, `D-046`, and the Step 2.3 round), the latest `PROJECT-LOG.md` row (`Lead/2.3`, `DONE`), and report `33`.

**OPEN decisions touching this step:** none. **Binding decisions followed:** `D-025` (duplicate key via the module exception), `D-007`/A7 (inbound path), DP-1/DP-2 (the ports are Lead-owned and CrmSync-implemented), DP-6 (idempotency key shape), DP-8 (HubSpot only), A4 (merge mapping conflict), A5 (`entity_type` + `local_entity_id` in the payload).

**GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed **nothing**. Passed.

---

## 2. GOAL AND SCOPE

Implement the two adapters that live in `core/CrmSync/Infrastructure` but implement **ports owned by `Lead`** — the transactional outbox and the merge re-link.

**ACCEPTANCE (plan line 221–222):** the outbox inserts the `sync_events` row inside the caller's transaction with the payload carrying `entity_type` and `local_entity_id`; a unique-key replay is a duplicate outcome. The relink re-points the secondary's mappings, keeps the survivor's on a collision, deletes the secondary's conflicting one and logs it (A4). No transaction is opened in the relink.

---

## 3. FILES

| File | Action |
| --- | --- |
| `core/CrmSync/Infrastructure/Adapters/LeadCaptureOutboxAdapter.php` | implemented (was a stub) |
| `core/CrmSync/Infrastructure/Adapters/LeadMappingRelinkAdapter.php` | implemented (was a stub) |
| `app/Providers/CrmSyncServiceProvider.php` | **edited** — bindings for both adapters extended with the dependencies their constructors now take |

The provider edit is the **INHERENT WIRING** `AGENTS §6` allows without a halt: the adapters gained constructor dependencies (`ClockContract`; `CrmMappingQueryContract` and `ClockContract`), and a binding that no longer supplies them would fail at the first resolve. No other provider or binding was touched.

---

## 4. WHAT WAS BUILT

### `LeadCaptureOutboxAdapter` — the transactional outbox

Writes the `sync_events` row through the ledger's own write port, and **opens no transaction**: the caller (Step 3.1's capture) already has one open, so the lead and its outbound event commit or roll back together. Verified directly — an enqueue inside a `START TRANSACTION` disappears on `ROLLBACK`.

**Idempotency key (settled here; no prior decision covered the outbound case).** DP-6 specifies `sha256(source + ':' + external id)` but for a *capture* no external submission id exists at this layer. The key is therefore `sha256(event_type + ':' + lead.uuid)`. The uuid is the right choice because it is minted once at capture and never changes — deriving the key from a mutable field such as the email would break the guarantee the moment the lead was enriched with a corrected address, and the retry would compute a different key and queue a **second** event for work already owed. The event type is folded in so a future "lead updated" event cannot collide with the capture that preceded it. Recorded as **`D-047`**.

A duplicate key is **absorbed, not raised**: it means the event this lead owes is already enqueued, which is exactly the caller's intent. Raising would abort the capture transaction for a non-failure. This mirrors the plan's own wording for the inbound path ("a duplicate key is a successful no-op", Step 3.3).

An unsaved lead (`id === null`) is **refused**: the row is keyed by `local_entity_id`, and an unsaved lead would file `local_entity_id: 0` — an event the worker could never resolve to a lead.

The payload is a **snapshot**, not a reference: a DLQ replay must push the state that failed, not whatever the lead looks like today.

### `LeadMappingRelinkAdapter` — the A4 merge order

For each of the secondary's mappings, the survivor's mapping for the same four-part local key is looked up **first**:

- **survivor has one** → the survivor's stays, the secondary's is deleted, and the removal is **logged** (A4);
- **survivor has none** → the secondary's is re-pointed.

That order is forced by the two UNIQUE indexes: re-pointing first would collide the four-part local key. The count returned is the number actually re-pointed, so a fully-conflicting merge correctly returns 0.

**No transaction is opened here** (the plan says so explicitly): the caller has one open, and a second begin inside it would be either a savepoint this adapter does not own or a nesting the driver does not provide. Verified by grep — neither adapter contains `beginTransaction`, `START TRANSACTION`, `COMMIT` or `ROLLBACK`.

**The log names keys, never a remote record id.** Verified: the recorded context carries the two lead ids, the provider, the object type and both mapping ids, and does **not** contain the remote record id. A remote id is a pointer into a third party's system, and a mapping removal is not a reason to write one into a log file an operator may paste into a ticket.

A duplicate-key failure that escapes `repoint()` is deliberately **surfaced rather than absorbed**: the survivor's mapping was just checked for, so a collision means another writer created one between the read and the write — and swallowing it would delete a mapping a concurrent merge had decided to keep.

---

## 5. SELF-CHECK

### Static

| Check | Result |
| --- | --- |
| `php -l` on both adapters and the provider | **PASS** |
| T8 — no `Framework\`, `App\`, `Illuminate` in `core/CrmSync/Infrastructure` | **PASS** — ZERO MATCHES |
| No framework helper (`add_action`, `env()`, `config()`, `app()`, `resolve()`) in the adapters (`AGENTS §4 D7`) | **PASS** — ZERO MATCHES |
| Neither adapter opens a transaction | **PASS** — ZERO MATCHES for `beginTransaction`/`START TRANSACTION`/`COMMIT`/`ROLLBACK` |
| Imports are Core, PSR or PHP built-ins only — no `$wpdb`, no WordPress, no `App\` (`02` §10) | **PASS** — audited all 16 import lines |
| Both adapters resolve through the **real container** via their bindings | **PASS** — see below |

### Dynamic — against the real WordPress database

Temporary script `s24-tmp.php`, bootstrapped through `wp-load.php`, clearing the two tables it touches at start and end. **Deleted after the run**; `ls` confirms no temp file remains. A second script resolved both adapters through the live container and was also deleted.

**Container resolution (the integration risk the lint cannot catch).** `app()` returned the real `DevLinkStudios\WPServiceContainer\Container`; `LeadCaptureOutboxContract` resolved to `LeadCaptureOutboxAdapter` and `LeadMappingRelinkContract` to `LeadMappingRelinkAdapter`, both with their full constructor dependency sets satisfied by the edited bindings.

| Group | Checks | Result |
| --- | --- | --- |
| outbox: one event queued; direction, provider, event type, status, attempts | 6 | PASS |
| outbox: payload carries `entity_type`, `local_entity_id`, the uuid, and the lead snapshot (A5) | 4 | PASS |
| outbox: the key is 64 hex chars (DP-6) | 1 | PASS |
| outbox: repeated enqueue is idempotent; the key is stable and derived from uuid + type | 2 | PASS |
| outbox: a different lead queues its own event | 1 | PASS |
| outbox: an unsaved lead is refused and queues nothing | 2 | PASS |
| outbox: **joins the caller's transaction** — visible inside, gone after rollback | 2 | PASS |
| relink: the secondary's unconflicting mappings move; remote record unchanged | 5 | PASS |
| relink: **A4** — survivor keeps its mapping, secondary's conflicting one is deleted, both verified by row id | 7 | PASS |
| relink: the removal is logged with keys and without a remote record id | 7 | PASS |
| relink: a self-merge is refused | 1 | PASS |
| relink: no mappings returns 0; a fully conflicting merge re-points nothing | 3 | PASS |
| cleanup: both tables empty again | 1 | PASS |
| **Total** | **42** | **42 pass / 0 fail** |

**No repair rounds were needed on the production code.** The AUTONOMOUS FIX LOOP was not entered for `core/` — the first run passed every check. Two **test-harness** fixes were needed before that run: a private method name in my recording logger collided with the PSR `log()` it must implement, and the script had tried to instantiate `WpSyncEventReadRepository`, which is still a Step 3.6 stub and out of this step's scope. Both were fixed in the harness; neither changed a production file.

**Complexity (rule 13).** The outbox is one insert (`O(1)` plus index maintenance, with the UNIQUE key making the retry path a single index probe). `relinkMappings` is `O(m · log n)` for *m* = the secondary lead's mappings (bounded by providers × object types, currently 1 × 2) and *n* = rows in `crm_mappings`: one indexed `findAllForLocalEntity` plus one indexed `findByLocalKey` and at most one write per mapping. No unbounded scan.

---

## 6. RULE COMPLIANCE

| Rule | Status |
| --- | --- |
| `AGENTS §1` — adapters never live in `app/`; the port is Lead-owned and the adapter CrmSync-implemented (DP-1, DP-2) | Met — the adapters stay under `core/CrmSync/Infrastructure/Adapters/`, and `Lead` names only `Core\Lead\Application\Contracts\*` |
| `AGENTS §10` — the outbox joins the caller's transaction and opens none; the relink opens none | Met and verified dynamically |
| `AGENTS §11` — the adapter holds no business rule; the transition rules stay in the entities | Met — `SyncEventEntity::enqueue()` and `CrmMappingEntity::relinkTo()` enforce the rules; the adapter orchestrates |
| `AGENTS §6` — INHERENT WIRING: binding a contract in the module provider is in scope | Met — the provider edit supplied the new constructor dependencies |
| `AGENTS §4 D7/D8` — no helpers or service locator in Infrastructure | Met — ZERO MATCHES |
| `AGENTS §4 D2` — no `Config` in Infrastructure; scalars arrive by contextual binding | Met — the adapters import no `Config`; the clock is a contract |
| `02` §10 — the adapter imports only Core, WordPress, PHP, PSR; leaks no `$wpdb` upward | Met — no `$wpdb` is imported at all; the port is injected |
| `AGENTS §6` — tests paused | Met — no `tests/`, no test authored or run |
| Plan §2 A4, A5, DP-6, DP-8 | Met — verified per-check above |

---

## 7. HANDOVER NOTES

**To the DB Optimizer lens (rule 13).** The outbox is a single indexed insert whose duplicate path is absorbed, not retried in a loop. The relink is index-served throughout (`findAllForLocalEntity` on the local index's leading columns, `findByLocalKey` on the full four-part key) with at most one write per mapping. No lock is taken beyond the rows the caller's transaction already holds.

**To the Security Auditor lens (rule 14).** Neither adapter builds SQL — all persistence goes through the repositories audited in Steps 2.1–2.3. The one output surface is the log line, and it was verified to carry no remote record id and no contact data. No nonce/capability concern arises in Infrastructure; the inbound verification path is delivery's (DP-5).

**Carried forward (binding).** `D-043` and `D-046` bind Step 3.5. New: **`D-047`** — the outbound idempotency key shape, which Step 3.1's capture and Step 3.2's worker both depend on.

---

## 8. STOP GATE

**Status:** `DONE_WITH_NOTES`. The note is `D-047`, a settled gap rather than an open question. Nothing is blocked.

**Report:** `docs/ai-reports/34-cross-module-adapters.md`

**Open decisions:** none.

**Next step:** `Lead/2.5` — HubSpot adapter and signature verifier. `HubSpotAdapter implements CrmContactGatewayContract` (Contacts / Deals v3; `lead.uuid` sent as `leadbridge_external_id`), `HubSpotSignatureVerifier implements WebhookSignatureVerifierContract` (`X-HubSpot-Signature-v3`, constant-time compare, 5-minute timestamp window). HTTP via `wp_remote_request` with timeouts; 429 / 5xx map to a transient exception subtype, other 4xx to a permanent one. Secrets arrive as scalars by contextual binding (D2, D8) and are never logged. **PREREQUISITE (developer):** the custom property `leadbridge_external_id` exists in HubSpot.
