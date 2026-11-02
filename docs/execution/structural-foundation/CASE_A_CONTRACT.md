# Case A — accepted-inquiry cost assessment contract

**2026-11-01 · BA-STRUCTURAL-FOUNDATION-001 / X01 · v0.2**

Disposition: **BOUNDED_CONTRACT_READY_FOR_REVIEW / STRUCTURAL_CODE_PRECEDENCE_NOT_ESTABLISHED / APPLICATION_IMPLEMENTATION_NOT_AUTHORIZED**. These are descriptive document labels, not new control-model or database states.

Continuation in `ba-commercial-expertise-001`, HEAD `c747f6d8a8f2011fa5dcc368da2aa4e8449e0565` with the existing local documentation candidate. [Integration record](EXPERTISE_INTEGRATION.md); [Owner mandate](OWNER_MANDATE.md). No new architecture program, re-evaluation of B01/B02, or change to accepted Stage A bytes.

## 1. Question, consumer and permitted conclusion

**User job:** understand whether a lower observed inquiry cost remains lower when the same eligible cohorts are assessed against an explicitly versioned acceptance criterion.

**One diagnostic behavior:** compare observed cost per inquiry and observed cost per accepted inquiry, with eligibility established separately for each measure and each cross-cohort comparison from its actual premises. Explain reversal, equality or preservation of the observed ordering. If comparability or inputs are missing, withhold the affected comparison and identify the exact missing premise.

The intended consumer is an advertiser-facing, read-only channel/qualification comparison, supported by an authorized analyst where applicable. **No such Case A consumer is implemented in this revision.** This is a concrete consumer contract, not a claim of an existing UI or a hidden call from Spend. It may inform further investigation; it does not recommend a budget, pick a strategy, declare the best channel, or approve an action.

Example in SAR, with all comparability conditions assumed ONLY for illustration:

| Cohort | Cost | Inquiries | Accepted inquiries | Cost/inquiry | Cost/accepted inquiry |
|---|---:|---:|---:|---:|---:|
| A | 1,000 | 100 | 10 | 10 | 100 |
| B | 1,000 | 50 | 20 | 20 | 50 |

A has lower inquiry cost; B has lower observed accepted-inquiry cost. Neither is thereby proven to have better buyers, profit, causal impact or scalable marginal returns. This counterexample demonstrates why the two measures cannot substitute for each other, not the effectiveness of a production algorithm.

## 2. Semantic ownership — narrow allocation, no new global owner

| Responsibility | Owner/boundary for this contract |
|---|---|
| Original inquiry, channel observation, cost observation | The source that legitimately records that specific fact; platform lead/conversion is not automatically a received business inquiry. |
| Acceptance criterion and its revision | Authorized business-side criterion owner, explicitly identified in the input lineage; no guessed universal definition of a serious customer. |
| Acceptance event | Its qualified originating authority and criterion revision. A BuilderAd qualification proposal is not an external system's accepted status. A human-maintained record may be a source assertion, not automatic verification. |
| Computed descriptive assessment | Method maintainer at the BF-03 qualification/diagnosis boundary, consistent with BF-03 I-07 and its QualificationAssessment proposal. X01 is the construction group, not a business-fact or decision owner. This is a proposed package allocation, not creation of a canonical writer. |
| Consumer presentation | The authorized read-only channel/qualification comparison; retains source, criterion, time and interpretation limits. |
| Accepted sale, fulfilled service, payment, margin | Their respective owners; intentionally not required or created by this diagnostic contract. BF-04's wider unresolved ownership is not silently decided here. |
| Allocation of resources and external change | N07 or other accepted domain for the actual decision, synthesis only if genuinely needed, followed by separately authorized effects. This diagnostic cannot issue or approve such a decision. |

No new domain, registry, coordinator, canonical customer ID, CRM, outcome ledger or entitlement owner is created. Existing protected owner disagreements must be escalated specifically if a proposed implementation actually requires changing them; no disagreement is resolved by the name of a PHP directory.

## 3. Input meaning, not provider/tool identity

