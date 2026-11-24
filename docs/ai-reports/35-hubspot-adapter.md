# Report 35 — Step `Lead/2.5`: HubSpot adapter and signature verifier

**Step:** `Lead/2.5` | **Mode:** ADAPTER | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 2.5 (lines 229–234)
**Predecessor:** report `34` (Step 2.4)

---

## 1. §0 PRE-FLIGHT

**CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries (`D-045`, `D-046`, `D-047`), the latest `PROJECT-LOG.md` row (`Lead/2.4`, `DONE_WITH_NOTES`), and report `34`.

**OPEN decisions touching this step:** none. **Binding decisions followed:** `D-002`/`D-007` (adapter zone, no helpers in Infrastructure), `D-025` (typed exceptions, not status codes, above the boundary), DP-8 (HubSpot only), plan §5 Step 2.5 (status mapping, 5-minute window, no secret in logs).

**GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed **nothing** for all three. Passed.

**DISK STATE (09 A14) — the interrupted run.** Step 2.5 had **no report and no `PROJECT-LOG` row**, while the filesystem already held both files and their provider bindings (`core/CrmSync/Infrastructure/Adapters/HubSpotAdapter.php`, `HubSpotSignatureVerifier.php`, modified `Nov 23 21:03`; bindings at `app/Providers/CrmSyncServiceProvider.php:83-102`). Per `A14` a step with no report is **unfinished**: the disk was taken as the truth, the existing code was re-verified line by line against the acceptance, and this report closes the step. No earlier step was partially written: `Lead/1.6` through `Lead/2.4` each have a report, a log row and their files on disk (`ls core/Lead core/CrmSync`), and `php -l` is clean on every file this step touches.

---

## 2. GOAL AND SCOPE

**GOAL.** The first release's CRM provider (DP-8): a HubSpot Contacts/Deals v3 gateway that converts HTTP into the two outcomes Core understands, and the webhook signature verifier delivery calls before any Service runs.

**ACCEPTANCE (plan lines 231–233).** `HubSpotAdapter implements CrmContactGatewayContract` with `lead.uuid` carried as `leadbridge_external_id`; `HubSpotSignatureVerifier implements WebhookSignatureVerifierContract` with `X-HubSpot-Signature-v3`, constant-time compare and a 5-minute timestamp window; HTTP through `wp_remote_request` with a timeout; 429/5xx transient, other 4xx permanent; secrets arrive as scalars and are never logged.

**PREREQUISITE (developer, unchanged):** the custom property `leadbridge_external_id` exists in the target HubSpot portal. Nothing in this step can create it.

---

## 3. FILES

| File | Action |
| --- | --- |
| `core/CrmSync/Infrastructure/Adapters/HubSpotAdapter.php` | present on disk from the interrupted run; verified, unchanged |
| `core/CrmSync/Infrastructure/Adapters/HubSpotSignatureVerifier.php` | present on disk from the interrupted run; verified, unchanged |
| `app/Providers/CrmSyncServiceProvider.php:83-102` | already binds both contracts (`registerProviderAdapters()`); verified, unchanged |
| `docs/ai-reports/35-hubspot-adapter.md` | created (this report) |
| `docs/ai-reports/PROJECT-LOG.md`, `docs/ai-reports/DECISIONS.md` | appended |

No production file was edited in this step: every acceptance clause already held, which is recorded under `Deviations` rather than claimed as new work.

---

## 4. WHAT IS THERE (verified against the code, not the intent)

### `HubSpotAdapter` — the transport boundary

