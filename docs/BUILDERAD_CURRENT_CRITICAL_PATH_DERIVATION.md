# Builderad — Current Critical Path Derivation 001

Prepared **2026-10-06, Europe/Istanbul**. **Status: OWNER_ACCEPTED.** Final Owner acceptance and selection of the sole eligible direction are granted. Active product-work authority is Semantic Design only, with bounded supporting Discovery only when a specific unresolved fact blocks that design. Implementation Plan and implementation are not authorized. Landing status is established externally through Git / PR / CI / authoritative-main provenance; this document does not self-prove landing.

## 1. Baseline and task boundary

```text
AUTHORITATIVE_MAIN = 2aedd0d5c8637b8cc2b015f23ec2597826296685
WORKTREE_HEAD = 2aedd0d5c8637b8cc2b015f23ec2597826296685
WORKTREE_BRANCH = codex/current-critical-path-derivation-001
WORKTREE_CLEAN_AT_ANALYSIS_START = YES
DOCUMENT_AUTHORITY = CURRENT_WORK_CONTROL_EVIDENCE_ONLY
MASTER_EXECUTION_MAP_V1 = LANDED_PROVEN
MASTER_EXECUTION_MAP_CHANGED = NO
MASTER_EXECUTION_MAP_MATCHES_HEAD = YES
MASTER_EXECUTION_MAP_COMPLETE = YES
READY_FOR_CURRENT_CRITICAL_PATH_DERIVATION = YES
CURRENT_CRITICAL_PATH_SELECTED = YES
NEXT_PRODUCT_SLICE = Joint Targeting + Spend recommendation from qualified retained evidence
NEXT_PRODUCT_SLICE_SELECTED = YES
ACTIVE_PRODUCT_WORK_AUTHORITY = SEMANTIC_DESIGN_ONLY
OWNER_FINAL_ACCEPTANCE = GRANTED
OWNER_SELECTION = GRANTED
NEXT_PRODUCT_SLICE_DIRECTION_SELECTED = JOINT_TARGETING_SPEND_RECOMMENDATION_FROM_QUALIFIED_RETAINED_EVIDENCE
NEXT_AUTHORIZED_PRODUCT_STAGE = SEMANTIC_DESIGN
```

The Master Execution Map's acceptance and LANDED_PROVEN status are Owner-established starting facts, corroborated by its presence at the pinned main commit (merge PR #22). Its document self-status is not landing proof. Candidate-era review wording in that accepted artifact does not reopen acceptance or invalidate external landing provenance. The map matches the HEAD blob exactly; no status-reconciliation delta remains.

Only this derivation document may change in this landing task. Owner authorizes its commit, push on the existing branch, PR to main and merge after all applicable required checks pass. No change to the map, Reconstruction, Control Model, canonical governance, product code, tests, database or CI is authorized. The original eligibility analysis below is preserved; current Owner selection and next-stage authority are recorded in §10.

## 2. Bounded progress objective and controlling records

> Determine what, if anything, must be resolved next before Builderad can safely authorize the next coherent product-value work without violating an accepted dependency, authority boundary, truth-integrity rule, closure requirement or material assurance requirement, and without creating material avoidable rework.

The controlling sources remain the [Master Execution Map](BUILDERAD_MASTER_EXECUTION_MAP.md), especially §§8–10, 14 and 16; [Project Control Model](BUILDERAD_PROJECT_CONTROL_MODEL.md) §8; [Current System Reconstruction](BUILDERAD_CURRENT_SYSTEM_RECONSTRUCTION.md); and [canonical governance](BUILDERAD_CORE_EXECUTION_GOVERNANCE.md), Core Execution System gate. This correction uses the existing accepted working analysis; no new reconstruction or source research is performed.

Owner review corrects the previous stopping point: first test upstream blockers, then enumerate eligible coherent directions when no universal blocker is established, and only then seek Owner selection. OWNER_PRESELECTION_REQUIRED_BEFORE_ELIGIBILITY = NO. A candidate direction supplies a bounded question for applicability testing without establishing a selected Slice, accepted Semantic Design, new dependency, or implementation authority.

“Earliest” remains prerequisite/topological order, not chronology, severity or Node/spine position. No formal dependency state, lifecycle enum or persistent control state is introduced by the descriptive conclusions here.

## 3. Existing blocker/applicability ledger

These rows are conditional analyses of existing claims, not proposed tasks, future Slices or an implementation order. They cannot be combined into one universal mandatory prerequisite list.

