# BUILDERAD — SCR-01 Bounded Semantic Design 001

Status: semantic design complete; proposed contract awaiting Owner review. No implementation authorized or implied.

## 1. Authority and accepted baseline

Authority: [canonical governance](../../BUILDERAD_CORE_EXECUTION_GOVERNANCE.md), then Owner-accepted landed contracts, then [accepted SCR-01 discovery](../../shared-canonical-reality-discovery-001.md), then implementation evidence. This document proposes bounded rulings under those authorities; it does not amend them. No concrete contradiction was found in the boundaries reviewed.

Accepted Current Intent authority: [2026-10-04 contract](2026-10-04-current-goal-intent-design.md), especially §§3–6: advertiser-confirmed intent, separate Desired Outcome Meaning, business-wide declaration scope, full historical meaning, and no attachment of provider observations to the shared Business Context revision.

Baseline: `29bd35bbc475731d555dfeea20cde363b09a87b7`; branch: `codex/shared-canonical-reality-discovery-001`; worktree: `C:\dev\builderad-worktrees\shared-canonical-reality-discovery-001`. Preflight status contained only the staged, accepted discovery addition. It remains staged and unchanged.

Frozen SHA-256 values, recorded before design writing:

| Protected artifact | SHA-256 |
| --- | --- |
| SCR-01 discovery | `A192A941454DB9AAE66E29B018483492E5DE325039BF0C766C82D5BE80AD7704` |
| Canonical governance | `74094391E9D3553156D20A3E48AF5028EC1DEAB0D0701A6A4CC2FABC834DA5DB` |
| Current Intent discovery | `CBE50B60606362CA0861B17458CD87FCC6E28A76E90D404EFD7BCAE330F69FD5` |
| Current Intent design | `3A881365BAA0EC34B2336B5055008BF0597F7AC4F799E14995550E17E5857200` |
| Current Intent plan | `CCBFFBC814013D0B93B7BD9A5383542FCF2004341211416B3B134EE2CA357992` |

These are preservation checks, not evidence-quality scores. Only this new document is authorized for this task.

## 2. Discovery rulings carried forward

Shared Canonical Reality is **PARTIAL**. Scoped source identity, authority, acquisition, durable observation history, generations/restatements and evidence mechanics already exist and are reuse candidates. Their existence does not complete shared consumption semantics.

Provider conversions are not accepted business outcomes. The missing observed-business-outcome path is conditional on an outcome-dependent decision. Actual targeting configuration is absent from the inspected Meta/Google mappings. Spend interpretation and comparability remain incomplete. No mandatory infrastructure repair was proved as a prerequisite to this design, and no second observation platform is justified.

Discovery's `COMPLETE` applies to bounded existing contracts. It does not certify universal identity, provider activation, all native objects, complete historical coverage or Core readiness. This design does not select the first Targeting or Spend intervention. Market/configuration consistency and exposure/comparison investigation remain examples, not approved features.

## 3. Shared Reality bounded definition

**SR1 — This first bounded advertising-evidence slice of Shared Canonical Reality defines Builderad's interpretation of what an identified advertising source reported for an identified scope and reporting period, together with shared usability restrictions on that evidence.** It preserves native meaning and distinguishes an observed value, acquisition coverage, knowledge generation and a supported quantitative operation. This is the scope of the selected first slice, not a global definition of Shared Canonical Reality.

This first slice neither excludes nor defines future governed Shared Reality classes such as business-observed or market evidence. Their semantics remain separately governed and outside this design.

The first slice answers three questions: what was reported; what can be asserted about its scope, currency, period and current knowledge; and whether a proposed comparison or addition has enough semantic support. It does not answer whether a business objective was achieved or what to do next.

Three alternatives were assessed. Native pass-through leaves consumers to invent incompatible currency, currentness and coverage meanings. A universal evidence model adds identities and lifecycle rules without a demonstrated decision need. The selected alternative is a small interpretation contract above existing observations: shared usability plus qualified source-reported advertising expenditure, with existing context and scoped identities referenced unchanged.

“Canonical” means stable Builderad interpretation and honest restrictions. It does not make a native number objectively verified, financially settled or equivalent to another provider's number. Rules below are semantic obligations, not a new DTO, entity or persisted status model.

## 4. Semantic ownership boundaries

**SR2 — Semantic ownership follows the meaning being asserted, independently of where evidence is stored or which actor may mutate it.**

