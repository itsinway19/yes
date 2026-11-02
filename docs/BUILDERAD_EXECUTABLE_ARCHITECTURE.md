# Builderad — Executable Architecture V1

Inspection baseline: `5c573b96cb514148fb9422abaf738a06b536705f` (Object Purpose landed). This record projects already-accepted rules into existing repository enforcement; it creates no product semantics. [Canonical governance](BUILDERAD_CORE_EXECUTION_GOVERNANCE.md), accepted semantic contracts and explicit Owner decisions remain authoritative. This is not a competing governance source, capability registry or product roadmap.

`GUARDRAILS_V1 = COMPLETE` means the bounded inspection/enforcement pass is complete for the currently landed system and declared enforcement surfaces. It does not mean every invariant is globally mechanically enforced, every future capability is covered, or every PARTIAL must become PASS. PARTIAL is legitimate when the normative rule, existing protection, useful current enforcement and remaining surfaces are accurately recorded. Future capabilities extend coverage when they actually exist; do not invent architecture to improve a status.

PASS proves only the declared coverage, at the tested candidate revision. These statuses do not prove preview/production deployment, real Pilot admission or GitHub branch protection. Source files and prior proof retain their original scope; the full Foundation run is the current regression gate.

Accepted-basis references below resolve to the existing [Project Control Model](BUILDERAD_PROJECT_CONTROL_MODEL.md), [DTM design](superpowers/specs/2026-10-04-declared-target-markets-design.md), [Current Intent design](superpowers/specs/2026-10-04-current-goal-intent-design.md), [Shared Reality design](superpowers/specs/2026-10-05-shared-canonical-reality-design.md), [Object Purpose design](superpowers/specs/2026-10-07-object-specific-advertising-purpose-semantic-design.md) and [landed admission plan](superpowers/plans/2026-10-07-object-purpose-canonical-admission-001.md). Section numbers refer to those exact sources; Core and provider-supplement sections refer to their distinct parts within canonical governance.

## Declared machine boundary and integration

`tests/Unit/CoreDependencyBoundaryTest.php` scans PHP names in `app/Business`, `app/Foundation`, `app/Sources`, `app/Commercial` and `app/MarketKnowledge`. It rejects direct `App\Providers\Ads` dependencies, including aliases, grouped imports, fully qualified names and case variants. Exactly three existing file/class exceptions remain:

- `Business/BusinessContext.php` → `App\Providers\Ads\Provider`, for existing source-guidance presentation.
- `Business/PromotionProduct.php` → `App\Providers\Ads\Provider`, for the existing approved promotion catalog.
- `Sources/EffectiveCapability.php` → `App\Providers\Ads\ProviderEvidenceValidity`, for the existing evidence classifier.

These exceptions are neither universal Source semantics nor permission to add live connectivity dependencies. The guard is a test-only PHP tokenizer, not a runtime policy or new static-analysis framework. Dynamic/class-string dependencies, indirect dependencies through other modules, SQL dependencies, other directories and browser code are not statically proven. Existing `Ads` placement does not establish semantic ownership (Shared Reality SR2); moving that code is outside V1.

`tests/Feature/ArchitectureSourceOptionalityTest.php` clears all five supported advertising-provider service configurations, starts with zero Source/provider-access rows, exercises existing public and authorized advertiser HTTP paths, and asserts no HTTP requests were sent. It checks Workspace/name/type, DTM, Current Intent and explicit finite Object Purpose admission/read. Identity is an explicitly supplied synthetic fixture with retained provenance/confirmations; missing identity still fails admission. It does not prove real object identity, live OAuth, deployed startup, arbitrary external-source types or future decisions.

Both guards are automatically discovered by the existing PHPUnit Unit/Feature suites. `.github/workflows/foundation.yml` already runs the full PHP suite, npm tests, audits, Pint and build: a guard failure therefore fails Foundation. No new workflow, dependency or branch-protection claim is introduced. Focused command: `pwsh -NoProfile -File scripts/builderad.ps1 test --compact --filter 'CoreDependencyBoundaryTest|ArchitectureSourceOptionalityTest'`. The official runner checks all three roles against the disposable test database before running Feature tests.

