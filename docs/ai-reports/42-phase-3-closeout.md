# Report 42 — Phase 3 closeout: application services

**Phase:** 3 (`Lead/3.1` – `Lead/3.6`) | **Modes:** CORE-PURE (+ inherent DELIVERY wiring) | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Phase 3
**Predecessor:** report `41` (Step 3.6)

---

## 1. §0 PRE-FLIGHT

Read `D-046`, `D-047`, `D-048` and the latest `PROJECT-LOG.md` row (`Lead/2.5`) at entry; each step report re-read the trail. Git tracking guard run at entry and again at closeout: `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed **nothing** (exit 1 = none ignored). Passed.

---

## 2. WHAT PHASE 3 DELIVERED

| Step | Report | Status | Content |
| --- | --- | --- | --- |
| `3.1` | `36-lead-capture.md` | DONE_WITH_NOTES | `captureLead()`: create / enrich / merge, S5 order, outbox with the persisted id, one duplicate retry |
| `3.2` | `37-sync-outbound-batch.md` | DONE_WITH_NOTES | claim → HTTP outside the transaction → per-event recording; mapping link + `recordSync` |
| `3.3` | `38-webhook-enqueue.md` | DONE_WITH_NOTES | verified DTO → DP-6 key → inbound ledger row in one transaction; duplicate = success |
| `3.4` | `39-inbound-processing.md` | DONE_WITH_NOTES | remote id, hash, echo suppression, Lead-owned enrichment port; **`D-048` closed** |
| `3.5` | `40-reaper-and-replay.md` | DONE_WITH_NOTES | D-043 + D-046 reaper, bounded exhausted-row lookup, one-shot DLQ replay |
| `3.6` | `41-query-services.md` | DONE_WITH_NOTES | leads list, sync audit, dead-letter inspector; read ports only, clamped filters |

**Inherent wiring (AGENTS §6).** `app/Providers/CrmSyncServiceProvider.php` and `app/Providers/LeadServiceProvider.php` now construct both services with the arguments their constructors actually declare, and `CrmProviderRegistryContract` is bound to `HubSpotProviderRegistry`. No contract is bound twice (audited binding key by key). This is the wiring the phase's services need to be resolvable at all; the **full** Step 4.1 work (`config/app.php` `crm.hubspot.*` scalars, contextual binding, `boot()` hooks) remains open.

---

## 3. VERIFICATION ACTUALLY RUN

### 3.1 Syntax

`php -l` on **104** files under `core/Lead`, `core/CrmSync`, `app/Providers`: **no syntax errors**.

### 3.2 Structural harness (temporary script, deleted after the run)

`s36-tmp.php` at the plugin root, run against `vendor/autoload.php`, then removed.

```
TOTAL pass=126 fail=0
```

After the final edits (reaper count, `recordOutcome` return type, query-service dependency removal) a
reduced re-run of the same harness was executed and returned `pass=120 fail=0`; both temporary
scripts were deleted, and no check script is part of the deliverable.

What it asserted:

| Group | Assertion |
| --- | --- |
| Autoload | every Lead/CrmSync/Shared class, interface and enum loads — which also makes PHP enforce every `implements` signature compatibility |
| Services | both Lead services and both CrmSync services satisfy their inbound contracts; `LeadCommandService` also satisfies `LeadInboundEnrichmentContract` |
| CQRS split | no read port declares a write method (`create`, `update`, `delete`, `insert`, `write`, `markReplayed`, `claim`); no write port declares a listing read (`getFiltered*`, `getDeadLetters`, `findByEmail`, `findByPhone`, `findByLocalKey`, `findByRemoteKey`, `findById`) |
| Wiring parity | `SyncCommandService` and `SyncQueryService` constructor parameter types, in order, equal the provider's resolution order |
| Registry | `HubSpotProviderRegistry` returns the injected gateway and verifier for `hubspot` |
| Inbound seam | `LeadCrmEnrichmentDTO` resolves in the Lead module |
| Hash equivalence | the outbound-shaped and inbound-shaped contact maps canonicalize to the same array |
| Hash canonicalization | `ContentHash` is key-order independent |
| Retry ceiling | with `attempts = MAX_ATTEMPTS - 1`, the next failure takes the dead path and `attempts` stays far below the 255 ceiling |

### 3.3 Boundary greps (06 T8)
- **T8 A** (`core/*/Domain`, `core/*/Application`, `core/Shared/{Domain,Application}`): **one** match, `core/Shared/Domain/Contracts/CacheInterface.php:46` — a pre-existing docblock naming `wp_cache_get()`, already recorded as the accepted port-declaration/prose case in `D-034`. No new match from Phase 3.
- **T8 B** (`core/*/Infrastructure`, `core/Shared/Infrastructure`): **zero** matches for `App\`, `Framework\`, `Illuminate`.
- Cross-module purity: `git grep 'Core\\Lead\\Domain' core/CrmSync` → **0** matches; `git grep 'Core\\CrmSync' core/Lead` → only the two contract docblocks that name the adapters' location, no class use.

### 3.4 Not run — and not claimed

- **No tests.** AGENTS §6 keeps them paused; none written, scaffolded, required or run.
- **No live CRM call.** `09` A7 forbids contacting external services here.
- **No database exercise.** The inbound/outbound hash equivalence is asserted on the canonicalization function, not on a MySQL round trip or a real HubSpot response.
- **No migration, no `framework/` or `vendor/` edit.**

---

## 4. DEFECTS FOUND AND REPAIRED THIS PHASE

| # | Defect | Where | Fix |
| --- | --- | --- | --- |
| 1 | Provider constructed `SyncCommandService` with the pre-Phase-3 argument list — the service could not be resolved at all | `CrmSyncServiceProvider` | arguments matched to the real constructor; `HubSpotProviderRegistry` bound (`D-052`) |
| 2 | Inbound hash compared an enrichment-shaped alias map against the outbound provider map, so a phone returned without `+` (or an uppercase email) would never look like an echo | `SyncCommandService::hashProperties()` | one canonicalization for both directions (`D-054`) |
| 3 | Retry guard used `! $event->isExhausted()`, so an event at `MAX_ATTEMPTS` attempted `scheduleRetry()` — which throws — turning the final transient failure into an unrecorded event | `SyncCommandService::recordOutcome()` | guard asked `mayRetry($attempts + 1)` (`D-053`) |
| 4 | `recordOutcome()` returned an array, so a failed recording was indistinguishable from "0 succeeded, 0 retried, 0 dead" | `SyncCommandService::recordOutcome()` | returns `Result` (`D-053`) |
| 5 | Replay wrapped a failure `Result` inside a success `Result` | `replayDeadLetter()` | closure returns `?int`; not-found is reported after the commit |
| 6 | Inbound event compared its own event id against the record's uuid, rejecting every genuine event | `applyInbound()` | removed; the mapping + `expectedLeadId` + uuid check enforce identity |
| 7 | Dead-letter count incremented even when the row was already settled by someone else | `deadLetterExhaustedEvent()` | returns `true`/`false`; only a real filing is counted |
| 8 | `SyncQueryService` declared an unused `CrmMappingQueryContract` dependency | service + provider | removed (`D-055`) |

All eight were repaired in place; no A6b repair round was needed (every check passed on the first full harness run).

---

## 5. NEW DECISIONS

| ID | Title |
| --- | --- |
| `D-049` | The outbound batch claims, then calls the CRM outside every transaction, then records per event |
| `D-050` | The reaper's exhausted-row identity is read through the **read** port, not by widening the frozen exception |
| `D-051` | Inbound enrichment crosses modules through a Lead-owned contract + DTO, never an entity or repository |
| `D-052` | `CrmProviderRegistryContract` is implemented by `HubSpotProviderRegistry` in Infrastructure |
| `D-053` | The per-event outcome is recorded via a `Result`-returning method, and the retry guard asks about the next attempt |
| `D-054` | Echo detection hashes one canonical provider-shaped map in both directions |
| `D-055` | The sync query service depends only on the ports it reads |
| `D-048` | **CLOSED** — inbound property set widened to the six contact fields + external id |

---

## 6. OPEN ITEMS HANDED FORWARD

| Item | Owner |
| --- | --- |
| `app.crm.hubspot.{access_token, client_secret, base_url}` config keys and `.env` entries | Step `4.1` |
| Contextual binding for those scalars; provider `boot()` hooks | Step `4.1` |
| CF7 listener + mapper | Step `4.2` |
| WooCommerce listener + mapper | Step `4.3` |
| `POST /wp-json/.../webhooks/hubspot` controller (raw body → verifier → `enqueueInboundWebhook`) | Step `4.4` |
| Worker + reaper scheduling (Action Scheduler / WP-Cron) calling `processOutboundBatch`, `processInboundEvent`, `releaseStaleLeases` | Step `4.5` |
| Admin leads list, sync audit and DLQ replay screens | Phase `5` |
| A real 429/5xx/4xx round trip against a portal holding `leadbridge_external_id` | developer's first live run |
| No delivery entry point yet calls `processInboundEvent()` — the inbound worker wiring lands in Step `4.5` | Step `4.5` |

---

## 7. HONEST LIMITS OF THIS PHASE

1. **The phase reports were written after the implementation was drafted**, in the closing sweep of a continuous run; every claim in them was re-checked against the code on disk during that sweep, which is why eight defects are listed above instead of being claimed as done.
2. **Nothing in the working tree is committed.** `core/CrmSync/`, `app/Providers/CrmSyncServiceProvider.php` and most Lead additions are still untracked (`git status`), so the trail depends on the files staying where they are.
3. **The pre-existing working tree carries unrelated modifications and deletions** (removed Auth module, removed Laravel adapters, deleted views and reports). They were not touched, not restored and not relied on.
4. **Behaviour against a real database and a real CRM is unverified.** Structural compatibility, syntax, boundary purity and the deterministic Domain rules are verified; HTTP, SQL and WordPress runtime behaviour are not.

---

## 8. STOP GATE

**Phase 3 complete.** Steps `3.1`–`3.6` are `DONE_WITH_NOTES`; reports `36`–`41` plus this closeout are on disk; `PROJECT-LOG.md`, `DECISIONS.md` and `NEXT-SESSION.md` are updated.

**Next step:** `Lead/4.1` — provider wiring and config (the `crm.hubspot.*` scalars, contextual binding, `boot()` hooks).
