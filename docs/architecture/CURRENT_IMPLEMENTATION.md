# BuilderAd — current implementation and evidence

Reconciled 2026-11-15 through `BA-FOUNDATION-READINESS-01`. This source and evidence map uses the [governing Markdown](BUILDERAD_GOVERNING_ARCHITECTURE.md) for design and its [HTML reading copy](BUILDERAD_GOVERNING_ARCHITECTURE.html) for presentation. [Engineering standards](../engineering/ENGINEERING_STANDARDS.md) maps protected technical behavior and commands.

## Verified baseline after PR #68

PR #68 merged at `c9147c366cd3d07510c299a5531e09056e5e816c`. The inspected worktree HEAD, `25f0209c00ec08be90855b3ffd3dea80c29c2723`, has the same Git tree `7a973e3de4363f45d45a6e3e11ea805d28bffc07`. GitHub Actions reports a successful [PR Foundation #43711975605](https://github.com/42Huss/builderad/actions/runs/43711975605) on the PR head and a successful [post-merge Foundation #43714626327](https://github.com/42Huss/builderad/actions/runs/43714626327) on the merge SHA. This supersedes the pending publication markers in the historical sections.

**`MAIN_VERIFIED` for the bounded PR #68 additions.** The independent result validators and the two-result qualified-read test described in the historical local evidence are part of the merged tree. The read test covers two separately qualified market findings, their distinct semantics, rejection after one grant is withdrawn, and continued qualified reading of the unaffected finding. It exercises `display`. The durable cross-domain decision contract and any `combine` rights or downstream effects of such a decision remain unimplemented.

### Boundary before a durable cross-domain decision

The existing material owner supports registered versioned references, operation-specific qualification, lineage and proof rechecks. `QualifiedKnowledgeReader` serves single-result market display consumers. Practice assessment qualifies one finding against a practice revision and a specific question. The first durable decision joining heterogeneous owner results needs a use-case-owned compatibility rule and a scoped record of context versions, result references and material dependencies, with tests for mismatch, context change and revocation. The [decision record](../execution/structural-foundation/BA-ARCHITECTURE-HIGH-COST-DECISIONS-01.md) lists the source boundaries and proof conditions. Current read and diagnostic paths can use their owner-specific contracts.

### Readiness assessment and targeted correction

The [readiness report](../execution/structural-foundation/BA-FOUNDATION-READINESS-01.md) separates locally verified source contracts from the future decision, external effect and evaluation paths. The prerequisite for publishing a cross-domain result is a stable owner, result kind and revision with a qualified read contract. The prerequisite for publishing a durable composite decision is a tested compatibility and persistence boundary for that actual consumer. These are distinct milestones.

The [forward migration](../../database/migrations/0001_01_01_000001_require_saved_practice_assessment_basis.php) requires positive evidence of the original subject basis to replay a saved practice assessment. A missing, null, or mismatched stored binding prevents release. A new assessment with finding-only access records an internal proof that it used no subject basis. It can replay the same limited judgment under eligible rights, with the public basis and binding fields remaining null. The [regression suite](../../tests/Feature/CustomerDiscoveryPracticeTest.php) exercises both branches, including narrower authorizations and preservation of the rejected record. PR #68 and its CI predate the migration.

**`LOCAL_VERIFIED_UNMERGED`.** The final targeted PostgreSQL run passed 17 tests and 320 assertions on the corrected source, including migration integrity, function ownership and execution permissions, and the final catalog seal. Hosted candidate Foundation remains pending.

### Historical publication preparation — BA-LOCAL-CLOSURE-PR-CI-01

The Owner authorized commit, push and one PR to `main` on 2026-11-15, without merge or deployment. Safe fetch resolved `origin/main` to `8751344ff21b4f3ecd25bbed0957dc237484aff0`, with exactly the same source tree as the preceding local base. The old branch belongs to merged PR #67; publication therefore uses `codex/evolutionary-validation-docs-001` in the same worktree, based directly on that main revision.

The verified production correction and its tests are committed as `5e0c47e2c2e2f4b1258864991120220895fcbbbc`. The documentation consolidation accompanies it in the same PR. Earlier task closure statements below describe those completed local tasks, not the publication branch's current Git state. Candidate acceptance is **PENDING_HOSTED_CI** at publication preparation; the new PR's Foundation/check results must be evaluated on its latest head, independently of baseline or local PASS. No merge/deployment acceptance is implied.

