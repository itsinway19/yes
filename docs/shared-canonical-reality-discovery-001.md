# BUILDERAD — SCR-01: Shared Canonical Reality / Observation Foundation

## Current-state discovery 001

**Owner-review artifact; repository investigation only; 2026-10-05.** No semantic design, implementation plan or execution authorization is created here.

**Ruling:** `SHARED_CANONICAL_REALITY_STATE = PARTIAL`. The repository already has substantial, guarded, durable provider observation infrastructure. It does not yet establish a sufficiently explicit shared interpretation/consumption contract for the first Targeting and Spend decisions. Recommend **A — SHARED REALITY SEMANTIC DESIGN REQUIRED**, reusing the existing infrastructure. This is not a ruling that all advertising evidence is missing or that business-outcome ingestion must precede every useful decision.

### Baseline and investigation limits

| Check | Observed result |
| --- | --- |
| Authorized baseline | `29bd35bbc475731d555dfeea20cde363b09a87b7` |
| Primary repository | `C:\dev\builderad` |
| Primary `main`, `origin/main`, primary HEAD | All equal the authorized SHA; primary branch is `main`; initial working tree clean. `origin/main` means the local remote-tracking ref; no fetch or remote freshness claim. |
| Target branch | `codex/shared-canonical-reality-discovery-001` |
| Target worktree | `C:\dev\builderad-worktrees\shared-canonical-reality-discovery-001` |
| Existing branch/worktree safety | Existing branches and all registered worktrees inspected; target already existed, exact branch/HEAD matched, initially clean. Reused without reset, deletion or branch movement. |
| Accepted artifacts | All three required paths present in this baseline and returned by `git ls-files`: `docs/current-goal-intent-discovery-001.md`, `docs/superpowers/specs/2026-10-04-current-goal-intent-design.md`, `docs/superpowers/plans/2026-10-04-current-goal-intent.md`. No old-worktree copies used. |

Evidence references below are repository-relative `path:line` anchors at this SHA. A reference to a test establishes checked-in assertions, not a new passing test run. Historical BB2 proof is explicitly historical. No runtime, database, OAuth or live-provider proof was performed. No dependency installation or external research occurred.

## 1. AUTHORITATIVE GOVERNANCE

The sole product-governance authority is `docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md`, read from this baseline. The accepted Current Intent specification fixes the already accepted declaration/outcome boundary; its discovery and plan provide historical context and implementation evidence, not competing governance.

| Canonical clause | What it already decides for this discovery |
| --- | --- |
| Core §§2–4; gate items 1–5 (`docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:79`, `:180`) | Inside-out Core; external sources do not own product semantics. Business/Decision Context, Current Intent, Canonical Outcome Meaning, Shared Canonical Reality precede the first real Decision Domains. Targeting and Spend are the minimum initial domains for the intended Core slice, not a permanent taxonomy. |
| Core §4, Shared Canonical Reality (`docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:209`) | “A shared fact has one semantic owner.” Do not duplicate canonical truths inside domains/providers. |
| Core §4, Outcome / Synthesis / Learning boundaries (`docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:335`) | Valuable outcomes vary by business/goal. Synthesis performs bounded reconciliation; learning remains traceable and must not overwrite declared intent. |
| Core §4, observation/verification and external adapter (`docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:311`, `:381`) | Observation after action does not automatically prove incremental/causal effect. Sources contribute evidence through an adapter without owning product meaning. Gate readiness requires usable, coherent, actually proven behavior, not schemas or mocks alone. |
| Core §5 (`docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:428`) | Targeting includes markets/configuration/responders/qualified outcomes; Spend includes resources/allocation/quality/economics. These are broad reasoning spaces. A campaign is not automatically a product domain; domains do not own duplicate truth. |
| Core §§7–10 (`docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:523`) | Ask what real-world fact a provider value represents; map an existing canonical fact to its owner. Classify A source-specific detail, B existing canonical fact, C genuinely new product truth. API shape alone cannot justify product redesign. Native identifiers/statuses/transport remain local where possible. |
| Core §§12, 14–15, 18–19 (`docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:664`, `:776`, `:810`, `:934`, `:960`) | Reuse coherent contracts, avoid speculative frameworks, demonstrate material value; no unproved savings/causal lift. Builderad owns canonical interpretation; external systems own native representation/operational semantics. Subsequent Core increments require separate approval. |
| Market Evidence §§2, 4–7 (`docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:1192`, `:1263`, `:1301`, `:1368`) | Shared evidence, not private domain copies; declared/observed/inferred/discovered/learned stay distinct. Evidence states are not a maturity ladder or enum mandate; no false certainty or decorative confidence. |
| Market Evidence §§10–13, 24 (`docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:1545`, `:1596`, `:1696`, `:2204`) | Provenance/rights/reproducibility matter; signals do not prove business outcomes. Decision Delta must change a meaningful action boundary; more charts/context fails the anti-data-lake rule. Canonical ownership depends on established meaning, not source prestige. |
| External Evidence Providers supplement §§1–8, 11 (same canonical file, heading “EXTERNAL EVIDENCE PROVIDERS — AUTHORITY, SUBSTITUTABILITY & CAPABILITY UTILIZATION”) | Source authority is fact-relative and independent of technical substitutability. Preserve native methodology/provenance. Source failure, absence of evidence and absence of the underlying fact differ. Degrade claim strength honestly. Evaluate capabilities by decision need; do not ingest a catalog merely because available. |

**Navigation-aid comparison:** the task's sequence corresponds to the canonical numbered gate, with `EXECUTION AUTHORITY` abbreviating `EXECUTION AUTHORITY LEVELS`. The file describes contracts required in usable form, not a command to postpone already approved provider evidence until after learning. Core §3 explicitly preserves required existing connectivity. No material sequencing contradiction found. The historical DTM execution instructions remain historical governance context; the present Owner request authorizes only this discovery, not restarting DTM or reopening landed Intent.

**Undecided by governance:** exact first Targeting/Spend interventions and order between them; shared spend/delivery interpretation; comparison eligibility/freshness policy; qualification-evidence relationship; supported reconciliation rules; budget/economic constraints; data shape/storage. Governance does not select a metric registry, a universal Outcome entity or a universal evidence enum.

**Accepted boundary preserved:** Current Intent owns the currently desired change; Desired Outcome Meaning separately owns the confirmed valuable-result/acceptance meaning (`docs/superpowers/specs/2026-10-04-current-goal-intent-design.md:79`, `:94`). Physical co-storage does not merge these owners. The landed implementation stores the nullable complete declaration on Workspace, immutably journals semantic transitions, and exposes value plus shared revision (`database/sql/current_intent.sql:87`, `:154`; `app/Business/BusinessContext.php:74`). It supplies no observed progress. A declaration is business-wide, optional, and never inferred from provider configuration. The accepted outcome value does **not** implement observed business-outcome evidence.

## 2. CURRENT SYSTEM STATE MATRIX

Classifications concern the stated boundary at the baseline. `COMPLETE` below means sufficient established behavior for the bounded existing contract, supported by actual implementation and checked-in assertions; it does not certify live provider activation or the entire Core gate.

