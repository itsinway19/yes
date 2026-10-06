# SCR-01 — Bounded Shared Canonical Reality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Execution and delegation require subsequent authorization; this artifact stops at Owner review.

**Goal:** Give a future internal consumer qualified advertising-evidence claims and bounded reported expenditure without making that consumer decode provider payloads or reconstruct current knowledge from entry history.

**Architecture:** Extend the existing journal with one bounded, generation-pinned protected read that owns capture, pure interpretation and the final current-authority check before release. Add one stateless shared semantic interpreter consuming trusted typed observation semantics. Keep immutable acquisition, source identity, monetary/window types, signed authority and native adapters unchanged.

**Tech Stack:** Existing PHP 8.5, Laravel 13, PostgreSQL 18 and PHPUnit 12.5; no dependency changes.

**Spec:** `docs/superpowers/specs/2026-10-05-shared-canonical-reality-design.md`, accepted FINAL after F1–F3. This plan is complete as a planning artifact, not implementation proof or Core readiness.

## 1. Authority and baseline

Authority read in order: canonical governance → accepted landed Current Intent contract → FINAL SCR semantic design → accepted SCR discovery → directly relevant implementation. Discovery candidates superseded by FINAL semantic rulings are not reopened. Stable owner: **BUILDERAD SHARED REALITY SEMANTIC CONTRACT**; SCR-01 is provenance, not the owner's name.

| Check | Verified planning baseline |
| --- | --- |
| HEAD / primary main HEAD | `29bd35bbc475731d555dfeea20cde363b09a87b7` |
| Branch | `codex/shared-canonical-reality-discovery-001` |
| Worktree | `C:\dev\builderad-worktrees\shared-canonical-reality-discovery-001` |
| Primary | `C:\dev\builderad`; clean; no fetch or remote freshness claim |
| Initial worktree changes | Only staged accepted discovery and semantic design |
| Execution authorization | Planning only; one new document; no implementation, database connection, private evidence, credentials or provider network |

Protected SHA-256 baselines, checked again at completion:

| Document | SHA-256 |
| --- | --- |
| `docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md` | `74094391E9D3553156D20A3E48AF5028EC1DEAB0D0701A6A4CC2FABC834DA5DB` |
| `docs/current-goal-intent-discovery-001.md` | `CBE50B60606362CA0861B17458CD87FCC6E28A76E90D404EFD7BCAE330F69FD5` |
| `docs/superpowers/specs/2026-10-04-current-goal-intent-design.md` | `3A881365BAA0EC34B2336B5055008BF0597F7AC4F799E14995550E17E5857200` |
| `docs/superpowers/plans/2026-10-04-current-goal-intent.md` | `CCBFFBC814013D0B93B7BD9A5383542FCF2004341211416B3B134EE2CA357992` |
| `docs/shared-canonical-reality-discovery-001.md` | `A192A941454DB9AAE66E29B018483492E5DE325039BF0C766C82D5BE80AD7704` |
| `docs/superpowers/specs/2026-10-05-shared-canonical-reality-design.md` | `C842B0D29891E3AC3D1BBF00165FAE655290CA20A3C9B263EF7039B4AE5E7817` |

## 2. Accepted semantic scope

This is the **first bounded advertising-evidence slice**, not a global definition of Shared Reality. R1/R2 = REUSE_EXISTING_OWNER_ONLY; R3 = FOUNDATIONAL_REQUIRED_NOW; R4 = FOUNDATIONAL_REQUIRED_NOW **for this slice only**; R5/R6 = CONDITIONAL_LATER. Reported expenditure is not required for all advertisers, pre-connection Market Evidence or expenditure-independent Targeting.

Implement SR3–SR7 shared use, knowledge and monetary restrictions; preserve SR10–SR13 outcome, coexistence, unknown and identity boundaries. SR1/SR2/SR14–SR15 constrain scope/ownership. No first Targeting/Spend intervention, threshold or domain policy is selected. Other future Shared Reality classes remain separately governed.

## 3. Current implementation map

Paths below are inspected current code, not proposed replacement owners. Checked-in tests establish assertions; no tests or runtime/database proofs were run for this document.

| Existing boundary | Actual capability / limitation |
| --- | --- |
| `app/Providers/Ads/ProviderScope.php`, `AdObservation.php` | Exact Workspace/source/provider/account/operation/purpose and campaign/window slot; typed provenance/native facts; read time distinct from recording time; no accepted business outcome |
| `ReportWindow.php`, `NativeAmount.php` | Native inclusive dates or explicit half-open instants; grain/timezone/semantics; signed exact decimal normalization; null scale/currency not resolved automatically |
| `ObservationBatch.php`, `ProviderReadResult.php` | Five acquisition states; scope/duplicate checks; COMPLETE means requested read only; only COMPLETE may propose progress |
| `ObservationJournal.php` | Signed-transaction accept/progress/history; history starts from entries, defaults to 50 and caps at 200; no empty-run or full-run membership proof |
| `database/sql/provider_observation.sql` | Immutable runs and entries; stream generation; RESTATEMENT/prior-generation lineage; empty/FAILED/PENDING runs possible; forced RLS and existing source.read SELECT; no structured requested window on runs |
| `database/sql/source_registry.sql`, `provider_access.sql` | Exact current source.read guard; provider.read/custody for live acquisition; current access separate from historical access and semantic truth |
| `ProviderEvidenceValidity.php` | Fact-relative capability validity; not an observation truth, freshness or outcome-acceptance classifier |
| `Meta/MetaObservationAdapter.php`, `MetaObservationReader.php` | Account-local DAY/TOTAL; spend scale 0; clicks/impressions native; failed/partial batches accepted through journal; no expiry invented |
| `Google/GoogleObservationAdapter.php`, `GoogleObservationReader.php` | Cost micros scale 6; native currency/metadata; conversion attribution explicitly not accepted outcomes; cooldown FAILED accepted; no zero rows invented |
| `Snapchat/SnapchatObservationAdapter.php`, `SnapchatObservationReader.php` | TOTAL native spend scale 6, swipes; async metadata gives PENDING or schema-unverified FAILED, not CSV observation parity |
| `TikTok/TikTokObservationAdapter.php`, `TikTokObservationReader.php` | Spend scale deliberately unknown; native delivery remains native; failed reads/cooldown journaled |
| `X/XCampaignReport.php`, `XCampaignReportReader.php`, `XObservationReader.php`, `XReadJob.php` | Placement/funding/time-specific native spend scale 6; sync reader rejects incomplete results; job records specified scheduling failures; do not promise every exception becomes a durable acquisition run |
| Five provider `*ReadExecution.php`; `database/sql/*_read_attempts.sql` | Immutable encrypted replay receipts paired by FK to accepted runs; no runtime SELECT grant on attempts; not an independently readable latest-request lifecycle |
| Five `app/Http/Controllers/*ReportController.php` | Latest accepted run status already queried; Meta/Google/Snapchat/TikTok request windows hashed in streams; X hashes richer report binding. Status paths need acquisition prerequisites and are provider presentation contracts |
| `Google/GoogleCampaignCsv.php`, `GoogleReportController.php` | Generation-pinned complete CSV, 10,000-row bound and final authority recheck; native export, not shared consumer semantics |
| Observation migration `0001_01_01_000160_create_provider_observation_journal.php`; attempt migrations `000190`, `000210`, `000220`, `000230`, `000260` | Install existing SQL; no missing schema required for qualified read-only interpretation |
| `tests/Unit/ProviderObservationContractTest.php`, Meta/Google/Snapchat adapter tests; `tests/Feature/Providers/ProviderAccessStoreTest.php` | Exact amounts, windows, empty/null/zero, native conversion, immutable lineage, failed/partial progress fences, RLS and real writer concurrency already exercised in checked-in tests |