## INV-001 — SOURCE_AND_CORE_PROVIDER_INDEPENDENCE

- **ACCEPTED_BASIS:** Core governance §2 Product Direction, §7 Provider Semantics and §8 Adapter Ownership; provider supplement §9 No Unnecessary Semantic Lock-in; Shared Reality design §4/SR2 and §3/SR1 (first advertising slice, not universal Source).
- **NORMATIVE_STATEMENT:** Core meaning does not derive from a specific provider implementation. A source-neutral boundary must not universally assume OAuth, ad accounts or campaigns. SOURCE != ADVERTISING_PROVIDER.
- **DOES_NOT_MEAN:** No provider evidence can ever be required, every existing Ads class must move, or a universal Source taxonomy should exist.
- **ENFORCEMENT_CLASS:** STATIC_DEPENDENCY / BEHAVIORAL / CI_GATE.
- **CURRENT_ENFORCEMENT_COVERAGE:** Existing Source Registry uses bounded family/namespace/external-id and resource/operation/purpose identity. The direct-name guard protects the five declared directories with the three exact existing exceptions; negative fixtures prove forbidden names fail. D1 market originals retain platform custody without a Workspace and are written/read with explicit grants and no provider transport (`MarketEvidenceOriginTest`). This does not prove D2/D3 execution or commercial accuracy.
- **KNOWN_UNENFORCED_SURFACE:** Indirect/dynamic/SQL/browser dependencies and future Source kinds; current BB1 capability axes remain advertising-oriented. No global provider independence claim.
- **ENFORCEMENT_ARTIFACTS:** `CoreDependencyBoundaryTest`, `tests/Support/CoreDependencyBoundary.php`, existing `SourceRegistryTest`, `database/sql/source_registry.sql`.
- **CHANGE_AUTHORITY:** Accepted Owner governance/semantic change; then update the bounded guard and its declared exceptions with evidence, not an unreviewed wildcard.
- **STATUS:** PARTIAL.

## INV-002 — ZERO_EXTERNAL_SOURCE_OPERABILITY

- **ACCEPTED_BASIS:** Project Control Model §7 (connection must not become a universal prerequisite); DTM design §§2–3; Current Intent design §§1/3; Object Purpose design §§4–6/12–13 (declaration ownership distinct from connectivity).
- **NORMATIVE_STATEMENT:** Zero external connections is valid. Existing source-independent declarations remain usable; missing source evidence may withhold an evidence-dependent conclusion.
- **DOES_NOT_MEAN:** Every decision works without evidence, absent connectivity establishes object identity, or authority/confirmation can be skipped.
- **ENFORCEMENT_CLASS:** BEHAVIORAL / AUTHORITY / CI_GATE.
- **CURRENT_ENFORCEMENT_COVERAGE:** New cross-surface HTTP guard verifies public/runtime entry, Workspace/name/type, DTM, Current Intent and Object Purpose under empty provider configurations and zero connections, without provider transport. Existing domain/security tests preserve declaration-specific prerequisites.
- **KNOWN_UNENFORCED_SURFACE:** Deployment startup and non-advertising/future capabilities are outside this test; no global boot/deployment/decision-availability claim.
- **ENFORCEMENT_ARTIFACTS:** `ArchitectureSourceOptionalityTest`; existing `BusinessFoundationTest`, `DeclaredTargetMarketsTest`, `CurrentIntentTest`, `ObjectPurposeHttpTest` and `ObjectPurposeSecurityTest`.
- **CHANGE_AUTHORITY:** Existing declaration contracts and accepted Owner decisions; extend coverage only for an actual landed source-independent surface.
- **STATUS:** PASS for the declared existing HTTP/declaration surfaces.