| Material area | Classification | Repository evidence and boundary |
| --- | --- | --- |
| Business/Decision Context declarations | COMPLETE | Business type/name and DTM are current declarations; `app/Business/BusinessContext.php:74`; DTM writer `database/sql/declared_target_markets.sql:40`. Not a complete future economic context. |
| Current Intent / first Desired Outcome Meaning | COMPLETE | Accepted specification §§3–6; actual `database/sql/current_intent.sql:87`, `:192`, `:225`; `tests/Feature/CurrentIntentTest.php:54`. Completeness is the landed declaration contract, not measurable business progress. |
| Source identity | COMPLETE | Workspace + UUIDv7 source, family/namespace/external ID uniqueness; `database/sql/source_registry.sql:34`; `ProviderScope.php:18`; contract test `ProviderObservationContractTest.php:65` under `tests/Unit/`. Registration remains UNVERIFIED, not universal source authority. |
| Source provenance | PARTIAL | Required API/report/documentation provenance, basis per native fact, attributable runs; `app/Providers/Ads/AdObservation.php:23`; `database/sql/provider_observation.sql:16`. Does not record a complete provider attribution methodology/configuration or business acceptance trail. |
| Provider-native acquisition | PRESENT_BUT_UNVERIFIED | Meta/Google read/map/page/guard code and adapter tests exist. Historical synthetic proof is recorded; current live activation and exact external semantics are not verified in SCR-01. |
| Observation persistence/history | COMPLETE | Forced RLS, immutable runs/entries, atomic acceptance, generations; `database/sql/provider_observation.sql:2`, `:29`, `:104`; `tests/Feature/Providers/ProviderAccessStoreTest.php:434`. Existing provider-history contract only. |
| Restatement/correction behavior | COMPLETE | Same measurement identity, database-derived content hash, prior-generation chain; `AdObservation.php:48`; SQL `:133`; test `ProviderAccessStoreTest.php:434`. Correction is not deletion and reread is not extra outcome. |
| Coverage/completeness | PARTIAL | Batch/request coverage is explicit (`ObservationBatch.php:7`); empty COMPLETE does not establish zero. No complete population/scope contract for domain totals; history projection omits entryless runs. |
| Freshness/validity | PARTIAL | Observation freshness and capability validity are separate; `AdObservation.php:71`; `ProviderEvidenceValidity.php:11`. Meta/Google map `freshUntil = null`; no decision-specific usability policy. |
| Report-window semantics | PARTIAL | Native inclusive dates/half-open instants/grain/timezone retained; `ReportWindow.php:9`; Google/Meta mapping. No general comparability/overlap-selection contract; empty-run window not exposed as structured run data. |
| Spend representation | PARTIAL | Exact native money and evidenced scale conversion (`NativeAmount.php:7`, `:28`); Meta scale 0, Google 6. No shared reported-spend owner/comparison/aggregation contract or currency conversion. |
| Delivery metrics | PARTIAL | Source-labelled impressions/clicks, nullable values and basis; Meta adapter `:211`, Google `:162`. No canonical equivalent click/reach/outcome definition established. |
| Provider conversion semantics | PARTIAL | Google conversions/all conversions/value retain decimal tokens and provider-attribution warning (`GoogleObservationAdapter.php:169`; test `GoogleObservationAdapterTest.php:44`). Attribution settings/qualification are not established; Meta conversions not acquired. |
| Observed business-outcome evidence | MISSING | `AdObservation.php:8` explicitly excludes accepted business outcomes. Search of app/SQL plus actual declaration/adapter mappings finds no business-order/message/qualified-outcome acquisition or acceptance bridge. Desired outcome prose is not such evidence. |
| Source-native object identity | COMPLETE | Existing bounded source/account/campaign/window slots, exact scope isolation; `ProviderScope.php:29`; `AdObservation.php:49`; adapter scope validation. Not complete for audiences, keywords or business objects. |
| Cross-provider semantic normalization | PARTIAL | Shared envelope and exact currency-scale arithmetic exist; metric native meaning remains explicit. No shared fact-level compatibility/normalization contract for domain consumption found. |
| Semantic ownership | PARTIAL | Governance and Intent specification establish declared/canonical/native boundaries; evidence mechanics explicitly defined. No established shared business interpretation of reported spend/delivery/qualification. Namespace/table/writer is not owner evidence. |
| Physical storage ownership/location | COMPLETE | Explicit source, access, observation, attempt and declaration stores and install migrations. This proves physical custody, not semantic ownership. |
| Write/mutation authority | COMPLETE | Narrow signed source/provider authority, exact tuple/access epoch, no raw runtime DML, final rechecks; provider SQL and checked-in authority tests. Grants do not verify evidence truth or permit campaign changes. |
| Conflict/coexistence semantics | PARTIAL | Provider scopes/streams/generations coexist and native keys differ; duplicate slots rejected inside one batch. No business-evidence coexistence/contradiction or domain reconciliation contract. |
| Absence/knowledge semantics | PARTIAL | Explicit null/UNKNOWN, FAILED/PENDING/PARTIAL and capability UNSUPPORTED; tests preserve zero separately. Missing/out-of-scope/hidden/history-empty interpretation not a full shared consumption contract. |
| Targeting-consumable shared reality | PARTIAL | DTM, Intent and source evidence available separately; no campaign geography/audience/search configuration or outcome segmentation in actual Meta/Google maps; no implemented Targeting consumer found. |
| Spend-consumable shared reality | PARTIAL | Native campaign-period spend/delivery and history available; no shared eligibility/aggregation contract, declared advertising cap/allocation/economics or accepted outcome performance found. No implemented Spend consumer. |
| Observation projections versus history | DUPLICATED_OR_OVERLAPPING | Five report controllers read the same durable run status; Google CSV separately projects stored facts (`GoogleReportController.php:38`, `:77`; `GoogleCampaignCsv.php:52`). Intentional overlapping projections, not proven competing canonical stores. |

Unqualified filenames in this matrix refer to `app/Providers/Ads/`, with the Google adapter under `Google/`. Test filenames refer to their stated test directory.

## 3. EXISTING OBSERVATION ARCHITECTURE

### Acquisition and acceptance

The existing flow is source registration/capability tuple → scoped encrypted provider access → guarded provider-specific acquisition → mapped `ProviderReadResult`/`ObservationBatch` → `ObservationJournal::accept` → SQL acceptance transaction. Provider request/paging/retry/async details remain local. The interface is read-only (`app/Providers/Ads/ProviderObservationAdapter.php:5`); an asynchronous report computation is not campaign mutation (`Meta/MetaObservationAdapter.php:66`).

Meta reader verifies selected account/timezone and currency; Google verifies native account metadata even for empty reports (`Meta/MetaObservationReader.php:34`; `Google/GoogleObservationAdapter.php:82`). Each network boundary requires current exact-scope authority. Jobs are encrypted, dispatched after commit, bind actor/session/Workspace/access version/configuration and revalidate before acquisition (`Google/GoogleReadJob.php:92`; `PersistObservationJob.php:47`, `:86`). Native attempt execution pairs encrypted retry receipts with the corresponding accepted journal generation (`Google/GoogleReadExecution.php:19`; `database/sql/google_read_attempts.sql:18`, `:46`). The five `*_read_attempts.sql` files implement parallel provider-local begin/finish fences; they are scheduling/replay custody, not observed business facts.

### Shape, identity, time and provenance

`AdObservation` requires a campaign-period advertising snapshot, exact ProviderScope, native spend, bounded fact map, provenance and read time. Fact value, unit, meaning and basis are separate. There is no generic event model or accepted-outcome flag (`app/Providers/Ads/AdObservation.php:8`, `:32`). `NativeProgress` is opaque adapter-owned encrypted progress, not observed advertiser progress (`NativeProgress.php:7`).

Scope is Workspace/source/provider/account/operation/purpose. Measurement identity adds campaign and the entire serialized window, including boundary/grain/timezone/semantics. SQL stream namespace additionally separates adapter-owned stream definitions. Distinct source IDs or purposes are not deduplicated economic identities (`ProviderScope.php:29`; `AdObservation.php:49`; `database/sql/provider_observation.sql:47`). X retains placement in window semantics to prevent overwrite across placements (`X/XCampaignReport.php:73`).