| Element | Semantic owner | Physical storage | Mutation authority | Source-native owner / boundary |
| --- | --- | --- | --- | --- |
| Current Intent and declaration currentness | Existing Current Intent contract | Existing placement unchanged | Existing authorized declaration path | No provider can declare advertiser intent |
| Desired Outcome Meaning and acceptance | Existing separate Desired Outcome Meaning owner | Existing confirmed value and full history unchanged | Advertiser confirmation under existing authority | Native conversion/event labels cannot change acceptance |
| Name, Business Type, DTM | Existing Business Context fact owners | Existing placement unchanged | Existing fact-specific authority | Native timezone/geography is not declared context |
| Workspace/source/access and bounded object references | Existing Foundation/source identity contracts | Existing placement unchanged | Existing scoped identity/access owners | Provider owns native account/object identity; no cross-provider equivalence inferred |
| Native amount, native facts and report definition | Source/adapter contract faithfully representing provider claims | Existing observation storage unchanged | Existing scoped acquisition/acceptance boundary | Provider owns accounting, attribution and delivery definitions; adapter cannot invent them |
| Acquisition coverage, provenance, generation and restatement lineage | Existing observation contract | Existing durable history unchanged | Existing signed, scoped acceptance authority | Provider report provenance remains attached to its claim |
| Safe interpretation, withholding and permitted monetary operations | BUILDERAD SHARED REALITY SEMANTIC CONTRACT | No new placement selected | No new writer or permission; existing evidence cannot be rewritten by a consumer | Underlying values, limitations and accounting basis remain native |
| Decision sufficiency, risk, thresholds, ranking and action choice | Relevant future Decision Domain | Outside this design | Relevant governed domain/action authority, when designed | Shared semantics supplies constraints, not a decision |

An `Ads` namespace, shared table or Business Context consistency token does not establish semantic ownership. Shared interpretation cannot grant source access, execution authority, or a right to edit native observations. Current authorization remains necessary to consume protected history; a historical grant is not current authority.

## 5. Existing contracts reused unchanged

Reuse scoped source/account/operation/purpose identity; current access enforcement; immutable accepted history and restatement lineage; read-level provenance; exact native monetary strings, currency and evidenced decimal scale; native report windows, grain and timezone; acquisition coverage; fact-relative capability/validity mechanics; and the declared context contracts.

Only focused code checks were needed to confirm three consequential boundaries already identified by discovery:

- [`NativeAmount`](../../../app/Providers/Ads/NativeAmount.php) preserves exact signed values and scale evidence; normalization does not establish accounting equivalence.
- [`ObservationBatch`](../../../app/Providers/Ads/ObservationBatch.php) defines completeness for the requested read, not the historical universe.
- [`ObservationJournal`](../../../app/Providers/Ads/ObservationJournal.php) keeps bounded entry history under current access. Its entry/run join cannot reveal empty runs, and its row limit does not prove complete latest-run membership.

These references describe existing evidence, not implementation destinations. A stream identifier or opaque acquisition progress is not, by itself, readable proof of a requested reporting population/window. No existing history interface is relabeled as a complete current-knowledge projection.

## 6. Shared consumption/usability semantics

**SR3 — Shared invariants constrain the claim; domain policy decides whether that qualified claim is sufficient for a particular decision.**

| Operation | Shared requirement | Consequence if unmet |
| --- | --- | --- |
| Consider recorded evidence | Authorized access; identifiable source/scope/period; preserve native meaning, provenance, known limitations and acquisition context | Withhold the unsupported interpretation; retain available evidence with its actual limitations |
| State a numeric monetary amount | Known native value, known evidenced scale and currency, identifiable advertising scope/window | Raw native value may remain available; no invented major-unit amount or currency |
| Present as latest supported read for a request | Identifiable request/stream semantics, latest acquisition knowledge and relevant membership, not merely the newest returned entry | State historical/limited knowledge; withhold latest/full-request assertion |
| Compare | SR5 proof for the intended comparison | Individually qualified values may coexist; withhold semantic equivalence or numeric comparative conclusion |
| Aggregate | SR6 proof for the intended total/subtotal | Withhold sum as an asserted expenditure claim |

A PARTIAL read can support “this source reported this value for this returned slot in a partial read.” It cannot support a complete account/population total. A historical COMPLETE read can support an explicitly historical statement. Unknown freshness does not erase that statement and does not prove suitability now. Existing FRESH/STALE/UNKNOWN semantics remain separate from read coverage, source capability and business-result acceptance.

No universal age limit, required evidence count, confidence threshold, minimum spend, ranking, fallback-source preference or intervention policy belongs here. A future domain may impose stricter sufficiency rules, but cannot relax a semantic invariant or call unknown a zero. Native facts may be considered under their native definitions without becoming shared business metrics.

## 7. Current-knowledge/restatement semantics

**SR4 — Knowledge is qualified by source, requested scope/window, report definition and acquisition lineage. Latest knowledge is not a global latest timestamp or a silently assembled set of last-good rows.**