## INV-003 — SOURCE_FAILURE_ISOLATION

- **ACCEPTED_BASIS:** Provider supplement §§8–9 (loss of evidence degrades affected conclusions; unrelated capabilities continue; removal affects acquisition/evidence strength); Project Control Model §§7–8 (fact-specific dependency); Shared Reality SR3–SR4 (qualified missing/failed acquisition).
- **NORMATIVE_STATEMENT:** Failure propagates only across actual dependency edges. Source acquisition/evidence consumers may degrade; unrelated work must not fail merely through a sibling source.
- **DOES_NOT_MEAN:** Shared DB/Redis availability is globally isolated, failed evidence is usable as current, or invented providers are needed for a matrix.
- **ENFORCEMENT_CLASS:** BEHAVIORAL / DATA_INVARIANT / AUTHORITY / CI_GATE.
- **CURRENT_ENFORCEMENT_COVERAGE:** Reused all-five-provider custody rollback/sibling tests, exact scoped queue isolation/retries, and independent Source audit-delivery/custody recovery tests. Queue names and locks bind actual provider/scope/stream. No duplicate isolation matrix added.
- **KNOWN_UNENFORCED_SURFACE:** Infrastructure-wide outages, future cross-source decision consumers and non-advertising acquisition have no general isolation proof here.
- **FAILURE_SCOPE:** Exact provider/access scope and acquisition stream; custody/audit-delivery failure does not rewrite independent local declaration state. Genuine shared-infrastructure failure remains a shared dependency.
- **ENFORCEMENT_ARTIFACTS:** `ProviderAccessStoreTest::test_every_provider_failure_and_rollback_leave_siblings_unchanged`, `test_queued_permission_failure_does_not_consume_sibling_jobs_or_advance_any_progress`, `SourceRegistryTest::test_custody_failure_recovery_and_lost_ack_are_independent_and_idempotent`; `ProviderQueuePolicy`.
- **CHANGE_AUTHORITY:** Accepted acquisition/authority contracts and actual dependency edges; extend tests when a real consumer appears.
- **STATUS:** PARTIAL.

## INV-004 — CANONICAL_OWNERSHIP_SEPARATION

- **ACCEPTED_BASIS:** Core governance §4 Business/Decision Context and §§7–8 Provider/Adapter Semantic Ownership; Shared Reality SR2; DTM design §3; Current Intent design §§1/4; Object Purpose design §§5–6.
- **NORMATIVE_STATEMENT:** Provider observations do not declare Builderad business facts, intent, purpose or decisions. Canonical writers keep their accepted owners and authority boundaries.
- **DOES_NOT_MEAN:** Every conceivable future writer is covered, evidence cannot support an authorized declaration, or co-location transfers semantic ownership.
- **ENFORCEMENT_CLASS:** AUTHORITY / DATA_INVARIANT / BEHAVIORAL / STATIC_DEPENDENCY / CI_GATE.
- **CURRENT_ENFORCEMENT_COVERAGE:** Reused runtime DB role restrictions, fact-specific canonical writers and security tests; native provider geography cannot populate/overwrite DTM; Purpose admission preserves Context version/Intent/DTM; opaque identity and explicit authority remain required. New guard prevents direct provider custody/transport classes entering declared neutral responsibilities.
- **KNOWN_UNENFORCED_SURFACE:** Future decision/writer ownership and arbitrary indirect code/SQL coupling are not globally proven; no new ownership engine or schema introduced.
- **ENFORCEMENT_ARTIFACTS:** `ProviderAccessStoreTest::test_provider_geography_never_populates_or_overwrites_declared_markets`; `ObjectPurposeHttpTest::test_owner_http_admission_read_and_stale_conflict_preserve_other_context`; `ObjectPurposeSecurityTest::test_fresh_owner_confirmation_admits_exact_finite_scope_without_context_mutation`; `DatabaseAuthorityBoundaryTest`; existing fact-specific SQL writers.
- **CHANGE_AUTHORITY:** The respective accepted semantic owner and governed authority contract, with explicit Owner approval for a genuine semantic change.
- **STATUS:** PARTIAL.