Time has different jobs: native report window describes the measurement period; `read_at` is acquisition time; SQL `recorded_at` is durable recording time. Google campaign status is native state returned at read, **not proven historical status throughout the metric period**. Current Intent's business deadline/timezone is a declaration, not the provider report window. No event/effective timestamp exists for business outcomes because that evidence path is absent. No invented freshness TTL is justified.

### Durability and isolation

Each accepted command creates an immutable run and zero or more immutable entries. Replay requires the same fingerprint; a changed command payload conflicts. New commands advance generation under the namespace lock; identical reread and restatement preserve their earlier generation link. The database independently derives content hash, so a caller's claimed hash cannot conceal a correction (`database/sql/provider_observation.sql:68`, `:133`). The PHP and SQL content-hash encodings need not be byte-identical; durable relation uses the database's own hash consistently, not PHP hash equality.

Only resolved COMPLETE reads may propose progress. PARTIAL/FAILED/PENDING runs can preserve their own history but cannot advance accepted progress. All observation/progress/run writes are in one transaction with final time-sensitive authority rechecks. `tests/Feature/Providers/ProviderAccessStoreTest.php:434`, `:461`, `:485`, `:525`, `:608` contain assertions for rereads, rollback, fences and hash independence.

**Important projection limitation:** `ObservationJournal::history` starts from entries joined to runs, bounds rows rather than full runs and returns no standalone entryless run/failure (`ObservationJournal.php:70`, `:89`). Report controllers separately expose the latest run. Consumers cannot equate empty history with no reads or use a truncated entry list as a complete report. A latest failed/partial run must not silently resurrect an older complete run as current knowledge.

**Empty-run scope limitation:** runs store coverage/provenance/failure but no structured requested window. Public Meta/Google stream definitions hash that window; entries carry windows when present (`GoogleReportController.php:134`; SQL run schema `:16`). Encrypted progress/attempt state is not a generic consumer-readable scope contract. This does not lose populated entry windows or invalidate the journal; it means shared consumption of empty/failed scoped reads needs an explicit boundary.

**Three separate permissions/claims:** `source.read` permits historical evidence access; `provider.read` plus current custody prerequisites permits live fetch/acceptance; external campaign mutation is not supported by this observation boundary. Observation freshness, native connection state, four-axis fetch eligibility and seven-axis product READY are separate (`ProviderReportPrerequisites.php:5`; `Sources/EffectiveCapability.php:11`; `database/sql/provider_read_prerequisites.sql:1`).

### Proof status

`docs/BB2_IMPLEMENTATION_EVIDENCE.md:1327` records historical complete synthetic regression and database queue/RLS/Redis proofs for the BB2 candidate. Earlier “unfinished” snapshots are superseded where later remediation says so, especially the support/privacy evidence-admission repair. `app/Providers/Ads/ProviderReportingEvidence.php:42` now admits attributable, exact-scope operator evidence; it does not invent approvals or certify business outcomes. Its physical store is encrypted installation storage, separate from observation tables (`:173`). No private records were read. Recorded historical proof is not fresh execution at this baseline and does not establish live provider acceptance.

### Required A–G hypothesis verification

| Hypothesis | Ruling from current code |
| --- | --- |
| A | CONFIRMED: `app/Providers/Ads/AdObservation.php:8` explicitly says campaign-period snapshot, never accepted business outcome. |
| B | CONFIRMED: `app/Providers/Ads/AdObservation.php:32` requires value/unit/meaning/basis and allows PROVIDER_REPORTED, MODELED, MIXED, UNKNOWN. This does not establish that actual adapters populate every basis. |
| C | CONFIRMED: `app/Providers/Ads/ObservationBatch.php:17` permits COMPLETE/PARTIAL/FAILED/UNKNOWN/PENDING and validates scoped observations. Completeness is this read only. |
| D | CONFIRMED WITH LIMITS: SQL generations, entries, lineage, independently derived hashes and immutable history exist. `app/Providers/Ads/ObservationJournal.php:89` is bounded entry history, not a complete-run/empty-run shared projection. |
| E | CONFIRMED: `app/Providers/Ads/Google/GoogleObservationAdapter.php:63`, `:157`, `:169`, `:177` map spend/status/impressions/clicks/conversions/all conversions/value; attribution is explicitly not business acceptance. |
| F | CONFIRMED: `app/Providers/Ads/Meta/MetaObservationAdapter.php:134`, `:211`, `:221` acquire spend/impressions/clicks. No purchases/leads/messages mapped. |
| G | CONFIRMED: provider transport/custody/observation and the exact-key declaration writer are separate; `database/sql/current_intent.sql:12`, `:154` and `app/Business/BusinessContext.php:74` do not infer Intent from provider state. |

## 4. CURRENT PROVIDER REALITY INPUT MATRIX

Each row is a current representation, not a proposed canonical metric. Shared column conventions:

- **S** = current `provider_observation_entries.observation` JSONB; associated run stores coverage/provenance/actor/time, stream stores generation/access/progress fence.
- **W** = app invokes `ObservationJournal::accept` within signed Workspace scope; SECURITY DEFINER `builderad_observation_accept` writes under current exact source/provider/custody checks. Adapter mapping supplies evidence; adapter is not thereby canonical semantic owner.
- **Scope M/G** = Workspace + source + provider + selected account + campaign + report window + operation/purpose + stream. No cross-provider campaign identity is implied.
- For provider-native meaning, **external source owns native representation** under governance §§7–9. **Builderad shared semantic owner = UNKNOWN / NOT PROVEN** unless the row explicitly names an existing established contract.
- Canonical-status A/B/C means governance classification, not implementation completeness. `C candidate` requires independent product review; it does not authorize a new truth.