Important boundary: latest **accepted acquisition generation** is readable; queued work, rejected authority, rollback, uncaptured exceptions and all external attempts are not a complete durable latest-request inventory. The new result must say so.

## 4. Reuse map

| Surface | Decision | Consequence |
| --- | --- | --- |
| Context/Intent/Desired Outcome declaration owners | REUSE | Future domains read them separately when needed; no observation-to-context revision attachment |
| Scope/window/money/observation types | REUSE | Rehydrate and validate; exact arithmetic and native meaning retained |
| Journal read boundary | EXTEND / PROJECT | Add one protected read method owning interpretation and final release authorization; accept/progress/history behavior unchanged |
| Streams/runs/entries; source/access guards; WorkspaceContext | REUSE | Existing SELECT/RLS and signed transaction; no grants or DDL |
| Native adapters/readers/jobs/receipts | REUSE | No acquisition, provider parity or transport changes |
| Provider status and Google CSV | REUSE as presentation only | Do not route shared consumers through acquisition/credential prerequisites |
| Shared interpretation owner | INTERPRET | One new stateless class; no new entity, storage or semantic configuration |

## 5. Exact implementation boundary

Two production files: extend `app/Providers/Ads/ObservationJournal.php`; add `app/Providers/Ads/SharedRealityInterpretation.php`. Two test files: add one pure unit test file and extend the existing provider database fixture/test file. No controllers, endpoints, UI, providers, writers, SQL, migrations, config, manifests or protected documents change.

The internal read produces qualified claims from a selected accepted generation, not a latest complete advertiser/account snapshot. It can expose entryless latest runs and membership truncation, so a consumer no longer guesses from empty/truncated history. Unknown request population/window remains explicit; do not fabricate it from a stream hash or the union of returned entries.

## 6. Proposed code ownership surface

**WHY THIS OWNER:** SharedRealityInterpretation implements the stable shared semantic owner; its physical Ads namespace reflects the advertising-only input boundary. ObservationJournal retains protected read custody and orchestration, not expenditure semantics. Its snapshot method is the single enforceable owner of final exact-scope source.read authorization before releasing the interpreted protected result. The interpreter has no database or authority responsibility; future Decision Domains do not own or repeat this guard.

**WHY NOT PROVIDER ADAPTER:** Adapters preserve native acquisition/meaning; five independent implementations would duplicate SR3–SR7. **WHY NOT DECISION DOMAIN:** Interpretation precedes selected domain decisions; domains may apply stricter sufficiency, not redefine unknown or additivity. **WHY NOT BUSINESS CONTEXT:** Evidence neither declares intent nor changes accepted results. **WHY NOT NEW GENERIC INFRASTRUCTURE:** One bounded advertising interpreter plus one existing-owner projection suffices; no repository/query framework, registry, resolver or global evidence DTO is needed.

Use documented bounded array results rather than another value-object hierarchy. Keep database access out of the interpreter. No additional new type is required merely to carry already existing scope/window/amount values.

## 7. R3 implementation design mapping

**CURRENT CAPABILITY:** Immutable accepted generations, run-level coverage/failure/provenance and lineage already exist; provider status can see empty latest runs. Historical access already uses source.read.

**SEMANTIC GAP:** Entry history hides entryless runs and truncates across generations. Status controllers expose no qualified shared monetary claims and require provider-specific request context/custody. Neither interface makes missing requested population or nonjournaled attempts explicit.

**MINIMUM REQUIRED CHANGE:** Journal `snapshot(\Illuminate\Http\Request $request, ProviderScope $scope, string $stream, ?int $generation = null, int $limit = 200): array`, plus interpreter `view(array $snapshot, DateTimeImmutable $at): array`. Request means exactly `Illuminate\Http\Request`, already imported by ObservationJournal for accept/progress/history; no new request DTO or HTTP-decoupling refactor. Null generation selects the captured latest accepted generation; a positive explicit generation selects historical evidence. The journal privately captures the bounded projection, invokes the pure interpreter with an explicit evaluation time, rechecks current exact-scope source.read and only then returns the qualified view. No raw-projection release or automatic last-good lookup.

**WHY SMALLER EXISTING SURFACE IS INSUFFICIENT:** Relabeling history cannot recover empty runs or membership; consuming status/CSV couples consumers to provider payloads and live-read prerequisites. Adding interpretation alone cannot discover hidden runs. A new read service/DTO/storage layer is larger than extending the current protected journal.

Output distinguishes run coverage, selection (latest accepted or historical), projection membership, observation freshness and request knowledge. Request-meaning/all-attempt coverage is unknown unless evidence actually establishes it; this first implementation has no caller-set `complete_request`, `current_truth` or `additive` flags.

## 8. R4 implementation design mapping

`NativeAmount` safely carries native value/unit/scale/basis and exact derived major units, but it does not carry knowledge restrictions or establish currency as known. Reuse it rather than create CanonicalMoney. The shared view adds the bounded expenditure meaning, scope/window, run/entry provenance, lineage and limitations.

Rehydrate stored observations through existing types; check exact scope and slot identity, valid dates/timestamps, typed observation fields and provenance. Recompute major units from native value/scale; never trust serialized `major_units` as another truth. Preserve database content hash as lineage evidence; do not compare it to PHP contentHash, whose encoding differs.

Provider adapters remain the sole owners of translating raw native fields into the accepted AdObservation contract. SharedRealityInterpretation consumes ProviderScope, typed AdObservation spend semantics, NativeAmount value/unit/scale/scale-basis/currency, ReportWindow, provenance and lineage/knowledge qualification, including a trusted bounded projection derived from that typed contract. This is semantic input ownership, not a requirement to pass PHP objects instead of arrays. No second native-field mapper or Meta/Google/provider whitelist belongs in the interpreter. If typed persisted evidence cannot establish a required semantic prerequisite, WITHHOLD.