## INV-005 — HONEST_TRUTH_STATES

- **ACCEPTED_BASIS:** Shared Reality §§6–9/SR3–SR7; Current Intent design §1 (no defaults/inference); DTM design §§2–3; Object Purpose design §§8/14–15 (unknown applicability/currentness is not a negative assertion).
- **NORMATIVE_STATEMENT:** UNKNOWN != ZERO; FAILED != ZERO; MISSING != FALSE; PARTIAL != COMPLETE; UNSUPPORTED != PROVEN_NEGATIVE, at each meaning-bearing boundary.
- **DOES_NOT_MEAN:** A universal epistemic enum, knowledge ontology or identical status vocabulary for every capability.
- **ENFORCEMENT_CLASS:** BEHAVIORAL / DATA_INVARIANT / CI_GATE.
- **CURRENT_ENFORCEMENT_COVERAGE:** Reused real coercion-boundary tests: missing NativeAmount stays null while evidenced zero stays zero; missing classifier axes cannot yield READY; FAILED/PARTIAL/PENDING and empty reads preserve qualified coverage/history; unknown DTM/Intent/Purpose are not inferred. The new zero-source test asserts UNKNOWN Purpose before explicit admission. No demonstrated uncovered coercion defect warranted changing product behavior.
- **KNOWN_UNENFORCED_SURFACE:** Arbitrary future presentation/aggregation/decision consumers; these tests do not prove every scalar or empty collection globally honest.
- **FAILURE_SCOPE:** The qualified exact observation/read/claim or declaration; failed acquisition cannot silently refresh last-known evidence.
- **ENFORCEMENT_ARTIFACTS:** `SharedRealityInterpretationTest`, `EffectiveCapabilityTest`, `ProviderEvidenceValidityTest`; `ProviderAccessStoreTest::test_shared_reality_snapshot_empty_failed_partial_pending_and_omission_never_fuse_history`; existing DTM/Intent/Purpose projection tests.
- **CHANGE_AUTHORITY:** The accepted contract owning each claim; add regression coverage at its actual coercion boundary when meaning changes.
- **STATUS:** PARTIAL.

## INV-006 — DECLARATION_SEMANTICS_PROVIDER_INDEPENDENCE

- **ACCEPTED_BASIS:** Shared Reality SR2; DTM design §§2–3; Current Intent design §§1/3–4; Object Purpose design §§4–6/8–13 and landed admission plan (provider-neutral tuple, Meta checks only at HTTP edge).
- **NORMATIVE_STATEMENT:** Canonical declaration meaning and ownership do not derive from live connectivity. External object references retain exact finite identity, applicability, provenance, authority and confirmation obligations.
- **DOES_NOT_MEAN:** Disconnected identity can be fabricated, native IDs are universally equivalent, or every provider edge is implemented.
- **ENFORCEMENT_CLASS:** STATIC_DEPENDENCY / AUTHORITY / BEHAVIORAL / DATA_INVARIANT / CI_GATE.
- **CURRENT_ENFORCEMENT_COVERAGE:** New neutral-directory dependency guard and explicit zero-source admission/read with missing identity rejected; reused canonical opaque-tuple tests, Meta HTTP shape validation, neutral Foundation actor, finite applicability/no-inheritance, temporal/history and DTM-lineage regressions. No Object Purpose product change.
- **KNOWN_UNENFORCED_SURFACE:** Real-world identity/linkage evidence completeness and future HTTP provider edges; synthetic fixtures prove accepted admission obligations, not the real Pilot's facts.
- **ENFORCEMENT_ARTIFACTS:** Both new guards; `ObjectPurposeDatabaseTest::test_opaque_identity_is_case_preserved_and_provider_neutral`, `ObjectPurposeHttpTest`, `ObjectPurposeSecurityTest`, `ObjectPurposeDtmLineageTest`.
- **CHANGE_AUTHORITY:** Accepted declaration contracts and explicit Owner authorization; identity owners retain native lifecycle responsibilities.
- **STATUS:** PARTIAL.