| Established claim being evaluated, if authorized as the objective | Relevant existing conditions and evidence | Upstream consequence and smallest distinguishing input |
| --- | --- | --- |
| Actual authorized native provider report read | D03/D05/D06; G02/M02; G04/M03 only for the claimed deployed operation. D05 gates are implemented/tested; actual support/privacy admission and native credentials/permissions/read proof are not established | D05's applicable admission/native/request predicates must hold before the read can be claimed safe. Identify exact provider/version/environment, current authority and scope-valid admission/native proof. Retained/tested acquisition code does not supply external proof |
| Final closure of the existing DTM Slice | G05; DTM remains COMPLETE/LANDED, Slice closure OPEN, historical required surface CLOSED | Explicit attributable final Owner closure acceptance controls this exact closure claim. It is not an established universal prerequisite to every country-consuming decision; D10's technical input remains available |
| Whole required surface closure for existing Foundation/Business/Commercial responsibilities | G03, exact N01/N02/N16 claim and surface; M01 where physical authentication is part of the claim | Applicable baseline-bound functional/visible-surface evidence is missing. Identify the bounded required surface and its obligations. Historical DTM/Intent surface closure does not prove broader parent closure |
| Physical WebAuthn assurance | M01, N01; D01–D03 only for that stronger assurance claim | Actual baseline/environment/device/browser proof is needed. Automated mocks/source do not prove physical authentication. This does not make M01 upstream of every independently scoped documentary or product claim |
| Current deployed-operation or deployment/release closure | G04/M03; exact existing runtime path; applicable D01–D07/D19/D20 qualifications | Environment identity, deployment ledger, applicable production migration rehearsal/reconciliation and bounded operational evidence are missing. Historical local tests cannot establish production truth. No order relative to provider or physical-device assurance is supplied |
| Access to Current Intent's historical raw artifacts or a stronger current-runtime claim | G01; G04 for runtime; D09/D11 input qualification | Recover raw evidence only when artifact access is required; obtain independently scoped runtime proof for stronger operational claims. N03 and its accepted bounded Slice remain COMPLETE/LANDED/CLOSED; missing downstream consumers do not reopen them |
| Country-consuming Targeting decision | D10; D09 only if intent-dependent; G07 for absent N06 capability; G06 for exact future lifecycle/surface claim | Declared country identities/set and accepted optional Intent input exist in their scope; the governed decision consumer is absent. No Targeting-before-Spend edge is established. An accepted bounded decision/consumer contract is needed to establish applicable prerequisites, not an invented Slice |
| Expenditure-consuming Spend decision | D08; D09 only if intent-dependent; G07 for absent N07 capability; G06 for exact future lifecycle/surface claim | SCR-01's qualified expenditure contract is available; Spend reasoning is absent. Preserve comparability/additivity and optional Intent meaning. No Spend-before-Targeting edge is established |
| Bounded product action or advertiser effect | D12/D13/D14; G07; actual coherent decision/evidence, bounded action semantics and applicable approval/mode/guardrails | Decision/evidence is required for D12; actual action semantics precede D13 enforcement; approved mode/guardrails **and** bounded action are required by D14. Missing product contracts prevent an effect claim. Foundation grants and report receipts cannot substitute. This partial order does not establish a unique first implementation task |
| Post-action result verification | D15/D16; D11 only if outcome-dependent; G07 | Identified action and relevant observation contract are needed for D15; observations **and** legitimate action/result criteria are needed for D16. D11 requires confirmed outcome meaning **and** observed acceptance evidence. Native observations or provider conversions alone do not prove the claim |
| Reusable product learning or a later learned-input decision | D17; D18 only for the later learning-consuming decision; G07 | Traceable action/result evidence is required by D17; the learning path and named consumer are absent. D18 is not a universal prerequisite for independent decisions and creates no global sortable whole-Node loop |
| Market Evidence capability or pre-connection value | N15, G06/G07; no accepted concrete Market source/consumer D-ID | Governed direction exists; capability implementation and bounded consumer evidence do not. Do not manufacture a Market edge or universal provider/OAuth prerequisite. Source dependency and pre-connection capability remain independent descriptors |
| Current commercial registration/receipt operational claim | D19; G03/G04 for the stronger bounded surface/runtime claim | Commercial implementation/tests exist; actual operational approvals were not inspected. Exact approval/catalog/configuration and current commercial authority must apply at effect time. Commercial geography cannot substitute for DTM |

The accepted first-Core Targeting + Spend envelope supplies the direction for eligibility analysis. The ledger below preserves the existing claim-local tests; candidate-specific applicability is determined in §6 without requiring Owner preselection.

### Required candidate-blocker applicability ledger

In the following tables, **APPLICABLE = NO** means the accepted records do not establish that this item is a controlling prerequisite to the current authorization objective. It does not mean satisfied, harmless or inapplicable to every eventual claim. **BYPASS = YES** means there is no established universal requirement to resolve the item before independently scoped work can be evaluated; bypassing its own protected claim is not allowed. Claim-local prohibitions and evidence are stated explicitly. These are reporting fields, not enums. The original general-envelope applicability findings are retained as working analysis. They are not a reason to stop eligibility analysis; §6 applies the same predicates to the candidate direction.

| ID / owning subject / source | Exact requirement; progress controlled and upstream of what | Satisfaction evidence; unsatisfied fact | APPLICABLE; reason | Material consequence; comparability | BYPASS; evidence-based scope |
| --- | --- | --- | --- | --- | --- |
| G01 / N03, Intent Slice / map §10 | Raw artifact access or stronger current-runtime assurance | E14 accepted bounded closure; raw artifacts unavailable locally, runtime unproven | NO; neither stronger claim is required by an established next-work contract | Unsupported raw/runtime proof claim; no order against G02–G05 | YES for independent authorization analysis; NO for claiming that missing proof. Do not reopen closed Intent |
| G02 / N04, native N12 / §10 | Actual provider activation/read evidence before live-operation claims | Source/tests exist; real provider proof unverified, Snapchat async conversion absent | NO; no specific live-evidence-consuming next work established | False live readiness if ignored; overlaps M02/D05, incomparable to DTM/physical/surface assurance | YES outside live-operation claim; NO for that claim |
| G03 / N01/N02/N16 / §10 | Whole named required surfaces before their closure | Historical bounded DTM/Intent surface proof retained; whole-parent current surface proof missing | NO; no whole-parent closure needed for an established next contract | Unsupported parent/surface closure; no accepted order with G05/M01/M02/M03 | YES outside whole-parent closure; NO for the claimed required surface |
| G04 / existing runtime paths / §10 | Exact environment/deployment reconciliation before deployed-operation claim | Landed code/historical tests; current deployment ledger/config/queue/log/Redis proof missing | NO; next-work authorization is not itself a release/runtime assertion | False deployment truth; overlaps M03, no order with G02/M01/G05 | YES outside stronger runtime claim; NO for that claim |
| G05 / DTM Slice, N02 / §10 | Attributable final Owner acceptance before DTM Slice closure | COMPLETE/LANDED, historical surface CLOSED; Slice closure OPEN | NO; D10 technical input is available and no next-work contract requires DTM closure | False Owner closure if bypassed; incomparable with assurance branches | YES outside DTM closure; NO for declaring DTM closed |
| G06 / nine MISSING Nodes / §10 | Accepted work/gate record and bounded surface contract before precise lifecycle/surface assertions | MISSING truth valid; lifecycle and future surface allocation withheld | NO as a universal product blocker; exact classification claim not established. It identifies part of the missing bounded control input, not a new prerequisite edge | Invented lifecycle/UI obligations cause rework; overlaps missing-capability scope but does not order Nodes | YES while withholding unsupported classifications; NO for asserting them without their record |
| G07 / N06–N11/N13–N15, N12 post-action, wider N05 / §10 | Actual capability and accepted proof before claiming absent functionality | Accepted MISSING/partial truth; domain/action/result/learning/Market paths absent | NO as a universal upstream authorization gate; capability absence alone cannot choose next work | False capability claims or speculative contracts; applicable D08–D18 govern their own claims, no whole-Node order | YES for analyzing bounded future work; NO for claiming the absent capability exists |
| M01 / N01 / §10 | Physical WebAuthn proof for physical-authentication assurance | E04/E05 protected code/automated proof; E10 F06 outstanding | NO; no next-work contract establishes this stronger claim as required now | Unproven physical assurance; no graph precedence over M02/M03/G05 | YES outside physical assurance; NO for that assurance/closure claim |
| M02 / N04/native N12 / §10 | Actual authorized provider operation for live-evidence claim | E08 controls/test proof; E09/E10 F07 real activation missing | NO; no live-operation-dependent next contract established | Unsafe/false live operation if admitted/native proof bypassed; overlaps G02/D05, incomparable with M01/M03/G05 | YES outside live operation; NO for operation lacking applicable admission/authority |
| M03 / N01/N04/N16 runtime / §10 | Deployment/migration rehearsal/reconciliation for deployed-state closure | Historical local E09 proof; E10 F08/F09 outstanding | NO; release readiness is excluded and no next contract requires current deployment closure | False deployment/migration assurance; overlaps G04, incomparable with M01/M02/G05 | YES outside deployed-state assertion; NO for that assertion |

