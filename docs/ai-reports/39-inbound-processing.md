# Report 39 — Step `Lead/3.4`: `SyncCommandService::processInboundEvent` (D-048 closure)

**Step:** `Lead/3.4` | **Mode:** CORE-PURE | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 3.4
**Predecessor:** report `38` (Step 3.3)
**Closes:** `D-048` (property set for the inbound read) — approved by the developer.

---

## 1. §0 PRE-FLIGHT

Read `D-046`, `D-047`, `D-048`; latest log row; report `35`; plan Step 3.4. `D-048` was the one OPEN decision that bound this step, and the developer approved widening the inbound property list.

---

## 2. GOAL AND SCOPE

**GOAL.** Process one claimed inbound event: normalize the remote id, compute the state hash, suppress our own echo, otherwise apply the change through a Lead-owned port and record the hash.

**ACCEPTANCE.** `RemoteId` normalization; `ContentHash` computation; `isEcho()` true → mark succeeded without touching the lead; otherwise enrich through Lead's **application contract**, never a Lead entity/repository; failures follow the same retry/dead path as Step 3.2.

---

## 3. FILES

| File | Action |
| --- | --- |
| `core/CrmSync/Infrastructure/Adapters/HubSpotAdapter.php` | `fetchRecord()` now requests the full contact property set (`D-048`) |
| `core/CrmSync/Application/Services/SyncCommandService.php` | `processInboundEvent()`, `applyInbound()`, inbound normalization + shared hash canonicalization |
| `core/Lead/Application/Contracts/LeadInboundEnrichmentContract.php` | created: the Lead-owned inbound seam |
| `core/Lead/Application/DTOs/Requests/LeadCrmEnrichmentDTO.php` | created: carries the verified snapshot in |
| `core/Lead/Application/Services/LeadCommandService.php` | implements the seam (`enrichFromCrm()`) |

**`D-048` closure — the property set.** `HubSpotAdapter::LEAD_CONTACT_PROPERTIES` is now
`leadbridge_external_id`, `email`, `firstname`, `lastname`, `phone`, `company`, `website`.
These are exactly the fields the enrichment DTO consumes and the fields the hash compares; the
set is a private constant beside `EXTERNAL_ID_PROPERTY`, so the adapter's request and the Core's
expectations cannot drift without one edit.

---

## 4. THE INBOUND PATH

| Clause | Evidence |
| --- | --- |
| Remote id normalized at the boundary | `RemoteId::fromString((string) ($payload['remote_id'] ?? ''), $event->provider)` |
| Mapping resolved by the three-part remote key | `crmMappingQuery->findByRemoteKey()` |
| Echo suppressed | `$mapping->isEcho($hash)` → `markSucceeded()` and no lead write |
| Immutable identity checked | the record's `leadbridge_external_id` must be present; the mapping's local id is passed as `expectedLeadId` and re-checked against the lead's uuid inside the Lead module |
| Applied through a Lead-owned port | `LeadInboundEnrichmentContract::enrichFromCrm(LeadCrmEnrichmentDTO)` |
| CrmSync never names a Lead entity or repository | no `Core\Lead\Domain\*` import in `core/CrmSync` |
| Hash recorded after a successful apply | `mapping->recordSync($hash, $now)` |
| Failures use the normal path | `CrmPermanentException` inside `recordOutcome()` is caught and turned into a permanent outcome → `markDead()` + dead letter |

**Hash equivalence (`D-054`).** Both directions now hash the **same provider-shaped map** through one `hashProperties()`: email case-folded, phone reduced to `+` + digits, empty strings normalized to `null`. Before this change the outbound path hashed the raw property map while the inbound path hashed an enrichment-shaped alias map, and a phone returned by HubSpot without its `+` would have produced a different digest — the echo check would have silently failed and every push would have come back as a "change". The harness asserts the two shapes hash identically.

**Why the enrichment boundary is a port and not a repository.** `CrmSync` must stay replaceable independently of `Lead` (AGENTS §1, §3). Passing an entity or a repository would couple the two modules' Infrastructure and make either impossible to port alone; the Lead module therefore declares the use case it offers and owns its DTO.

---

## 5. SELF-CHECK

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | both new files verified absent first |
| C2 scope | PASS | `HubSpotAdapter` change is the approved `D-048` widening, one constant + one line |
| C3 syntax | PASS | `php -l` clean |
| C4 contract | PASS | the Lead service implements both inbound contracts (reflection) |
| C5 cross-module purity | PASS | `git grep` for `Core\\Lead\\Domain` in `core/CrmSync` → 0 |
| C6 hash equivalence | PASS | harness: outbound-shaped and inbound-shaped maps produce identical canonical arrays |
| C7 echo path | PASS | `isEcho()` returns early and the event is still marked succeeded |
| C8 retry/dead parity | PASS | the inbound failure is rewritten to a `permanent` outcome inside `recordOutcome()`, so it takes the dead-letter path |
| C9 T8 | PASS | A: 1 pre-existing docblock match; B: 0 |

---

## 6. DEVIATIONS

1. **The external-id equality check against the event payload was removed.** The event payload's `external_id` is the *webhook's* event identity (its own idempotency input), not the record's uuid; comparing the two would reject every genuine inbound event. The check that matters — that the record's uuid matches the local lead and the mapping — is enforced by `expectedLeadId` plus the uuid comparison inside `LeadCommandService::enrichFromCrm()`.
2. **The enrichment uses Lead's `enrichWith()` semantics**, so a locally corrected field is not overwritten by a remote one. A remote-authoritative overwrite is not in the plan and is not invented here.
3. **`D-048` widened one adapter constant, not the port signature.** `CrmContactGatewayContract::fetchRecord()` already promised "the record's properties".

---

## 7. STOP GATE

**Status:** `DONE_WITH_NOTES`. `D-048` is closed. Next step: `Lead/3.5`.