| SOURCE | NATIVE FIELD / REPRESENTATION | REAL-WORLD SEMANTIC CLAIM | EVIDENCE ORIGIN / BASIS | TIME / WINDOW | SCOPE / IDENTITY | CURRENT STORAGE | CURRENT SEMANTIC OWNER | CURRENT WRITE / MUTATION AUTHORITY | CURRENT CANONICAL STATUS | BUSINESS-OUTCOME STATUS | TARGETING RELEVANCE | SPEND RELEVANCE | DECISION_USE_IF_ANY | MUST REMAIN SOURCE-SPECIFIC? |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Meta | `spend`; native major currency, scale 0 | Meta-reported campaign advertising spend, not profit or business value | Source-reported; null preserved; scale/documentation basis retained | Account-local inclusive DAY/TOTAL; read and recorded time separate | M | S | Meta native meaning; shared spend owner UNKNOWN / NOT PROVEN | W | A native report; common monetary interpretation exists; C shared spend candidate | Not an outcome | Bounds exposure behind a targeting test | Exposure accounting, conditionally comparable | Withhold scaling/aggregation when amount, coverage or comparison basis unknown | YES native field/basis; NO exact evidenced scale arithmetic |
| Google | `metrics.cost_micros`; scale 6 | Google-reported campaign cost in account currency | Source-reported; nullable signed int64 text; documented scale | Account-local inclusive DAY/TOTAL | G | S | Google native meaning; shared spend owner UNKNOWN / NOT PROVEN | W | A + existing exact amount arithmetic; C shared spend candidate | Not an outcome | Test exposure context | Native reported-cost accounting | Avoid rounding/10^6 error; compare only eligible same-currency compatible claims | YES native semantics; NO exact evidenced scale arithmetic |
| Meta | `impressions` | Meta Insights impression count | PROVIDER_REPORTED or UNKNOWN | Same campaign report period | M | S native facts | Meta; shared delivery owner UNKNOWN / NOT PROVEN | W | A; shared envelope does not establish unique reach | Not buyers, leads or business success | Delivery support only; no audience/market identification | Distinguish delivery evidence from cost alone | Avoid interpreting absent delivery evidence as zero or proof of demand | YES |
| Google | `metrics.impressions` | Google impression measurement | PROVIDER_REPORTED or UNKNOWN | Same report period | G | S native facts | Google; shared delivery owner UNKNOWN / NOT PROVEN | W | A | Not a business outcome or unique reach | Delivery support only | Delivery context for exposure | Hold a delivery-dependent conclusion if measurement unknown | YES |
| Meta | `clicks` | Meta Insights clicks under native definition | PROVIDER_REPORTED or UNKNOWN | Same report period | M | S native facts | Meta; shared response owner UNKNOWN / NOT PROVEN | W | A; not proven equivalent to Google clicks | Not qualified response | Weak response signal; not responders' identity/quality | No automatic value/waste judgment | Prevent click-to-lead or click-to-sale promotion; no demonstrated need to promote now | YES |
| Google | `metrics.clicks` | Google native click measurement | PROVIDER_REPORTED or UNKNOWN | Same report period | G | S native facts | Google; shared response owner UNKNOWN / NOT PROVEN | W | A | Not accepted outcome | No search-term/audience evidence | Native engagement only | No first-slice financial-value decision proven; retain native, defer promotion | YES |
| Google | `campaign.status` | Native ENABLED/PAUSED/REMOVED/UNKNOWN/UNSPECIFIED state; not readiness | PROVIDER_REPORTED if present; UNKNOWN if null | Returned at read; not an effective-time history of status during the report | G | S native facts | Google; shared status owner UNKNOWN / NOT PROVEN | W | A | Not outcome | Whether native object is currently actionable needs separate authority/currentness | Prevent treating a paused/removed object as currently deliverable | Only if selected intervention requires campaign actionability; defer promotion now | YES |
| Google | `metrics.conversions` | Provider-attributed conversions | PROVIDER_REPORTED or UNKNOWN; decimal/exponent token retained | Native report period; full attribution configuration not captured | G | S native facts | Google; shared outcome owner UNKNOWN / NOT PROVEN | W | A | Explicitly NOT accepted business outcome | Cannot rank qualified audiences | Cannot prove valuable-result efficiency | Block unsafe conversion-to-qualified-result inference; not mandatory first-slice metric | YES |
| Google | `metrics.all_conversions` | Separate native attribution aggregate | PROVIDER_REPORTED or UNKNOWN | Same; attribution equivalence not established | G | S native facts | Google; shared outcome owner UNKNOWN / NOT PROVEN | W | A | Not accepted outcome | No automatic sum with conversions | No cross-source addition | Preserve distinct claim; defer consumer promotion | YES |
| Google | `metrics.conversions_value` | Native attributed conversion value, not proven realized revenue/profit | PROVIDER_REPORTED or UNKNOWN; exact token | Same native period | G | S native facts; unit `provider_conversion_value` | Google; shared monetary business-value owner UNKNOWN / NOT PROVEN | W | A | No accepted economic-result status; no dedicated proven currency contract for this field | No qualification proof | No proven ROAS/profitability | Withhold realized-return conclusion; do not borrow spend currency/scale implicitly | YES |
| Meta + Google | Requested coverage COMPLETE/PARTIAL/FAILED/UNKNOWN/PENDING | State of this read, not all historical/account/market reality | Adapter result with read provenance; database validates admissibility | Run recorded time; scoped request window via stream/entries | Source/account/operation/purpose/stream/generation | Run plus S entries | Existing bounded `ObservationBatch`/SQL read-coverage contract | W | B existing bounded acquisition meaning; not canonical total population | Not outcome | Require more evidence rather than infer failed targeting | Avoid false zero/partial sums | Choose delay/reacquire over outcome/exposure conclusion | NO bounded read meaning; YES provider-specific failure detail |
| Meta + Google | `account_timezone`, currency, provider account and campaign IDs | Reporting context and source-native references | Verified native selection/metadata, not business declarations | Account context at verification/read; no automatic business-time mapping | Selected source/account; IDs provider-relative | Encrypted provider custody; scope/window/currency in S | Native context owners; existing `ProviderScope`/`ReportWindow` representation owns their bounded internal meaning | Access writer and W; no browser truth assertion | A native context + B scoped/time representation | No outcome or declared market | Prevent wrong-account/market inference | Prevent incorrect window/currency comparisons | Deny cross-account and ambiguous comparisons | YES native references/context; NO bounded identity/window structure |

Exact mapping evidence: `app/Providers/Ads/Meta/MetaObservationAdapter.php:117`, `:134`, `:199`; `Meta/MetaObservationReader.php:34`; `Google/GoogleObservationAdapter.php:63`, `:133`, `:157`, `:169`, `:177`; `NativeAmount.php:28`; `database/sql/provider_observation.sql:16`. Tests explicitly preserve omitted fields versus observed zero (`tests/Unit/MetaObservationAdapterTest.php:111`; `tests/Unit/GoogleObservationAdapterTest.php:135`, `:181`).

### Snapchat / TikTok / X: current state, without parity claims

| Source | Actual repository boundary | Classification and first-slice consequence |
| --- | --- | --- |
| Snapchat | Sync campaign TOTAL breakdown: native spend micro-currency scale 6, impressions and **swipes**, not relabelled clicks; scope/range checks (`Snapchat/SnapchatObservationAdapter.php:112`). Async reader accepts metadata-only PENDING or `snapchat_async_schema_unverified` FAILED, never CSV observations (`Snapchat/SnapchatObservationReader.php:93`). | PARTIAL. Do not describe async download infrastructure as completed observation mapping. Not a Meta/Google first-slice blocker. |
| TikTok | Campaign spend/impressions/clicks; native spend scale deliberately null pending accepted metric-unit evidence (`TikTok/TikTokObservationAdapter.php:106`). Delivery basis currently says PROVIDER_REPORTED even if nullable value; no explicit business acceptance. | PRESENT_BUT_UNVERIFIED for monetary interpretation/external contract. Null must remain unknown despite that basis label; do not manufacture a scale or canonical equivalence. Not a reason to rebuild common money/history. |
| X | Separate native `XCampaignReport` mapper/reader rather than pretending identical adapter shape. TOTAL, placement-specific campaign spend `billed_charge_local_micro`, scale 6, impressions/clicks, funding-instrument reference; current-offset/switch-aware native time binding (`X/XCampaignReport.php:53`, `:73`, `:170`; `X/XCampaignReportReader.php:20`). | PARTIAL as shared reality; native window/currency handling is implemented and tested. Placement/funding/native offset remain traceable. No required all-provider parity for SCR first slice. |

Checked-in provider tests and historical BB2 evidence support implementation presence; exact live provider semantics/activation remain unverified here.

## 5. CANONICALIZATION_BOUNDARY

| Meaning | Established semantic ownership | Physical storage | Write authority | Ruling |
| --- | --- | --- | --- | --- |
| Declared business facts / DTM | Existing Business Context fact owners | Workspace/business target-market tables | Prepared Business Context writer and exact manage grant | REUSE; provider geography must not overwrite declarations. |
| Desired change / current declaration | Current Intent contract | `workspaces.current_intent`, immutable `business_current_intent_events` | `builderad_current_intent_write`, existing Business Context serialization/revision | REUSE; no observation progress or inferred intent. |
| Valuable-result / acceptance meaning | Desired Outcome Meaning, distinct confirmed value in accepted specification | Same declaration/history bundle | Same controlled writer with independent outcome confirmation | REUSE; co-storage does not own observed qualification evidence. |
| Scoped provider evidence/history/coverage | Existing explicitly bounded scope, observation and acquisition contracts | Source/access/observation/attempt stores | Signed source/provider operations; controlled acceptance | REUSE existing meanings; do not reinterpret “COMPLETE” beyond a read. |
| Exact amount/scale representation | Existing `NativeAmount` arithmetic contract | JSONB serialized native/major values | Mapper then W | Safely reuse exact conversion where basis is established; normalized digits do not verify economic truth. |
| Shared reported advertising spend / evidence usability for domains | UNKNOWN / NOT PROVEN as a complete Builderad product contract | Native observations already stored | W already exists | Candidate C: bounded shared interpretation, not another spend store or automatic currency conversion. |
| Canonical clicks, attributed business return, qualified outcome | UNKNOWN / NOT PROVEN in current observation implementation | Native metrics or no business-evidence store | Native W or no business-observation writer | Do not promote provider metrics to these meanings. Qualification evidence, if required, is C independently of source shape. |