| ID / owning subject / source | Exact requirement; progress controlled and upstream of what | Satisfaction evidence; unsatisfied fact | APPLICABLE; reason | Material consequence; comparability | BYPASS; evidence-based scope |
| --- | --- | --- | --- | --- | --- |
| D01 / N01 → N02 / map §8 | Scoped current declaration authority before context mutation | E04/E05/E06 implemented/tested; deployment unverified | NO as an unsatisfied current blocker; exact technical contract proven | Unauthorized context effects; upstream of DTM branch only | NO for a context mutation; YES to defer stronger deployment proof outside such a claim |
| D02 / N01 → N03 / §8 | Current authority and prepared revision before Intent mutation | E07/E04 and accepted closed Intent | NO; exact bounded input proven | Unauthorized/stale mutation; upstream of Intent-consuming D09/D11 input only | NO for Intent mutation; no reopening to authorize independent work |
| D03 / N01 → N04 / §8 | Current Workspace/source grant before provider flow/read | E08/E04 tested; actual grants uninspected | NO; no actual provider flow authorized by this task | Unauthorized acquisition; precedes its read, not unrelated branches | NO for provider flow without current grant; YES outside that flow |
| D04 / N04/N12/N05 / §8 | Registered source/scope and current source.read before protected history/snapshot release | E08/E11/E04 implemented/tested, final release check | NO; contract proven within accepted scope | Unauthorized evidence release; upstream of protected evidence consumption | NO for protected release; no new blanket remediation gate |
| D05 / N04 / §8 | Admitted support/privacy, native credentials/permissions and request gate before live read | Gate code/tests E08/E09; actual external proof missing | NO; no next contract requires a particular live read | False readiness/unauthorized operation; before actual D06 live batch, incomparable to G05/M01 | NO for live read without predicates; YES outside live-read-dependent work |
| D06 / N04 → N12 / §8 | Typed batch/provenance and protected acceptance before observation retention | E08/E11/E04 implemented/tested; current live read unproven | NO; technical contract proven, stronger live claim not established | Fabricated observation on failure; before D07 for relevant retained evidence | NO for fabricated/unprotected retention; YES outside stronger live assurance |
| D07 / N12 → N05 SCR-01 / §8 | Immutable accepted run/membership/evidence before SCR view | E11/E04 accepted tests, closed SCR-01 | NO; exact bounded contract proven | False totals/mismatched evidence; before D08 only for expenditure-consuming claim | NO for invalid snapshot; closed SCR is sufficient in accepted scope |
| D08 / N05 → N07 / §8 | Qualified expenditure/operation constraints before expenditure-consuming Spend | SCR contract available; N07 consumer absent | NO; no bounded Spend decision selected/established | Invented additivity/comparability; incomparable with D10 | NO for that consuming decision's constraints; YES outside its predicate |
| D09 / N03 → intent-dependent domain / §8 | Explicit supported optional confirmed Intent/outcome before that consumer | E07 accepted bounded closure; consumer absent | NO; no intent-dependent next contract established | Inferred/default direction or compulsory Intent; no DTM-before-Intent order | NO for misreading confirmed Intent; YES for independent decisions outside predicate |
| D10 / N02 DTM → N06 / §8 | Actual advertiser country declaration before country-consuming Targeting | E06 technical set available; N06 consumer absent | NO; no country-consuming next contract established | Substituted commercial/provider geography; incomparable with D08 | NO for country-consuming predicate; YES outside it; G05 closure not silently added |
| D11 / outcome input → N13 / §8 | Confirmed outcome AND observed acceptance evidence before outcome verification | Declared meaning exists; observed evidence/consumer absent | NO; no applicable verification claim established | False business success from provider conversion; conjunction with applicable verification, not every decision | NO for outcome-dependent verification without both inputs; YES outside predicate |
| D12 / N08 → N09 / §8 | Coherent decision/evidence before bounded action | E01 requirement; N08/N09 implementation absent | NO; no bounded action is proposed/authorized | Action lacks governed provenance; before applicable D13/D14 action use | NO for bounded action claim; YES outside action/effect predicate |
| D13 / N09 → N10 / §8 | Actual action semantics before approval/mode enforcement | Foundation separate; product contracts absent | NO; no actual action-mode contract established | Generic grant/display substituted for guardrails; action semantics precede enforcement | NO for applicable mode claim; YES outside it |
| D14 / N09/N10 → N11 / §8 | Approved mode/guardrails AND bounded action before advertiser effect | Product effect path/ad-write capability absent | NO; effect work not established or authorized | Unapproved external effect; both conjuncts precede effect, no first conjunct winner | NO for advertiser effect without predicates; YES outside effect work |
| D15 / action → N12 post-action / §8 | Identified material action and observation contract before result association | Native observations exist; action association absent | NO; no action-result claim established | False result-window attribution; relevant action upstream, distinct from native history | NO for unproven association; YES for independent native retention |
| D16 / observations/criteria → N13 / §8 | Relevant observations AND applicable result criteria before verification | Result/criteria contract unsatisfied | NO; named verification path absent | Unsupported success/failure/inconclusive claim; applicable D15/D11 facts only | NO for verification without predicates; YES outside verification |
| D17 / traceable action/results → N14 / §8 | Traceable execution evidence before reusable learning | Native history exists; product learning absent | NO; no learning-update claim established | Unsupported learned update/rewritten Intent; before learning-consuming D18 | NO for unsupported learning claim; YES outside learning |
| D18 / N14 → later reasoning / §8 | Retained learning before reasoning that consumes it | No implemented learning consumer | NO; absent named predicate, not universal decision blocker | False improvement claim; later iteration, not globally sortable Node order | NO for learned-input claim without learning; YES for independent decisions |
| D19 / commercial prerequisites → N16 / §8 | Approval/catalog/configuration and commercial authority before registration/receipt | E13/E10 implementation/tests; actual operational approvals uninspected | NO; no actual registration effect proposed | Unauthorized commercial effect; separate from DTM/advertiser geography | NO for actual effect without current predicates; YES outside effect |
| D20 / neutral countries → DTM / §8 | Valid neutral identities/labels before country declaration | E06 FK/validation/249-country accepted historical proof | NO; bounded technical input proven | Invalid identity/declaration; precedes DTM country-consuming input, not Intent | NO for invalid codes; no new universal blocker |