Known value + evidenced scale + known currency supports an exact signed **source-reported advertising expenditure** claim for that recorded advertising slot. Null value, scale or currency retains native evidence with the specific monetary interpretation withheld. Zero remains zero; negative remains signed. Read coverage/currentness qualify the claim separately. Preserve source-native accounting/report restrictions; do not invent completeness of accounting inclusions/exclusions.

No claim of settlement, invoices, cash paid, budget, affordability, all business cost, revenue, profit, ROI/ROAS or value. Google conversion value is not expenditure and cannot borrow its monetary contract.

## 9. Current-knowledge projection plan

One parameterized SELECT statement validates the visible registered source's provider family/account reference and captures the exact stream tuple, its generation pointer, selected immutable run and up to `limit + 1` ordered entries of **that run** (bounded lateral subquery/aggregate). Reject a source-family/account mismatch; distinguish it from a valid source with no recorded stream. Require this slice's `report.read` operation. Use left joins so zero-entry runs survive. Select only safe run/entry columns; no encrypted progress, attempt envelopes, command fingerprints or actor/session details. Return selected and captured latest generations explicitly.

| Condition | Required result |
| --- | --- |
| No visible authorized stream / selected run absent | No recorded support for that selection; not zero/no external activity; final authority guard still runs |
| COMPLETE, zero entries | Entryless accepted COMPLETE run; no monetary claim; requested population/window not reconstructed |
| COMPLETE, entries within bound | Entire returned membership of selected accepted run, not proof of whole account/request population |
| More than limit entries | Trim sentinel; `membership_complete=false`; individual returned claims remain qualified; no complete-request or total assertion |
| New PARTIAL | Only new run entries; old COMPLETE remains historical, never fills missing members |
| New FAILED/PENDING | Expose run and failure/pending qualifier; no refresh of historical values |
| UNKNOWN | Expose actual entries; withhold completeness |
| Identical reread / RESTATEMENT | Exactly one selected-run value per identity; carry relation/prior generation; versions never additive |
| Later COMPLETE omits a slot | Old value is not a selected-run member; no deletion/zero inference |
| Explicit old generation | Historical selected run even if its coverage is COMPLETE; latest pointer retained for qualification |

A single statement gives coherent generation/membership at its database read point under normal isolation, without observation-writer locks or a new OCC protocol. New acceptance after that point does not make the result globally current; return the capture qualification. ObservationJournal owns the complete protected sequence within exactly one signed Workspace transaction: authorize exact source scope → capture coherent run/membership → invoke pure interpretation → final current source.read recheck → return qualified result. No early return, including empty/missing-run results, bypasses the final guard; no public raw capture is introduced.

Report windows on populated claims come from validated observations. No structured run request window exists, so request window/population remains unknown, including entryless runs. Latest-request assertion is WITHHELD; latest accepted-run state is supported. This satisfies SR3/SR4's explicit limited-knowledge branch rather than silently substituting a hash or last-good rows. Do not decrypt custody/attempt receipts to overcome that limitation.

## 10. Comparison enforcement plan

Add only internal interpreter `assess(array $claims, string $operation): array` for `revision_comparison`, `period_comparison`, `addition`; pair operations require exactly two bounded qualified claims. Return eligibility/reasons, not a comparison endpoint, numerical difference, ratio, ranking or trend engine. This is needed to prove operation restrictions; it is not a standalone feature.

For a narrowly supported same-slot expenditure revision comparison: require exact Workspace/source/provider/account/operation/purpose/stream, slot identity/window/grain/boundary/timezone; right-hand `prior_generation` naming the left-hand generation in that stream and a validated RESTATEMENT or IDENTICAL_REREAD relation; known currency/value/evidenced scale; compatible typed AdObservation spend meaning, NativeAmount unit/scale-basis and report definition/provenance. Consume only the established typed semantics; do not recognize raw native fields, repeat adapter mapping or use a provider whitelist. Equal names/provenance strings or PROVIDER_REPORTED alone do not establish accounting compatibility. This proves only supported versions of that typed reported-expenditure claim; it does not certify unchanged unobserved accounting policy. If typed persisted evidence does not establish the required compatibility, WITHHOLD rather than manufacture a positive comparison. Both values remain qualified with their original run coverage and historical/revision context; no full-population, behavioral or causal claim.

Period comparison eligibility remains UNDETERMINED where compatible accounting/population/request coverage cannot be established from current persisted evidence. This plan adds no caller-supplied compatibility proof or new methodology catalog. The accepted semantic permission for a future proved like-for-like comparison is preserved; there is no need to implement every permitted operation now.

Known cross-provider equivalence is UNSUPPORTED in this slice. Scope/population/grain/boundary/timezone incompatibilities are explained; missing value/currency/scale yields WITHHELD. Exact offset equivalence may be checked for explicit instants; inclusive dates retain native timezone/boundaries. Exact scale conversion is representation normalization only. Native facts and separately labeled provider claims may coexist without any asserted comparison.

## 11. Aggregation enforcement plan

The same small `assess` method returns no sum and no subtotal engine. Check known forbidden cases first: cross-provider/source/account, repeated slot/reread/restatement, account-total plus child population when explicitly evidenced, overlapping periods and overlapping DAY/TOTAL. Overlap uses native inclusive-date intersection with compatible timezone, or exact half-open instants; unknown timezone/boundary compatibility cannot establish disjointness.

Remaining components with missing currency/scale/value, accounting compatibility, additivity, population disjointness or union coverage get the specific WITHHELD/UNDETERMINED reason. Different campaign IDs, disjoint dates, COMPLETE acquisition and matching currencies alone never prove additivity or whole-account coverage. Unknown population relationships are not silently classified as known parent/child or disjoint.

No positive addition is required by the selected descriptive consumer. Existing persisted observations do not carry proved native accounting additivity plus requested union/population coverage. Therefore test J proves refusal of insufficiently evidenced components, including disjoint signed values, rather than introducing a proof-upload interface or sum facility. A future actually required subtotal needs separately reviewed, evidenced operation scope. Partial signed amounts are never presented as a lower bound.

## 12. Authority and security reuse

Run inside existing `WorkspaceContext::run`; require transaction level exactly one as the journal already does. Validate stream syntax, positive generation/bounded limit, and exact ProviderScope. Reuse `builderad_source_read_scope` before and after retrieval/format validation; rely additionally on existing forced RLS and exact SELECT tuple. Reject wrong Workspace/source/account/provider/operation/purpose and expired/revoked authority, rather than interpreting denied rows as factual absence.