| Clause | Evidence |
| --- | --- |
| Contacts/Deals v3 paths | `collectionPath()` → `crm/v3/objects/{contacts\|deals}` (`:284-290`), a `match` that keeps HubSpot's URL word separate from our enum value |
| `/crm/v3` in one constant | `API_VERSION` (`:40`) |
| Create / update / fetch | `createRecord()` `:78`, `updateRecord()` `:101` (`PATCH`, reuses the known id on a `204`), `fetchRecord()` `:131` |
| Timeout on every call | `TIMEOUT_SECONDS = 20` (`:54`), passed in `$args['timeout']` (`:180`); 20 < `SyncRetryRules::LEASE_TIMEOUT_SECONDS` = 300, so a call cannot outlive the lease the reaper reclaims |
| Transport failure is transient | `is_wp_error()` → `CrmTransientException` (`:203-217`) |
| 429 and 5xx transient | `:232-234` |
| Other 4xx permanent | `:236` |
| 2xx without a record id is permanent | `remoteIdFrom()` (`:302-319`) — a write that cannot be addressed must surface, not succeed silently |
| Unreadable body is permanent | `decode()` (`:334-349`) |
| Ids canonicalised at the boundary | `RemoteId::fromString($id, CrmProviderName::hubspot)` (`:318`) |
| No secret in any log or exception | `grep logger->` shows four calls, none carrying the token or headers; a `WP_Error` logs `error_code` only (`:207-211`) and the exception carries the code, never the `WP_Error` message (which can echo the request, including `Authorization`) |
| Misconfiguration is a runtime fault, not a retry | empty token/base URL throws `RuntimeException` **before** any call (`:166-174`) |
| `leadbridge_external_id` is a named fact | `public const EXTERNAL_ID_PROPERTY` (`:43`), used by `fetchRecord()` and available to the payload builder in Step 3.2 |
| No transaction, no `$wpdb`, no helper, no `Config` | imports are Core + PSR + PHP (`:7-15`); `grep` below returns zero for `App\`/`Framework\`/`Illuminate`/`config(`/`resolve(`/`$wpdb` |

The adapter deliberately does **not** open a transaction and does not decide when a retry happens: it types the outcome and returns. `Retry-After` is not honoured, and the reason is written in the code (`:226-229`) — waiting would hold the lease.

### `HubSpotSignatureVerifier` — what delivery calls first

| Clause | Evidence |
| --- | --- |
| `X-HubSpot-Signature-v3` signed input | `hash_hmac('sha256', $method . $requestUri . $rawBody . $timestamp, $clientSecret)` (`:166-171`) |
| Constant-time compare | `hash_equals($expected, strtolower($signature))` (`:116`) |
| 5-minute window, both directions | `MAX_TIMESTAMP_AGE_SECONDS = 300` (`:41`), `abs($now - $sentAt) <= ...` (`:149`) |
| Timestamp must be decimal seconds | `preg_match('/^\d{1,10}$/', ...)` (`:142`), so `1e9`/`0x10` cannot coerce into the window |
| Case-insensitive headers | `normalizeHeaders()` (`:185-194`) |
| Fails closed with no secret | `clientSecret === ''` → `false` + one error log (`:73-81`) — an empty secret would make every signature forgeable |
| Body size guard before hashing | `MAX_SIGNED_BODY_BYTES = 1048576` (`:50`), checked at `:83` |
| Raw body required, not a decoded array | contract docblock + `:166`; re-encoding changes the bytes and therefore the signature |
| No secret logged | `grep logger->` → five calls; the context arrays carry a byte count and the timestamp only |

`time()` is used for the freshness check. It is wall-clock comparison in an adapter (the outer edge), not a business rule, so `ClockContract` is not injected here; the entity transitions that must be testable keep the clock injected.

### Wiring

`CrmSyncServiceProvider::registerProviderAdapters()` (`:83-102`) binds both contracts to closures that build the adapters with **scalars** from `config('app.crm.hubspot.*')` and a `LoggerInterface` — D2 is respected (no `Config` object reaches Infrastructure) and the secrets never travel through a class that could serialise them. The `config/app.php` `crm.hubspot` keys do **not** exist yet; the defaults are an empty token (fails closed) and `https://api.hubapi.com`. Those keys are Step 4.1's deliverable, not this step's.

---

## 5. SELF-CHECK

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | Both adapters, the provider and this report exist; no unexpected file created |
| C2 scope | PASS | Only the two adapter files already in scope were verified; the provider edit is INHERENT WIRING; no file outside the step changed |
| C3 syntax | PASS | `php -l` clean on `HubSpotAdapter.php` and `HubSpotSignatureVerifier.php` (one command per file; output below) |
| C4 autoload | PASS | no new PSR-4 prefix — `Core\` → `core/` already maps these paths; both classes resolved at runtime through the real container |
| C5 rule DoD | PASS | 06 T10 walked: two persistence adapters untouched, no platform type in a signature, duplicate key still translated below; `02` §10 imports audited |
| C6 T8 greps | PASS | A: zero matches in `core/*/Domain`, `core/*/Application`, `core/Shared/Domain`, `core/Shared/Application` (matches exist only in `Infrastructure`/`Shared/Exceptions` docblocks, as expected). B: zero `App\`/`Framework\` in `core/*/Infrastructure` |
| C7 wiring | PASS | each contract bound exactly once (`CrmContactGatewayContract`, `WebhookSignatureVerifierContract`), in the module provider, which is listed in `config/app.php:69` |
| C8 schema fidelity | PASS | no column read or written by this step |
| C9 no leftovers | PASS | the temporary check script `s25-tmp.php` was deleted (`ls` → no such file); no `dd`/`dump`/`var_dump`; the only TODOs in `core/` belong to Steps 3.1–3.6 and are listed there |
| C10 runtime check | PASS | 13/13 checks through `wp-load.php` (below) |
| C11 tests | PASS | paused per AGENTS §6 — none written, none run, none claimed |
| C12 transactions | PASS | the adapter opens none and holds none; it is called by Step 3.2 **outside** any transaction, which the code supports by never writing |
| C13 boundaries | PASS | Infrastructure imports Core/PSR/PHP only; no `Wp`/`$wpdb` type reaches Core; the verifier receives the raw body from delivery |
| C14 file existence | PASS | both adapter paths were occupied by the interrupted run and named by the brief — verified with `ls` before any write; no file created at an occupied path |
| C15 A5b sweep | N/A | no AGENTS.md, rule or skill edit in this step |
| C16 repairs | N/A | no A6b round was entered — Round 0 passed |

### Runtime checks (temporary script, real WordPress bootstrap, deleted after the run)

```
PASS verifier resolves from container
PASS gateway resolves from container
PASS valid signature accepted
PASS tampered body rejected
PASS stale timestamp rejected
PASS future timestamp rejected
PASS missing signature rejected
PASS missing timestamp rejected
PASS non-numeric timestamp rejected
PASS lowercase header names accepted
PASS no configured secret fails closed
PASS empty token raises a runtime fault before any call
PASS no secret in adapter failure text

TOTAL pass=13 fail=0
```

No HTTP call was made: the brief's own prerequisite (a live portal with `leadbridge_external_id`) is not available here, and AGENTS `09` A7 forbids contacting external services. What could be verified without a network is verified; the mapping of a real 429/5xx/4xx response is asserted by reading `request()` (`:222-236`) and is re-exercised by Step 3.2's worker against a stub gateway, which is Core-side and needs no portal.

The two earlier ad-hoc failures (`Container::make()` — the container exposes `resolve()`; the wrong relative depth for `wp-load.php`) were **harness** mistakes in the temporary script, fixed there; no production file changed, so no A6b round applies to `core/`.

---

## 6. COMMANDS RUN

```
git check-ignore -v .claude/rules .claude/skills docs/ai-reports      # no output
ls -R core/Lead core/CrmSync core/Shared                             # disk-truth inventory
git ls-files core app config routes views                             # existence guard
php -l core/CrmSync/Infrastructure/Adapters/HubSpotAdapter.php        # clean
php -l core/CrmSync/Infrastructure/Adapters/HubSpotSignatureVerifier.php # clean
grep -rnE 'Framework\|App\|Illuminate|config\(|resolve\(|wpdb' core/CrmSync/Infrastructure/Adapters/  # 0 matches
php s25-tmp.php                                                       # 13 pass / 0 fail
rm s25-tmp.php                                                        # temp file removed
```

---

## 7. DECISIONS

**`D-048`** (GAP, OPEN) — `fetchRecord()` asks HubSpot for one property, not the record. Recorded in `DECISIONS.md` §N; it binds Step 3.4 to name the property set it needs before the inbound path is written.

---

## 8. DEVIATIONS

1. **The step's production code already existed when this session began** (interrupted run, no report). This report verifies it instead of re-authoring it; every acceptance clause was checked against the source lines cited in §4. Reported as a deviation so the trail does not imply the files were written today.
2. **`config/app.php` has no `crm.hubspot` section yet.** The bindings fall back to safe defaults. Adding the keys is Step 4.1's stated scope; adding them here would move configuration work into an adapter step.

---

## 9. DEFERRED AND OPEN

| Item | Owner |
| --- | --- |
| The `leadbridge_external_id` custom property must exist in the HubSpot portal | developer (plan line 233) |
| Which properties `fetchRecord()` requests for inbound enrichment | Step 3.4 (`D-048`) |
| `app.crm.hubspot.{access_token,client_secret,base_url}` config keys + `.env` | Step 4.1 |
| The webhook endpoint that calls the verifier with the raw body | Step 4.4 (DP-5) |
| Mapping of a live 429/5xx/4xx response from a real portal | Step 3.2 (with a stub gateway) or the developer's first live run |

---

## 10. TESTS

Paused per AGENTS.md §6. No test file was created, none was run, and none is claimed.

---

## 11. STOP GATE

**Status:** `DONE_WITH_NOTES`. The note is `D-048`, an OPEN decision handed to Step 3.4; nothing is blocked.

**Phase 2 is complete:** `2.1` DONE, `2.2` DONE_WITH_NOTES, `2.3` DONE, `2.4` DONE_WITH_NOTES, `2.5` DONE_WITH_NOTES.

**Reports:** `31`–`35` in `docs/ai-reports/`.

**Next step:** `Lead/3.1` — `LeadCommandService::captureLead` (LeadCaptureDTO, `LeadMapper`, `LeadResponseDTO`, `LeadResponseMapper`, one `executeInTransaction()`, S5 merge order, outbox enqueue through `LeadCaptureOutboxContract`).