| Later acquisition | Semantic interpretation |
| --- | --- |
| Identical reread of the same slot | Repeated reporting/recording, possibly newer acquisition knowledge; not an additive event, new outcome or correction |
| Restatement of the same slot | Later source-reported version in that lineage; replaces the earlier version for that slot's latest reported-value interpretation; never sum the versions |
| COMPLETE for an identified request | Latest completed requested read, subject to native coverage and known validity; not proof of all objects or all time |
| PARTIAL after COMPLETE | Latest acquisition is partial. Returned slots can be considered with that qualifier; the old complete snapshot remains historical. Missing slots cannot be silently filled from it to create a current complete snapshot |
| FAILED or PENDING after COMPLETE | Latest acquisition failed or is pending. Earlier completed evidence remains explicitly last-known/historical; it is not refreshed/current truth |
| UNKNOWN acquisition coverage | Known entries retain their qualified native claims; no completeness assertion |
| COMPLETE with no rows | Source returned no observation rows for that identified requested read. No invented zero rows or negative business facts |

A changed content record may change amount, unit, meaning or provenance. A restatement is not proof of changed real-world behavior, causal impact or an accounting correction; reassess operation compatibility using its actual semantics. An earlier value remains historical evidence.

When a later COMPLETE read omits a previously returned slot, its earlier value is not supported as a member of the latest requested result. Omission does not establish zero, deletion or nonoccurrence. When PARTIAL contains a restated slot, that slot's latest reported version can be named explicitly, while the full-request state remains partial. Do not conflate slot recency with snapshot completeness.

Knowledge is bounded by the accessible projection. If it cannot expose newer empty/failed/pending runs, request meaning or untruncated membership, the consumer cannot assert latest complete knowledge from it. Native report period, source observation time and Builderad recording time answer different questions. A later generation does not revise the advertiser's intent or Desired Outcome Meaning.

## 8. Comparability versus aggregation

**SR5 — Comparison is permitted only for an explicitly identified comparison meaning with demonstrated compatible monetary claim semantics.** The first contract covers reported advertising expenditure only; it does not establish generic metric comparability.

| Dimension | Required evidence / exactness | Safe transformation or restriction |
| --- | --- | --- |
| Claim and accounting basis | Same source-defined expenditure meaning and known compatible accounting/report methodology; native adjustments/limitations retained | Equal field names, provider labels or `PROVIDER_REPORTED` are insufficient |
| Currency and scale | Exact same known currency; each scale evidenced | Exact decimal scale conversion only; no rounding as evidence, FX, inferred micros or currency substitution |
| Population and identity | Same scoped account/object population definition for a like-for-like comparison | Campaign names or two provider accounts do not establish shared population |
| Period and grain | Same period for same-period comparison; explicitly different periods for a period comparison, with comparable scope, grain and methodology | No implicit per-day rates, prorating or DAY/TOTAL equivalence |
| Boundary and timezone | Native inclusive-date/explicit-instant meaning preserved; equivalent bounds must be demonstrated | Explicit instants may have equivalent offset notation. Native date windows cannot be reassigned to Intent/DTM timezone |
| Coverage and knowledge | Comparable identified read context and membership; no silent partial/full substitution | A revision comparison can explicitly compare same-slot versions; it does not compare two complete populations |
| Overlap and lineage | Distinguish same-slot versions, overlapping windows and separate claims | A supported revision difference is descriptive; neither version becomes an additional observation to count |

Supported forms are a qualified same-slot revision comparison and a same-source, like-for-like reported-expenditure comparison across identified reporting periods when the conditions above are demonstrated. Different periods need not have equal amounts or durations; any claim of trend, rate, efficiency or causal explanation needs its own domain meaning and evidence. Merely displaying two labelled native numbers is not an assertion that they are comparable.

Unknown compatibility makes the requested comparative conclusion **UNDETERMINED**; known incompatible semantics makes it **UNSUPPORTED**; missing prerequisites means that conclusion is **WITHHELD**. These words explain a specific operation, not a new stored enum or evidence hierarchy. Numerical normalization proves only representation equivalence.

**SR6 — Addition needs separate proof of additivity and nonduplication. Comparison permission never authorizes aggregation.**

The first slice permits only same-source, same-account addition of expenditure components whose native report meaning establishes additivity, with identical known currency, exact scales, compatible accounting basis, and proven disjoint reporting populations or nonoverlapping periods. The stated population/period must be exactly the supported union of the components. A complete total requires demonstrated complete coverage of that union; complete acquisition of some rows alone does not prove this.

An explicitly named subset subtotal is possible only when its component membership, additivity and disjointness are proved; it cannot be called the whole-account total. Signed native amounts remain signed, so a partial subtotal is not necessarily a lower bound. If even subset disjointness is unknown, withhold addition.

Never add rereads/restatements; account totals plus their campaign children; DAY rows plus overlapping TOTAL rows; or overlapping periods. Do not infer disjointness from distinct names or API rows. **Cross-provider addition and cross-provider equivalent-spend numeric comparisons are outside this first contract**, even with the same displayed currency. Individually qualified provider expenditure claims may coexist. Future expansion requires an explicit semantic ruling, not a consumer assertion that money is fungible.