Governance A/B/C application: identifiers, statuses, conversion definitions, attribution configuration and native report semantics are **A**; established DTM/Intent/outcome declaration and bounded scope/coverage/amount mechanics are **B**. A provider observation may contribute toward B only where its claim actually matches that owner. Shared spend interpretation, eligibility of comparisons and observed outcome acceptance may be **C candidates** because decisions need them; Owner-approved semantic design is still required. A name such as `spend`, `canonical` or `ProviderEvidenceValidity` alone does not settle ownership of business meaning.

The safely reusable normalization now is exact, evidenced amount scale plus explicit scope/window/provenance structure. Meta spend and Google cost can be candidates for “reported advertising expenditure,” but their matching currency, period, population and accounting basis must be established before aggregation. Google conversions/all conversions/value, Meta/Google click definitions and X/Snapchat native distinctions stay source-native. No generic metric registry is justified.

## 6. EPISTEMIC / EVIDENCE BOUNDARY

The repository already distinguishes several dimensions rather than one universal evidence rank:

1. Declaration: advertiser-confirmed Intent and Desired Outcome Meaning; no provider/machine fallback.
2. Native fact basis: PROVIDER_REPORTED / MODELED / MIXED / UNKNOWN allowed by `AdObservation.php:36`; Meta/Google mappings currently populate source-reported or unknown, not new Builderad inference.
3. Read coverage: ObservationBatch's five states refer to acquisition.
4. Freshness: observation read/expiry, independent of coverage and capability axes. Actual Meta/Google expiry is unknown.
5. Verification/eligibility: provenance-bearing capability facts can be VERIFIED/REVOKED/PARTIAL/FAILED/UNSUPPORTED/VERIFICATION_NEEDED or UNKNOWN; expired becomes STALE (`ProviderEvidenceValidity.php:13`). Operator admission covers support/privacy, not outcome acceptance.
6. Exact derivation: `majorUnits()` derives representation by scale; it does not infer revenue, qualification, causality or opportunity.

Source-reported does not mean directly business-observed or universally true. VERIFIED capability means the relevant evidence axis passes its predicate, not that conversions are qualified outcomes. Canonical meaning does not make a measurement verified. Normalized representation does not make the source claim true. No implemented learned/discovered business evidence is established by these adapters; explanatory native strings are not an inference engine.

## 7. CONFLICTING_EVIDENCE_SEMANTICS

**Different claims:** Meta-attributed purchases 8, business-confirmed paid orders 5 and qualified WhatsApp prospects 3 can coexist. They can have different population, attribution, window, acceptance and units. No sum, maximum, automatic deduplication or “strongest number” is justified. These are illustrative claims: the current Meta adapter does not acquire purchases, and no implemented business-order/WhatsApp path was found.

**What current persistence actually supports:** Meta and Google evidence can coexist because source/provider/account/purpose/stream are isolated. Google conversions and all conversions are distinct native keys in the same snapshot. Same-slot revisions remain immutable generations, not simultaneous extra outcomes. Distinct source records referring to the same underlying account can preserve distinct evidence, but no economic deduplication/identity rule is established. A duplicate measurement slot within one batch is rejected rather than arbitrarily selected (`ObservationBatch.php:29`).

**Genuine contradiction:** incompatible values for the same semantic claim, same scope/window/units/methodology and relevant evidence time need explicit interpretation. The journal can retain conflicting/restated content across generations; it does not know whether disagreement is a correction, differing methodology or a business contradiction. Its RESTATEMENT means changed content in a slot, not adjudicated truth.

**Not supported yet:** coexistence of campaign-free business observations with provider aggregates is not proved by a campaign-mandatory DTO. The journal's general JSON storage does not prove that business evidence can lawfully enter its advertising contract. Do not insert business outcomes as fake campaigns/native metrics.

Provenance that must survive interpretation: source/provider/account, native claim/unit/basis, campaign reference where relevant, exact window/grain/timezone, acquisition/recording times, coverage and failure, generation/restatement lineage, and acceptance basis for any future business evidence. Shared Reality preserves meaning/evidence. Domain reasoning and later governed Decision Synthesis may perform bounded reconciliation; no synthesis is built here.

## 8. ABSENCE SEMANTICS

Start from actual behavior: nullable native amounts/facts remain null; real zero is string `"0"`. An empty COMPLETE report returns zero rows without inventing campaigns. PARTIAL means unresolved population; FAILED means failed acquisition; PENDING means no resolved result. Capability UNSUPPORTED is separate from missing or expired evidence. Intent absence is NOT_DECLARED, not zero desired/observed results. RLS may hide data; lack of visible rows cannot prove factual absence.

| Distinction | Material need now / concrete error prevented | Existing support and remaining boundary |
| --- | --- | --- |
| UNKNOWN | Required: do not treat missing spend, delivery, qualification, timezone or comparison basis as zero; do not scale from fabricated certainty. | Null/basis/freshness/axis UNKNOWN already exist. Carry them through the eventual shared consumer contract. |
| UNAVAILABLE | Required as an explanation of failed/pending/currently inaccessible acquisition, not a new stored enum: a retry/evidence request differs from a zero-performance conclusion. | Coverage/failure/authority/connection reasons already represent much of it. Preserve cause and affected scope; no universal “unavailable fact” state proposed. |
| UNSUPPORTED | Required when established: do not repeatedly fetch or promise a decision input an accepted source capability cannot supply. | Existing capability classification supports it. Absence of a field in the current mapping establishes “not acquired here,” not proof that the provider API globally cannot supply it. |
| NOT_APPLICABLE | No first-slice need for a new state established. Only if a later selected decision/input has a proved applicability rule would it prevent requesting irrelevant evidence. | Do not label no Intent, unknown results, non-countable outcomes or absent campaign links NOT_APPLICABLE. Optional quantity is already explicit; campaign linkage can be unknown/absent. DEFER additional distinction. |

COMPLETE + no rows does not equal zero advertiser spend or zero business results. A known field zero is only that source's zero claim under its report semantics. No silent backfill from older generations or weaker evidence preserving stronger claim strength.

## 9. OBSERVED-OBJECT IDENTITY

The minimum **existing** advertising references are Workspace/business association, registered source, provider-native account and campaign, operation/purpose, report window and accepted generation/stream. Account is required for currency/timezone/namespace; campaign is appropriate for existing campaign-period reports only.

For a first market-consistency Targeting investigation, add only the relevant country/market reference and the specific native configuration reference needed to know which campaign/ad-set/search surface targets it. DTM country identity exists; native targeting geography/exclusions/configuration do not appear in the inspected report mappings. Campaign ID alone cannot prove which market or audience received delivery. Do not invent ad-set/audience/keyword identity unless the selected intervention requires it.

For a first exposure/comparison Spend investigation, current account/campaign/window/currency plus relevant generation can suffice at that scope; no universal campaign or cross-provider entity is needed. Market/audience allocation requires additional demonstrated evidence, not repurposed campaign totals.

Business evidence must be allowed conceptually to exist without campaign identity; an unknown attribution link does not erase an order or prospect. Its source/business reference, acceptance context and appropriate time would matter only when a selected outcome-dependent decision requires it. No generic polymorphic subject or Outcome identity is proposed.

## 10. TARGETING NEEDS