No provider.read requirement for consuming authorized historical evidence; no credentials/native HTTP/progress restoration. No new namespace, grant, role, definer function or campaign mutation capability. Interpreter inputs are trusted in-process projection results, not a browser API or persisted semantic receipt; current authorization must be reacquired for each protected read. Do not cache or redistribute protected results under historical authority.

**FINAL_PROTECTED_RELEASE_AUTHORITY_OWNER = ObservationJournal::snapshot (existing protected-read owner). CONSUMER_MUST_MANUALLY_REIMPLEMENT_FINAL_GUARD = NO.** Current code already imports Illuminate\Http\Request, enforces its signed transaction and invokes builderad_source_read_scope. The existing SQL source_require locks the exact grant for share and re-evaluates time-dependent predicates after waits using clock_timestamp. Thus the journal can own interpretation and final release checking within the accepted two production files; no new request abstraction, authority namespace, service or third file is needed. Preserve the existing grant-lock ordering: concurrent revocation is either already effective and denies, or ordered after the authorized read transaction; expiry between capture and release must still deny at the final guard.

## 13. Persistence decision

**NEW_PERSISTENT_STORAGE_NEEDED = NO; NEW_AUTHORITY_NEEDED = NO.** Existing streams/runs/entries contain everything required to describe accepted evidence honestly. Unknown request semantics and unjournaled acquisition remain limitations, not missing fields that this plan may invent.

No schema/index migration, materialized view, journal, duplicate major amount, semantic lifecycle enum, generic metric registry, opaque metadata write or new acceptance payload. An in-memory result does not become stored state. Stronger all-attempt/full-request claims are deliberately withheld as the accepted design requires when evidence is unavailable.

## 14. Consumer interface boundary

Internal sequence owned by ObservationJournal::snapshot: authorize exact source scope in the existing signed Workspace transaction → privately capture projection → invoke shared `view` → final current source.read recheck → release qualified result. Future consumers call this protected method within their existing Workspace boundary; they do not invoke view as a separate protected-release step or manually reimplement the final guard. Optional pure eligibility assessment over already released qualified claims performs no further protected read and grants no authority. No new route, facade service or third production file is needed.

Result keys have documented meanings: scope/stream, selected/captured generations, recorded time, coverage/failure/provenance, selection qualification, membership completeness, request limitations, and bounded claims. Each claim carries its scope/stream/generation references, slot/campaign/window, native amount and exact specified monetary expression when available, native facts with meaning/basis, read time/freshness, provenance and relation/prior generation. These are transient evidence references, not another identity or revision owner. It has no accepted-outcome, action, confidence or aggregate-total field.

`view` is pure and receives an explicit evaluation time; no hidden clock or database lookup. `assess` consumes only its qualified claims, bounded to 200, and returns operation-specific eligibility plus dimensions/reasons. It cannot trust ad hoc booleans or raw provider arrays. Validate required shape/version locally without introducing a persistent/global status model.

Future consumers need no `metrics.cost_micros`, raw `spend` payload key, native scale-name mapping or journal schema knowledge. Preserved native metadata is evidence, not work delegated to domains. R1 context is read separately from its existing owner when a particular decision needs it; native evidence never requires an Intent declaration just to be considered.

## 15. Test and proof matrix

Review Focus: empty latest runs; partial/failed knowledge without last-good fusion; exact null/zero/signed money; operation refusal despite superficially matching amounts; revoked access and coherent generation capture. These five boundaries anchor the tasks below.

| Proof | Exact planned assertion | Test / task |
| --- | --- | --- |
| A | Meta and Google both yield separately qualified money; revision/period equivalence and addition denied across providers | Unit U-A / T3 |
| Typed provider matrix | Synthetic typed Meta/Google/Snapchat/X value + currency + evidenced scale produce qualified expenditure; typed TikTok value/currency with unverified scale retains native evidence and withholds specified money. No raw fields, provider whitelist, provider calls/expansion or cross-provider comparison | Pure unit U-P / T1–T3 |
| B | Generations 1/2 reread and 3 restatement preserve history; latest selected value once; linked compatible revision eligible; versions never additive | DB D-B and unit U-B / T2–T3 |
| C | COMPLETE then FAILED/PENDING returns newest entryless state; COMPLETE then PARTIAL returns only new rows; explicit old read historical | DB D-C / T2 |
| D | Empty COMPLETE survives projection with zero claims, unknown request window, no zero-money claim | DB D-D and unit U-D / T2–T3 |
| E | Native `"0"` expresses exact zero when scale/currency known; null does not | Unit U-E / T3 |
| F | Google conversions/all conversions/value remain native facts with no accepted-business-result promotion | Unit U-F / T3 |
| G | Unknown scale or currency withholds specified money; signed/high-precision values exact; tampered major_units recomputed | Unit U-G / T3 |
| H | Overlapping inclusive-date and instant windows never additive; unknown compatibility does not prove disjointness | Unit U-H / T3 |
| I | DAY plus overlapping TOTAL denied; no automatic repartition or daily rate | Unit U-I / T3 |
| J | Positive subtotal NOT SELECTED; disjoint same-source/account/currency rows still withheld without evidenced additivity/union; signed partial not lower bound | Unit U-J / T3 |
| K | 201 entries at limit 200 yield sentinel/truncation; newer empty run visible despite populated history; full membership is not full-request proof | DB D-K / T2 |
| L | Wrong Workspace/source/account/provider/purpose, revoked/expired source.read denied; history read acquires no provider.read/campaign authority | DB/security D-L / T4 |
| Effect-time authority | Initial authorization/capture succeeds but source.read expires during controlled interpretation delay: journal final guard denies and releases no result, including empty-run path. Already committed revocation denies; concurrent revocation respects existing grant-lock ordering rather than bypassing the guard | DB/security D-E / T4 |
| Additional | Later COMPLETE omission has no current member/zero; UNKNOWN retained; invalid stored scope/identity/shape rejected; provenance changes withhold revision comparison | DB D-O, unit U-V / T2–T3 |
| Concurrency | Independent writer commits a restatement around snapshot read; returned run and entries always one captured generation, new value visible on subsequent read | DB D-R / T4 |

Pure unit tests extend PHPUnit TestCase, not database-backed Tests\TestCase. Database/feature/security/concurrency proofs extend the existing ProviderAccessStoreTest with its private synthetic fixtures; avoid a second broad five-provider fixture infrastructure. Add no live provider, browser, queue or Redis proof for this read-only change. Existing acquisition/writer regression tests remain relevant but are not rewritten.

## 16. Failure and risk matrix