## 9. Reported advertising expenditure ruling

**SR7 — ACCEPT, bounded to “source-reported advertising expenditure for a specific advertising scope and report period.”** This is a descriptive monetary claim, not a harmonized accounting total.

The meaning preserves the exact value, evidenced unit/scale, currency, native scope and report period, report/API/documentation provenance, relevant native accounting limitations, acquisition coverage and restatement lineage. Known scale permits exact major-unit expression. Unknown scale/currency permits retention of the native token, not a fully specified monetary expenditure claim. Null is unknown; evidenced zero remains zero. Negative native amounts are preserved, not clamped or reclassified as invalid solely by sign.

This meaning is needed now for safe consumption of already-existing advertising monetary evidence: it changes whether a consumer can call a value an amount, a current scoped report, or a sum. Native strings alone do not prevent doubling a restatement, promoting a partial read to a total or equating different native cost definitions. Shared interpretation stabilizes those boundaries without selecting a Spend feature.

The source continues to own accounting basis, inclusions/exclusions, adjustments, report cutoff and native restrictions. This contract does not certify cash paid/settled, invoices, all advertising accounts, total business cost, available budget, affordability, revenue, profit, value, ROI, ROAS or efficiency. It creates no resource constraint. SR5/SR6 govern comparison/addition; SR4 governs corrections/current knowledge. Unknown native accounting comparability is preserved even when individual amounts are usable descriptively.

## 10. Delivery-evidence ruling

**SR8 — CONDITIONAL_LATER for any additional shared numerical delivery meaning.** Existing impressions, clicks, swipes and similar facts remain qualified source-native evidence. No demonstrated consumer delta requires a canonical delivery measure before a first domain intervention is selected.

Shared usability already distinguishes observed native evidence from failed, partial or unknown acquisition. This does not establish equivalent impressions/clicks across providers, a universal “exposure” quantity, attention, qualified response, lead, buyer or sale. Activation requires a selected domain decision whose interpretation materially depends on a precisely defined delivery claim. That future ruling must establish native meaning, scope/window, coverage and operation compatibility; names alone are insufficient.

## 11. Targeting-configuration ruling

**SR9 — CONDITIONAL_LATER.** DTM is advertiser-declared context; actual/effective configured targeting is a source observation. The former neither proves nor substitutes for the latter.

No shared configuration abstraction is needed for qualified expenditure consumption. A selected Targeting intervention that compares configured targeting with declared markets would activate a dependency on relevant effective inclusion/exclusion meaning, native object reference, scope and sufficiently current evidence. The intervention determines which configuration matters. No API field, reader, ingestion path, audience/keyword model, or universal targeting object is designed here. Unavailable configuration prevents that comparison, not the known DTM declaration or all unrelated evidence consideration.

## 12. Business-outcome evidence ruling

**SR10 — CONDITIONAL_LATER; no observed-business-outcome abstraction enters this first slice.** An outcome-dependent decision activates the missing evidence dependency; source-reported expenditure alone does not.

Desired Outcome Meaning defines what counts as valuable. Observed Business Outcome would require evidence that a result satisfying that confirmed result/acceptance meaning occurred. Provider attribution or an event name is insufficient. A future consumer must preserve the full meaning and acceptance it evaluated, tied to the consumed Workspace/shared revision with retrievable historical meaning; a label or mutable current pointer is insufficient. Changing acceptance cannot silently relabel old events as accepted results.

Evidence may be assessed against an explicitly chosen historical or current meaning, but must say which was used and demonstrate the acceptance match. The accepted contract does not attach provider observations to Business Context revisions. Campaign attribution is optional conceptually: a business result can occur without a known advertising campaign. No outcome entity/catalog, commerce/CRM/WhatsApp integration or attribution engine is selected.

## 13. Conflict/coexistence semantics

**SR11 — Preserve distinct claims and unresolved contradictions; do not reconcile them automatically.** Provider-attributed conversions = 8, confirmed paid orders = 5 and qualified prospects = 3 can all be valid because their meaning, population and methodology differ. Counting them together or declaring one erroneous is unsupported.

A genuine conflict requires evidence that both claims intend the same semantic quantity/result, same scope and population, same reporting period/boundaries, same unit/currency where relevant, and materially equivalent accounting/counting/acceptance methodology. Their values must then be incompatible after valid exact normalization, and neither must merely supersede the other in an established restatement lineage. Unknown sameness does not establish conflict.

For conflicting claims, retain each value and its provenance, scope, knowledge and limitations. No latest-timestamp, provider, storage location or authority-role ranking selects a winner. “Two supported claims disagree” may be stated; a stronger uncontested single value is withheld. Conflict is not automatically an ingestion error. Future Decision Synthesis may govern reconciliation; none is designed here.