The local run artifacts and visualization reports below are workstation-only evidence and are intentionally excluded from the PR. The local source manifests identify the tested file state; the publication's new hosted CI supplies independently accessible candidate evidence. They are not claimed as GitHub artifacts or CI results.

Publication index checks include new files, beyond the earlier tracked-diff documentation check. `git diff --cached --check` reports exactly four original Markdown hard breaks (two trailing spaces on the approved reference's metadata lines 3–6). These are intentional Markdown syntax and remain byte-identical to the Owner's source. All other staged files pass the whitespace check; no repository rule or guard was changed to suppress the original formatting.

## Historical reconciliation baseline and evidence labels

Recorded before publication: worktree `C:\dev\builderad-worktrees\ba-workspace-retirement-001`; branch `codex/workspace-model-final-retirement-01`; base HEAD `df863866154f9f723e56cbc7655407e007e964db`. Local `main` and `origin/main` were then stale at `d5d93ed73f20f5e81972e8b5d19cb5cb95db3fe4` (four commits behind HEAD). Read-only GitHub verification during documentation reconciliation found actual main at `8751344ff21b4f3ecd25bbed0957dc237484aff0`, whose tree equals that local HEAD's `825d30cd1955e894e41c398adbc5df5c0bbbdacc`. That task needed no checkout, fetch, branch or publication; the authorized publication state is recorded above.

| Label | Meaning here |
|---|---|
| `MAIN_VERIFIED` | Bounded behavior protected by tests in the identified main source tree and matching authoritative CI. Not production, provider activation, commercial truth or browser acceptance. |
| `LOCAL_VERIFIED_UNMERGED` | Local correction has matching focused test evidence, base HEAD and source manifest. It has not been merged or accepted by candidate Foundation. |
| `SOURCE_PRESENT_UNVERIFIED` | Source exists, but available evidence does not establish the specified operational claim. |
| `NOT_IMPLEMENTED` | The specified capability is not present in the inspected runtime boundary; a research/design name does not implement it. |
| `UNKNOWN` | Evidence is insufficient to determine the claim or target environment state. |

Authoritative baseline: [PR Foundation 43620633657](https://github.com/42Huss/builderad/actions/runs/43620633657) for `df86386`; [main Foundation 43627883966](https://github.com/42Huss/builderad/actions/runs/43627883966) for `8751344`. Reused CI covers the identical baseline source tree, not the unmerged validators below. No comprehensive or behavioral suite was rerun for the documentation task.

## Actual owners and extension boundaries

| Responsibility | Source / executable contract / real consumer | State and evidence boundary |
|---|---|---|
| Identity and authority | [SessionAuthority](../../app/Security/SessionAuthority.php), [PrincipalContext](../../app/Foundation/Tenancy/PrincipalContext.php), [database configuration](../../config/database.php), SQL authority/permit/receipt owners. | `MAIN_VERIFIED` within [SessionAuthorityTest](../../tests/Feature/Security/SessionAuthorityTest.php), [DatabaseAuthorityBoundaryTest](../../tests/Feature/Security/DatabaseAuthorityBoundaryTest.php) and baseline CI. Document evidence, fresh authentication and current grants remain independent. |
| Business / Practice / Offering | [BusinessScope](../../app/Foundation/Business/BusinessScope.php), [business.sql](../../database/migration-sql/0001_01_01_000000_business_native_baseline/business.sql), practice/context revisions and [activity client](../../resources/js/business-foundation/activity.ts). | `MAIN_VERIFIED` bounded Business creation/read/write and practice contracts: [BusinessFoundationTest](../../tests/Feature/BusinessFoundationTest.php), [BusinessActivityTest](../../tests/Feature/BusinessActivityTest.php), baseline CI. Offering is a declared practice value, not a global catalog entity. |
| Goals and outcomes | [AdvertiserGoalPreferences](../../app/Business/AdvertiserGoalPreferences.php), `BusinessScope::preferences/intent`, versioned SQL owners; [InquiryEvidence](../../app/Commercial/InquiryEvidence.php) separately owns private source assertions. | `MAIN_VERIFIED` finite preferences/history and assertion semantics: [BusinessPreferenceVariantsTest](../../tests/Feature/BusinessPreferenceVariantsTest.php), [BusinessHistoryTest](../../tests/Feature/BusinessHistoryTest.php), [InquiryEvidenceAdmissionTest](../../tests/Feature/InquiryEvidenceAdmissionTest.php). No general observed-business-outcome or causal evaluation system is implied. |
| Sources and qualified evidence | [Sources](../../app/Sources), scoped material planning/rights and [MarketProcessingTask](../../app/MarketKnowledge/Processing/MarketProcessingTask.php). [BusinessNativeMaterialUseTest](../../tests/Feature/BusinessNativeMaterialUseTest.php) protects the actual read/use boundary. | `MAIN_VERIFIED` for admitted scopes/fields/purposes/versions in baseline CI. Source rights do not transfer through team, subscription or connection identity. Live provider access/fitness is `UNKNOWN` unless separately evidenced. |
| Analytical methods | [MarketMethod](../../app/MarketKnowledge/Processing/MarketMethod.php), [selector](../../app/MarketKnowledge/Processing/MarketMethodSelector.php), three finite methods; [MarketProcessing](../../app/MarketKnowledge/Processing/MarketProcessing.php). | Exact question/revision selection and replay are `MAIN_VERIFIED` within [MarketProcessingTest](../../tests/Feature/MarketProcessingTest.php). Independent semantic validation is `MAIN_VERIFIED` in [result validation tests](../../tests/Feature/MarketResultValidationTest.php) and PR #68 CI. A new real method needs its own semantics/validator and coordinated finite SQL/API admission, not a global registry. |
| Trusted completion / publication | [TrustedCompletion](../../app/MarketKnowledge/Processing/TrustedCompletion.php), [KnowledgePublisher](../../app/MarketKnowledge/KnowledgePublisher.php), [foundation SQL](../../database/migration-sql/0001_01_01_000000_business_native_baseline/foundation_functions.sql), material lineage/use dependencies. | `MAIN_VERIFIED` ticket binding, current-right requalification and publication isolation; local semantic correction also exercised the real paths. [Completion security](../../tests/Feature/MarketProcessingCompletionSecurityTest.php), [publication](../../tests/Feature/MarketKnowledgePublicationTest.php), [publication security](../../tests/Feature/MarketKnowledgeCompletionSecurityTest.php). |
| Qualified consumers / composition | [QualifiedKnowledgeReader](../../app/MarketKnowledge/QualifiedKnowledgeReader.php), [CustomerDiscoveryConsumer](../../app/MarketKnowledge/CustomerDiscoveryConsumer.php), [CompetitiveIntelligenceConsumer](../../app/MarketKnowledge/CompetitiveIntelligenceConsumer.php), SQL `assess_practice`. | Baseline `MAIN_VERIFIED` within [consumer tests](../../tests/Feature/MarketKnowledgeConsumersTest.php) and [practice tests](../../tests/Feature/CustomerDiscoveryPracticeTest.php). [Two-result qualified reading](../../tests/Feature/QualifiedKnowledgeCompositionTest.php) under current display rights is also `MAIN_VERIFIED`. |
| Commercial context / access | [CommercialContext](../../app/Commercial/CommercialContext.php), [RegisterCommercialScope](../../app/Commercial/RegisterCommercialScope.php). | `MAIN_VERIFIED` atomic context binding and Business beneficiary, with UNKNOWN subscription/entitlement/quota and HOLD for conditioned features; [BusinessCommercialCompositionTest](../../tests/Feature/BusinessCommercialCompositionTest.php). Operating billing/subscription is `NOT_IMPLEMENTED` in this boundary. |
| User experience | [SPA entry](../../resources/js/spa/main.ts), transport/router/presentation/lifecycle, Business views, [appearance context](../../DESIGN.md). | `MAIN_VERIFIED` bounded contracts under baseline CI. No fresh browser evidence was produced here; source/UI existence does not make every job `BROWSER_VERIFIED`. Actual placement/authority and missing/denied/unknown states need proof for each changed job. |
| Decisions / interventions / learning | Current local commands and provider-specific read receipts remain with their operation owners. Governing domain names are not runtime modules. | A general cross-domain decision engine, external intervention approval/reconciliation product, AI runtime, causal learning and automation platform are `NOT_IMPLEMENTED` in inspected boundaries. Read adapters/source claims are `SOURCE_PRESENT_UNVERIFIED` for real external operation absent matching live evidence. |

## Business versus Workspace

Public Workspace entry/creation paths are gone, and [WorkspaceRetirementTest](../../tests/Feature/WorkspaceRetirementTest.php) checks 404 responses and absence of Workspace schema/authority objects in fresh installation. [WorkspaceOwnershipRetirementTest](../../tests/Feature/WorkspaceOwnershipRetirementTest.php) checks Business-native ownership and denial to another actor. Existing Business grant ancestry, source ownership, receipts, corrections and retained-history rights remain protected independently.

Do not reinterpret old test/file names or earlier Workspace designs as current runtime ownership. The fresh clean baseline is not proof of an upgrade, data erasure, preview migration or production deployment. Those environment claims are `UNKNOWN` here. Historical source is available in Git; it is not a compatibility API.

## Historical local evidence for the correction merged in PR #68

The preceding `BA-EVOLUTIONARY-ARCHITECTURE-FOUNDATION-01` work is preserved unchanged, including its [execution report](../execution/structural-foundation/EVOLUTIONARY_ARCHITECTURE_FOUNDATION.md). Base: `df863866154f9f723e56cbc7655407e007e964db`.

Affected production files are `Findings.php`, `MarketProcessing.php` and the three `*Result.php` classes in `app/MarketKnowledge/Processing`. They independently validate existing v1 meanings and exact value fields, and return explicit validated/abstained classifications. The report preserves negative reproductions for the prior validator. The correction moved from local evidence to the merged baseline documented above.

| Matching evidence | Result / exact covered state |
|---|---|
| Local-only `storage/framework/testing/goals-spa-9478afb8-9a6c-4a61-b14a-6db50b22d133/source-manifest.json` and `tests.log` | 194 passed / 1559 assertions; final production correction and validation tests, trusted processing/publication/rights/concurrency and existing practice composition. |
| Local-only `storage/framework/testing/goals-spa-8d2a2ca1-7b13-4ba7-9b93-6db16f3896c3/source-manifest.json` and `tests.log` | 1 passed / 25 assertions; same production files plus [QualifiedKnowledgeCompositionTest](../../tests/Feature/QualifiedKnowledgeCompositionTest.php). Two independently licensed results, real Business growth preference/context, scoped withdrawal and qualified replay. No synthesized opportunity recommendation. |

Both runs recorded unchanged tested source, exit 0, stopped task-owned processes and deleted disposable data. The documentation task compared preserved file hashes without rerunning or relabelling those tests. Foundation was then **PENDING_PUBLICATION_AUTHORIZATION**; the later authorization and pending hosted candidate gate are recorded above. Local UI/browser/deployment acceptance is not inferred from these runs.

## Extension conditions

- A genuinely new method/revision needs its independent meaning, state/disclosure contract and matching admission at SQL/API/consumer boundaries; preserve earlier revision selection and history.
- A consumer synthesizing results from further domains needs explicit compatible subject/offering, geography, time, uncertainty and rights. Current qualified multi-result reading and practice assessment do not establish a universal comparison or causal engine.
- A real subscription, AI, learning or remote-effect function needs its own usage/cost/failure and current-right/approval/outcome contracts. Do not create placeholder infrastructure merely because the governing document names the responsibility.

These are extension conditions, not a sequence or authorization to implement them.

## Documentation disposition and retained evidence

`AGENTS.md` and `README.md` are operational entry points. Product design is only the new governing Markdown; this record and Engineering standards describe current source and protections. `DESIGN.md` is a narrow appearance-owner contract, not a product roadmap.

| Decision | Documents / reason |
|---|---|
| `REPLACE` | Old Core governance, Project Control and protected-evidence link destinations become short successor links with pinned Git provenance, removing competing rules. No old priority/state machine governs new work. |
| `MERGE_ESSENTIAL` | Valid isolation/rights/history, migration integrity, completion, lifecycle, appearance and verification rules move to Engineering standards/README/current owners. Old Workspace dependency graphs and screen sequences are not carried forward. |
| `RETIRE` | Master execution map, critical-path derivation, historical build program/mandates, obsolete implementation plans and proposals whose authority/use ended. References in retained narrative evidence point to exact Git snapshots rather than active plans. No archive directory is created. |
| `RETAIN_TECHNICAL_CONTRACT` | Runtime appearance context; existing `contracts/`, SQL, source and test guards remain unchanged. Inertia inventory outputs remain at their script-owned paths; generated inventories are not design authority or behavioral proof. |
| `RETAIN_VERIFICATION_EVIDENCE` | The preceding local report, its original executable-architecture evidence, source manifests/logs and existing review/test artifacts. Preserved reports retain their dates, failures and scope; old instructions/authorizations inside them are historical, not active. |
| `BLOCKED_BY_LIVE_REFERENCE` for deletion only | A small set of old paths is retained as successor pointers because the preceding immutable executable-architecture evidence links to them. Their old governing/plan contents are removed; this does not block consolidation or create another authority. |

The older [executable-architecture record](../BUILDERAD_EXECUTABLE_ARCHITECTURE.md) is preserved byte-for-byte from this task's starting state, including the prior local invariant note. It is **historical verification evidence**, not a current design source: its inspection dates, Workspace descriptions, PARTIAL statuses and old contract references are scoped to their recorded revisions. The preceding local report is likewise immutable here. Follow current source and the entry documents for implementation.

Historically approved original-copy SHA-256 before the revisions linked above: Markdown `47a2e18c3c07803a55a7a3b13817b1175eabec5db7ea6ffb018954aa7a77b01d`; HTML `00a10600b76569a9b2b7a99292a97a4f17690e1c7b530ca00a07e11972ed0042`. Copies preserve original bytes. Subject-matter comparison found identical normalized document text, 17 tables, 394 HTML table rows, matching sections/identifiers/relationships, no missing index anchors and no duplicate IDs. No claim of browser rendering or interaction testing is made from this structural comparison.

## Documentation task closure — 2026-11-15

**DOCUMENTATION_AUTHORITY_CONSOLIDATED.** The branch and HEAD above are unchanged. Starting state contained only the preceding local correction/report/invariant note; finishing state adds documentation-only changes. No commit, push, PR, merge, deployment, database operation or application/test/script/CI mutation occurred.

31 obsolete instruction/plan files were retired, seven old paths became successor pointers for immutable evidence links, and 71 historical narrative records were explicitly qualified as evidence rather than active instructions. The generator-owned Inertia matrix and inventory remain unchanged. The five requested modern files are present; no archive or documentation build dependency was added.

Final checks: original-copy SHA-256 equality and full normalized body/section/table parity; zero broken local links outside recorded immutable historical exceptions; zero code/test/script/CI references to retired files; exact source/preserved-evidence hash comparison across 1182 protected files with zero changes; the README literals consumed by `BaselineContractTest`; source-backed command/entry-point checks; successful `scripts/builderad.ps1 preflight`; successful Node syntax check of the original HTML's inline script; and `git diff --check`. PHP/SQL/JS behavioral suites, builds, browser interaction and Foundation were not run for this documentation task.

25 pre-existing narrative links were resolved: three point to verified historical Git source revisions, and 22 local artifacts already absent at task start retain their original paths explicitly marked `UNKNOWN` instead of dead hyperlinks. Four immutable copied-review snapshots retain 19 occurrences of 17 distinct pre-existing relative-link problems; their bytes and recorded context are preserved rather than rewritten. They are historical evidence exceptions, not active contracts or new broken references. No missing artifact was fabricated, recreated or counted as a passing witness.

The detailed before-state, per-file disposition, original-source comparison, link exceptions and final verification are local artifacts under `C:\Users\stoma\.codex\visualizations\2026\11\14\01a1dc0d-6678-7d41-aeda-5d02ff6ef80f` (`documentation-before.json`, `documentation-disposition.json`, `governing-source-comparison.json`, `documentation-verification.json`). They are verification artifacts, not another repository authority. The verified baseline above records the subsequent merge and Foundation results.
