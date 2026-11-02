# BF03-I01 — independent security/database subreview

Review date: 2026-11-01. Parent review: `BF03-I01-FINAL-INDEPENDENT-REVIEW-001`.

This reviewer did not implement the candidate. This is a delegated same-model source/evidence review, not a distinct-provider assessment. Root owns final scope fingerprint, merged findings, evidence equivalence, and dispositions. No application, SQL, test, permission, or governance changes were made. No database, server, worker, or comprehensive Foundation process was started.

## Scope and original inputs

Actual local HEAD supplied and observed by the parent: `c747f6d8a8f2011fa5dcc368da2aa4e8449e0565`, with the uncommitted candidate. Read applicable AGENTS guidance and inherited review assignment. Inspected the relevant accepted policy text in the original Owner attachment `541193bb-a684-4b7f-b4c9-aa1e66e8bc3d/Pasted text.txt`, plus the subsequent attachment `08140d3a-53b9-4915-bee8-53b941b52ba1/Pasted text.txt`, `NEXT_IMPLEMENTATION_PACKAGE.md` §§3–8, `CASE_A_CONTRACT.md` relevant input/ownership/negative cases, BF03 candidate architecture invariant additions, and execution-record artifact pointers. Prior reviewer final reports were not adopted as findings.

Production sources inspected:

- `app/Commercial/InquiryEvidence.php`
- `app/Http/Controllers/InquiryEvidenceController.php`
- `routes/web.php` authentication/freshness and inquiry routes.
- `database/migrations/0001_01_01_000540_create_inquiry_evidence.php`
- All six files under `database/sql/inquiry_evidence/`.
- Immediate authority dependencies in `database/sql/business_scope/commands.sql`, `authority.sql`, `scope_authority.sql`, and `permissions.sql`.
- Current `command_replay` and relevant guard behavior in `database/sql/market_evidence/integration.sql`, plus installation `catalog_seal.sql`.
- `app/Foundation/Tenancy/PrincipalContext.php` and the framework Migration transaction default.

Tests inspected: `InquiryEvidenceAdmissionTest.php`, `InquiryEvidenceConcurrencyTest.php`, `InquiryEvidenceUpgradeTest.php`, `InquiryEvidenceFixture.php`, and `InquiryEvidenceWorker.php`. Relevant criterion API/editor/display declarations were inspected to qualify the semantic concern below.

## Confirmed enforcing boundaries

| Property | Actual enforcing source | Negative proof in inspected tests |
| --- | --- | --- |
| No implicit BF03 permission from older rights | `inquiry_require`, authority.sql:18–38 selects exact action, actor, Business, active enrollment, qualified-policy root and nondelegability. | `test_old_rights_do_not_implicitly_authorize_new_operations_or_auto_enroll`; foreign/synthetic-root test. |
| Exact original creator and qualification | `inquiry_basis`, authority.sql:6–12; original basis and generation checked in `inquiry_require`:29–38. | Foreign actor/Business/root enrollment; original qualification revocation test. |
| Deliberate fresh enrollment | authority.sql:73–79 rejects missing command/basis/confirmation; `inquiry_fresh`:46–49 requires current 15-minute authentication. | Explicit enrollment test, false confirmation rejection, replay under revoked basis denial. |
| Current principal/session/generation/lifecycle | `InquiryEvidence::run`:43–50 activates a clean signed transaction. Existing `principal` checks advertiser/current verified user, session generation, revocation and idle/absolute expiry. `require_business` rejects suspended/archived Business. | Current BF03 session-expiry real-lock test and original-root denial; broader existing owner coverage must be reconciled by root for generation/lifecycle permutations not separately exercised here. |
| Authority after waits | K3 Business fence before policy checks; `inquiry_require`:27 freezes selected K4 footprint including original basis, then reads current qualification. Final record/read checks in declarations.sql:38–39,70; existing `record_command` calls `assert_invocation_current` after receipt insertion. | Three real-process contention tests, described below. |
| Action separation and no write-to-read escalation | action selected at declarations.sql:10; read always requires `business.inquiry_evidence.read`:47,70; write acknowledgment deliberately contains no value:35–36. | `test_permissions_are_independent_and_write_ack_does_not_disclose_current_values`. |
| Cross-actor/Business private read and history | declarations.sql:48–49,56,66 restrict heads by Business and original issuer before output; generic denial, not foreign existence details. | Foreign actor/Business test; separate-read and pagination/revocation tests. |
| Exact command identity, no unauthorized replay | Current `command_replay` compares Business, actor, action and payload; write proof precedes replay and is repeated before returning. Own-surrender replay has separate current-original-basis authorization. | Changed payload conflict, stale correction conflict, original-basis revoked replay denial, revoke lost-ACK test. |
| Append-only history and optimistic correction | Schema unique `(declaration_id,revision)` and command ID, no executor UPDATE on revision table, guarded DML; declarations.sql:19–34 checks current revision and terminal withdrawal, appends next revision. | Initial/correct/history/withdraw test; stale correction; two-process revision conflict. |
| Runtime cannot directly reach private tables/helpers | table_permissions.sql:3–16 revokes app/authority/PUBLIC table access, forces RLS and exact effect triggers; permissions.sql:4–5 exposes only public BF03 entry points to app. New function ownership derives from creation under executor role in migration:17–20. Privileged functions qualify public relations and pin `pg_catalog,builderad_private,pg_temp`. | Direct app/authority SELECT and runtime INSERT denials. Actual catalog/runtime privileges were not independently queried in this subreview. |
| No semantic assurance escalation or arbitrary payload | validation.sql exact nested key sets and bounded typed fields; declarations.sql:36,71 emits `SOURCE_ASSERTED`, `independently_verified=false`. | Explicit unsupported verified/customer_phone/revenue fields, negative count, accepted-count overflow, missing cost basis, precision and timezone rejection. |