Governance establishes a domain, not an accepted first concrete targeting intervention. Therefore the **following is a bounded decision-need hypothesis**, not an invented product decision or Targeting design: determine whether a specific current targeting configuration is consistent with the advertiser's declared market, or whether the evidence is insufficient to justify changing it. This can avoid a geographically misdirected recommendation without claiming qualified-audience performance.

| Decision need | Required reality/evidence input | Available source evidence | Minimum Builderad meaning / native remainder | WITHOUT → WITH Decision Delta |
| --- | --- | --- | --- | --- |
| Know intended business market/change | Existing DTM and relevant complete Intent/outcome value + revision; explicit absence | Landed Business Context/Intent | Reuse declared owners; neither provider country nor account timezone declares intent | Unsupported desired-market claim → compare against actual declaration or require declaration where decision depends on it |
| Know what is actually targeted | Current native targeting geography/exclusions plus scope/reference/effective observation context | **Not in Meta/Google campaign metrics maps**; DTM alone is not this evidence | Need bounded shared interpretation of configuration coverage relative to DTM; preserve native location IDs/mechanisms | Campaign totals cannot establish mismatch → established config can justify avoid/change investigation |
| Know evidence is fit for that comparison | Provenance, scope, timeliness, coverage and explicit unavailable/unknown reason | Existing evidence mechanics, no config-specific usable contract | One shared eligibility meaning, no arbitrary TTL; source limitations remain explicit | Guess from incomplete/out-of-date evidence → delay/require current evidence |

Outcome-based audience choice is a **different, richer decision**: it requires scoped responder/qualified-outcome evidence and interpretation against the accepted outcome value. Current clicks/conversions do not supply that. Search-term, audience/creative/market performance, marginal return and learning are excluded from this minimum until a selected decision demonstrates their need. Spend/delivery become required for Targeting only if the selected decision is an exposure-bounded test, not automatically for every market check.

## 11. SPEND NEEDS

Bounded hypothesis: decide whether currently available campaign-period reported expenditure is sufficiently comparable to support an exposure/allocation investigation, or whether increased exposure must wait for missing evidence. This is a useful protection/evidence gate, not a profitability conclusion or a policy permitting reduction/scaling.

| Decision need | Required reality/evidence input | Current availability | Minimum Builderad meaning / native remainder | WITHOUT → WITH Decision Delta |
| --- | --- | --- | --- | --- |
| Know reported exposure | Exact native spend + currency + evidenced scale + window + source/account/campaign | Meta/Google populated mapped entries | Shared reported-advertising-expenditure interpretation candidate; preserve native accounting claim | Mis-scaled/mixed money → correct scoped exposure or explicit inability to compare |
| Know comparison population/time | Requested scope, coverage/failure, grain/window/timezone, generation and correction relation | Entry/run mechanics exist; complete population/entryless-run projection/comparison rules not established | Shared comparison eligibility, not summing all history/DAY+TOTAL | Double-count or partial sum → withhold comparison or use a bounded justified snapshot |
| Distinguish delivery from valuable result | Minimal native delivery evidence if decision asks whether exposure produced delivery; relevant outcome declaration for outcome-dependent claims | Impressions/clicks available; business acceptance evidence absent | Delivery remains source-labelled; result acceptance stays Desired Outcome Meaning | Spend+clicks mislabeled as waste/profit → restrict conclusion and request appropriate evidence |
| Permit a specific cap/allocation/scale action | Actual declared resource constraint or bounded approved test/guardrail; valid outcome/economic evidence when that claim is made | Advertising cap/allocation/economics not established; provider request budgets are technical limits | Additional product truth only if action requires it; existing observation permission does not grant execution | Invented budget/success threshold → do not authorize action until justified constraints exist |

No claim that money normalization alone makes a materially useful allocation policy complete. No currency conversion, causal lift, marginal efficiency, realized ROAS or campaign-zero inference. Missing business outcomes block outcome-dependent scaling/efficiency decisions; they do not force a business-outcome integration before every exposure/evidence-protection decision.

## 12. FIRST_SLICE_REALITY_INPUT_SET

This is the minimum **candidate consumption set for the bounded needs in §§10–11**, derived backward from those needs. It contains declarations consumed from existing owners, bounded interpreted measurements and retained native evidence; not every member is a canonical business fact. Exact intervention, thresholds and acceptance of this candidate slice remain **UNDECIDED** because no accepted first-domain contract was found. Claiming one exact production-ready minimum for unspecified interventions would invent architecture. Conditional members become mandatory only if that decision is chosen; no field promotion merely for completeness.

| Member | REAL_WORLD_MEANING | EVIDENCE_ORIGIN | CURRENT_OR_PROPOSED_SEMANTIC_OWNER | SOURCE_NATIVE_MEANING_TO_PRESERVE | SCOPE / REFERENCE | TIME / WINDOW REQUIREMENT | CONFLICT / COEXISTENCE BEHAVIOR | CURRENT SOURCE AVAILABILITY | DECISION_USE_IF_ANY | WHY_REQUIRED_NOW | TARGETING / SPEND CONSUMER |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| R1 Declared context needed by decision | Intended market and desired change/valuable-result acceptance | Advertiser-confirmed declaration | Existing DTM, Current Intent, Desired Outcome Meaning owners | None; provider objective is not fallback | Workspace + coherent shared revision + actual value | Current declaration; confirmed horizon only if relevant | History retains old meaning; observed discrepancy never rewrites declaration | Implemented; optional absence explicit | Establish decision context or require missing declaration | Prevent recommendation against fabricated intent/market | Targeting; Spend where intent-dependent |
| R2 Scoped native references | What source/account/object a claim concerns | Registered identity + verified native evidence | Existing ProviderScope/source identity; native IDs external-owned | Native account/campaign IDs and resource/operation/purpose | Workspace/source/provider/account; campaign only advertising evidence; stream/generation | Observation/ref verification context | Different sources/streams coexist; no implied same economic object | Implemented for current advertising reports | Ensure right subject and no double attribution | Avoid cross-account/campaign or wrong-business action | Both |
| R3 Evidence usability envelope | What read resolved, what is unknown/unavailable and when acquired/recorded | Adapter/custody/journal evidence | Existing read/validity owners; proposed bounded shared consumption eligibility | Native limitations/failure reason/API/report/basis | Requested scope plus exact claim and generation | Native window where measurement; read/record times; freshness policy undecided | Preserve failed/partial/empty run and corrections; no silent latest-good fallback | Implemented mechanics; partial projection/eligibility | Delay/reacquire versus act/compare | Failure/absence is not zero; completeness is not truth | Both |
| R4 Reported expenditure | Source-reported advertising money expended in a defined period | Native reported amount; exact scale derivation | Native meaning external; proposed one Builderad reported-spend interpretation, current owner NOT PROVEN | Currency, native unit/scale/basis, signed adjustments/accounting scope | R2 advertising account/campaign | Exact comparable non-overlapping/report-compatible period/grain/timezone | Rereads/corrections are alternatives, not additive new spend; sources coexist | Meta/Google available; TikTok scale unverified | Exposure/comparison evidence gate; test exposure only if selected | Prevent mis-scaled/mixed/duplicated expenditure | Spend; Targeting only for bounded-test exposure |
| R5 Native delivery measurement, conditional | Provider reports delivery, not valuable business result | Provider-reported impressions or unknown | Native source; shared interpretation NOT PROVEN beyond evidence envelope | Native impression definition/count, not unique reach | Same advertising population as spend when compared | Same eligible report period; no assumed effective-event time | No cross-provider equivalence or missing-as-zero | Meta/Google implemented | Delivery-dependent exposure investigation | Required only when distinguishing measured delivery from cost-only knowledge changes decision | Spend conditional; Targeting test conditional |
| R6 Actual market targeting configuration, conditional | Which declared market current native configuration includes/excludes | Source-reported configuration; not inferred from spend | Proposed bounded shared interpretation of configured geography versus DTM; current owner NOT PROVEN | Native geography IDs, inclusion/exclusion mechanisms, object level | Only object/market refs required by selected check | Current/effective config context sufficient for comparison; no temporal framework | Different configurations/time snapshots retained; declaration remains independent | MISSING in inspected Meta/Google observation mappings | Investigate/avoid established market mismatch or ask for evidence | Mandatory for positive market-consistency check; cannot replace with campaign totals | Targeting |