| Severity / risk | Planned prevention and proof |
| --- | --- |
| CRITICAL: authority broadening / cross-tenant leak | Existing source.read/RLS/exact tuple; journal owns post-interpretation final guard; D-L/D-E/T4; no consumer choreography, new grants, receipt decryption or unchecked raw SQL identifiers |
| CRITICAL: stale last-good resurrection / partial promoted to total | Selected immutable run only, historical generation explicit, request scope unknown; D-C/D-K/T2 |
| CRITICAL: double count restatements / overlapping periods | No sum feature; lineage and native-window rejection; U-B/U-H/U-I/T3 |
| MATERIAL: empty read becomes zero | Entryless run retained, no synthetic claim; D-D/U-D |
| MATERIAL: native conversion/delivery becomes business value | Typed AdObservation spend interpretation only; native fact meanings retained, no provider whitelist/second mapper; U-F/U-P |
| MATERIAL: scale/currency invention / duplicated money truth | Existing typed validation, recomputed exact amount, explicit missing component; U-E/U-G |
| MATERIAL: cross-provider equivalence | Explicit out-of-scope rejection; U-A |
| MATERIAL: provider payload/schema leakage into domains | Shared documented result, no raw acquisition JSON/attempts; U-V and output-key assertions |
| MATERIAL: duplicated ownership / fake full request | One interpreter, no provider/domain/context semantics changes; hashes/file scope review and D-K |
| ROUTINE: query cost / oversized output | One run, 201-row sentinel maximum, safe selected columns; query-budget/limit assertions in T2 |

Bad stored shape fails closed for interpretation; it is not repaired by mutating immutable evidence. Distinct claims coexist; no latest timestamp/provider preference resolves genuine non-superseding contradiction. Unsupported stronger claims are withheld with their specific causes, not a confidence rank.

## 17. Performance and data access

Use the existing source registry identity index, unique stream tuple index and runs/entries primary keys `(namespace,generation[,identity])`. One scope guard before, one bounded projection statement, one scope guard before release; no per-entry queries, all-history reconstruction, last-good search or live calls. Projection cost depends on one run and the local bound, not total retained history.

Fetch sentinel rows, not full count; document returned membership rather than implying omitted count. Do not add an index speculatively. A measured disposable proof showing unacceptable query behavior is a STOP to review the smallest remedy, not approval for a new migration. Native facts remain bounded by existing DTO constraints; acceptance already caps payload bytes. Do not stream an unbounded JSON aggregate before applying the row limit.

## 18. Admin and operator impact

**ADMIN_OPERATOR_SURFACE_IMPACT = NOT_APPLICABLE.** Inspected ProviderReportingEvidence admission/withdrawal, `app/Console/Commands/AdmitProviderReportingEvidence.php`, source/capability authority and provider run-status projections. Existing operators admit attributable support/privacy prerequisites and resolve native access issues; SCR creates no catalog, configurable semantic mapping, correction writer, system-owned list or new approval responsibility.

Unknown request/additivity/accounting support is an honest read limitation, not an administrator task to override. No admin UI/dashboard or semantic override flag. **ADMIN_ACTIVITY_MANAGEMENT_DEPENDENCY = NONE**; existing business type/context is referenced only under its accepted owner, not through a new activity catalog dependency.

## 19. Advertiser surface impact

**ADVERTISER_SURFACE_IMPACT = NOT_APPLICABLE.** No selected feature, endpoint, UI, download change or new declaration. Existing provider reports retain current presentation. A later consumer must present qualifiers honestly, but this task does not invent that consumer or require every advertiser to connect a spend source.

## 20. Support and audit impact

**SUPPORT_AUDIT_SURFACE_IMPACT = PARTIAL.** Existing immutable generations/provenance and provider run status support traceability. Planned results add run selection, lineage and operation reasons useful to an authorized caller; there is no current dedicated shared-interpretation support viewer. No support console or new log store is required without a selected support consumer.

Retain safe scope/generation/provenance references for investigation; exclude credential/progress/attempt ciphertext, session data and provider failure messages. Do not grant staff access by virtue of support role. This classification records the current limited surface, not a missing prerequisite for the bounded library implementation.

## 21. Automation impact

**AUTOMATION_SURFACE_IMPACT = NOT_APPLICABLE.** No scheduler, service-principal grant, reader job or automated action is introduced. Future automation needs its own already governed current authorization and decision sufficiency; an in-memory interpretation result is not an execution grant or durable workflow state.

## 22. Ordered implementation tasks

All tasks below are **future, unexecuted**. After Owner authorizes implementation, verify baseline/worktree/protected hashes again. If unrelated changes, authority/storage need, materially expanded files or incompatible semantics appear, STOP for Owner review. No task includes commit, push or merge authorization.

### T1 — Define failing shared contract tests (MATERIAL)

- PURPOSE: Fix the minimum in-memory view and operation semantics before implementation.
- EXACT FILE: create `tests/Unit/SharedRealityInterpretationTest.php` only.
- REUSE: typed scopes/windows/amounts/observations and pure PHPUnit fixtures; no application/database fixture.
- SR MAPPING: SR3–SR7, SR10–SR13; Review Focus money, operation refusal and provenance boundaries.
- [ ] Write U-A/U-B/U-D/U-E/U-F/U-G/U-H/U-I/U-J/U-P/U-V as assertions over `view`/`assess`, including narrowly compatible typed same-slot revision only when prerequisites are established, changed/unknown accounting withholding and all typed dimensions. U-P covers the five-provider typed matrix in §15 with no native-field mapper or provider whitelist. Use only synthetic values.
- [ ] Run `pwsh -NoProfile -File scripts/builderad.ps1 php vendor/bin/phpunit tests/Unit/SharedRealityInterpretationTest.php`; expect missing SharedRealityInterpretation/method failure, not environment/bootstrap failure. If runtime dependencies are unavailable, record blocked proof rather than install or change manifests silently.
- ACCEPTANCE: tests express §14 result shape, preserve native metadata and forbid fabricated stronger claims; no production edit yet.
- DEPENDENCIES: Owner implementation authorization and §24 preflight. STOP: a test needs a new product meaning/configuration, positive subtotal or persistent state.

### T2 — Extend the protected journal projection (CRITICAL)