## 14. Absence/unknown semantics

**SR12 — Reuse existing absence mechanics and state the missing fact or unsupported operation specifically. No universal absence-state enum is required.**

| Known condition | Honest interpretation | Forbidden inference |
| --- | --- | --- |
| Value null, unknown scale or currency | This component is unknown; numeric/monetary interpretation may be unavailable | Zero or inferred currency/scale |
| Failed read | Acquisition did not establish a new value | Zero fact or failed business outcome |
| Partial read | Returned evidence with incomplete requested acquisition | Complete population |
| Unsupported capability | Source/path does not support this requested evidence operation | Business result did not occur |
| No observation rows | No rows returned for the identified request, subject to coverage | No spend, no campaign, no result |
| Stale or unknown validity | Existing fact-relative validity qualification | Freshness, false fact, or a universal decision prohibition |
| Intent not declared | No current confirmed intent for an intent-dependent decision | Zero desired results or inferred provider intent |

“Unavailable” describes missing usable support for a specific claim, which may result from absent evidence, access, failure or unknown semantics; retain the actual reason. “Unsupported” has a distinct meaning already represented by source/capability mechanics. No additional `NOT_APPLICABLE` distinction has a demonstrated first-slice correctness need, so it is not introduced. Unknown is neither a stored zero nor a domain-default decision.

## 15. Identity boundary

**SR13 — Reuse existing bounded identity; do not generalize it.** Advertising expenditure needs the existing Workspace/source/provider/account/operation/purpose scope, native campaign/object reference where present, native report window and report/stream lineage. These establish the reported slot, not universal equivalence or an advertiser's whole business population.

A same-account operation still requires proof of reporting population; multiple sources pointing to an account are not automatically additive or interchangeable. Two providers' objects need not have any shared identity. Audience/search/configuration evidence may later use different native levels. Business outcomes may have no campaign link. No UniversalCampaign, UniversalAdGroup, polymorphic subject hierarchy or new persistent identity is justified.

## 16. Final first-slice contract

**SR14 — Select only shared usability/current-knowledge restrictions and bounded source-reported advertising expenditure interpretation.** Reference existing declared context and scoped identities without acquiring their ownership. Leave delivery harmonization, configuration and observed business outcomes conditional.

| NOW element | Owner and consumer delta | Why native representation alone is insufficient | Stabilized meaning / retained native boundary | Unknown and coexistence behavior |
| --- | --- | --- | --- | --- |
| Shared consideration/current-knowledge/operation restrictions (R3) | BUILDERAD SHARED REALITY SEMANTIC CONTRACT; prevents unsupported current/full/comparable claims in any evidence consumer | Entry history, coverage labels and native values do not by themselves explain last-good history, empty runs or permissible operations | Qualified claim use and withholding; existing acquisition/identity/validity owners unchanged | Missing support withholds only the stronger claim; historical/native statements remain qualified; conflicts preserved |
| Reported advertising expenditure (R4) | BUILDERAD SHARED REALITY SEMANTIC CONTRACT; distinguishes a specified amount from a native token and a supported subtotal from duplicate/overlapping values | Native values do not stabilize shared monetary interpretation or comparable/additive semantics | Exact scoped source-reported amount with explicit provenance/window; accounting meaning remains native | Null/unknown scale never zero; restatements replace within lineage; independent claims coexist; cross-provider operations withheld |

These needs arise from existing evidence consumption, independently of a future intervention. Neither element selects an action or mandates new ingestion. The selected slice can safely describe what is known even when a particular domain cannot yet decide anything. Document completion is not implemented readiness, Owner acceptance or Core-gate completion.

## 17. R1–R6 disposition matrix

Each discovery candidate receives exactly one disposition. Required references are not new shared ownership.

For R4, `FOUNDATIONAL_REQUIRED_NOW` means required for **this selected first Shared Canonical Reality advertising-evidence slice**. It does not make reported advertising expenditure required for every future Shared Reality consumer, every advertiser, pre-connection Market Evidence, or Targeting decisions that do not consume expenditure. It is neither a universal prerequisite to Builderad value nor part of every future Shared Reality slice. R3/R4 remain the selected first slice; this clarification changes no disposition.

