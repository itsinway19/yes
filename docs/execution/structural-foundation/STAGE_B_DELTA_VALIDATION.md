# Stage B — independent interest-delta validation

Date: 2026-11-06. Status: LOCAL_PACKAGE_VERIFIED / HOSTED_FOUNDATION_PENDING.

## Goal, authority and baseline

Spec: the Owner adopted STAGE_B_EXECUTION_BRIEF and its four execution additions in this conversation. This authorizes one bounded routine package after qualification, without protected semantic/authority changes, Owner-data operations, commit, push, merge or deployment.

Baseline: detached isolated worktree at `e2fd972985be3c1bb7c9d6a80b73b23a45281096`, tree `f0d6a1eda6481d82e01d740ef6df3de7f54c3e72`. GitHub main matches; Foundation run #147 / `42162995488` succeeded at that SHA. No open PRs were returned by the live repository query. Existing tracked and untracked PROGRAM, BUILD_LINKAGE, progress and review work remain preserved; the clean main CI is not evidence for these local additions or the new correction.

## Selection and G8 qualification

This is an F03/X01 correction to the existing D2 completion boundary, not a new analysis engine. `Findings::validate` accepts a delta of +0.000001 for original values 0.000002 → 0.000001, even with the correct -50% result. The executed source-only baseline probe returned `ACCEPTED_REVERSED_DELTA`. Cause: an absolute 0.00001 allowance contradicts the method's accepted eight-decimal output contract. The built-in method computes correctly; this is a defective independent validation boundary, not evidence of malicious external input or existing corrupt historical records.

Known consumers: MarketProcessing → trusted completion → private findings/publication → qualified CustomerDiscoveryConsumer (which checks positive delta). Existing numeric semantics and ownership suffice. False positive direction is consequential to these consumers; fixing the gate precedes new uses. G-01 Business-native data-use issuance still needs a protected issuer policy; no grant is invented here. The historical G-02 workbench read-disclosure approval remains deferred. Neither blocker stops this independent correction. Generic F02/D01 expansion has no demonstrated missing contract warranting replacement of existing owners.

Architecture/technology: existing pure PHP method and independent validator, existing Laravel HTTP processing, PostgreSQL custody/RLS and trusted-completion owner. No new service, framework, provider dependency, schema, migration or endpoint. Preserve INV-001 provider independence, INV-004 fact ownership and INV-005 honest knowledge states. Selection does not imply that the whole platform, AI, automation or the remaining program is complete.

Inputs: two already-qualified market.interest_signal:2 originals, same qualified source, comparison semantics and time windows, finite numeric values in [0, 1e12], positive baseline. Output: existing comparable finding, delta rounded to eight decimals and percentage only for a qualified ratio scale. The independent validator recomputes delta from original values; a changed declared decimal unit or reversed sign must fail. It must continue accepting legitimate rounded outputs, integer/float representations, subnormal values and numeric boundaries.

Dependencies verified for this use: actual QualifiedInterestChange computation and arithmetic declaration; Findings shape/finite/ratio checks; MarketProcessing validates before issuing completion and records validation exceptions as failed; existing trusted SQL ticket and publication custody; consumer's direction test. Source rights, Business selection, correction, withdrawal, expiry and replay are existing owners, exercised by the affected integration selection. All DB fixtures must be isolated/disposable; the runner checks occupied ports before contacting any service. No Owner database will be contacted. Missing vendor is an environment dependency, resolved by frozen Composer install without scripts/updates; no new production dependency is required.

Research reuse: BUILD_LINKAGE §§3–5 and PROGRAM F03/X01 bound this package to existing owners and real consumers. BF02's distinction between observed interest, opportunity and buyers is retained; R01 CONSOLIDATED_DECISION's rejection of unsupported numeric accuracy/causal claims is retained. This correction checks exact arithmetic and contract behavior, not real-market validity. No specialist-model selection or external study is needed to establish the demonstrated arithmetic contradiction. Revalidate when numeric units/range/rounding/method version or consumer meaning changes; do not reinterpret persisted history.

## Acceptance and execution plan

- [x] Reproduce wrong-sign acceptance against baseline source; trace validator, completion and consumer.
- [x] Add regression tests first: reversed signs, one eight-decimal unit, ratio/non-ratio and legitimate rounding boundaries.
- [x] Record RED with unchanged production validator, including an actual prepared HTTP processing run using real computation followed by controlled wrong-sign output.
- [x] Correct only independent rounded-delta validation; preserve method output, percentage arithmetic and authority.
- [x] Run unit and isolated HTTP completion/replay tests; malformed output becomes failed with no persisted/publishable finding, and replay does not execute again.
- [x] Run affected processing/completion-security/knowledge/publication/consumer and architecture checks. Preserve failures, exact counts and final source identity.
- [x] Fresh scoped review, formatting and process cleanup. Final identity is recorded in `storage/framework/testing/stage-b-delta-20261106/candidate-manifest.json` and its checksum; hosted Foundation remains pending for the changed candidate.