The evaluator needs a qualified snapshot of exactly two comparison cohorts for this first bounded method. That is a method limit, not a permanent platform limit; other comparisons may use other methods later. No CRM, provider, campaign, ad account or declared goal is mandatory. Inquiry/acceptance evidence may originate from authorized forms, calls, messaging, CRM or other source records with adequate meaning and rights. A click, call attempt or message-delivery status alone cannot stand in for a received inquiry.

For each requested measure or comparison, each participating cohort must carry or resolve the applicable premises below. Acceptance-only inputs are not prerequisites for inquiry-cost measures or comparisons, and a missing total-inquiry count does not automatically invalidate independently admitted accepted-inquiry facts:

- Business/resource scope, stable cohort definition and revision, acquisition interval, explicit timezone/boundaries, observation `as_of` and accepted input revisions.
- Source references/generations for cost, inquiry count, acceptance and lawful linkage; linkage/counting policy revision, cost allocation basis and overlap/deduplication policy. No inferred global identity from matching local keys. Retain references rather than copying private content unnecessarily.
- A nonnegative finite cost in declared currency and precision, with the same cost-component definition across cohorts. Full-channel spend is not silently assigned to a selected subset of inquiries. Attributed cost is an explicit scoped allocation, not incremental causal cost.
- Nonnegative integer count of distinct admitted inquiry units, acceptance count on the SAME units, acceptance-criterion reference/revision, acceptance observation cutoff, agreed follow-up/maturity window, pending/unknown acceptance count and coverage. Repeated inquiries may be meaningful: the declared unit/policy decides; do not indiscriminately deduplicate people.
- Current authority, processing purpose, source/use and retention rights, and independently qualified display rights for the actual output audience. These are checked by an admitted input/read boundary, not trusted booleans supplied to a pure calculator.

When both measures are related to the same inquiry population, accepted units must be a qualified subset of that population. If both counts are known, aligned and comparable, `accepted > inquiries` is inconsistent; do not manufacture an upper bound from a missing count. Independently admitted accepted-inquiry cost requires its own valid cost/population linkage and accepted count, not necessarily an available total-inquiry count. Negative counts, invalid finite values or incompatible unit definitions invalidate the affected input/claim; shared cost or linkage failures invalidate every claim that actually depends on them. Fractional attribution counts need a different explicitly qualified method; they are not rounded into whole inquiries here. Missing acceptance is not zero; unresolved outcomes are not rejected outcomes.

## 4. Comparability and mathematics

Eligibility is claim-specific, not one all-or-nothing flag for the assessment. Compute and authorize each within-cohort measure independently, then qualify each cross-cohort comparison independently. Two valid displayed values do not by themselves establish a valid comparison.

| Claim | Necessary premises beyond current scope/use/display authority |
|---|---|
| Inquiry cost for one cohort | Qualified finite cost, positive inquiry count, compatible cost/population/counting scope, observation cutoff and coverage. No acceptance criterion or acceptance maturity required. |
| Inquiry-cost comparison | Eligible inquiry-cost measures for both cohorts plus compatible currency/precision, cost-component/allocation meaning, inquiry unit/definition, eligibility, time/coverage appropriate to this descriptive claim. No acceptance data required. |
| Accepted-inquiry cost for one cohort | Qualified finite cost, positive admitted accepted-inquiry count, cost/population linkage, versioned criterion and acceptance observation/maturity/coverage appropriate to the claim. A total-inquiry count is not an automatic prerequisite. |
| Accepted-inquiry-cost comparison | Eligible accepted-cost measures for both cohorts plus compatible currency/cost scope, accepted unit, criterion revision or qualified equivalence, cohort eligibility and acceptance cutoff/maturity/coverage. |

A changed criterion does not rewrite historical acceptance or invalidate an independent inquiry-cost comparison. Different channels are allowed; channel labels do not prove comparability. Market, sector, product and period differences must be disclosed and evaluated against the specific claim. Do not require causal exchangeability or proof of future budget response merely to report a qualified descriptive cost comparison. Conversely, compatible metric definitions alone never establish causal effect, best-channel strategy or scalability.