At general-envelope level, no universal controlling blocker is established. G01/G03/G04/M01–M03 retain their stronger-claim limits; material assurance is not waived. Candidate-specific applicability, including dependencies that must be honored within the future bounded work, is evaluated in §6. Independent open conditions do not become a joint controlling set merely because they are open.

### Accepted topology and incomparability

The map's D01–D20 identities and reporting are preserved. In particular:

- SATISFIED_IMPLEMENTED_EDGES remains D01 D02 D03 D04 D06 D07 D19 D20 within accepted implementation/test scope; runtime qualifications remain.
- D05 retains tested gate structure without established actual external proof. D19 retains uninspected actual operational approvals.
- D08–D10 retain available bounded inputs and absent named consumers; consumer absence is not a newly declared universal unsatisfied execution gate.
- D11/D16 retain missing evidence/result criteria even where their named runtime consumer is not currently applicable.
- D12–D17 retain their bounded action/effect predicates and prerequisite conjunctions. No universal domain-to-synthesis conjunction is added.
- D18 retains its later learned-input consumer scope; feedback does not become a whole-Node implementation schedule.

The accepted partial relationships can be read as:

```text
D01/D20 -> existing DTM input -> D10 country-consuming decision
D02     -> accepted Intent input -> D09 intent-dependent decision
D04/D06/D07 -> retained/qualified evidence -> D08 expenditure-consuming decision
  D05 limits actual live-read claims; accepted-test evidence is not live proof

coherent decision/evidence -> D12 bounded action
applicable action semantics -> D13 mode/approval enforcement
approved mode/guardrails + bounded action -> D14 advertiser effect
identified action + observation contract -> D15 post-action association
observations + applicable criteria -> D16 verification
traceable action/result evidence -> D17 learning -> D18 later learned-input consumer
```

This view summarizes the map's predicates; it adds no edge. D11's outcome-dependent conjunction also applies where the verification claim consumes it. D19 remains the separate commercial predicate. N12 native retention and N12 post-action association remain distinct portions of one Node.

There is no accepted Targeting → Spend or Spend → Targeting edge. Country and Intent declaration branches share Foundation but do not order each other. Commercial/advertiser geography meanings remain separate. N15 has no concrete accepted D-ID. G05, G03, M01, M02 and M03 constrain different claims; no precedence between them is established by material severity or diagram adjacency. Post-action prerequisites do not become prerequisites for existing native history.

Consequently, neither an “earliest numbered missing Node” nor “first material finding” is a valid path derivation. Even after the bounded work/claim contract is established, applicable conjunctions can leave multiple minimal unsatisfied conditions. Expose those conditions rather than impose a chronological winner.

## 4. Accepted first-Core sequencing envelope

Canonical governance already establishes Targeting + Spend as the minimum initial Decision Domains expected to demonstrate the currently intended first Core slice. This supplies a product direction for eligibility analysis. It establishes neither Targeting-before-Spend nor Spend-before-Targeting, separate modules/Slices, whole-Domain implementation, a permanent taxonomy, or a new domain-to-synthesis D-edge.

Use the smallest joint advertiser decision that preserves declared context/Intent meaning and qualified shared evidence. Missing N06/N07/N08 consumer behavior is work a candidate would need to establish, not proof that all capability implementation must precede eligibility analysis. Accepted Current Intent and SCR-01 closures satisfy their bounded input contracts; parent PARTIAL status does not reopen them.

## 5. Eligible candidate Product-Slice direction