| Candidate | Disposition | Ruling and reason |
| --- | --- | --- |
| R1 — declared context | REUSE_EXISTING_OWNER_ONLY | Existing Current Intent, Desired Outcome Meaning and Business Context owners suffice; reference only when the consumer's claim needs them. No duplicated shared context/history |
| R2 — scoped source/object references | REUSE_EXISTING_OWNER_ONLY | Existing bounded identity is necessary and sufficient for this slice; no new identity owner or universal mapping |
| R3 — evidence usability envelope | FOUNDATIONAL_REQUIRED_NOW | Adopt semantic restrictions only, not a new envelope entity/schema; resolves currentness, coverage and operation misuse independently of a first intervention |
| R4 — reported spend | FOUNDATIONAL_REQUIRED_NOW | Accept SR7's bounded descriptive expenditure meaning over existing evidence, with SR5/SR6 limits; not universal cost/resource context |
| R5 — delivery facts | CONDITIONAL_LATER | Preserve native evidence now; selected decision must prove the need for a shared quantitative delivery meaning |
| R6 — actual targeting configuration | CONDITIONAL_LATER | A selected configuration-dependent Targeting decision must establish the needed evidence; DTM alone cannot activate or satisfy it |

No `DECISION_SPECIFIC_REQUIRED_NOW` membership is justified before selecting an intervention. The smaller alternative to accepting all six is precisely R3/R4 semantics with R1/R2 references; candidate listing is not acceptance evidence.

## 18. Acceptance examples

These are conceptual contract checks, not implementation tests or new integration commitments. Each claim retains its actual provenance and authorization boundary.

### A. Meta spend and Google cost, same displayed currency

**Knows:** Each source reported its scoped monetary value; accounting comparability is unknown. **May say:** “Meta reported X USD; Google reported Y USD for the stated periods and scopes.” **Must not say:** They are equivalent cost measures, one spent more on a comparable basis, or combined expenditure is X + Y. **Future domain may need:** A relevant comparison purpose and a separately approved accounting/population compatibility ruling; a cross-provider sum remains outside this slice.

### B. Later same-slot spend restatement

**Knows:** Same source/scope/window/lineage reported 100, then restated it as 90. **May say:** “The latest reported version is 90; the earlier reported version was 100,” with knowledge qualifications. **Must not say:** Total is 190, a new result occurred, or behavior improved by 10. **Future domain may need:** Why the source changed it, full-request completeness, validity and relevant decision sufficiency.

### C. COMPLETE followed by FAILED or PARTIAL

**Knows:** Earlier identified read completed; newer acquisition failed or returned a partial result. **May say:** “Last completed evidence was recorded at T; latest acquisition failed/is partial”; returned partial slots may be stated individually. **Must not say:** Old complete values are refreshed current truth, or old and partial rows form a complete current population. **Future domain may need:** Successful sufficiently current acquisition or an explicit domain policy for historical qualified evidence.

### D. COMPLETE read with no rows

**Knows:** No rows were returned in the complete identified requested read, if its request and latest-run status are available. **May say:** That bounded acquisition statement. **Must not say:** Spend = 0, no campaigns/results exist, or a bounded empty entry projection proves no newer run. **Future domain may need:** Native empty-report semantics and the relevant reporting population; zero requires an explicit supported zero claim.

### E. Known "0" versus null

**Knows:** A known amount with known scale/currency can be zero; null is missing value. **May say:** “Source reported zero expenditure for this slot” only for the former. **Must not say:** Null is zero, or reported zero proves no business activity. **Future domain may need:** Coverage, native accounting limitations and suitability for its question. Unknown scale prevents major-unit interpretation even when a native token is present.

### F. Google conversion versus Desired Outcome Meaning

**Knows:** A provider-attributed conversion under Google's native methodology and a separately confirmed business acceptance meaning. **May say:** These are distinct claims. **Must not say:** Conversion count satisfies the accepted business outcome or intent target. **Future domain may need:** Actual business-result evidence and demonstrated acceptance match under the consumed full meaning/revision.

### G. WhatsApp message versus qualified lead

**Knows:** Hypothetically, evidence of a message, with its own source/scope; this task establishes no WhatsApp path. **May say:** A message was observed if supported. **Must not say:** It is a qualified lead, buyer or sale merely because communication occurred. **Future domain may need:** Authorized business evidence satisfying advertiser-confirmed qualification; no ingestion abstraction is created here.

### H. Provider timezone versus DTM/Intent time

**Knows:** Report dates belong to a native account/campaign reporting timezone; DTM is declared geography and Intent has its own declared horizon meaning. **May say:** Amount covers the explicit native period; desired horizon/context is separately declared. **Must not say:** A Saudi market or Intent deadline changes the source's day boundaries, or timezone equality follows from country. **Future domain may need:** Explicit supported alignment of the relevant horizon and report interval; no timezone inference, splitting or prorating.

### I. Different valid claims coexist

**Knows:** Provider-attributed conversions = 8, confirmed paid orders = 5, qualified prospects = 3 under distinct meanings/populations. **May say:** All three qualified claims coexist. **Must not say:** There are 16 business results, one count is wrong, or the differences prove data loss. **Future domain may need:** Which claim relates to its question and full acceptance/coverage evidence; synthesis is not supplied.

### J. Genuine conflict