**Explicitly excluded from unconditional first slice:** clicks as universal response, Google conversions/all conversions/value as canonical success, generic business outcome catalog, all provider fields, audience/search-term hierarchy, full campaign state model, Market ingestion, universal evidence/time/subject shapes, economic rankings and inferred/learned opportunity.

**Conditional outcome bridge:** if Owner selects qualified-outcome audience choice or preserve-value efficiency, directly business-observed acceptance evidence becomes necessary. At minimum the claim must retain business/source association, actual observed event/aggregate and appropriate time, qualification/acceptance basis, and the full relevant confirmed outcome meaning/revision. Campaign relationship may be unknown. No such bridge exists now; comparing a native field name to outcome prose is insufficient. This dependency is recorded, not inserted into R1 or implemented as an Outcome subsystem.

**Conditional action constraints:** if Owner selects a cap-protection/reallocation/test intervention, a real resource constraint/approved exposure bound becomes necessary. None is inferred from request budgets, commercial service scope or Intent quantity. Record that requirement in subsequent semantic design only when the decision demonstrates it.

## 13. DUPLICATION / ARCHITECTURE-DEBT RISKS

| Risk | Existing evidence | Consequence to avoid |
| --- | --- | --- |
| Domain code reads provider metric names directly | Native fact strings and Google-specific CSV interpretation | Separate Targeting/Spend success/coverage/spend definitions; shared interpretation must have one owner. |
| Rebuild the durable foundation | Journal already implements generations/replay/restatements/RLS/atomic progress | New evidence lake/history store duplicates proven machinery without decision value. |
| Campaign becomes universal business owner | `AdObservation` requires campaign ID | Business orders/prospects forced into fake campaigns; loss of campaign-free reality. |
| Conversion equals valuable outcome | Google explicitly says provider attribution; desired outcome remains declaration | False qualified-result counts, false value/ROAS and premature scaling. |
| History or overlapping windows summed | Each reread has entries; DAY/TOTAL and different streams coexist | Double counting, mixed populations or manufactured campaign zero. |
| Capabilities/connection/freshness conflated | Four-axis fetch versus seven-axis readiness; observation freshness separate | Fetch deadlock, false readiness or stale performance treated as current. |
| Denormalized major amount mistaken for separate truth | Native and major units both serialized; SQL hashes content | Treat representation derivation as independent measured money or trust unchecked JSON arithmetic. Consume established typed mapping/eligibility boundary; no new store implied. |
| Provider UI projections treated as shared contracts | Five status controllers overlap; Google CSV omits general shared eligibility | Domain consumption depends on UI/CSV/provider details rather than governed meaning. No competing canonical write store was found. |
| Old documents read as current implementation | BB2 log has historical pre-remediation snapshots; Intent spec describes pre-implementation baseline | Incorrectly call fixed support/privacy admission missing, or landed Intent unimplemented. Latest code and superseding proof control current-state classification. |

These are bounded semantic risks, not an invitation to refactor all five controllers or run a broad security audit.

## 14. REUSE / REPAIR / ADD DECISION

Each named existing component receives one category. No replacement architecture is recommended.

| Component / surface | Decision | Reason / boundary |
| --- | --- | --- |
| Business Context/DTM and landed Intent/outcome declaration/history | REUSE_AS_IS | Accepted declaration contracts; no outcome progress, market rewriting or Intent reopening. |
| Source identity/capability registration, ProviderScope | REUSE_AS_IS | Bounded identity/authority semantics already explicit; do not extend campaign identity universally. |
| NativeAmount | REUSE_WITH_SEMANTIC_BOUNDARY | Reuse exact scale arithmetic; no financial outcome/comparison/FX claim; reject unknown scale for comparisons. |
| ReportWindow | REUSE_WITH_SEMANTIC_BOUNDARY | Preserve native dates/instants/timezone/grain; comparison eligibility still needs bounded meaning. |
| AdObservation / ObservationBatch / ProviderReadResult | REUSE_WITH_SEMANTIC_BOUNDARY | Existing campaign-period/source evidence and read coverage; no campaign-free business outcome claim. |
| ObservationJournal + streams/runs/entries SQL | REUSE_WITH_SEMANTIC_BOUNDARY | Keep immutable evidence/restatements/authority. Bounded entry history is not complete-run/domain consumption; scope/empty-run eligibility needs design. |
| ProviderEvidenceValidity / EffectiveCapability / ProviderReportPrerequisites | REUSE_WITH_SEMANTIC_BOUNDARY | Existing axis/fetch/readiness meanings; not evidence truth, qualification or action permission. |
| Meta/Google native adapters/readers | REUSE_AS_SOURCE_NATIVE_ONLY | Actual mappings retained; shared meaning not inferred from metric names or adapter namespace. |
| Snapchat/TikTok/X native mappings | REUSE_AS_SOURCE_NATIVE_ONLY | Preserve swipes, unknown money scale, placement/funding/native window; no invented parity. |
| Provider access/request gates/operator evidence admission | REUSE_AS_IS | Existing exact authority/custody/support/privacy boundary; no new authority system. |
| NativeProgress, five native read-attempt tables/executions, provider queues/jobs | REUSE_AS_IS | Acquisition progress/replay/transport lifecycle; not business progress or learned truth. |
| Google CSV / report status controllers as shared domain contract | DO_NOT_REUSE_FOR_SHARED_REALITY | Keep existing presentation behavior; native export/status is not canonical domain reasoning input contract. |
| Campaign AdObservation as business-evidence container | DO_NOT_REUSE_FOR_SHARED_REALITY | Do not stuff campaign-free outcomes into a campaign snapshot. This is a use restriction, not removal of advertising observations. |
| New outcome acceptance/acquisition, detailed targeting evidence, resource constraints | DEFER | Record demonstrated conditional need; only separately selected/authorized consumer scope can justify additions. |

No component is ruled `REPAIR` as a **pre-semantic-design prerequisite**: no localized failure in the reusable foundation was established that prevents reasoning about the first contract. The history/empty-run/comparison gaps require determining the consumer contract before deciding whether bounded projection repair or addition is necessary. Existing provider-specific incompleteness does not automatically select phase C.

## 15. EARLIEST UNSATISFIED CORE DEPENDENCY

```text
EARLIEST_UNSATISFIED_CORE_DEPENDENCY = SHARED CANONICAL REALITY
SHARED_CANONICAL_REALITY_STATE = PARTIAL
```

Business/Decision Context, Current Intent and the accepted first Desired Outcome Meaning declaration are landed and remain closed. The next gate contract is only partly satisfied: substantial shared source-evidence mechanics exist, but fact-level shared interpretation, evidence usability/comparison/coexistence and minimum decision-specific consumption are not established sufficiently for the first real Decision Domains. This ruling follows governance item 4, not absence of a future-named table.

The earliest gap is **not** a new generic Outcome system, missing all-provider integration, or mandatory attribution engine. Missing accepted business observations become blockers for selected outcome-dependent decisions. Missing targeting configuration becomes material for the positive market/configuration comparison. Neither justifies reopening the declared outcome contract or rebuilding persistence.

## 16. RECOMMENDED NEXT PHASE