No UI changes or browser claim: this package changes an internal validation boundary. Existing public result states and APIs remain unchanged. No new surface is justified for the primitive. The user benefit is rejection of contradictory analysis before it becomes trusted knowledge.

Ruling: use this existing linked worktree and progress record, not a new checkout from main that would omit local studies. Execute inline using the worktree, planning, systematic-debugging and TDD skills; retain evidence rather than deleting prior work. The authorization excludes commits even where a generic skill suggests committing.

## Execution evidence

Evidence root: `storage/framework/testing/stage-b-delta-20261106`. Initial unit RED: 14 cases, 45 assertions, 7 failures. Expanded RED includes zero/subnormal/sub-quantum alterations: 16 cases, 47 assertions, 9 failures. Logs/JUnit remain failed. Initial guarded HTTP run `goals-spa-ed694edb-a07b-497b-89a8-3bdb82b777a8`: 4 failed / 50 assertions; 2 demonstrate wrong-sign completion as validated, 2 fail because ajv was missing. Source unchanged=True and task-owned services stopped. The earlier restricted launch failed at CIM preflight before starting services; this is an environment failure, not a behavior result.

Frozen Composer install completed (129 installs, zero updates/removals, no scripts); frozen npm ci completed (192 packages, no scripts). Restricted attempts failed due sandbox DNS/file access and were retried with execution approval. Lock files are unchanged. Initial unit invocations could not read the installed bootstrap under restricted execution and did not execute test cases; the approved RED/GREEN runs above did execute.

Implementation: exact float comparison with `round((float) $after - $before, 8)`. No tolerance is appropriate at zero: even an epsilon-sized false positive could change the consumer's direction branch. Integer/float equivalence and legitimate JSON round trips remain accepted. The method's producer expression, percentage logic, public shapes, trusted-completion owner, DB schema and historical records are unchanged.

Unit GREEN: MarketProcessingMethodsTest + CoreDependencyBoundaryTest, **82 PASS / 233 assertions**, PHP8.5.10 / PHPUnit12.5.35. Focused Pint on all three changed PHP files: PASS. Fresh read-only scoped review found no actionable correctness/regression findings and passed diff whitespace checks; it did not independently run integration tests.

Guarded affected GREEN reused only the recorded task-owned disposable cluster, preserving failed logs under prefix `b807ff86-288c-4914-a235-00ff0c52ca3d-`: **132 PASS / 1065 assertions**, runner duration445.62s, exit0, source unchanged=True, PostgreSQL/Redis stopped. Selection: MarketProcessingMethodsTest, CoreDependencyBoundaryTest, MarketProcessingTest, MarketProcessingCompletionSecurityTest, MarketKnowledgeCompletionSecurityTest, MarketBusinessFindingTest, MarketKnowledgeConsumersTest, MarketKnowledgePublicationTest and MarketKnowledgeOperationsIntegrationTest. All four new HTTP cases pass: legitimate declines validate in both ratio modes, reversed signs fail; failed custody has null finding and METHOD_OR_VALIDATION_FAILED; attested failure/success replay durably with exactly one execution. Private-capture SQL rejects failed/null-finding runs before publication qualification; downstream security/publication/isolation tests pass.

After that run, a test-case label was corrected from “subnormal” to “minimum-normal” to describe PHP_FLOAT_MIN accurately; input/assertions did not change. Final unit rerun: **82 PASS / 233 assertions**, `unit-green-final.xml`, final source hashes in `unit-final-source.json`. Production/HTTP-test bytes remain the guarded GREEN bytes. Final Pint and composer validate --strict pass. The final candidate manifest/verification JSON reconcile these tested states; documentation completion updates are not new runtime tests.

## Closure and next dependency

Local bounded acceptance is satisfied. Hosted Foundation/CI on this changed candidate: NOT_RUN / pending authorized publication; main Foundation147 is a separate baseline PASS. Browser/build/JS, concurrency scheduling and migration-upgrade reruns were not required by this internal PHP arithmetic correction: no UI, API shape, SQL, lock ordering, schema, timeout, dependency version or producer calculation changed. No new advertiser journey, real-market effectiveness or historical-data audit is claimed; the validation defect is not evidence of pre-existing wrong stored results.

Integration remains local in the existing detached worktree: no commit/push/PR/merge/deploy or Owner database operation. Prior studies/local work, failed evidence and the synthetic cluster data are preserved; task services and installers are stopped.

The next known operating blocker remains G-01/BN-USE-01: a protected Business-native data-use issuer/policy, scope and withdrawal boundaries, distinct from Source connection. This fix does not authorize that policy or automatically qualify another package. F02/D01 extensions and specialist consumers require a demonstrated missing behavior and their own contracts; AI, automation, learning and other PROGRAM families retain their linked owners/dependencies. This closes the one authorized package rather than starting another planning cycle.