**Knows:** Two non-superseding claims assert the same quantity for demonstrably identical meaning, scope/population, period, unit and methodology, with incompatible values. **May say:** The supported claims disagree and a unique uncontested value is unavailable. **Must not say:** Newer timestamp/provider/storage wins, or average them to resolve it. **Future domain may need:** Governed reconciliation or additional disambiguating evidence; preserved provenance supports that later work.

### K. Business outcome without a campaign

**Knows:** Hypothetically, supported business evidence satisfies a specified confirmed acceptance meaning but has no known campaign attribution. **May say:** That qualified business result occurred, if the future outcome path proves it. **Must not say:** It is invalid because campaign is absent or was caused by an arbitrary campaign. **Future domain may need:** Attribution only if the selected decision depends on attribution; occurrence and qualification remain separate.

### L. DTM known, actual targeting unavailable

**Knows:** Advertiser declared markets; actual/effective targeting evidence is unavailable. **May say:** DTM is known and configuration consistency is undetermined. **Must not say:** Campaign matches/mismatches DTM or targets it by default. **Future domain may need:** Selected intervention's relevant configuration, effective exclusions/inclusions and sufficiently current evidence. Other qualified expenditure claims can still be considered.

## 19. Deferred semantics

**SR15 — Explicit deferral is a contract boundary, not an implied future implementation commitment.**

Deferred: first Targeting/Spend intervention selection; shared delivery measures; actual configuration meaning beyond the conditional boundary; observed business-result acquisition/acceptance mechanisms; cross-provider expenditure equivalence/addition; FX; accounting settlement/reconciliation; business-wide financial totals; resource constraints; attribution; rates/efficiency/value/ROI/ROAS; causal or performance explanations; decision history design; synthesis/reconciliation policy; universal identity; domain freshness/sufficiency thresholds.

No more capable storage, generic temporal machinery or evidence hierarchy is selected to accommodate these possibilities. A future need must prove its own consumer delta and governed semantic owner.

## 20. Conditional dependencies / implementation consequences later

| Classification | Activating condition / consequence | Boundary preserved |
| --- | --- | --- |
| IMPLEMENTATION_CONSEQUENCE_LATER | To assert latest requested knowledge, consumption must have enough evidence of request meaning, latest acquisition and relevant membership, including empty/failed runs and bounded-history limitations | Existing entry history alone does not prove it; no projection mechanism or implementation location selected |
| IMPLEMENTATION_CONSEQUENCE_LATER | A requested comparison/addition needs evidenced native accounting compatibility, population, exact units/windows and nonduplication | Missing proof withholds the operation; no registry, mapping architecture or verification workflow designed |
| CONDITIONAL_DEPENDENCY | Selected Targeting intervention depends on actual configuration | Relevant configuration meaning and sufficiently current evidence must first be established; no acquisition design |
| CONDITIONAL_DEPENDENCY | Selected decision depends on observed business results | Accepted result evidence must match consumed confirmed meaning/acceptance; no mandatory campaign link or universal ingestion requirement |
| CONDITIONAL_DEPENDENCY | Selected decision requires intent/DTM context | Consume existing owners explicitly; do not make these declarations prerequisites to every native evidence read |
| CONDITIONAL_DEPENDENCY | Selected action changes resources | Relevant resource constraints and governed execution authority are needed under their owners; reported spend supplies neither |

These conditions do not select files/classes/tables/endpoints, create a sequence of work, or authorize implementation. No discovered deficit is silently upgraded to a mandatory infrastructure repair before Owner review.

## 21. Explicit non-goals

No production code, tests, SQL/migrations, API/controller/UI design, implementation plan, deployment or provider activation. No second observation platform, generic Fact/Evidence/Observation entity, metric registry, evidence-level enum, confidence score, universal absence enum, temporal platform, event bus, warehouse or universal object hierarchy.

No Targeting/Spend implementation, Decision Synthesis, action/execution contract, attribution engine, business-outcome ingestion, connector expansion, Market Intelligence or M02. No rewrite of source, access, authority or observation foundations. No changes to governance, discovery or Current Intent artifacts. No provider API, real credential/customer-data use, primary database change, commit, push or merge.

## 22. Owner-review questions

**MATERIAL_OWNER_RULINGS_REQUIRED = NONE.** The bounded semantic choices above are evidence-backed proposals ready for Owner review; no unresolved product choice blocks this document. The Owner's acceptance of this design remains pending. Selecting a first intervention is a later governed task, not a missing answer used to expand this slice.

Quality-gate self-review: no second platform/storage proposal; no ownership inferred from storage; no native-name promotion; no conversion/click promotion to accepted outcomes; no universal campaign; no automatic R1–R6 acceptance; comparison and addition have separate proofs; normalization is not verification; coverage is not population truth; older COMPLETE is never silently refreshed by later failure/partial acquisition; unknown stays unknown; no action choice or hidden thresholds; outcome/configuration acquisition remains conditional. The contract is materially smaller than a generic analytics/evidence platform and coherently overlays existing observations.