Maturity is policy-defined and versioned; there is no global wait of N days. Equal calendar periods alone do not establish comparable maturity. If one cohort has unresolved acceptance or incomplete counting at the required cutoff, withhold accepted-cost ordering rather than treating its unknowns as failures. A valid independent inquiry-cost measure AND its qualified cross-cohort comparison remain available with their own limits. Conversely, missing total-inquiry counts can withhold inquiry-cost comparison while independently qualified accepted-inquiry-cost measures/comparison remain eligible. No inference survives a failed premise that it actually shares; one unrelated missing premise does not erase all knowledge.

For each cohort: observed inquiry cost = admitted cost / admitted inquiry count. Observed accepted-inquiry cost = SAME admitted cost / admitted accepted count. This is not cost per buyer or customer lifetime value.

- Zero denominator yields an undefined ratio with its reason, never zero cost or an untyped infinity. Known zero cost with a positive, qualified denominator is valid zero.
- Use exact decimal/rational arithmetic or checked equivalent; rounding is for display and must not create the ranking. No float epsilon may mask a material ordering change. Currency precision is input metadata, not a universal two-decimal assumption; no automatic FX conversion.
- The assessment states an observed ordering at the declared snapshot. No causal, statistical-significance, future-performance or channel-superiority claim follows from that ordering; no arbitrary minimum sample size converts it into such a claim.

This contract does not prescribe a general numeric framework or language. Implementation must choose the smallest reliable arithmetic path for its supported range and test overflow/range/precision explicitly before claiming coverage.

## 5. Output and invalidation

Return method/version, question and scoped subject, as-of/input references, eligibility and reasons for EACH measure and EACH comparison, independently available measures and their units/denominators, observed ordering only for the supported comparison, explicit limitations and the exact evidence needed for a withheld comparison. Separate invalid input, unavailable/unauthorized evidence, valid but noncomparable cohorts and a completed descriptive calculation. Output states are method-local design meanings, not an instruction to introduce a global epistemic enum.

Changing criterion, attribution/deduplication policy, source correction, acceptance maturation, scope or rights changes the affected assessment's applicability. Preserve the original assessment/input lineage; do not overwrite history or reuse a prior result under a new input meaning. A pure deterministic method does not itself implement durable replay, revocation, scheduling or secure publication.

Required Arabic consumer meanings include: «هذه تكلفة الاستفسار المقبول وفق المعيار المحدد، وليست تكلفة المشتري»، «لا يمكن مقارنة القبول بعد لأن نتائج إحدى المجموعتين لم تكتمل»، and «اختلاف التكلفة لا يحدد سبب الاختلاف أو يجيز تغيير الميزانية». No raw PII or hidden source counts are disclosed to compensate for absent display rights.

At consumer integration, qualify disclosure of aggregates for the actual audience, purpose and cohort: small groups, known group membership, overlapping filters and differences between successive snapshots can reveal sensitive facts without names. Apply and test the relevant disclosure policy, including derived ratios/denominators and longitudinal differences, before exposing results. Do not invent a universal minimum group size, noise mechanism or automatic suppression rule here. Process permission alone does not authorize aggregate display; do not relabel disclosure withholding as zero activity or no accepted inquiries.

## 6. Reuse, actual gap and proposed implementation envelope

Inspected current source: D2's selector/validator and SQL constrain methods/questions to market offer/interest/business-feasibility meanings; current D3 consumers yield bounded reach/competition assessments, not accepted-inquiry cost diagnostics. Focused source search for inquiry/accepted-lead/CPL semantics found goal preference labels, not an implemented diagnostic at these boundaries. This is bounded inspection, not a repository-wide proof of absence. Goal preferences declare desires, not observed inquiries or business outcomes.

| Reuse / change | Disposition |
|---|---|
| Authority, Business scope, source rights, corrections, lineage and truth-state principles | REUSE established owners; qualify their actual input integration for this new fact type. No generic permit substitution. |
| D2 `MarketMethodSelector`, `Findings`, market schemas and D3 market publication | KEEP unchanged; do not add CRM facts/extra methods to force the fit. |
| Descriptive inquiry/accepted-inquiry calculation | A real new behavior, if this package is chosen. No current mapped method provides it. |
| New inquiry/acceptance input reader and lawful linkage | NOT yet admitted/implemented. Contract definitions do not establish a secure source ingress. Do not expose caller-submitted aggregates as trusted evidence. |
| User surface | DEFERRED_UI while this is a contract. An operational package is incomplete until authorized users can find/understand it with Arabic/RTL, permissions and browser evidence; do not build a global dashboard now. |

