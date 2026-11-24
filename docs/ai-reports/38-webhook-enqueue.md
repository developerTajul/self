# Report 38 — Step `Lead/3.3`: `SyncCommandService::enqueueInboundWebhook` (A7)

**Step:** `Lead/3.3` | **Mode:** CORE-PURE | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 3.3
**Predecessor:** report `37` (Step 3.2)

---

## 1. §0 PRE-FLIGHT

Read `D-046`, `D-047`, `D-048`; latest log row; report `35`; plan Step 3.3 and A7/DP-5/DP-6. Git tracking guard: no output.

Binding: **DP-5** — authenticity is verified in delivery, so this method receives an already-verified DTO and performs no signature work. **DP-6** — key is `sha256(source + ':' + external id)`.

---

## 2. GOAL AND SCOPE

**GOAL.** Accept one verified webhook, compute the idempotency key, write a `sync_events` row in **direction `inbound`** in one transaction, and return fast. A duplicate key is a successful no-op.

**ACCEPTANCE.** Already-verified DTO input; key computed per DP-6; inbound row enqueued in one transaction; duplicate → success; `Result` the controller can turn into `200`.

---

## 3. FILES

| File | Action |
| --- | --- |
| `core/CrmSync/Application/DTOs/Requests/WebhookEnqueueDTO.php` | created: provider, event type, remote id, payload, source, external id |
| `core/CrmSync/Application/Contracts/SyncMapperContract.php` | extended: `mapToWebhookEnqueueDTO()` |
| `core/CrmSync/Application/Mappers/SyncMapper.php` | implemented: verified-input → enqueue DTO, incomplete input rejected by name |
| `core/CrmSync/Application/Services/SyncCommandService.php` | implemented `enqueueInboundWebhook()` |

---

## 4. WHAT THE METHOD DOES

| Clause | Evidence |
| --- | --- |
| Key per DP-6 | `hash('sha256', $dto->source . ':' . $dto->externalId)` — 64 hex chars, fits `varchar(191)` unique |
| One transaction | `executeInTransaction()` wrapping a single `create()` |
| Direction inbound | `SyncDirection::inbound` in `SyncEventEntity::enqueue()` |
| Handler inbound | `jobClass()` derives `HANDLER_INBOUND` from the direction, so no caller can pair the wrong handler (`A6`) |
| Payload carries the routing facts | `object_type`, `remote_id`, the provider payload, `source`, `external_id` |
| Duplicate is a no-op success | `executeInTransaction()` maps `SyncEventDuplicateException` → `crm_sync_duplicate`; the method returns `Result::success(null, …duplicate…)` |
| No signature work here | no verifier dependency on the service; DP-5 keeps that in delivery |
| Fast return | one insert, no HTTP, no CRM call |

**Why the payload keeps `source` and `external_id`.** The row's unique key is a digest, so the two fields it was derived from are not recoverable from it. The inbound processor needs the external id to check it against the record it fetches (`Step 3.4`), and an operator reading the audit log needs to know which provider event this row came from.

**Duplicate-detection mechanism.** The decision is made by the **unique index**, not by a prior read: two concurrent redeliveries both attempt the insert, one wins and the other receives the translated conflict. That is what makes the no-op race-free.

---

## 5. SELF-CHECK

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | all three paths verified absent before creation |
| C2 scope | PASS | no controller, no route, no verifier change (delivery is Step 4.4) |
| C3 syntax | PASS | `php -l` clean |
| C4 contract | PASS | `SyncMapper implements SyncMapperContract`; `SyncCommandServiceContract::enqueueInboundWebhook` signature matches |
| C5 transaction | PASS | one `executeInTransaction()`; no manual begin/commit |
| C6 vendor exception | PASS | `SyncEventDuplicateException` is a Domain type |
| C7 failure signal | PASS | the closure writes and returns an entity; nothing is swallowed after the write |
| C8 T8 | PASS | A: 1 pre-existing docblock match; B: 0 |

---

## 6. DEVIATIONS

1. **`SyncMapperContract` grew a third method** (`mapToSyncEventFilterDTO`, `mapToDeadLetterFilterDTO` came with Step 3.6). The contract was extended in this phase rather than creating a second mapper port, because all three methods turn plain delivery arrays into sync DTOs and one port keeps a controller's dependency count at one.
2. **The DTO carries `source` and `externalId` separately** rather than a pre-computed key, so the key derivation stays in one place (the service) and a caller cannot supply a key that disagrees with DP-6 (`D-047`'s reasoning applied to the inbound side).

---

## 7. STOP GATE

**Status:** `DONE_WITH_NOTES`. Next step: `Lead/3.4`.