### Required handoff

```text
BASELINE_SHA = 29bd35bbc475731d555dfeea20cde363b09a87b7
BRANCH = codex/shared-canonical-reality-discovery-001
WORKTREE = C:\dev\builderad-worktrees\shared-canonical-reality-discovery-001
DISCOVERY_ACCEPTED_INPUT = YES
DISCOVERY_DOCUMENT_UNCHANGED = YES
GOVERNANCE_UNCHANGED = YES
SEMANTIC_DESIGN_COMPLETE = YES; Owner acceptance pending
SHARED_REALITY_CONTRACT = This first bounded advertising-evidence slice only: qualified scoped native advertising claims plus usability and reported-expenditure interpretation; not a global Shared Canonical Reality definition
SEMANTIC_OWNER = BUILDERAD SHARED REALITY SEMANTIC CONTRACT
SOURCE_NATIVE_BOUNDARY = Accounting, attribution, delivery definitions, native objects and report limitations remain native
SHARED_USABILITY_BOUNDARY = Claim scope/provenance/knowledge compatibility and withholding; no sufficiency thresholds
DOMAIN_POLICY_BOUNDARY = Decision sufficiency, risk, ranking, tradeoffs, thresholds and actions belong to future domains
CURRENT_KNOWLEDGE_RULE = Identify request/latest acquisition; preserve historical qualification; never silently fuse last-good slots
COMPARABILITY_RULE = Evidenced same-source compatible expenditure semantics for the stated comparison; cross-provider equivalence excluded
AGGREGATION_RULE = Proven same-source/account additive disjoint union only; no duplicate/restated/overlapping components or cross-provider sum
REPORTED_ADVERTISING_EXPENDITURE_RULING = ACCEPT bounded descriptive source-reported amount; no settlement/business cost/budget/value claim
DELIVERY_EVIDENCE_RULING = CONDITIONAL_LATER; native evidence retained without shared numerical equivalence
TARGETING_CONFIGURATION_RULING = CONDITIONAL_LATER; selected configuration-dependent intervention activates evidence need
BUSINESS_OUTCOME_EVIDENCE_RULING = CONDITIONAL_LATER; acceptance match to full confirmed meaning/revision; campaign optional
CONFLICT_COEXISTENCE_RULING = Different claims coexist; demonstrably same non-superseding contradictory claims preserved without winner
ABSENCE_SEMANTICS_RULING = Reuse specific existing mechanics; unknown/failure/partial/unsupported/no rows never imply zero/nonoccurrence
IDENTITY_BOUNDARY = Existing scoped advertising identity only; no universal or cross-provider object equivalence
R1_DISPOSITION = REUSE_EXISTING_OWNER_ONLY
R2_DISPOSITION = REUSE_EXISTING_OWNER_ONLY
R3_DISPOSITION = FOUNDATIONAL_REQUIRED_NOW
R4_DISPOSITION = FOUNDATIONAL_REQUIRED_NOW
R4_FOUNDATIONAL_SCOPE = Required only for this selected first advertising-evidence slice; not every future consumer, every advertiser, pre-connection Market Evidence, expenditure-independent Targeting, a universal Builderad value prerequisite, or every future Shared Reality slice
R5_DISPOSITION = CONDITIONAL_LATER
R6_DISPOSITION = CONDITIONAL_LATER
FIRST_SLICE_SHARED_REALITY_CONTRACT = R3/R4 bounded semantics for this selected first advertising-evidence slice, with R1/R2 references; R4 foundation is slice-local; no new platform or implementation
CONDITIONAL_DEPENDENCIES = Selected configuration/outcome/intent/resource-dependent needs only; see section 20
IMPLEMENTATION_CONSEQUENCES_LATER = Sufficient latest-request/membership evidence and operation compatibility proof; no mechanism selected
MATERIAL_OWNER_RULINGS_REQUIRED = NONE
DESIGN_DOCUMENT = docs/superpowers/specs/2026-10-05-shared-canonical-reality-design.md
PRODUCTION_FILES_CHANGED = NONE
TEST_FILES_CHANGED = NONE
SQL_MIGRATIONS_CHANGED = NONE
GOVERNANCE_FILE_CHANGED = NO
DISCOVERY_FILE_CHANGED = NO
PRIMARY_DATABASE_TOUCHED = NO
IMPLEMENTATION_PLAN_CREATED = NO
COMMIT_CREATED = NO
PUSH_PERFORMED = NO
MERGE_PERFORMED = NO
```

Handoff is for Owner review. The complete semantic document does not claim implementation, live evidence sufficiency or Core readiness.