Potential bounded pure-method envelope for subsequent review, **not files created or implementation authority**:

- `app/Commercial/Diagnostics/AcceptedInquiryCostComparison.php`: method-local pure descriptive calculation, no DB/HTTP/credentials/actions.
- `tests/Unit/AcceptedInquiryCostComparisonTest.php`: mathematical, input-validity, comparability and uncertainty cases below.
- `tests/Unit/CoreDependencyBoundaryTest.php`: existing recursive guard already covers descendants of `app/Commercial`; no exception or guard expansion needed merely for placement.

Do not create a general SpecialistMethod registry or shared outcome store for this envelope. A pure method alone would establish a computational component, not a delivered Case A capability. Production source admission, scoped reads, UI and any durable result each require concrete contracts and affected files; none is smuggled into this two-file proposal.

## 7. Priority finding — qualification is justified; code-first necessity is not proved

Case A has a documented research basis and a decisive semantic difference from market methods. Qualifying it now directly addresses the approved expertise-integration question and prevents a future consumer equating cheaper inquiries with better accepted inquiries. That justifies THIS bounded contract task.

An already implemented and blocked consumer is NOT a prerequisite for a new foundation: an accepted behavior, demonstrated need and qualified dependencies can justify an independent package. For this candidate, no current implemented consumer was shown to be blocked by its absence, and no alternative demonstrated dependency impact establishes first-code necessity. No demonstrable irreversible rework or critical shared security defect proves it must be the first code package. Do not manufacture that priority from the number of hypothetical consumers. U01 remains a deferred operational capability; B02 remains a local native-evidence gap; X01 is not itself a missing universal engine; D02/D03 prerequisites remain scoped. This task does not rerank all independent construction branches or require them to wait.

**Decision:** retain Case A as a concrete reviewable contract and possible bounded computational package. Do not label it the first mandatory structural implementation or ready production capability. The immediate prerequisite to meaningful operational implementation is one admitted inquiry/acceptance evidence route with current scope/rights and a real consumer integration; it need not be a CRM or live advertising account. Further work should close that exact input/consumer boundary if this package is selected, rather than reopen X01/D02/D03 or repeat platform research. Evidence gaps do not preclude testing the stable mathematical contract, and contract tests do not supply real evidence.

## 8. Acceptance cases for implementation — NOT EXECUTED

| ID | Discriminating case | Required behavior |
|---|---|---|
| A01 | 1,000/100/10 versus 1,000/50/20 | CPL favors A; accepted-inquiry cost favors B; no spend action. |
| A02 | Second cohort has 10 accepted instead | Accepted cost equal despite different CPL; no fabricated winner. |
| A03 | Acceptance missing/pending or unequal maturity, with comparable cost/inquiry inputs | Withhold accepted-cost ordering; retain qualified inquiry-cost measures AND cross-cohort ordering; no business-superiority claim. |
| A04 | Known zero accepted; positive inquiry count | Undefined accepted ratio, not zero or automatic worst-channel strategy. |
| A05 | Known zero cost; positive denominators | Valid zero costs, provenance retained. |
| A06 | Different criterion revisions with no qualified equivalence | Withhold accepted-cost ordering only; preserve independently eligible inquiry-cost comparison and historical meaning. |
| A07 | Different currencies, cost coverage or attribution scopes | Noncomparable; no implicit conversion or count/spend mismatch. |
| A08 | Duplicate/local keys, unit mismatch, accepted outside subset | Require qualified linkage/counting; reject invalid arithmetic assumptions. |
| A09 | Non-finite/negative/fractional counts, invalid range/overflow | Typed failure or bounded unsupported input, no coerced numeric result. |
| A10 | Exact near-equal costs displayed identically | Ordering follows exact supported values, not formatted rounding; disclose precision. |
| A11 | Same admissible semantics from form/call/message/CRM | Same calculation; no requirement for vendor or ad account. Caller authority is separately tested at ingress. |
| A12 | Cost/criterion/correction/as-of changes | Track affected claim dependencies/input identity; retain original interpretation. A criterion-only change does not invalidate independent inquiry-cost knowledge. |
| A13 | Process right without display, other Business, revoked rights | Application boundary denies dependent disclosure/use. Pure unit tests cannot prove this. |
| A14 | Late follow-up correlates with fewer accepted inquiries | No causal loss or response-time diagnosis from this ratio method. |
| A15 | Closed-Won, payment, refund or margin supplied | Not reinterpreted as this method's accepted count without the admitted criterion; no profit/ROI claim. |
| A16 | Evaluation feeds a budget or messaging action | Evaluation grants no execution right; independent authorization remains mandatory. |
| A17 | Missing total-inquiry counts, but independently admitted cost/accepted-unit linkage, criteria and maturity | Withhold inquiry-cost measures/comparison; allow accepted-cost measures/comparison ONLY when their own premises are met. Never infer missing totals. |
| A18 | Individually valid costs in different currencies or incompatible scopes | Individual qualified measures may remain; cross-cohort ordering withheld without a qualified comparison basis. |
| A19 | Small/known-member cohorts or overlapping/successive snapshots disclose sensitive facts | Apply audience/purpose-specific aggregate disclosure policy to counts, ratios and differences; do not rely on removed PII or invent a global threshold. Integration test, NOT pure arithmetic proof. |
| A20 | Descriptively comparable data with no causal or future budget-response evidence | Permit the bounded descriptive comparison; withhold causal/channel-superiority/budget claims. |