**One direction is supported at the available evidence level.** The records support reuse of declared countries, optional confirmed Intent and qualified retained expenditure evidence. They do not establish country-level performance comparability, optimal allocation, business-outcome measurement, a new budget declaration contract, or an independently proven source-free Targeting + Spend decision. Splitting this direction into separate Targeting and Spend candidates would not demonstrate the joint envelope. Additional alternatives would require assumptions not supported here.

### Joint Targeting + Spend recommendation from qualified retained evidence

| Required field | Bounded candidate direction |
| --- | --- |
| CANDIDATE_DIRECTION | Joint Targeting + Spend recommendation from qualified retained evidence; descriptive name only, no Slice ID |
| ADVERTISER_QUESTION | Within my declared customer-country scope and optional confirmed Intent, what coherent targeting-and-spend decision is justified by the qualified evidence available, and which parts must remain undecided? |
| DECISION_DELTA | A coherent recommendation about targeting scope and spend direction, with reasons and explicit evidence limits, rather than separate declarations and expenditure interpretation. The completion claim must include a substantive supported joint decision in a bounded admissible case; merely restating facts or always abstaining is insufficient. No particular recommendation rule, amount, country ranking or claimed optimum is established here |
| GOVERNANCE_FIT | Demonstrates actual bounded Targeting and Spend reasoning together inside the accepted first-Core envelope, without implementing whole Domains or advertiser execution |
| ACTUAL_CONSUMER_OR_DECISION | A bounded advertiser-facing recommendation consuming the actual declared scope/optional Intent and authorized qualified evidence. It is a proposed consumer direction, not an existing implemented consumer or broad future engine |
| TARGETING_CONTRIBUTION | Evaluate the permitted decision scope against the advertiser's actual declared countries and legitimate evidence. Do not substitute commercial/provider geography, infer recommended countries from DTM, or claim that declaration proves effectiveness |
| SPEND_CONTRIBUTION | Use qualified expenditure and operation constraints to inform a supported spend decision within that same scope. Respect units, scope, compatibility/additivity and unknown/null/zero distinctions; no unsupported budget, cross-country return ranking or outcome inference |
| SYNTHESIS_REQUIREMENT | Reconcile the targeting and spend conclusions, shared evidence and any applicable explicit Intent into one consistent recommendation. Expose conflicting or insufficient evidence; neither domain independently invents shared facts. This bounded coherence requirement does not add a universal Targeting + Spend dependency edge |
| CURRENT_REUSABLE_INPUTS | N02 Business Context and technically complete DTM declaration, neutral country identity contract D20; closed optional Current Intent/Desired Outcome Meaning through N03; closed SCR-01 qualified interpretation; authorized immutable Observation/history contracts D04/D06/D07. Source/data instance availability and suitability must be checked: historical tests do not prove current usable advertiser data or live provider operation |
| MISSING_CAPABILITY_REQUIRED | The bounded N06/N07 reasoning consumer and its N08 coherence behavior, including access to authorized retained evidence through an actual consumer. No implemented domain/snapshot caller is claimed. Necessary candidate-specific decision semantics and proof remain next-stage work under subsequent authority; not an implementation plan here |
| APPLICABLE_UPSTREAM_DEPENDENCIES | D10 for the country-consuming decision; D08 for expenditure-consuming reasoning; D09 only when the decision is Intent-dependent; D04/D06/D07 for protected qualified retained evidence, provenance and SCR consumption according to their accepted contracts; D20 preserves the neutral valid country identity contract supporting DTM declaration. D01 and D02 are not applicable dependencies for read-only consumption of existing declarations. D01 applies only if the later selected scope mutates N02 Business / Decision Context declarations under its authority contract; D02 applies only if it mutates N03 Current Intent under its writer/revision/authority contract. G06 limits future lifecycle/surface assertions; G07 records missing candidate capability. A live-read extension would additionally require D03/D05/G02/M02 and applicable runtime assurance, and is excluded from this bounded eligibility claim |
| NON_BLOCKING_OPEN_ITEMS | G01 raw/current-runtime limits, G02/M02 live activation, whole-parent G03, deployed-state G04/M03, G05 DTM Owner closure and M01 physical assurance are not prerequisites to selecting this bounded retained-evidence direction. D11/D16 outcome verification, D12–D15/D17 action/effect/result/learning, D18 learned-input reasoning and D19 commercial effects are outside its recommendation-only claim. G06/G07 are not treated as requirements to complete whole Nodes before candidate work |
| SOURCE_DEPENDENCY | REQUIRED_FOR_THIS_DECISION: the evidence-grounded spend decision requires legitimate qualified expenditure evidence. This does not mean a current live connection or every provider must be activated; authorized suitable retained evidence can satisfy the bounded input |
| PRE_CONNECTION_CAPABLE | NO for an advertiser with no legitimate expenditure evidence before any source acquisition: the accepted records do not prove this joint recommendation source-free. An authorized retained-evidence case may operate without a new/current OAuth connection, subject to current read authority; that is not a universal before-first-connection claim |
| ADVERTISER_BUSINESS_VALUE | One traceable targeting/spend decision within the advertiser's actual scope, reducing contradictory domain advice and unsupported spend conclusions. No achieved outcome or optimal allocation is promised |
| REUSABLE_CUMULATIVE_VALUE | An actual shared-evidence consumer, explicit declared-scope/Intent handling, bounded cross-domain coherence and honest recommendation limits that later Core decisions and action contracts can reuse within their scope |
| REWORK_RISK | MEDIUM descriptively: data suitability, consumer decision semantics and cross-domain coherence are not implemented/accepted yet. Reuse of closed input ownership/contracts reduces duplicated-fact rework; unsupported optimization, country-performance assumptions or inferred Intent would materially increase it and are excluded |
| END_TO_END_VERIFIABILITY | Under later bounded authorization, demonstrate an actual advertiser decision delta on admissible authorized evidence, jointly coherent Targeting/Spend reasoning, traceable provenance, current read denial/revocation, applicable Intent and DTM boundaries, compatibility/unknown handling and safe withholding where evidence is insufficient. Prove the required visible surface, focused behavior/integration/regression and independent review/Owner closure as applicable. Fixtures alone do not establish live performance or business success |
| SURFACE_EXPECTATION | A bounded advertiser-visible recommendation and its reasons/evidence limits are required for the stated value. No page, component, API, whole-parent surface redesign or UI architecture is specified |
| WHY_ELIGIBLE_NOW | The accepted joint first-Core direction and closed qualified input contracts permit a coherent proposed consumer direction; no universal unsatisfied upstream gate is established before Owner selection and applicable next-stage authorization. Eligibility does not certify existing data suitability, semantic completion or implementation readiness |
| WHY_NOT_SELECTED_YET | Historical eligibility finding: the direction was unselected at derivation time. Owner has now selected it with Semantic Design authority only, as recorded in §10; eligibility alone did not grant authority |