- PURPOSE: Expose selected run knowledge and bounded membership, including empty latest runs, through one journal-owned protected-release boundary.
- EXACT FILES: modify `app/Providers/Ads/ObservationJournal.php`; add focused methods to `tests/Feature/Providers/ProviderAccessStoreTest.php` using existing fixtures.
- REUSE: signed WorkspaceContext, source.read, forced RLS, existing stream/run/entry indexes and immutable generation contract.
- SR MAPPING: SR3/SR4/SR12/SR13; Review Focus empty, failed/partial and truncation.
- [ ] Add D-B/D-C/D-D/D-K/D-O with COMPLETE→FAILED/PARTIAL/PENDING, UNKNOWN, omission, explicit historical generation, missing stream/run and 201-entry sentinel cases. Expect missing snapshot method failure in guarded disposable tests.
- [ ] Implement only the §7 snapshot signature using exactly Illuminate\Http\Request and the §9 privately captured single-statement projection; validate scope/limits/generation; include safe run provenance/failure and entry lineage; invoke the pure interpreter and perform the final exact-scope source.read guard before every result return. The journal owns this sequence, including empty/missing-run paths; no public raw-projection release, request DTO or historical fallback.
- [ ] Assert bounded SQL query count, run/entry generation coherence, no encrypted/private output, exact membership and immutable-table/stream contents unchanged by reads.
- [ ] Run the existing isolated runner: `pwsh -NoProfile -File scripts/Test-BusinessFoundation.ps1 -Filter 'ProviderAccessStoreTest::test_shared_reality_snapshot'`. Prefix new D-* test method names `test_shared_reality_snapshot_` so this filter is concrete. Expected result: all new projection assertions pass.
- ACCEPTANCE: entryless states visible, membership bound honest, request semantics unknown, source.read retained and final release owned by the journal, accept/progress/history regressions unchanged. T2's projected shape/guard ordering may use a test-bound pure interpreter double until T3 supplies the real interpreter; mark end-to-end semantic proof pending T3/T4 rather than requiring production semantics prematurely.
- DEPENDENCIES: T1 shape. STOP: needed data cannot be honestly qualified without new storage/grants, or projection returns mixed generations.

### T3 — Implement the shared interpreter (MATERIAL)

- PURPOSE: Realize qualified R3/R4 and operation eligibility without consumer provider decoding.
- EXACT FILES: create `app/Providers/Ads/SharedRealityInterpretation.php`; complete `tests/Unit/SharedRealityInterpretationTest.php` only.
- REUSE: NativeAmount/ReportWindow/AdObservation validation and freshness; projection data, original provenance and durable lineage.
- SR MAPPING: SR3–SR7, SR10–SR13; all unit proofs in §15.
- [ ] Implement `view(array $snapshot, DateTimeImmutable $at): array` and `assess(array $claims, string $operation): array`; document bounded input/output keys. Consume trusted typed observation semantics/projection only, with no raw-field mapping or provider whitelist. No DB/network/writer or authority responsibility; ObservationJournal invokes view and owns the final protected release.
- [ ] Recompute money, preserve null/zero/sign, slot/run selection and native definitions; exclude accepted outcomes/totals/actions. Refuse malformed or scope/identity-mismatched records without changing them.
- [ ] Implement narrow revision eligibility and dimension-specific comparison/addition withholding from §§10–11; no sum/difference, public operation or caller-provided proof flags.
- [ ] Run the T1 pure command; expect all unit tests pass. Run existing ProviderObservationContractTest and Meta/Google/Snapchat adapter unit tests through the same pure PHPUnit path to prove representation/acquisition semantics unchanged; no new provider coverage obligation.
- ACCEPTANCE: all planned unit cases including U-P pass; clients receive qualified money/current-knowledge restrictions through the journal without raw provider/schema mapping; allowed representation normalization never becomes economic equivalence.
- DEPENDENCIES: T1 and T2 projection contract. STOP: unknown accounting/request evidence is being replaced by inferred certainty, or any provider-owned raw-field mapping/whitelist is duplicated.

### T4 — Prove authority, integration and read coherence (CRITICAL)

- PURPOSE: Demonstrate the new projection/interpreter together under current authorization and concurrent restatement.
- EXACT FILE: extend `tests/Feature/Providers/ProviderAccessStoreTest.php`; reuse `tests/ObservationCommandWorker.php` unchanged for independent writer acceptance.
- REUSE: existing source fixture/owner helpers, current grants, signed transaction and real writer proof pattern; no production read/write grants in fixtures.
- SR MAPPING: SR2–SR7/SR13; D-L/D-R and integrated A–K.
- [ ] Add methods prefixed `test_shared_reality_authority_` and `test_shared_reality_coherence_`; prove unauthorized tuples/current expiry/revocation deny release and historical source.read does not acquire provider.read. D-E authorizes and captures before a real SQL-clock expiry, uses a test-local container-bound interpreter double to invoke actual pure interpretation with a controlled delay, then asserts the journal's final guard denies without returning protected data. Cover populated and empty results, committed revocation and existing concurrent grant-lock ordering. No production test hooks, new helper file or authority responsibility enters the pure interpreter.
- [ ] Use an independent writer and deterministic synchronization around two read captures; assert each result pairs one immutable run with only that generation's entries, and a subsequent capture sees the committed newer generation. Do not demand a global latest value after the read point.
- [ ] Exercise journal snapshot's owned capture→view→final-guard→release sequence on stored COMPLETE/empty/failed/partial/restated/oversized cases; assert qualification, native monetary semantics, no storage mutation and no consumer-supplied final guard.
- [ ] Run `pwsh -NoProfile -File scripts/Test-BusinessFoundation.ps1 -Filter 'ProviderAccessStoreTest::test_shared_reality_|ProviderAccessStoreTest::test_journal_|ProviderAccessStoreTest::test_partial_failed_and_rolled_back_reads_never_advance_progress|ProviderAccessStoreTest::test_real_journal_workers_replay_once_conflict_and_recheck_authority_after_namespace_wait'`. Expect focused new and existing journal/authority proofs pass.
- ACCEPTANCE: no denied data, stale fusion or mixed-generation interpretation; test cluster/processes stopped in runner finally; actual evidence/exit codes retained.
- DEPENDENCIES: T2/T3. STOP: test requires existing primary port/database, private credentials, broader authority, or a new worker/service beyond the bounded proof.

### T5 — Final bounded verification and Owner handoff (ROUTINE)

- PURPOSE: Assess §24 against actual proofs and scope, not artifact completion.
- EXACT FILES: review only the two production/two test files listed in §25; this planning artifact is not blanket authorization to edit accepted docs.
- [ ] After T1–T4 focused proofs pass, run syntax checks and Pint in verification mode for all changed PHP files: `pwsh -NoProfile -File scripts/builderad.ps1 php vendor/bin/pint --test app/Providers/Ads/ObservationJournal.php app/Providers/Ads/SharedRealityInterpretation.php tests/Unit/SharedRealityInterpretationTest.php tests/Feature/Providers/ProviderAccessStoreTest.php`. Require PASS; no automatic unrelated formatting.
- [ ] Require one complete PHP test-suite run as the final local regression gate, inside the existing guarded disposable runner: `pwsh -NoProfile -File scripts/Test-BusinessFoundation.ps1 -Filter '.*'`. This matches the entire configured PHP suite, not only SCR tests. Record result/count/exit code and confirm no unintended filter or skipped required proof. No unrelated JS/browser gate is added for the PHP-only implementation. GitHub Foundation remains the later authoritative integrated CI gate.
- [ ] If the full PHP suite exposes a historical/flaky/unrelated failure, classify it with evidence; report the regression gate failed or blocked as appropriate, without silently expanding SCR-01. Recheck `git diff --check`, protected hashes, production SQL/config/manifest absence and worktree/primary baseline.
- [ ] Self-review the five Review Focus boundaries, operation-result ownership, remaining unknown request/all-attempt semantics and read query bound; record actual gate results, blocked proofs and files.
- [ ] Stop implementation for Owner review; no commit/push/merge, provider activation or Core readiness claim. Stop task-started processes; retain test logs.
- ACCEPTANCE: §24 honestly reported; all planned proof failures resolved within scope or explicitly blocked. DEPENDENCIES: T4. STOP: any material expansion or contradiction.

