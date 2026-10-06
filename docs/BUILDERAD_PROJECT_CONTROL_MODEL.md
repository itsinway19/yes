# Builderad — Project Control Model v1

Prepared 2026-10-06 (Europe/Istanbul). **Status: OWNER_ACCEPTED_AND_LANDED.** Final Owner acceptance has been granted. Original accepted-model landing: PR #17, merge `ca49cec88ea03004ead4ff2fa148dc32902db1df` (historical provenance). This document remains an operational control contract under [canonical Builderad governance](BUILDERAD_CORE_EXECUTION_GOVERNANCE.md), not product governance, implementation authorization by itself, or Current System Reconstruction.

## 1. Baseline and document-overlap gate

| Check | Verified result |
| --- | --- |
| Repository | `42Huss/builderad` |
| `LOCAL_MAIN`, fetched `ORIGIN_MAIN`, `WORKTREE_HEAD` | Each is `58e021d88a0012890de9e8d26f2b70d89f41f8d1` |
| `WORKTREE`, `WORKTREE_BRANCH` | `C:\dev\builderad-worktrees\project-control-model-v1`; `codex/project-control-model-v1` |
| Initial `GIT_STATUS` | Clean; existing isolated worktree and branch reused, no unique work found |
| PR #16 | Git merge parents and [GitHub PR](https://github.com/42Huss/builderad/pull/16) confirm MERGED; final head `f83d36933557e06bd80c9c343af3aa1845de617d`, merge `58e021d88a0012890de9e8d26f2b70d89f41f8d1` |
| Foundation CI | [Run 37453087884](https://github.com/42Huss/builderad/actions/runs/37453087884), Foundation SUCCESS on that final PR head; log records PHP 792 passed / 9793 assertions and JavaScript 135 passed, zero failed/cancelled/skipped. Historical proof inspected, no suites rerun |
| Owner acceptance / security-blocker closure | Owner-supplied accepted starting facts: acceptance GRANTED, SCR-01 LANDED_AND_CLOSED, CI_SECURITY_BLOCKER CLOSED. Merge and successful CI independently corroborate landing and the passing CI gate; they do not independently prove the Owner's acceptance statement or every security property |
| `EXISTING_CONTROL_MODEL` | PARTIAL: scoped state/proof/closure fragments exist, no equivalent authoritative project-wide control contract found |
| `OVERLAPPING_DOCUMENTS` | No equivalent doctrine. Related fragments: canonical governance; [Foundation checkpoint](FOUNDATION_CHECKPOINT.md); [Engineering Remediation handoff](ENGINEERING_REMEDIATION_001_HANDOFF.md); [BB2 handoff](BB2_OWNER_HANDOFF.md); accepted Current Intent and SCR discovery/design/plan artifacts |
| `CONTRADICTIONS` | No material contradiction with this control contract in the inspected boundaries. Historical claims retain their original baseline/scope; superseded Intent discovery ownership is resolved by its accepted semantic design, not rewritten here |
| `DOCUMENT_OVERLAP_CHECK` | PASS: documentation terminology search and relevant fragment inspection found no competing equivalent. External `GOVERNING_EXECUTION_CONTRACT.md` is a bounded release-execution proposal marked READY FOR OWNER ACCEPTANCE, not a replacement control model |

The inspection is limited to authority, terminology, overlap and the SCR-01 example. It makes no repository-wide completeness, dependency inventory, runtime deployment or release-readiness claim. If an equivalent authoritative model is discovered later, stop and resolve ownership with the Owner before establishing competing doctrine.

## 2. Authority and evidence boundaries

PROJECT CONTROL MODEL IS NOT PRODUCT GOVERNANCE. Canonical governance remains highest authority for product meaning/direction, semantic responsibilities, authority semantics, accepted architecture constraints and product commitments. Explicit accepted Owner decisions and semantic contracts are read under it; a material unresolved contradiction requires Owner resolution.

| Layer | Question | Authoritative input and limit |
| --- | --- | --- |
| CANONICAL GOVERNING AUTHORITY | What must Builderad mean/become? | Canonical governance, explicit accepted Owner decisions, authoritative semantic contracts. A future requirement is not present implementation |
| LANDED SYSTEM TRUTH | What exists in authoritative main now? | Exact accepted content verified on main; database/configuration/deployment proof where the claim requires it. A merged source file alone does not prove runtime deployment |
| ACTIVE-WORK AUTHORITY | What may current work become? | Owner-accepted Slice contract, Semantic Design, Implementation Plan and explicit implementation authorization within their scope |
| CURRENT-WORK EVIDENCE | What was observed/proven about that work? | Worktree delta, focused/runtime/integration/regression/surface evidence, diagnostics, independent review and CI, each tied to subject and baseline |

`GOVERNED_REQUIREMENT != LANDED_CAPABILITY`. `CANONICAL_GOVERNING_AUTHORITY != LANDED_SYSTEM_TRUTH`; `ACTIVE_WORK_AUTHORITY != LANDED_SYSTEM_TRUTH`; `CURRENT_WORK_EVIDENCE != LANDED_SYSTEM_TRUTH`. These layers remain distinct before and after landing. After exact accepted content is proven landed, prior current-work evidence may support a claim about landed system truth, but CURRENT_WORK_EVIDENCE does not become LANDED_SYSTEM_TRUTH. Landing changes the authoritative system state; it does not collapse authority, evidence and truth into one layer. Keep authorization and observation provenance separate even when one document contains both.

This contract organizes state, evidence, lifecycle, dependencies, consumers, closure and sequencing. It creates no product semantics, semantic ownership, Decision Domains, provider obligations, implementation authority, product architecture, application enums/schema or roadmap commitments.

## 3. Product navigation and bounded units

The governed spine is navigation/context:

Business / Decision Context → Current Intent → Canonical Outcome Meaning → Shared Canonical Reality → Decision Domains → Decision Synthesis → Action / Execution Contract → Execution Authority → Execution → Observation → Verification → Learning → Better Next Decision.

It does not imply one module/service/table per concept, a waterfall, mandatory implementation order or automatic feature selection. **Canonical Outcome Meaning remains a governed semantic responsibility. For current bounded control purposes, Desired Outcome Meaning is represented through the accepted [Current Intent contract](superpowers/specs/2026-10-04-current-goal-intent-design.md).** Reference that responsibility through the contract without inferring a standalone Node, module, service, engine, entity, catalog or domain. Representation does not collapse its distinct semantic responsibility into another owner. Canonical governance is unchanged.

Targeting and Spend remain broad governed Decision Domains/reasoning spaces, not automatically pages, tabs, campaign modules, provider objects or a permanent taxonomy. Refer to canonical Core §§4–5 for their doctrine; neither is automatically next because of spine position.

**Node:** a durable governed system/product responsibility, with a precisely bounded capability claim. Examples include Shared Canonical Reality, Targeting, Spend, Decision Synthesis and Execution Authority; examples do not create or select work. Tests, documents, bugs, findings, fields, dependencies and ideas do not automatically become Nodes.

**Product Slice:** a bounded coherent unit of advertiser/business decision value, potentially using parts of multiple Nodes. SCR-01 is one bounded advertising-evidence Slice under Shared Canonical Reality. `SLICE_CLOSED != CAPABILITY_CLOSED`; `NODE_PARTIAL` does not imply no product value. A useful Slice need not complete an entire future Domain.

## 4. State, structural condition and closure

These three state axes are independent:

| Field | Allowed values exactly | Meaning |
| --- | --- | --- |
| `SYSTEM_STATE` | MISSING / PARTIAL / PRESENT_BUT_UNVERIFIED / COMPLETE | What exists / how complete is the bounded capability claim? MISSING: no implementation of that claim established. PARTIAL: evidenced portions exist but the whole claim is not satisfied. PRESENT_BUT_UNVERIFIED: relevant implementation exists but adequate behavioral proof is absent. COMPLETE: evidence satisfies the exact bounded claim and required applicable surfaces/invariants |
| `LIFECYCLE_STATE` | NOT_STARTED / DISCOVERY / SEMANTIC_DESIGN / IMPLEMENTATION_PLAN / IMPLEMENTATION / INDEPENDENT_REVIEW / VERIFICATION / OWNER_ACCEPTANCE / LANDED | Where is this specific work item in delivery? Record its current governed gate, not presumed completeness |
| `EXECUTION_DISPOSITION` | ACTIVE / BLOCKED / DEFERRED / NOT_SELECTED | Is this work currently being advanced? BLOCKED requires an identified blocker; DEFERRED an explicit deferral reason; NOT_SELECTED means no current selection, including settled work with no active delta |

Define separately `STRUCTURAL_CONDITION = NORMAL / DUPLICATED_OR_OVERLAPPING`. It answers: **Do multiple implementations, authorities, contracts or ownerships materially overlap?** NORMAL means no material overlap established within the assessed scope; it is not universal architectural assurance. DUPLICATED_OR_OVERLAPPING identifies the actual overlapping boundaries and evidence. This is independent of completeness, lifecycle and disposition; it is not a new control subsystem or an additional delivery state axis.

Both `SYSTEM_STATE = COMPLETE; STRUCTURAL_CONDITION = DUPLICATED_OR_OVERLAPPING` and `SYSTEM_STATE = PARTIAL; STRUCTURAL_CONDITION = NORMAL` are valid. Overlap may coexist with a fulfilled bounded claim; if it creates a material contradiction in that claim, the closure-quality rule applies. Code existence alone never justifies COMPLETE. `SYSTEM_STATE = PARTIAL; LIFECYCLE_STATE = LANDED` is valid.

Every state is scoped to a subject, claim and verification baseline. Do not invent classifications from missing evidence: record the missing evidence in the record, obtain the smallest necessary proof, and withhold stronger claims. Where some portions are proven and others missing/unverified, use PARTIAL and enumerate those portions; use PRESENT_BUT_UNVERIFIED when source presence is the supported claim without sufficient behavioral proof.

**Child Slice owns its own lifecycle, active delta, evidence, landed baseline and closure.** A landed parent does not move to IMPLEMENTATION merely because a child starts. Parent `LANDED_BASELINE`, `ACTIVE_CHILD_SLICES` and `ACTIVE_DELTA_IF_ANY` distinguish settled parent truth from authorized child work; a child may span multiple parents without duplicating its canonical record.

Closure claims are separate subject-specific fields, each allowing `OPEN / CLOSED / NOT_APPLICABLE`:

- Node owns `CAPABILITY_CLOSURE` and `REQUIRED_SURFACES_CLOSURE` for its bounded Node claim.
- Product Slice owns `SLICE_CLOSURE` and `REQUIRED_SURFACES_CLOSURE` for its bounded Slice contract. Parent records link child IDs, never duplicate child Slice closure semantics.
- CLOSED requires adequate exact-scope proof and the applicable acceptance/landing gates. Before landing, a complete accepted implementation can still have SLICE_CLOSURE OPEN. NOT_APPLICABLE requires a legitimate absence of that closure subject, not an escape from missing proof. Never use bare CLOSED.

A future Master Execution Map may summarize linked child states; the canonical child closure claim remains on the Slice record. No map is created by this task.

`RELEASE_READINESS = NOT_ASSESSED / NOT_READY / READY` belongs only to an applicable aggregate release/system assessment. It is not Node completeness, Slice lifecycle or a closure synonym, and is not required on every record. Use READY only when the actual aggregate release obligations are proven; bounded Slice closure alone does not establish it.

## 5. Evidence and change impact

`CONTROL_EVIDENCE_TYPES` is a set of non-linear classifications, not a maturity ladder and not Builderad's product epistemic states:

| Type | What it proves |
| --- | --- |
| DECLARED | An explicit governed or accepted statement. It proves only that the statement is governed/accepted |
| SOURCE_PRESENT | Relevant source/config/schema/document materially exists at the identified baseline |
| FOCUSED_BEHAVIOR_PROVEN | Direct deterministic/functional proof of bounded behavior |
| INTEGRATION_PROVEN | Behavior proven with required surrounding systems |
| REGRESSION_PROVEN | Required broader regression gate passed at the identified baseline |
| SURFACE_PROVEN | Required user/admin/support surface functionally verified and, where visible, actually inspected |
| INDEPENDENTLY_REVIEWED | Independent bounded review completed with no unresolved material blocker |
| OWNER_ACCEPTED | Explicit final Owner acceptance for the bounded subject |
| LANDED_PROVEN | Exact accepted content verified in authoritative main |

DECLARED does **not** prove implementation, current landed presence, runtime behavior, integration, regression correctness, surface correctness or completeness. `DECLARED != IMPLEMENTED`; `DECLARED != LANDED_PROVEN`; `PROSE_EXISTENCE != IMPLEMENTATION_PROOF`; `IMPLEMENTATION_PRESENT != COMPLETE`.

Multiple types may apply, but one cannot substitute for an unrelated obligation. Every COMPLETE/CLOSED claim identifies evidence adequate for that exact claim: source/command/artifact, result, baseline, subject/scope and limits, with the accepted authority reference. Historical results must retain their baseline; no silent promotion to current proof. Owner acceptance does not substitute for technical proof, and CI does not substitute for Owner acceptance.

Define `CHANGE_IMPACT_STATUS = NOT_APPLICABLE / UNCHANGED_VALID / IMPACT_REVIEW_REQUIRED / REOPENED_BY_CONTRADICTION`:

- NOT_APPLICABLE: no legitimate impact assessment exists for this bounded record. Do not force ceremonial impact classifications.
- UNCHANGED_VALID: a legitimate assessment supports continued validity of the accepted bounded claim.
- IMPACT_REVIEW_REQUIRED: new evidence creates plausible material dependency impact; perform focused impact review without asserting the product is defective.
- REOPENED_BY_CONTRADICTION: evidence establishes an accepted invariant/contract is materially false, violated or invalidated; reopen only the affected claim and closure subject, preserving historical evidence and unaffected claims.

`NEW_INFORMATION != AUTOMATIC_REOPENING`. Later work, adjacent changes, another subsystem's failure, new tests or an investigation alone do not reopen accepted work. A real contradiction downgrades the affected system claim according to evidence; lifecycle and landing history are not erased. Record an authorized repair as a child Slice or an explicit Node delta, with its own current gate.

## 6. Compact records

These are document records, not application/database schemas. Every field below must be addressed where meaningful. Use an explained not-applicable value or omission for legitimately inapplicable facts, never invented owners/proofs. States use their exact allowed values. Evidence, dependencies and child IDs are references, not copied dossiers.

### Node record

| Fields | Required content |
| --- | --- |
| `NODE_ID`, `NAME`, `ROLE_IN_PRODUCT_SPINE` | Stable control identity, governed responsibility and navigation reference |
| `SEMANTIC_OWNER`, `PHYSICAL_OWNER`, `AUTHORITY_OWNER` | Separate accepted owners with authority references; record genuinely unknown/not-applicable ownership explicitly |
| `CURRENT_CAPABILITY_CLAIM` | Exact assessed scope, obligations and exclusions |
| `EVIDENCED_EXISTING_PARTS`, `EVIDENCED_MISSING_OR_UNVERIFIED_PARTS`, `EXPLICITLY_OUT_OF_SCOPE` | What is proven, what remains and what is excluded |
| `UPSTREAM_DEPENDENCIES`, `DOWNSTREAM_CONSUMERS` | Actual prerequisite/consumer edges, governing gate, satisfaction evidence and blocking/rework consequence |
| `SYSTEM_STATE`, `STRUCTURAL_CONDITION`, `LIFECYCLE_STATE`, `EXECUTION_DISPOSITION` | Independent classifications with scope/evidence and blocker/deferral reason where relevant |
| `CONTROL_EVIDENCE_TYPES`, `AUTHORITATIVE_EVIDENCE` | Classification set and exact supporting authority/proof references |
| `LAST_VERIFIED_BASELINE`, `LANDED_BASELINE` | Last assessment baseline and proven authoritative landed content; absent landing is explicit |
| `ACTIVE_CHILD_SLICES`, `ACTIVE_DELTA_IF_ANY` | Child record references and separately scoped authorized Node delta; no inherited child lifecycle/closure |
| `REQUIRED_PROOF`, `OPEN_CRITICAL_FINDINGS`, `OPEN_MATERIAL_FINDINGS`, `OPEN_MINOR_FINDINGS` | Applicable proof gates and findings with evidence/owner/resolution or explicit minor deferral |
| `SURFACE_REQUIREMENT`, `WHAT_IT_UNLOCKS`, `WHAT_IT_DOES_NOT_UNLOCK` | Required surface and bounded consumer effects/limits |
| `CAPABILITY_CLOSURE`, `REQUIRED_SURFACES_CLOSURE`, `CHANGE_IMPACT_STATUS`, `NOTES` | Node-owned closure evidence, legitimate impact assessment and necessary qualifications |

### Product Slice record

| Fields | Required content |
| --- | --- |
| `SLICE_ID`, `SLICE_NAME`, `PARENT_NODE_OR_NODES` | Stable bounded Slice identity and references to responsibilities it uses |
| `USER_OR_BUSINESS_DECISION_SERVED`, `DECISION_DELTA`, `WITHOUT_THIS_SLICE`, `WITH_THIS_SLICE` | Actual consumer and meaningful before/after decision boundary |
| `MINIMUM_REQUIRED_FACTS`, `DEPENDENCIES`, `CONSUMERS`, `AVAILABLE_EVIDENCE` | Minimal accepted facts, actual graph prerequisites/consumers, evidence and limits |
| `SOURCE_DEPENDENCY`, `PRE_CONNECTION_CAPABLE`, `AUTHORITY_REQUIREMENT`, `SURFACE_REQUIREMENT` | Independently record decision-specific evidence-source need and accepted bounded value before the relevant connection; existing authority and applicable surfaces |
| `REWORK_RISK`, `END_TO_END_VERIFIABILITY`, `CURRENT_ELIGIBILITY`, `SELECTION_REASON` | Evidence-backed risk, testable decision-to-result path, satisfied/unsatisfied gates and Owner selection reference when selected |
| `SYSTEM_STATE`, `LIFECYCLE_STATE`, `EXECUTION_DISPOSITION` | Slice's own independent states, not inherited from parent |
| `STRUCTURAL_CONDITION` (conditional) | Include only where the Slice itself has a legitimate structural-overlap condition to assess; omit with a short applicability reason otherwise. Do not force NORMAL or inherit the parent's condition |
| `CONTROL_EVIDENCE_TYPES`, `PROOF_GATE`, `ACCEPTANCE_EVIDENCE` | Slice-owned evidence set, applicable invariant/regression/review gates and explicit acceptance reference |
| `WHAT_THIS_SLICE_CLOSES`, `WHAT_THIS_SLICE_DOES_NOT_CLOSE`, `WHAT_IT_UNLOCKS`, `WHAT_IT_DOES_NOT_UNLOCK` | Exact closure scope and bounded downstream effects/exclusions |
| `SLICE_CLOSURE`, `REQUIRED_SURFACES_CLOSURE` | Canonical child-owned closure claims with proof references |
| `LAST_VERIFIED_BASELINE`, `LANDED_BASELINE`, `CHANGE_IMPACT_STATUS` | Slice's own assessment/landing baselines and legitimate impact status |

The Slice's active delta is described through its accepted contract, existing/missing proof and current worktree evidence; link findings from PROOF_GATE rather than adding another subsystem. Execution, Observation, Verification and Learning participate only where its accepted contract requires them.

## 7. Value, source and surface rules

Every candidate Slice answers: **What meaningful decision changes because this Slice exists?** State WITHOUT/ WITH and the consumer. Preventing waste, protecting productive spend, redirecting targeting/spend/channel/timing, enabling/rejecting a test, preventing unsafe execution, improving verification, reusable verified learning or correctly declining intervention can qualify. Reduced uncertainty qualifies only when it changes a meaningful action/approval/exposure boundary. More charts or a more confident-sounding recommendation do not.

Track value contribution without manufacturing financial proof. Do not fabricate monetary value, equate recommendation with savings or claim causal lift without causal proof. Preserve OBSERVED CHANGE, ESTIMATED EFFECT, INCREMENTAL EFFECT and CAUSALLY DEMONSTRATED EFFECT. Numerically aggregate multiple value claims only when semantically compatible, adequately evidenced and non-overlapping; otherwise keep them separate.

`SOURCE_DEPENDENCY = NONE / OPTIONAL / REQUIRED_FOR_THIS_DECISION` answers how dependent the bounded decision is on the identified external/source evidence:

| Value | Meaning |
| --- | --- |
| NONE | The bounded decision does not depend on the identified external/source evidence. This does not mean external evidence can never be useful elsewhere |
| OPTIONAL | Source evidence may strengthen, personalize, verify, improve or reduce uncertainty around the bounded decision, but is not required for that decision to be validly reached within its accepted contract |
| REQUIRED_FOR_THIS_DECISION | The bounded decision cannot be validly reached without the identified source/evidence prerequisite. Name the exact required evidence; do not generalize this decision-specific requirement into a universal provider dependency |

Define independently `PRE_CONNECTION_CAPABLE = YES / NO / NOT_APPLICABLE`. It answers whether the Slice can provide accepted bounded value before the relevant advertiser/source/account connection exists:

| Value | Meaning |
| --- | --- |
| YES | The accepted evidence/semantic contract establishes useful bounded product behavior before the relevant connection exists. Prove YES from that bounded contract; do not infer it from Builderad's pre-OAuth intention in another governed scope |
| NO | The accepted bounded Slice cannot provide the claimed useful behavior before the relevant required connection exists, based on its actual contract and dependency |
| NOT_APPLICABLE | No meaningful pre-connection versus post-connection distinction exists for this bounded Slice. This is not a substitute for unknown |

Required invariants: `SOURCE_DEPENDENCY != PRE_CONNECTION_CAPABILITY`; OPTIONAL_SOURCE does NOT imply PRE_CONNECTION_CAPABLE; PRE_CONNECTION_CAPABLE does NOT imply `SOURCE_DEPENDENCY = NONE`. A valid combination is `SOURCE_DEPENDENCY = OPTIONAL; PRE_CONNECTION_CAPABLE = YES`: useful bounded value before connection can coexist with optional source evidence that benefits the decision after connection.

These are independent project-control descriptors, not application enums or another state axis. Record what evidence is sufficient and what absence prevents; provider/source connection must not silently become a universal prerequisite for Builderad value. `USEFUL_BEFORE_OAUTH = YES` and `SAME_REASONING_SYSTEM_BEFORE_AND_AFTER_OAUTH = YES` retain their accepted Market Evidence governance scope, not universal scope for every capability. Do not infer a specific Slice's pre-connection capability without accepted evidence/semantic support.

`SURFACE_REQUIREMENT = NONE / ADVERTISER / ADMIN_OPERATOR / SUPPORT_AUDIT / AUTOMATION / MULTIPLE`. Name the actual surfaces for MULTIPLE. No invented UI for infrastructure-only capability. `IMPLEMENTED != SURFACE_CLOSED`. Before REQUIRED_SURFACES_CLOSURE CLOSED, prove behavior, functional verification and required surface existence; inspect actual visible frontend/admin surfaces and check applicable visual, RTL, accessibility and usability obligations. Automation needs its applicable functional/authority proof, not fictional visual inspection. NONE normally gives REQUIRED_SURFACES_CLOSURE NOT_APPLICABLE.

## 8. Critical path, eligibility and decisions

First ask:

> WHAT IS THE EARLIEST UPSTREAM UNSATISFIED GOVERNED GATE OR DEPENDENCY IN THE ACTUAL DEPENDENCY GRAPH THAT EITHER blocks safe progress OR makes downstream work materially susceptible to rework?

**EARLIEST means dependency/topological order.** It does not mean oldest by date, discussion, branch or idea. Start from the actual consumer/decision: what minimum governed dependency enables it safely? Link prerequisite → dependent claim with authority, satisfaction evidence and material blocking/rework reason. Spine proximity, chronology and convenience do not establish an edge. Do not build broad foundations just in case or require an entire Domain when the consumer needs only a governed subset.

An unsatisfied upstream prerequisite controls the path before its dependent work. Among incomparable upstream blockers there is no invented unique chronological winner: expose each, compare safety/rework consequences and preserve the Owner's bounded sequencing decision. A dependency cycle is an unresolved gate/authority problem; resolve the actual boundary before claiming eligibility. This is graph reasoning over existing records, not a graph engine or new framework.

1. Close the earliest relevant unsatisfied mandatory governed gate; resolve dependencies capable of invalidating downstream work in topological order. Neither label permits jumping past an upstream prerequisite.
2. If no such upstream blocker exists, enumerate eligible coherent Product Slices; show governance fit, satisfied dependencies, actual consumer, Decision Delta, advertiser/business value, available evidence, reusable/cumulative value potential, rework risk and end-to-end verifiability.
3. Only then may the Owner select the smallest coherent high-value Slice and grant the applicable next-stage authority. Eligibility is not implementation authorization. Do not automatically select Targeting, Spend, provider integration or the next diagram box.

Every material sequencing decision preserves the following small decision ledger, within the relevant accepted record:

`WHY_THIS_NOW`; `WHY_NOT_OTHER_ELIGIBLE_WORK`; `WHAT_DEPENDENCY_CONTROLS_THE_ORDER`; `WHAT_EVIDENCE_SUPPORTS_THE_DECISION`; `WHAT_NEW_EVIDENCE_WOULD_CHANGE_THE_ORDER`.

No major order change by novelty, recency or intuition alone. This task defines the method; it does not reconstruct the actual graph, enumerate real next candidates or select the next Slice.

Every new execution idea becomes exactly one: NOW (authorized current critical-path work); DEPENDENCY (required for the current Slice to proceed safely); LATER (potentially valuable, not required now); RESEARCH_INPUT (evidence that may change later decisions, no implementation authority); OUT_OF_SCOPE (intentionally excluded). Record a reason; interest never silently enlarges scope.

## 9. Assurance, closure and stopping

The quality floor is non-negotiable: never delete required assurance to save time. Optimize above it through bounded scope, better questions, dependency order, valid evidence reuse, distinguishing experiments, risk-proportionate review and avoiding redundant reruns.

If more evidence does not improve discrimination between competing explanations, **change the method before collecting more of the same evidence**. Use: failure → required invariant → owning layer → minimal competing causes → distinguishing evidence → smallest causal experiment → root cause versus historical trigger → smallest correction → proof → close. Repeated logs/reruns without discrimination do not justify progress or closure.

No bounded Node, Slice or required Surface may close while a known Critical/Material defect, semantic contradiction, authority gap, truth-integrity gap, material architectural inconsistency or required unverified surface remains open. Classify findings against the actual contract, not merely their location inside/outside a patch. A material harness/CI failure can block required assurance without proving product behavior defective. Repair only under its appropriate authorization; do not silently reopen unrelated accepted semantics.

Minor findings may be deferred only with evidence that they cannot materially alter correctness, security/authority, truth integrity, accepted architecture or the accepted product/UX contract. Record the finding, rationale and deferral explicitly.

For the bounded subject, stop once required invariants are proven, known Critical/Material findings closed, required regression/surface/independent-review gates passed, Owner acceptance granted, accepted content landed and authoritative main verified. Target **NO KNOWN MATERIAL DEFECT + REQUIRED INVARIANTS PROVEN**, not infinite hypothetical defect search. A later genuine material impact is handled by §5, not anticipatory endless investigation. The present document stops earlier at its explicitly authorized Owner-review boundary.

## 10. Operational loop and transitions

| Transition | Evidence permitting it |
| --- | --- |
| Canonical authority → landed system truth | Read governed obligations, then separately verify exact current main and applicable runtime/configuration evidence; never infer existence from doctrine |
| Landed truth → active-work authority → current-work evidence | Explicit accepted bounded contract/stage authorization; inspect only relevant delta and proof at named baselines |
| Evidence → current system state → earliest upstream unsatisfied gate/dependency | Scope-aware proven/missing parts and actual prerequisite/consumer edges with material safety/rework consequence |
| Gate resolution → eligible Product-value Slices → Owner selection | Satisfaction proof, candidate comparison under §8 and explicit selection; no candidate becomes authorized merely by eligibility |
| Selection → semantics + data + authority → implementation | Accepted Semantic Design/Implementation Plan as required and explicit implementation authority; required input/ownership/authority boundaries resolved |
| Implementation → required product/admin/support/automation surfaces → functional + applicable visual verification | Exact-scope source/behavior/integration/regression evidence and required surface proof; no paper closure |
| Verification → independent review → Owner acceptance | Required bounded review with no unresolved material blocker; explicit final Owner acceptance of the exact content and known limits |
| Acceptance → land → verify actual main | Separate source-control authorization, exact accepted content landed, authoritative main identity/content and applicable gate proof verified |
| Verified main → update Master Execution Map → recompute critical path → consider next Slice | Update existing control records/map when separately authorized; preserve claim scope, resolved edges and remaining gates before comparing next eligible work |

This is an evidence-permitted control loop, not a new product waterfall. Applicable approved review/verification ordering may iterate; it never removes a required gate. Lifecycle labels track the current gate for the specific record. Source-control authority remains explicit: this assignment authorizes no commit, push, PR or merge.

After **every Owner-accepted landed Slice**: inspect actual main; verify its exact landed baseline; record child closure evidence; update parent capability state only for what is proven; update downstream consumers/unlocked dependencies; remove resolved blockers; invalidate stale assumptions only where evidence requires; reassess active child Slices/deltas; recompute the path; only then evaluate the next Slice. Do not blindly continue an old roadmap or promote child closure to parent completeness.

## 11. SCR-01 concise worked example

This illustrates existing accepted bounded facts, not a new SCR investigation. Semantic scope remains in the accepted [SCR design](superpowers/specs/2026-10-05-shared-canonical-reality-design.md) and [plan](superpowers/plans/2026-10-05-shared-canonical-reality.md).

| Subject | Bounded control statement |
| --- | --- |
| Parent Node | Shared Canonical Reality; broad governed capability is not automatically COMPLETE. CAPABILITY_CLOSURE remains OPEN for the wider responsibility; this example does not classify every existing/missing parent part or structural condition |
| Child Slice | SCR-01 bounded advertising-evidence slice; SYSTEM_STATE COMPLETE only for its accepted bounded claim; LIFECYCLE_STATE LANDED; EXECUTION_DISPOSITION NOT_SELECTED at settled closure |
| Child closure/limits | SLICE_CLOSURE CLOSED; no required new UI in this bounded backend Slice, so REQUIRED_SURFACES_CLOSURE NOT_APPLICABLE. Does not close all Shared Reality, Targeting/Spend decisions, business-outcome verification, live provider activation or aggregate release readiness |
| Evidence/baseline | Exact final head `f83d36933557e06bd80c9c343af3aa1845de617d` is merge parent of authoritative main `58e021d88a0012890de9e8d26f2b70d89f41f8d1`; Foundation SUCCESS, PHP 792 / 9793 assertions, JavaScript 135; explicit Owner-supplied final acceptance GRANTED |
| Ownership/impact | Child owns CONTROL_EVIDENCE_TYPES, SLICE_CLOSURE and LANDED_BASELINE. Supported example types: OWNER_ACCEPTED (Owner starting statement), LANDED_PROVEN (Git/PR), REGRESSION_PROVEN (CI). STRUCTURAL_CONDITION omitted on this Slice because no Slice-level overlap assessment is required here; CHANGE_IMPACT_STATUS NOT_APPLICABLE because this example performs no legitimate new impact assessment |

No stronger runtime, integration, surface or parent-completeness proof is manufactured from these facts. No SCR implementation is reopened.

## 12. Hypothetical scenario validation

These are fixtures, **not real reconstruction data**. The supplied short scenarios do not specify all lifecycle/closure facts. The explicit assumptions below make each classification determinate; remove an assumption and preserve the actual known gate instead of guessing. All rows use CHANGE_IMPACT_STATUS NOT_APPLICABLE unless an actual impact premise is stated. Structural condition is not inferred; Slice fields are omitted absent a legitimate overlap assessment. No row is an aggregate release question, so RELEASE_READINESS is not assessed or added.

In the table, `S / L / D` means SYSTEM_STATE / LIFECYCLE_STATE / EXECUTION_DISPOSITION. Closure columns always identify their subject; NOT_APPLICABLE in a Node's Slice column or a Slice's Capability column means that field belongs to the other record, not a schema field to add.

| Scenario and explicit bounded assumptions | S / L / D | SLICE_CLOSURE | CAPABILITY_CLOSURE | REQUIRED_SURFACES_CLOSURE | CHANGE_IMPACT_STATUS | NEXT_ACTION |
| --- | --- | --- | --- | --- | --- | --- |
| A. Active backend Slice source exists, behavior never verified; implementation submitted to verification, no surface required | PRESENT_BUT_UNVERIFIED / VERIFICATION / ACTIVE | OPEN | NOT_APPLICABLE | NOT_APPLICABLE | NOT_APPLICABLE | Prove required bounded behavior/integration/regression gates; source presence does not justify COMPLETE |
| B. Backend Slice satisfies all technical/review gates and final Owner acceptance; landing awaits separately authorized source-control action | COMPLETE / OWNER_ACCEPTANCE / BLOCKED | OPEN | NOT_APPLICABLE | NOT_APPLICABLE | NOT_APPLICABLE | Obtain landing authority, land exact accepted content, verify main; active-work proof is not landed truth |
| C. Previously closed landed Node; genuine evidence falsifies one required portion, others remain proven; no surface required, repair not yet authorized | PARTIAL / LANDED / BLOCKED | NOT_APPLICABLE | OPEN | NOT_APPLICABLE | REOPENED_BY_CONTRADICTION | Reopen affected claim, preserve historical landed baseline, obtain bounded repair authorization and prove correction |
| D. Previously accepted landed Node has a material new harness failure affecting its required regression assurance; product defect not established, Node has no active delta/surface | COMPLETE / LANDED / NOT_SELECTED | NOT_APPLICABLE | CLOSED | NOT_APPLICABLE | IMPACT_REVIEW_REQUIRED | Localize harness boundary and its claim impact; do not invent a product contradiction. Any active Slice requiring that broken regression gate is BLOCKED with SLICE_CLOSURE OPEN until valid proof exists |
| E. Newly discovered external supply-chain/CI security gate; bounded gate remedy is missing, no product contradiction established, repair authorization pending | MISSING / NOT_STARTED / BLOCKED | NOT_APPLICABLE | NOT_APPLICABLE | NOT_APPLICABLE | NOT_APPLICABLE | Record mandatory gate and affected dependency edges; secure bounded remediation authority, resolve and prove the CI security gate before dependent landing. Existing accepted product claims retain their states unless focused impact evidence requires change |
| F. Active Slice backend proven, required advertiser/admin surface present but unverified; current gate is verification | PARTIAL / VERIFICATION / ACTIVE | OPEN | NOT_APPLICABLE | OPEN | NOT_APPLICABLE | Verify required surface functionality and actual visual/RTL/accessibility/usability obligations before closure |
| G. Proposed backend Slice implementation absent; no surface required in this fixture; unresolved governed semantics/authority is an actual upstream prerequisite; candidate not selected | MISSING / NOT_STARTED / BLOCKED | OPEN | NOT_APPLICABLE | NOT_APPLICABLE | NOT_APPLICABLE | Resolve earliest upstream semantics/authority gate under its authorization; withhold Slice eligibility/implementation. Recompare eligible candidates only after the gate is satisfied |
| H. Authorized Slice discovery has established some bounded facts; more evidence no longer distinguishes minimal competing causes; no surface obligation | PARTIAL / DISCOVERY / ACTIVE | OPEN | NOT_APPLICABLE | NOT_APPLICABLE | NOT_APPLICABLE | Change method and run the smallest distinguishing causal experiment; do not collect more identical logs or assert closure |
| I — parent. Broad Node is evidenced PARTIAL, own baseline LANDED, no Node delta or required surface, one active child | PARTIAL / LANDED / NOT_SELECTED | NOT_APPLICABLE | OPEN | NOT_APPLICABLE | NOT_APPLICABLE | Retain Node lifecycle/landed baseline; link active child, update only after its actual landing proof |
| I — child. Authorized child Slice partly implemented, no required surface in this fixture | PARTIAL / IMPLEMENTATION / ACTIVE | OPEN | NOT_APPLICABLE | NOT_APPLICABLE | NOT_APPLICABLE | Complete accepted bounded implementation and applicable proof/review/acceptance/landing gates; do not rewrite parent lifecycle |

D preserves historical accepted closure while reviewing plausible impact; no **new** closure is permitted over a known material required-proof gap. If review establishes the accepted product invariant was false, apply C; if it only breaks future assurance, repair that gate and preserve unaffected product truth. E is a gate/dependency record, not a new product Node or Product Slice; its closures are inapplicable, and its lifecycle/disposition refer to the bounded remediation work.

Correction probes also pass: COMPLETE with DUPLICATED_OR_OVERLAPPING is valid when no material bounded-claim defect follows; PARTIAL with NORMAL is valid; a later-created prerequisite precedes its older dependent topologically; no upstream blocker leads to candidate comparison without automatic selection; a governance-only statement carries DECLARED without IMPLEMENTED or LANDED_PROVEN; child closure is never copied into the parent.

`SCENARIO_VALIDATION = PASS` for these explicit hypothetical fixtures and correction probes.

## 13. Handoff verification

The contract sections above establish the required checks; fresh file-scope/whitespace verification is performed at handoff. These checks describe this document, not reconstructed product readiness.

```text
CANONICAL_GOVERNING_AUTHORITY_SEPARATED = YES
LANDED_SYSTEM_TRUTH_SEPARATED = YES
ACTIVE_WORK_AUTHORITY_SEPARATED = YES
CURRENT_WORK_EVIDENCE_SEPARATED = YES
CONTROL_EVIDENCE_TYPES_DEFINED = YES
SYSTEM_LIFECYCLE_DISPOSITION_SEPARATED = YES
SYSTEM_STATE_EXCLUDES_STRUCTURAL_OVERLAP = YES
STRUCTURAL_CONDITION_DEFINED = YES
NODE_CHILD_SLICE_LIFECYCLE_RULE_DEFINED = YES
CHILD_SLICE_CLOSURE_OWNED_BY_SLICE = YES
CLOSURE_CLAIMS_SCOPE_AWARE = YES
RELEASE_READINESS_AGGREGATE_ONLY = YES
NODE_VS_SLICE_EXPLICIT = YES
SLICE_CLOSURE_DOES_NOT_IMPLY_CAPABILITY_COMPLETION = YES
NO_STANDALONE_OUTCOME_NODE_INFERRED = YES
DECISION_DELTA_DEFINED = YES
VALUE_CLAIM_DISCIPLINE_DEFINED = YES
SOURCE_DEPENDENCY_DEFINED = YES
PRE_CONNECTION_RULE_SCOPE_PRESERVED = YES
SOURCE_DEPENDENCY_AND_PRECONNECTION_AXES_SEPARATED = YES
SOURCE_DEPENDENCY_VALUES = NONE / OPTIONAL / REQUIRED_FOR_THIS_DECISION
PRE_CONNECTION_CAPABLE_VALUES = YES / NO / NOT_APPLICABLE
OPTIONAL_DOES_NOT_IMPLY_PRECONNECTION = YES
PRECONNECTION_DOES_NOT_IMPLY_NO_SOURCE_DEPENDENCY = YES
PRE_CONNECTION_SCOPE_PRESERVED = YES
NO_PROVIDER_CONNECTION_UNIVERSAL_PREREQUISITE_CREATED = YES
CURRENT_WORK_EVIDENCE_REMAINS_DISTINCT_FROM_LANDED_TRUTH = YES
ACTIVE_WORK_AUTHORITY_REMAINS_DISTINCT_FROM_LANDED_TRUTH = YES
EVIDENCE_MAY_SUPPORT_LANDED_TRUTH_BUT_DOES_NOT_BECOME_IT = YES
CANONICAL_OUTCOME_MEANING_PRESERVED = YES
DESIRED_OUTCOME_CURRENT_INTENT_BOUNDARY_PRESERVED = YES
OWNER_ACCEPTANCE_WORDING_NOT_PREMATURE = YES
NO_OTHER_CONTROL_MODEL_BEHAVIOR_CHANGED = YES
SURFACE_FRONTEND_RULE_DEFINED = YES
ASSURANCE_EFFICIENCY_RULE_DEFINED = YES
CLOSURE_QUALITY_RULE_DEFINED = YES
NO_UNBOUNDED_PERFECTIONISM_RULE_DEFINED = YES
CHANGE_IMPACT_RULE_DEFINED = YES
CHANGE_IMPACT_NOT_APPLICABLE_SUPPORTED = YES
DECLARED_DOES_NOT_PROVE_IMPLEMENTATION = YES
IDEA_CLASSIFICATION_DEFINED = YES
CRITICAL_PATH_RULE_DEFINED = YES
CRITICAL_PATH_USES_DEPENDENCY_TOPOLOGY = YES
CONSUMER_DRIVEN_DEPENDENCY_RULE_DEFINED = YES
DECISION_LEDGER_DEFINED = YES
UPDATE_AFTER_CLOSURE_DEFINED = YES
DOCUMENT_OVERLAP_CHECK = PASS
SCENARIO_VALIDATION = PASS
CANONICAL_GOVERNANCE_REMAINS_AUTHORITY = YES
PRODUCT_GOVERNANCE_DUPLICATION_MINIMIZED = YES
NO_NEW_PRODUCT_SEMANTICS_CREATED = YES
NO_CURRENT_SYSTEM_RECONSTRUCTION = YES
NO_MASTER_EXECUTION_MAP = YES
NO_NEXT_PRODUCT_SLICE_SELECTED = YES
ONLY_AUTHORIZED_DOCUMENT_CHANGED = YES
GIT_DIFF_CHECK = PASS
```

Authorized output is only this document. No product code, tests, database, CI, dependencies, package files, accepted contracts or canonical governance changed. No commit, push, PR or merge; no task-owned server/watcher/helper started. **STOP = Owner review.**