## 6. Candidate-specific blockers and dependencies

| Blocker test | Evidence-based result |
| --- | --- |
| A. Upstream unsatisfied gate that must close before candidate work | None established universally before Owner selection/appropriate next-stage work. Reuse closed SCR-01/Intent and existing DTM technical input; establish the bounded missing consumer under subsequent authority. No whole-Domain completion requirement is added |
| B. Material safety/rework risk if bypassed | Current read authority, exact provenance, qualified expenditure constraints, real declared countries and explicit optional Intent cannot be bypassed in the eventual decision. D04/D07/D08/D09/D10 remain applicable invariants. The direction cannot produce a supported recommendation from unsuitable or unavailable evidence |
| C. Assurance applicability | G02/M02/D05 apply if the scope adds real live reads; G04/M03 apply to stronger deployed-operation claims; G03/M01 apply to their exact required surface/physical claims. None is a blanket prerequisite to candidate selection. Required bounded recommendation surface assurance remains a completion obligation; no assurance is waived |
| D. Closed Slice input satisfaction | Current Intent = COMPLETE/LANDED/CLOSED; SCR-01 = COMPLETE/LANDED/CLOSED. DTM = COMPLETE/LANDED with Slice closure OPEN. G05 does not invalidate D10 technical input or close DTM by implication |
| E. Is live activation necessary? | Not for the proposed retained-evidence scope when suitable authorized evidence exists. Source evidence is required; current/new OAuth is not universally required. Without suitable retained evidence, the recommendation must be withheld; live acquisition would require its own admission/authority/proof and cannot be silently included |

There is a **candidate-local evidence condition**, not a universal controlling gate: a substantive evidence-grounded decision needs suitable authorized expenditure evidence and enough legitimate facts to justify the joint conclusion. Availability is not freshly proven here. It must be tested in the applicable next authorized lifecycle stage; if evidence cannot support a substantive decision within the bounded direction, revise/withhold that claim and return the specific input limitation. Do not fabricate outcome or country-performance evidence to preserve eligibility.

G06/G07 identify the consumer/surface/behavior that the candidate would establish; they are not prerequisites to implement those same capabilities before eligibility. The distinction is eligibility for Owner selection and the next required stage, versus permission to ship or assert a completed capability. After Owner selection, the selected bounded direction receives explicit active-work authority and enters the earliest governed lifecycle gate actually required by its unresolved facts. Discovery is required only if unresolved factual questions require it. For this candidate, decision semantics and the bounded Targeting/Spend/N08 coherence contract are not yet established: Semantic Design is required before Implementation Plan if Owner selects it. After accepted Semantic Design, Implementation Plan follows as required. Implementation begins only after required semantics/data/authority boundaries are resolved, the applicable Implementation Plan is accepted, and explicit implementation authority is granted. This candidate-specific requirement does not create a universal fixed lifecycle waterfall for future Product Slices.

## 7. Candidate comparison

Only one evidence-supported coherent direction is enumerated, so no artificial two-candidate ranking is created. A source-free targeting/spend recommendation is not added merely because Market governance permits useful pre-connection value within its own scope. A live-provider optimization direction is not separately enumerated without accepted decision/evidence semantics; it would add D05/G02/M02 obligations and unsupported optimization breadth.

| Comparison dimension | Supported direction and practical tradeoff |
| --- | --- |
| Advertiser/business value | A coherent joint decision with traceable reasons; actual performance improvement remains unproven |
| Dependency burden | Reuse DTM/Intent/SCR inputs and protected history; honor consumer-specific D08–D10. No live-activation blanket gate |
| Existing landed reuse | Closed Intent/SCR, DTM technical contract and accepted acquisition/history boundaries |
| Pre-connection value / provider dependence | Requires legitimate source expenditure evidence; cannot promise before-first-acquisition value. Suitable retained evidence can avoid new live connection |
| Implementation breadth | One bounded joint decision consumer and advertiser surface; not whole Domains, execution, verification or learning |
| Rework risk | MEDIUM pending bounded decision semantics and evidence-suitability validation; do not invent scope/ownership or arithmetic |
| End-to-end verifiability | Actual joint decision delta plus authority/provenance/semantic/surface proof; no live/outcome claim from fixtures |
| Cumulative Core value | Actual shared-evidence consumption and coherent domain decision that later bounded action work can reuse |

BEST_SUPPORTED_OWNER_CANDIDATE = Joint Targeting + Spend recommendation from qualified retained evidence. This is the sole supported direction for Owner consideration, not evidence that it outperforms unexamined alternatives or an automatic selection. The tradeoff is concrete reuse and bounded evidence handling versus lack of proven source-free value and unverified actual input suitability.

## 8. Final derivation outcome and preservation

**CASE C — an eligible coherent Product-Slice direction exists.** No universal controlling unsatisfied blocker has been established before bounded first-Core selection/next-stage work. The accepted Targeting + Spend sequencing envelope supplies the direction; Owner preselection is not required before eligibility. One candidate is enumerated without adding a Slice ID, product semantics specification, dependency or automatic implementation authority.