## 9. Evidence actually obtained

Two exact-rational arithmetic witnesses were evaluated with Python `Fraction`: reversal and equality (A01/A02 numeric expectations only). Both checks passed. Artifact and hash in EVIDENCE.json. This is a numerical oracle for the contract, not a Case A production implementation, rights test or application acceptance. The earlier eight D2 probes remain limited prior evidence and were not rerun.

No new application/test/SQL/migration files, endpoints, permissions or dependency changes. No PHP/JavaScript suite, browser, Foundation, CI, private account access, commit, push, merge or deployment. The current output closes the authorized qualification task with an explicit priority limitation, rather than claiming code or commercial capability completion.

## 10. Research reuse and revalidation

- BF-03 v1.1 I-06/I-07 and ownership/interface tables: inquiry is not click/delivery; qualification proposal differs from source acceptance; acceptance criterion and authority are explicit. Used to bound this method's inputs and owner; not used to impose CRM.
- BF-04 v1.1 opening decisions and arbitration items 3/5: do not invent universal customer/outcome ownership or equate Closed-Won with cash/profit. Used to keep those claims OUT of this contract.
- CAEI v1.1 §3 ownership, §8 method/assessment and §13 recommendations: specialist computation is pure/scoped; no source transport or action authority; native advice is assessed rather than obeyed. Do not implement the entire illustrative assessment envelope.
- Blueprint v1.1 §11.1–11.3: reuse relevant research and allow independent question/capability discovery; no mandatory advertiser goal.
- Current INV-001/004/005/007 and existing finite market implementations: preserve provider optionality, owners and honest limits; no production invariant coverage added by this document.

Reopen only the affected part if accepted domain ownership changes, a qualified existing implementation is found, cost/qualification semantics change, a necessary evidence route becomes available, or numeric/counterexample evidence invalidates the proposed behavior. No automatic repeat of the whole program or Meta study.


## 11. Bounded review correction — v0.2

Owner instruction: «اضبط ما يحتاج». Clarified independent eligibility for each measure and comparison (including asymmetric missing inputs), separated descriptive from causal premises, added aggregate-disclosure integration protection, and retained valid accepted-inquiry evidence without forcing an available raw inquiry total. Added A17–A20 and sharpened A03/A06/A12. These are contract corrections, not newly executed application tests or a security defect claim against deployed code. Ownership/input-admission/consumer prerequisites and code priority remain open as previously recorded; follow-up and capacity diagnosis remain outside this method.

This contract-review batch is closed at its stated scope. The next construction choice comes from the existing program and actual dependency/priority record, not automatic Case A implementation, a third example, or another general architecture review. No application implementation or publication authorization is added.