## INV-007 — DECISION_AUTHORITY_SEPARATION

- **ACCEPTED_BASIS:** Core governance §4 points 7–8, especially OBSERVE/ADVISE/PREPARE and explicit authority before external effect; Shared Reality SR2; Object Purpose design §7 (canonical acceptance does not establish execution authority).
- **NORMATIVE_STATEMENT:** Decision != execution authority; recommendation != approval; approval != external effect. Each existing effect needs its separately governed authority.
- **DOES_NOT_MEAN:** V1 implements Decision Synthesis, advertiser execution, N06/N07/N08 or future approval machinery.
- **ENFORCEMENT_CLASS:** AUTHORITY / BEHAVIORAL / AUDIT_ONLY / CI_GATE.
- **CURRENT_ENFORCEMENT_COVERAGE:** Reused exact action/scope/session/generation checks for existing Source/provider acquisition and audit-recovery effects; a recovery projection alone cannot authorize recovery. Service identity alone cannot grant Workspace/action authority. Evidence classification explicitly does not substitute for authority. Decision-to-advertising-effect machinery does not exist and gets no invented tests.
- **KNOWN_UNENFORCED_SURFACE:** Future decision/recommendation/approval/external-advertising-write chain remains AUDIT_ONLY; the existing guards are not proof of that absent chain.
- **FAILURE_SCOPE:** Only the currently authorized action at its exact authority boundary; a readable projection, credentials or declaration cannot substitute for that grant.
- **ENFORCEMENT_ARTIFACTS:** `SourceRegistryTest::test_recovery_projection_does_not_authorize_effect_or_leak_across_workspaces`; `ServiceAuthorityTest`; `ProviderEvidenceValidityTest::test_classification_never_substitutes_for_authority_and_fetch_is_distinct_from_readiness`; existing provider queued-authority tests.
- **CHANGE_AUTHORITY:** Accepted Owner execution/authority contract for an actual capability; future enforcement follows that capability, not this record.
- **STATUS:** PARTIAL.

## D2 candidate — bounded processing coverage

D2 extends the existing MarketKnowledge guard surface; the recursive Core dependency guard already covers its methods. `MarketProcessingMethodsTest` uses independent golden expected outcomes for offer equivalence, comparable interest and declared-country feasibility. `MarketProcessingTest`, `MarketProcessingConcurrencyTest` and `MarketProcessingUpgradeTest` exercise the candidate HTTP/capture/complete boundary, independent Business rights, metadata confidentiality, immutable v1 meaning, v2 typed extensions, current-session/rights requalification, real contention, failure/retry and populated D1 upgrade. Verification results and limits belong to `docs/execution/market-processing/PROGRESS.md`; test existence is not PASS.

This adds bounded INV-001/002/003/004/005/007 protection, not global enforcement or commercial readiness. Feasibility does not invent operational capacity/cost, source assertions do not establish market demand, and a D2 handoff is not a published D3 material or execution authority. No provider transport, background identity, subscription policy, advertiser UI or D3 publication is introduced. Existing invariant statuses retain their stated scope.

## D3 candidate — bounded publication and operational coverage

D3 extends the existing MarketKnowledge owner with a non-Business `market.knowledge` producer (typed descriptor v2) and an actual-Business `market.business_finding` producer (private v1). Both use existing material lineage, scopes, effect permits and command receipts. Source admission and initial licence enrollment remain isolated maintenance; repeated capture/publication use explicit current human staff authority and independently qualified source process/forward/retain/output rights. Observation does not confer an effect right. Trusted PHP completion uses a separate one-use authority issuer, not a caller-authored SQL result.