UNIQUE_CRITICAL_PATH_ESTABLISHED = NO. CASE A is unsupported because no single open gate is mandatory before all bounded first-Core candidate work. CASE B is unsupported because independent open conditions are not demonstrated jointly controlling this bounded recommendation envelope. CASE D is no longer supported: the accepted first-Core decision and landed input facts are sufficient to enumerate the direction, even though its detailed decision semantics and data suitability remain unproven.

The source documents remain unchanged. N01–N16 identities/classifications/dispositions/closures, three Slice identities/parent links, D01–D20 and their reporting, G01–G07, M01–M03 and source/pre-connection distinctions remain preserved. Current Intent/SCR-01 stay closed, DTM closure stays OPEN, and accepted SCR-01 advancement/Critical Path qualifications are not reopened. No current runtime, release readiness, optimal targeting/allocation or achieved business outcome is established.

Verification remains documentary/Git only: pinned HEAD/origin/main, unchanged tracked/staged content, map blob identical to HEAD/main, only this existing candidate document in worktree status, whitespace/local-source-link checks and preservation of the existing ledger rows. No product suites, build, migration, audit, browser helper, server or watcher is started.

## 9. Decision ledger

| Field | Supported sequencing conclusion |
| --- | --- |
| WHY_THIS_NOW | The accepted first-Core Targeting + Spend direction and reusable closed input contracts support completing eligibility before Owner selection |
| WHY_NOT_OTHER_ELIGIBLE_WORK | No other direction is enumerated without introducing unsupported source-free, optimization or whole-Domain assumptions. Open provider/assurance/Market work is not automatically first |
| WHAT_DEPENDENCY_CONTROLS_THE_ORDER | Applicable protected evidence/declared-input contracts must precede their consuming decision; D08–D10 do not order Targeting against Spend. Live/effect dependencies control only any separately expanded claims |
| WHAT_EVIDENCE_SUPPORTS_THE_DECISION | Canonical first-Core sequencing decision, Control Model §8, accepted map D/G/M ledger and closed Intent/SCR with DTM technical truth |
| WHAT_NEW_EVIDENCE_WOULD_CHANGE_THE_ORDER | Evidence that no suitable authorized retained evidence can support the substantive joint decision; a required live input with unsatisfied admission/authority; a concrete semantic/authority contradiction or applicable material assurance requirement. Owner selection establishes scope and next-stage authority, not retroactive eligibility |

## 10. Owner handoff

Owner selects **Joint Targeting + Spend recommendation from qualified retained evidence**. CURRENT_CRITICAL_PATH_SELECTED = YES means only that the Case C sequencing decision has selected the next coherent Product-Slice direction; it does not establish a unique topological blocker. NEXT_PRODUCT_SLICE_SELECTED = YES applies to this bounded direction, with no permanent Slice ID assigned. The accepted derivation, candidate meaning and blocker ledger remain unchanged.

### Selected direction authority and foundational platform rule

After selection, explicit active-work authority is **SEMANTIC_DESIGN_ONLY**. Discovery is authorized only as a bounded supporting step if a specific unresolved fact blocks Semantic Design. Decision semantics and the bounded N06/N07/N08 coherence contract remain unresolved, so accepted Semantic Design is required before the applicable Implementation Plan. Neither Implementation Plan nor implementation is authorized by this selection. Implementation requires resolved semantics/data/authority boundaries, acceptance of the applicable plan and a separate explicit implementation authorization. No design work starts in this landing task.

The Owner requires foundational platform semantic invariants to be correct, explicit and durable from the first Product Slice. The selected Slice must not create:

- duplicated semantic ownership or duplicated Context / Intent / Shared Reality inside Decision Domains;
- provider-owned Builderad product semantics or first-Slice-specific permanent platform contracts;
- Targeting defined merely as countries or Spend defined merely as expenditure metrics;
- a monolithic recommendation service owning unrelated responsibilities;
- N06/N07 contracts incompatible with N08 synthesis;
- temporary decision semantics intended to be replaced later or known throwaway architecture;
- collapsed UNKNOWN / PARTIAL / FAILED / INSUFFICIENT states;
- implicit Action or Execution authority;
- speculative generic infrastructure without accepted meaning or a consumer.

The next Semantic Design must establish durable platform-level boundaries for:

- canonical semantic ownership;
- Fact vs Evidence vs Interpretation vs Domain Decision vs Synthesized Decision vs Action vs Outcome;
- evidence provenance and lineage; decision identity and lineage;
- uncertainty, insufficiency and withholding semantics;
- N06 Targeting, N07 Spend and N08 cross-domain synthesis responsibilities;
- provider-independent product semantics and extension/versioning boundaries;
- compatibility with future additional Decision Domains and Market Evidence;
- compatibility with future Action / Execution and Observation / Verification / Learning.

These requirements do not authorize designing every future domain or feature. Future domain details must not be invented before their meaning, consumer and evidence are established. These are requirements for the next authorized design stage, not a Semantic Design or Implementation Plan produced here.