## 23. Deferred and forbidden scope

No R5 shared delivery, R6 targeting acquisition/geography/audiences/ad sets/keywords, business outcomes/qualification/CRM/orders/WhatsApp, Outcome catalog, attribution, generic metric/evidence/time/identity infrastructure, resource/budget/action policy or reconciliation winner. No Targeting/Spend implementation or first intervention selection.

No comparison/aggregation product feature, cross-provider equivalent spend/sums, FX, temporal reassignment, all-history merge or sum, confidence hierarchy or persistent result state. No activity-management reopening. No live provider calls, customer data, credentials, database connection or execution during planning.

## 24. Implementation acceptance gate

This is a **future proof gate**, not results claimed now. Before execution: exact approved baseline/branch/worktree; protected hashes intact; no unrelated edits; disposable PostgreSQL 18 only. Existing `scripts/Test-BusinessFoundation.ps1` creates its own cluster on 5433, refuses an occupied/responding port, sets disposable database/token roles and stops/removes only its task cluster. `tests/TestCase.php` invokes DisposableTestDatabaseGuard before reset/TRUNCATE. Do not use the CurrentIntent runner's different baseline or an existing 5432/primary connection. No test runner is executed in this planning task.

```text
R3_SHARED_USABILITY = PASS
R3_CURRENT_KNOWLEDGE = PASS (bounded accepted-generation knowledge; unsupported latest-request claims withheld)
LATEST_FAILED_PARTIAL_NO_SILENT_LAST_GOOD_FUSION = PASS
EMPTY_COMPLETE_NOT_ZERO = PASS
RESTATEMENT_NOT_ADDITIVE = PASS
R4_REPORTED_EXPENDITURE = PASS
UNKNOWN_SCALE_CURRENCY_NOT_INVENTED = PASS
COMPARISON_SEMANTICS = PASS (narrow revision eligibility; other unsupported conclusions withheld)
AGGREGATION_SEMANTICS = PASS (proven refusal; no selected subtotal operation)
STANDALONE_COMPARISON_FEATURE_REQUIRED = NO
STANDALONE_AGGREGATION_FEATURE_REQUIRED = NO
CROSS_PROVIDER_EQUIVALENCE = NOT_IMPLEMENTED
BUSINESS_OUTCOME_PROMOTION = NOT_IMPLEMENTED
R5 = NOT_IMPLEMENTED
R6 = NOT_IMPLEMENTED
NEW_PERSISTENT_STORAGE_NEEDED = NO
NEW_AUTHORITY_NEEDED = NO
NEW_AUTHORITY_NAMESPACE = NO
SECOND_OBSERVATION_STORE = NO
GENERIC_METRIC_REGISTRY = NO
ADMIN_OPERATOR_IMPACT_REVIEWED = YES
PRIMARY_DATABASE_TOUCHED = NO
ACCEPTED_DESIGN_IMPLEMENTATION_CONTRADICTION = NO
```

Gate PASS requires §15 assertions, actual disposable/pure proof evidence, Pint verification for every changed PHP file and one complete PHP test-suite run after T1–T4. Historical/flaky/unrelated failures are classified explicitly and do not authorize scope expansion. No unrelated JS/browser gate; GitHub Foundation remains the later authoritative integrated CI gate. It does not certify complete requested population, all acquisition attempts, supported period comparison/subtotals, live provider semantics, first Decision Domains or the whole Core gate. Those remain explicitly limited or unselected, not silently counted as implemented.

## 25. Expected changed files

| Classification | Exact file(s) | Necessity |
| --- | --- | --- |
| REQUIRED production MODIFY | `app/Providers/Ads/ObservationJournal.php` | Existing protected owner needs a bounded run-first capture and owns pure interpretation/final authority recheck before release; history cannot expose empty runs/full membership |
| REQUIRED production ADD | `app/Providers/Ads/SharedRealityInterpretation.php` | Existing types carry representation, not shared qualified meaning; one stateless owner avoids provider/domain duplication |
| REQUIRED test ADD | `tests/Unit/SharedRealityInterpretationTest.php` | No existing pure shared semantic consumer test; proves exact meanings/operation withholding |
| REQUIRED test MODIFY | `tests/Feature/Providers/ProviderAccessStoreTest.php` | Reuse private synthetic authority/journal fixtures and writer concurrency rather than create another harness |
| CONDITIONAL | NONE | No speculative files authorized; material new need triggers Owner review |
| NOT_EXPECTED SQL/migrations | ALL; specifically observation/source/access/five attempt SQL and installation migrations | Existing read grants, forced RLS, immutable history/indexes sufficient |
| NOT_EXPECTED other production | Provider adapter/reader/job/controller/CSV, context/Intent, admin/advertiser UI, routes, config, dependencies/manifests | No acquisition/presentation/declaration changes required |
| NOT_EXPECTED proof infrastructure | Existing runner, TestCase/guard/reset, ObservationCommandWorker | Reuse unchanged; no new server/browser/Redis worker |
| NOT_EXPECTED accepted documents | Governance, Intent artifacts, accepted SCR discovery/design | Frozen meaning and baseline |

Current task changes only this new plan. Future implementation file expansion is not preapproved merely because another file would be convenient.

## 26. Accepted-design implementation contradiction status

**ACCEPTED_DESIGN_IMPLEMENTATION_CONTRADICTION = NO. MATERIAL_OWNER_RULINGS_REQUIRED = NONE.** Reuse/projection/interpretation and existing source.read suffice for the accepted limited-knowledge/descriptive branch. Missing structured run request scope, all-attempt inventory and additivity proof do not justify storage or authority: SR3/SR4/SR5/SR6 require withholding those stronger claims when support is absent.

This is not a workaround pretending unknown is complete. If subsequent implementation requires those stronger claims, or cannot satisfy the bounded qualified contract without storage/authority, STOP and return the exact unsatisfiable requirement, inspected surfaces, why reuse is insufficient, smallest believed new storage/authority and affected accepted clause. Do not implement that mechanism or modify the semantic design without Owner ruling.