No CRITICAL authority bypass, destructive path, or unauthorized read/mutation was substantiated in this source review. This is bounded assurance, not a proof that every adversarial permutation is impossible.

## Database installation, concurrency, and recovery

Migration 540 verifies the current protected installation seal before adding objects, installs owner-created tables with executor-owned functions/triggers, revokes temporary schema CREATE, and reseals the catalog (migration:10–23). Framework migrations default to transaction wrapping where supported. The upgrade test creates a populated pre-540 schema, compares existing rows before/after, confirms no automatic new grants/enrollments, reruns the migrator, checks temporary schema privilege removal, and exercises empty down/up. This establishes only its inspected test contract until exact retained passing evidence is reconciled.

`down()` checks enrollment/head population before DDL and refuses populated rollback (migration:29–31), preserving declarations. This is a safe refusal. It is not an operational forward-recovery procedure. No concrete executable populated recovery procedure was present in this bounded record; that remains a separately qualified production planning condition, not evidence of an exploitable local data-loss bug. No production migration was run here.

Opened original retained files under:

`C:/Users/stoma/.codex/visualizations/2026/10/30/01a18cdf-2b2d-7682-ac51-b50f944e5fcc/workspace-ux-20261101T171558Z/`

- `final-tests.xml`: exactly one test (`test_http_empty_permission_map_is_an_object_not_a_list`), 3 assertions, no errors/failures. It is not a full BF03 result.
- `inquiry-basis-8b3f7c2d-68cb-4809-af69-1ddb49ca6342-evidence.json`: worker PID 10352, XID 50428, transaction-ID lock blocked by 21208, then `DENIED_STATE=42501`, exit 2.
- `inquiry-session-f68aae28-aada-4969-a3bb-6afce0e86f58-evidence.json`: PID 16556, XID 50447, relation lock blocked by 15248; observed time after stored second-precision expiry, then 42501/exit 2.
- `inquiry-revision-f12bd5bf-ddf9-4be0-86cd-3597e9413c6f-evidence.json`: distinct PIDs 19656 and 3652, actual blocking edges 19656→3652→20968; one revision-2 commit, one `I4090`, exits 0/2.

The inspected concurrency test additionally asserts no accepted revision or command receipt for the denied cases and exactly two total revisions after competing corrections. The evidence contains genuine lock observations, not sleep-only contention or screenshots. Root must reconcile source hashes and the aggregate test log, and must not equate these later focused artifacts with a full Foundation PASS. This subreview did not diagnose the original Foundation pre-READY failures or memory exhaustion; root owns that separate track.

## Semantic concern for merged adjudication

**Candidate MATERIAL: acceptance criterion is a category/version without a preserved rule or resolvable reference.** This concern was sent to root for reconciliation with the semantic reviewer; avoid issuing a duplicate finding ID.

Accepted BF03 contract `NEXT_IMPLEMENTATION_PACKAGE.md`:42 requires accepted count plus criterion/ref/version; K02 at :108 requires preserving the criterion and historical meaning. The actual closed acceptance object at validation.sql:45–50 permits only count, pending count, criterion_code, criterion_version, cutoff, maturity and coverage. Criterion code is one of `operator_defined`, `within_service_area`, `response_ready`. There is no immutable criterion definition/reference in the payload or resolvable criterion owner in the inspected implementation.

The default form is `operator_defined`, version 1 (`InquiryEvidencePanel.vue`:28). Form :149 selects only the category and :161 supplies a number; serialization :82 cannot preserve an actual Business-defined rule. Details :35–36 display the generic label and version. A targeted search of app, SQL, scripts, docs and frontend found no definition resolver for these enum values.

Counterexample: two declarations use different Business-defined acceptance rules (e.g. a minimum order value versus service-area eligibility), same category/version/count/time fields. Their accepted count criterion information serializes identically. No current read/history can recover which declared rule gave the number, and a user is told a criterion was stated when the rule itself was never recorded. This is a current declaration/history fidelity issue, not a demand to implement future Case A analysis.

Smallest legitimate correction, if confirmed by merged review: preserve a bounded immutable criterion definition or an exact resolvable per-Business criterion reference/version in the declaration lineage, with the same private authority and no PII/arbitrary-JSON escape. Alternatively, represent an unstated criterion as explicitly unknown and withhold the claim that it is preserved; do not silently invent a universal rule. Tests should distinguish two actual criteria and retain old criterion meaning after a correction/version change. No correction was implemented.

Other examined edge cases (count arithmetic variants, mature/unknown pending values, declared currencies/country codes) did not establish an additional accepted-contract violation from the inspected scope and are not reported as defects.

## New checks executed and limits

Executed read-only PHP syntax checks using the installed `C:/tools/php85/php.exe`:

- `php -l app/Commercial/InquiryEvidence.php` — PASS.
- `php -l app/Http/Controllers/InquiryEvidenceController.php` — PASS.
- `php -l database/migrations/0001_01_01_000540_create_inquiry_evidence.php` — PASS.

These checks prove syntax only. No new PHP integration, database migration, SQL execution, browser, production build, or Foundation run was executed by this subreview. No task-started persistent processes require cleanup. Only this authorized review artifact was written.