```text
CURRENT_STATE = CURRENT_CRITICAL_PATH_DERIVATION_OWNER_ACCEPTED_FOR_AUTHORIZED_LANDING
OWNER_FINAL_ACCEPTANCE = GRANTED
OWNER_SELECTION = GRANTED
OWNER_SELECTED_DIRECTION = Joint Targeting + Spend recommendation from qualified retained evidence
NEXT_PRODUCT_SLICE_DIRECTION_SELECTED = JOINT_TARGETING_SPEND_RECOMMENDATION_FROM_QUALIFIED_RETAINED_EVIDENCE
NEXT_AUTHORIZED_PRODUCT_STAGE = SEMANTIC_DESIGN
ACTIVE_PRODUCT_WORK_AUTHORITY = SEMANTIC_DESIGN_ONLY
SEMANTIC_DESIGN_AUTHORIZED = YES
DISCOVERY_AUTHORIZED = ONLY_AS_A_BOUNDED_SUPPORTING_STEP_IF_A_SPECIFIC_UNRESOLVED_FACT_BLOCKS_SEMANTIC_DESIGN
IMPLEMENTATION_PLAN_AUTHORIZED = NO
IMPLEMENTATION_AUTHORIZED = NO
IMPLEMENTATION_AUTHORITY = NO
AUTHORITATIVE_BASE = 2aedd0d5c8637b8cc2b015f23ec2597826296685
PROGRESS_OBJECTIVE = SAFE_AUTHORIZATION_OF_NEXT_COHERENT_PRODUCT_VALUE_WORK
ACCEPTED_PRODUCT_SEQUENCING_ENVELOPE = FIRST_CORE_SLICE_WITH_TARGETING_AND_SPEND_MINIMUM_INITIAL_DOMAINS
OWNER_PRESELECTION_REQUIRED_BEFORE_ELIGIBILITY = NO
CANDIDATE_BLOCKERS_EVALUATED = D01-D20; G01-G07; M01-M03
DERIVATION_RESULT = CASE_C
APPLICABLE_CONTROLLING_BLOCKERS = NONE_UNIVERSAL_BEFORE_BOUNDED_SELECTION_NEXT_STAGE;
  CANDIDATE_LOCAL_INPUT_AUTHORITY_AND_SUITABILITY_CONDITIONS_RETAINED_IN_SECTION_6
UNIQUE_CRITICAL_PATH_ESTABLISHED = NO
ELIGIBLE_PRODUCT_SLICE_DIRECTION_COUNT = 1
ELIGIBLE_PRODUCT_SLICE_DIRECTIONS = Joint Targeting + Spend recommendation from qualified retained evidence
CANDIDATE_DETAILS = SECTION_5_REQUIRED_FIELD_LEDGER
F1_D01_D02_READ_SCOPE_CORRECTED = YES
D01_APPLIES_ONLY_TO_N02_MUTATION = YES
D02_APPLIES_ONLY_TO_N03_MUTATION = YES
F2_POST_SELECTION_LIFECYCLE_CORRECTED = YES
DISCOVERY_REQUIRED_ONLY_IF_NEEDED = YES
SEMANTIC_DESIGN_REQUIRED_FOR_THIS_CANDIDATE_BEFORE_IMPLEMENTATION_PLAN = YES
IMPLEMENTATION_REQUIRES_SEPARATE_AUTHORITY = YES
CASE_C_CHANGED = NO
CANDIDATE_CHANGED = NO
BLOCKER_LEDGER_REBUILT = NO
BEST_SUPPORTED_OWNER_CANDIDATE = Joint Targeting + Spend recommendation from qualified retained evidence
OWNER_SEQUENCING_DECISION_REQUIRED = NO (direction selection granted)
WHY_THIS_NOW = ACCEPTED_FIRST_CORE_ENVELOPE_AND_REUSABLE_CLOSED_INPUTS
WHY_NOT_OTHER_ELIGIBLE_WORK = NO_ARBITRARY_ALTERNATIVES_OR_AUTOMATIC_OPEN_ITEM_PRIORITY
WHAT_DEPENDENCY_CONTROLS_THE_ORDER = APPLICABLE_INPUT_AUTHORITY_PROVENANCE_AND_D08_D10_PREDICATES;
  NO_TARGETING_BEFORE_SPEND_OR_SPEND_BEFORE_TARGETING_EDGE
WHAT_EVIDENCE_SUPPORTS_THE_DECISION = CANONICAL_FIRST_CORE_DECISION; CONTROL_MODEL_SECTION_8;
  ACCEPTED_MAP_AND_PRESERVED_D_G_M_LEDGER
WHAT_NEW_EVIDENCE_WOULD_CHANGE_THE_ORDER = UNSUITABLE_OR_UNAVAILABLE_REQUIRED_INPUT;
  ACTUALLY_REQUIRED_LIVE_AUTHORITY_OR_MATERIAL_ASSURANCE; CONCRETE_SEMANTIC_CONTRADICTION
CURRENT_CRITICAL_PATH_SELECTED = YES (bounded Case C direction-selection sense only)
NEXT_PRODUCT_SLICE_SELECTED = YES (selected bounded direction only)
PRODUCT_IMPLEMENTATION_STARTED = NO
MASTER_EXECUTION_MAP_CHANGED = NO
MASTER_EXECUTION_MAP_MATCHES_HEAD = YES
MAP_RECONSTRUCTION_REOPENED = NO
NODE_CLASSIFICATIONS_CHANGED = NO
SLICE_CLASSIFICATIONS_CHANGED = NO
DEPENDENCY_EDGE_SET_CHANGED = NO
DEPENDENCY_REPORTING_CHANGED = NO
G01_G07_CHANGED = NO
M01_M03_CHANGED = NO
SCR_01_CHANGED = NO
CURRENT_INTENT_CHANGED = NO
DTM_CLOSURE_CHANGED = NO
NEW_PRODUCT_SLICE_ID_CREATED = NO
FILES_CHANGED = docs/BUILDERAD_CURRENT_CRITICAL_PATH_DERIVATION.md only
GIT_DIFF_CHECK = PASS
SOURCE_CONTROL_LANDING_AUTHORIZED = YES
MERGE_CONDITION = ALL_APPLICABLE_REQUIRED_CHECKS_PASS_AND_ONLY_AUTHORIZED_DOCUMENT_CHANGED
LANDING_PROVENANCE = ESTABLISHED_EXTERNALLY_FROM_GIT_PR_CI_AND_AUTHORITATIVE_MAIN
MATERIAL_CONCERNS = NONE_ESTABLISHED_IN_THIS_DOCUMENTARY_CORRECTION;
  ACTUAL_DATA_SUITABILITY_AND_DECISION_SEMANTICS_ARE_NOT_COMPLETION_PROOF
READY_FOR_SEMANTIC_DESIGN_WORKTREE = YES (after verified landing; worktree not created here)
STOP = RETURN TO OWNER AFTER VERIFIED LANDING
```