### Planning handoff

```text
BASELINE_SHA = 29bd35bbc475731d555dfeea20cde363b09a87b7
BRANCH = codex/shared-canonical-reality-discovery-001
WORKTREE = C:\dev\builderad-worktrees\shared-canonical-reality-discovery-001
DISCOVERY_ACCEPTED = YES
SEMANTIC_DESIGN_ACCEPTED = YES
DISCOVERY_DOCUMENT_UNCHANGED = YES
SEMANTIC_DESIGN_DOCUMENT_UNCHANGED = YES
IMPLEMENTATION_PLAN_COMPLETE = YES
IMPLEMENTATION_BOUNDARY = BOUNDED_JOURNAL_SNAPSHOT_PLUS_ONE_STATELESS_SHARED_INTERPRETER
R3_IMPLEMENTATION = ACCEPTED_RUN_SELECTION_MEMBERSHIP_LINEAGE_AND_EXPLICIT_KNOWLEDGE_LIMITATIONS
R4_IMPLEMENTATION = EXACT_QUALIFIED_SOURCE_REPORTED_ADVERTISING_EXPENDITURE_USING_EXISTING_TYPES
CURRENT_KNOWLEDGE_MECHANISM = ONE_GENERATION_PINNED_BOUNDED_RUN_FIRST_READ; REQUEST_AND_ALL_ATTEMPT_KNOWLEDGE_NOT_INVENTED
COMPARISON_MECHANISM = INTERNAL_ELIGIBILITY_ONLY; NARROW_SAME_SLOT_REVISION; UNSUPPORTED_OR_UNKNOWN_COMPARISONS_WITHHELD
AGGREGATION_MECHANISM = INTERNAL_FORBIDDEN_OR_UNPROVEN_ADDITION_REFUSAL; NO_SUM_OR_SUBTOTAL_ENGINE
NEW_PERSISTENT_STORAGE_NEEDED = NO
NEW_AUTHORITY_NEEDED = NO
EXPECTED_PRODUCTION_FILES = app/Providers/Ads/ObservationJournal.php; app/Providers/Ads/SharedRealityInterpretation.php
EXPECTED_TEST_FILES = tests/Unit/SharedRealityInterpretationTest.php; tests/Feature/Providers/ProviderAccessStoreTest.php
EXPECTED_SQL_MIGRATION_FILES = NONE
ADMIN_OPERATOR_SURFACE_IMPACT = NOT_APPLICABLE
ADVERTISER_SURFACE_IMPACT = NOT_APPLICABLE
SUPPORT_AUDIT_SURFACE_IMPACT = PARTIAL
AUTOMATION_SURFACE_IMPACT = NOT_APPLICABLE
ADMIN_ACTIVITY_MANAGEMENT_DEPENDENCY = NONE
IMPLEMENTATION_TASKS = T1_CONTRACT_TESTS; T2_JOURNAL_PROJECTION; T3_INTERPRETATION; T4_AUTHORITY_COHERENCE_PROOF; T5_BOUNDED_HANDOFF
CRITICAL_RISKS = AUTHORITY_LEAK; LAST_GOOD_OR_PARTIAL_PROMOTION; RESTATEMENT_OVERLAP_DOUBLE_COUNT
MATERIAL_RISKS = EMPTY_ZERO; NATIVE_OUTCOME_PROMOTION; INVENTED_SCALE_CURRENCY; CROSS_PROVIDER_EQUIVALENCE; PAYLOAD_LEAK; DUPLICATED_OWNERSHIP
IMPLEMENTATION_ACCEPTANCE_GATE = SECTION_24_FUTURE_PROOF_REQUIRED; NOT_EXECUTED
FINAL_PROTECTED_RELEASE_AUTHORITY_OWNER = ObservationJournal::snapshot
CONSUMER_MUST_MANUALLY_REIMPLEMENT_FINAL_GUARD = NO
ACCEPTED_DESIGN_IMPLEMENTATION_CONTRADICTION = NO
MATERIAL_OWNER_RULINGS_REQUIRED = NONE
PLAN_DOCUMENT = docs/superpowers/plans/2026-10-05-shared-canonical-reality.md
PRODUCTION_FILES_CHANGED = NONE
TEST_FILES_CHANGED = NONE
SQL_MIGRATIONS_CHANGED = NONE
GOVERNANCE_FILE_CHANGED = NO
DISCOVERY_FILE_CHANGED = NO
SEMANTIC_DESIGN_FILE_CHANGED = NO
PRIMARY_DATABASE_TOUCHED = NO
IMPLEMENTATION_PERFORMED = NO
COMMIT_CREATED = NO
PUSH_PERFORMED = NO
MERGE_PERFORMED = NO
```

### Final plan correction verification (F1–F5)

These are verified planning corrections, not claims of passing implementation tests:

```text
F1_NO_SECOND_PROVIDER_MAPPER = PASS
PROVIDER_MAPPING_DUPLICATED_IN_SHARED_INTERPRETER = NO
R4_TYPED_CONTRACT_PROVIDER_MATRIX_PLANNED = YES
F2_FULL_PHP_FINAL_REGRESSION = PASS (required in T5; not executed)
PINT_FINAL_CHECK_PLANNED = YES
F3_SR_REFERENCE = PASS
F4_PROTECTED_RELEASE_OWNER = PASS
FINAL_PROTECTED_RELEASE_AUTHORITY_OWNER = ObservationJournal::snapshot
CONSUMER_MUST_MANUALLY_REIMPLEMENT_FINAL_GUARD = NO
EFFECT_TIME_AUTHORITY_PROOF_PLANNED = YES
F5_REQUEST_TYPE_EXPLICIT = PASS
SNAPSHOT_REQUEST_TYPE = Illuminate\Http\Request
NEW_REQUEST_DTO = NO
EXPECTED_FILE_SET_EXPANDED = NO
ACCEPTED_DESIGN_IMPLEMENTATION_CONTRADICTION = NO
R1_R6_DISPOSITIONS_CHANGED = NO
DISCOVERY_UNCHANGED = YES
SEMANTIC_DESIGN_UNCHANGED = YES
GOVERNANCE_UNCHANGED = YES
PRODUCTION_FILES_CHANGED = NONE
TEST_FILES_CHANGED = NONE
IMPLEMENTATION_PERFORMED = NO
COMMIT_CREATED = NO
PUSH_PERFORMED = NO
MERGE_PERFORMED = NO
```

Planning verification: protected hashes, branch/HEAD/primary cleanliness, accepted artifacts staged, correction changes confined to this plan, path references, 26 required sections, task/proof/gate completeness and whitespace. No runtime/database/test execution, dependency installation or task-started long-running process. **STOP for Owner review.**