The existing recursive Core guard covers these classes. `MarketKnowledgeAuthorityTest`, `MarketKnowledgeCompletionSecurityTest`, `MarketKnowledgeConcurrencyTest`, `MarketKnowledgePublicationTest`, `MarketBusinessFindingTest`, `MarketKnowledgeConsumersTest`, `MarketOperationsTest` and `MarketKnowledgeOperationsIntegrationTest` protect bounded INV-001/002/003/004/005/007 behavior: no provider transport requirement, distinct qualified consumers, shared/private isolation, current rights and validity, trusted atomic publication/replay, field-dependent corrections and safe operational metadata. `MarketKnowledgeFactsTest` preserves observed zero versus unknown and geographic observation versus audience reach. The actual compiled UI/state and guarded market-knowledge browser selection cover the operational surface; a generic Foundation result does not establish browser behavior.

These are declared protection surfaces, not a claim that every gate passed. Exact source, results and limitations are recorded in `docs/execution/market-knowledge/PROGRESS.md`. No scheduler/service identity, provider acquisition, generic graph, paid entitlement or completed customer-discovery/competition journey is introduced. Finite retained-storage obligations are rejected until an erasure executor exists; explicit supported withdrawal removes only the D3-owned result payload, not original evidence, backups or recipient copies. Existing invariant statuses remain bounded and PARTIAL.

## BF03-I01 candidate — private source-asserted inquiry custody

Owner-authorized policy `inquiry_evidence_custody_v1` / `own_private_statement` adds exact record/correct/read actions for a freshly opted-in original Business creator. This is a bounded BF-03 evidence custody/readback responsibility, not a commercial outcome, diagnostic, resource decision or external-effect authority. Original source assertions remain unverified. Existing Foundation Business/Security owns principal, original qualification and action authority; no provider implementation dependency or new generic authority resolver is introduced.

- **INV-001:** `app/Commercial/InquiryEvidence.php` stays inside the existing Core dependency guard; the admitted human-assertion route requires neither CRM nor advertising connection. Future source adapters need their own legitimate evidence and rights.
- **INV-004:** `InquiryEvidenceAdmissionTest` exercises independent record/correct/read authority, original-basis revocation, exact Business/actor privacy and app/authority direct-table denial. `InquiryEvidenceConcurrencyTest` observes actual process PIDs/blockers and requalifies after business-fence and receipt-lock waits; expiry/revocation cannot leave an effect. Runtime SQL uses existing permits/footprint ordering.
- **INV-005:** `validation.sql`, admission tests and the Arabic readback retain unknown versus zero, independent evidence axes, criterion/version, observation/cutoff/coverage, original issuer and append-only corrections. They reject fabricated verified/sale/profit/PII fields. Save success does not independently verify a source assertion.
- **INV-007:** New action authority is private custody/readback only. No analysis, sharing/export, budget decision or external write follows from these grants; no absent future decision engine is claimed as implemented.

Actual evidence is in `docs/execution/structural-foundation/IMPLEMENTATION_PROGRESS.md`. Focused Backend, UI integration, real browser and mandatory Foundation acceptance remain distinct. These additions do not upgrade the global PARTIAL statuses above or establish legal custody, source truth, commercial value, live-provider readiness or deployment approval.


### BF03-I01 bounded correction protection

`InquiryEvidenceAdmissionTest` protects INV-005 criterion-definition snapshots across Arabic/English revision/history/withdrawal, strict closed admission, and exact replay. `InquiryEvidenceUpgradeTest` protects immutable legacy unknown definitions and historical command replay under current authority, plus fresh/additive migration540. The Inquiry workspace activation boundary requalifies display rather than promoting retained client state into authority (INV-004/005). Existing record/correct/read separation and INV-001/007 limits remain unchanged; no general criterion registry, CRM, analysis or execution capability is added. This records bounded protection, not global Foundation acceptance.