```text
RECOMMENDED_NEXT_PHASE = A
RECOMMENDED_NEXT_PHASE_LABEL = SHARED REALITY SEMANTIC DESIGN REQUIRED
IF_B_NEXT_GOVERNED_DEPENDENCY = NOT_APPLICABLE
```

Owner review should decide whether to authorize one bounded Shared Reality semantic design. Its unresolved product question is which materially useful first consumption boundary to prove, using the candidate needs above and reusing the native observation foundation. This report does not create that design or an implementation plan.

**Why not B:** common envelopes/storage and accepted declaration meanings do not establish fact-level consumption/comparison or a useful positive Targeting input. No accepted contract proves the first required slice semantically sufficient. **Why not C:** no mandatory infrastructure failure was demonstrated before the semantic question can be answered. **Why not D:** no material canonical-governance/current-repository contradiction requires an Owner ruling to unblock discovery.

If a later separately reviewed contract proves Shared Reality sufficient, the next numbered governed dependency is **FIRST REAL DECISION DOMAINS**. Governance expects Targeting and Spend in the first Core slice but does not establish simultaneous start or which domain's semantic design is first. Do not invent that priority or treat it as implementation authorization.

### Success-test answers

| Question | Evidence-backed answer |
| --- | --- |
| What did the advertiser declare? | Current complete nullable Intent/outcome value and coherent revision, plus declared DTM/context; history retains prior complete meaning. |
| What did an external source report? | Provider-native campaign-period spend/delivery and Google native status/attribution measurements, with provenance/scope/window/read history. |
| What does Builderad already own canonically? | Accepted declared fact/Intent/outcome meanings and explicitly bounded identity/read/representation mechanics; not all business meanings of fields inside the envelope. |
| What remains source-native? | Provider identifiers, report definitions, click/conversion/attribution/status/accounting/time quirks and unsupported/unverified provider-specific meanings. |
| What is genuinely observed about business reality? | No implemented direct business-outcome/qualification evidence path established. Provider observations are real evidence of what providers report, not accepted business results. |
| What is inferred? | Exact scale conversion is representation derivation. No implemented business-qualification/causal/learning inference is established by these maps. |
| What remains unknown? | Full native attribution configuration, business outcomes/qualification, granular targeting evidence, shared comparison/freshness policy, advertising resource/economic constraints and exact first interventions. |
| Can two valid non-equivalent observations coexist? | Existing native source claims and generations can coexist; the campaign-free business/provider coexistence contract is not yet proved. No automatic winner/sum. |
| What exact minimum do first consumers require? | §§10–12 give bounded, decision-derived candidate requirements and unavailable inputs. A definitive authorized minimum cannot be claimed before the concrete first interventions are selected. |
| What should be reused? | Source/authority/custody, native acquisition, exact amount/window representation, immutable journal and queue/attempt fences; preserve their current meaning. |
| What dependency follows sufficient Shared Reality? | FIRST REAL DECISION DOMAINS; ordering of first Targeting/Spend work requires separate scope decision. |

### Required final handoff

```text
BASELINE_SHA = 29bd35bbc475731d555dfeea20cde363b09a87b7
BRANCH = codex/shared-canonical-reality-discovery-001
WORKTREE = C:\dev\builderad-worktrees\shared-canonical-reality-discovery-001
WORKTREE_IDENTITY_VERIFIED = YES
GOVERNANCE_READ_FROM_CANONICAL_FILE = YES
ACCEPTED_CURRENT_INTENT_ARTIFACTS_PRESENT = YES
CURRENT_STATE_REVIEW_COMPLETE = YES
EARLIEST_UNSATISFIED_CORE_DEPENDENCY = SHARED CANONICAL REALITY
SHARED_CANONICAL_REALITY_STATE = PARTIAL
EXISTING_PROVIDER_OBSERVATION_INFRASTRUCTURE = SUBSTANTIAL_DURABLE_SCOPED_SOURCE_EVIDENCE; LIVE_ACTIVATION_NOT_VERIFIED_HERE
CANONICALIZATION_BOUNDARY = REUSE_DECLARED_OWNERS_AND_BOUNDED_EVIDENCE_MECHANICS; NO_NATIVE_METRIC_TO_BUSINESS_SUCCESS_PROMOTION
SEMANTIC_OWNERSHIP_BOUNDARY = DECLARED_OWNERS_ESTABLISHED; SHARED_SPEND_DELIVERY_OUTCOME_EVIDENCE_INTERPRETATION_NOT_PROVEN
PHYSICAL_STORAGE_BOUNDARY = WORKSPACE_DECLARATION_HISTORY; SOURCE_ACCESS_OBSERVATION_ATTEMPT_STORES; PRIVATE_OPERATOR_PROOF_CUSTODY
WRITE_MUTATION_AUTHORITY_BOUNDARY = SIGNED_EXACT_SOURCE_PROVIDER_GRANTS_AND_CONTROLLED_ACCEPTANCE; NO_EXTERNAL_CAMPAIGN_MUTATION
CONFLICTING_EVIDENCE_SEMANTICS = PRESERVE_SOURCE_CLAIMS_AND_RESTATEMENTS; NO_AUTOMATIC_RECONCILIATION; BUSINESS_COEXISTENCE_NOT_PROVEN
FIRST_TARGETING_NEEDS = DECLARED_CONTEXT; SCOPED_CURRENT_TARGETING_CONFIGURATION_FOR_MARKET_CHECK; EVIDENCE_USABILITY; EXACT_INTERVENTION_UNDECIDED
FIRST_SPEND_NEEDS = SCOPED_EXACT_REPORTED_EXPENDITURE; WINDOW_POPULATION_COVERAGE_CORRECTIONS; USABILITY; DELIVERY_OR_OUTCOME_CONSTRAINTS_ONLY_WHEN_DECISION_REQUIRES
FIRST_SLICE_REALITY_INPUT_SET = R1_R2_R3; R4_FOR_SPEND; R5_CONDITIONAL_DELIVERY; R6_CONDITIONAL_TARGETING_CONFIG; SEE_SECTION_12
REUSE_DECISIONS = REUSE_EXISTING_SOURCE_AUTHORITY_ACQUISITION_HISTORY; BOUND_AMOUNT_WINDOW_COVERAGE_SEMANTICS; KEEP_NATIVE_METRICS_NATIVE
SEMANTIC_COLLISIONS = CONVERSION_VS_BUSINESS_OUTCOME; READ_COVERAGE_VS_POPULATION; CONNECTION_VS_READINESS; CAMPAIGN_VS_BUSINESS_IDENTITY; NORMALIZATION_VS_VERIFICATION
MATERIAL_GAPS = SHARED_CONSUMPTION_COMPARISON_OWNERSHIP; CURRENT_TARGETING_CONFIGURATION; CONDITIONAL_BUSINESS_ACCEPTANCE_AND_RESOURCE_CONSTRAINTS
RECOMMENDED_NEXT_PHASE = A
IF_B_NEXT_GOVERNED_DEPENDENCY = NOT_APPLICABLE
DISCOVERY_DOCUMENT = docs/shared-canonical-reality-discovery-001.md
PRODUCTION_FILES_CHANGED = NONE
TEST_FILES_CHANGED = NONE
SQL_MIGRATIONS_CHANGED = NONE
GOVERNANCE_FILE_CHANGED = NO
PRIMARY_DATABASE_TOUCHED = NO
COMMIT_CREATED = NO
PUSH_PERFORMED = NO
MERGE_PERFORMED = NO
```

**Verification scope:** fresh source-control identity/cleanliness checks, required artifact presence, one-document change scope, reference-path/line validity, required section/column/handoff completeness and whitespace checks. No full test suite required or run. Task-owned long-running processes started: zero.

**STOP for Owner review.** This discovery authorizes no next artifact, production change, integration, database connection or external action.
