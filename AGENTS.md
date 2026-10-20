# Builderad implementation guidance

Before material product implementation:

- Identify the accepted semantic/capability owner and applicable INV-* rules in [Executable Architecture](docs/BUILDERAD_EXECUTABLE_ARCHITECTURE.md).
- Preserve provider/source optionality; source-dependent conclusions still require their legitimate evidence.
- Keep provider implementation dependencies outside declared Core boundaries.
- Do not convert missing/failed/unknown evidence into false certainty.
- Update applicable invariant protection when accepted landed semantics change; do not invent future capabilities to obtain PASS.
- Run applicable architecture guards and Foundation gates.

Canonical governance/contracts remain authoritative. This file is operational guidance; tests, database authority boundaries and Foundation CI enforce the declared coverage.

### Engineering verification, regression investigation, and database safety

This guidance does not override canonical governance, accepted contracts, mandatory gates, or explicit Owner instructions.

#### 1. Core principle

Engineering work must remain correct, secure, efficient, and proportionate to actual risk.

Developers and coding agents are expected to exercise technical judgment within the authorized task. These instructions must not prevent necessary refactoring, SQL optimization, migration work, or legitimate architectural improvements within that scope.

Verification exists to establish correctness, not to create unnecessary execution cycles.

#### 2. Establish the baseline before investigating failures

When a test or required verification gate fails:

- Identify the last authoritative passing revision for the affected gate, including its commit SHA and available CI evidence.
- Since tasks commonly use separate Git worktrees, establish the current worktree's base revision, subsequent commits, and tracked, staged, and untracked changes.
- Preserve the existing worktree state and all unrelated work.
- Compare the failing candidate with the last relevant passing revision before beginning broad investigation.
- Establish whether baseline and candidate were verified under materially comparable environments, fixtures, test runners, and database configurations. Record differences before attributing causality.
- Examine changes to implementation, tests, fixtures, dependencies, migrations, configuration, and execution environment.
- Prioritize changes that plausibly affect the failing behavior or its dependencies.

A previously passing Foundation result is a starting point for regression investigation, not proof that every historical behavior was defect-free.

Do not assume the most recent commit is the passing baseline.

#### 3. Diagnose failures efficiently

Investigate failures through evidence and controlled comparisons:

- Identify the precise failed behavior, error, test, and execution conditions.
- Use existing CI logs, test artifacts, and repository history before generating new evidence.
- Distinguish between candidate regressions, baseline defects, environment differences, test or fixture defects, and intermittent failures.
- Start with the smallest experiment capable of distinguishing the leading explanations.
- Do not automatically attribute a failure to the latest change, but do not dismiss it as unrelated without evidence.
- Do not label a failure flaky merely because an isolated retry passes.
- Before modifying production behavior to resolve a test failure, establish whether the failing expectation and test orchestration reflect the intended product contract. A test failure is evidence requiring investigation, not by itself authorization to change production semantics.
- Avoid broad performance investigations or unrelated optimizations unless evidence establishes their relevance to the failure.

For concurrency failures, distinguish worker bootstrap/readiness, the actual lock attempt and observed blockers, early worker exit, and timeout. Retain worker output, connection identities, and wait evidence. A timeout alone does not establish a product defect; successful verification must still observe the required real contention.

If two focused diagnostic experiments do not materially narrow the cause, stop unproductive expansion, summarize the evidence, and identify the smallest useful next step. Escalate before starting another prolonged investigation.

This limit applies to unproductive investigation, not to the number of tests required to verify a clearly understood correction or productive work converging toward a solution.

#### 4. SQL and database engineering

SQL and database changes are normal engineering work, not exceptional operations requiring separate permission merely because they modify SQL files.

Within the authorized task, developers may design, refactor, optimize, and implement SQL, database functions, queries, indexes, and migrations using sound technical judgment.

Apply safeguards according to actual impact:

- Understand the affected behavior, database contracts, callers, dependencies, and deployment state.
- Preserve intended data integrity, authorization, tenant isolation, RLS, transactional guarantees, and concurrency semantics unless changes to those contracts are explicitly authorized.
- When changing existing database objects, determine whether a migration is required for already-initialized databases. Keep installation definitions and upgrade paths consistent.
- Prefer safe, appropriately scoped migrations that preserve existing data and permissions. Test upgrades, repeatability, and fresh installation where relevant to the actual change.
- For meaningful SQL optimizations, verify the required behavior remains correct and measure performance when performance is the reason for the change.
- Test relevant success cases, failure cases, and database boundaries proportionately to risk.
- Use approved isolated and disposable test environments. Never run destructive development tests against production, preview, or shared owner data.

Do not introduce unnecessary migrations, compatibility layers, tests, or approval steps for changes that do not require them.

Do not change security contracts, lock ordering, transaction isolation, authority semantics, or critical timeouts merely to make tests pass.

If the authorized task genuinely requires changing such contracts, identify the impact and obtain the applicable architectural or owner decision before proceeding.

The goal is to enable correct database engineering while preventing accidental changes to protected behavior.

#### 5. Verification during development

Select tests according to the impact of the change, not the number or extension of modified files.

- Documentation-only changes: validate affected content, references, and formatting.
- Visual changes: verify affected screens, relevant viewports, RTL, interactions, and accessibility.
- Local application behavior: run focused tests covering affected behavior, failure paths, and immediate consumers.
- Shared contracts, database behavior, authority, tenant isolation, security, schema, migrations, and infrastructure: run applicable focused integration, boundary, and architecture checks.
- Uncertain or broad impact: expand verification as necessary to establish adequate coverage.

Use official repository test entry points and required environment guards.

`npm test` is the complete JavaScript selection; `npm run test:spa-foundation` and the other `test:*` commands are focused subsets. Run one production `npm run build` per comprehensive gate (`build:goals-spa` is an alias); proof-mode builds remain distinct. See [validated command mapping and evidence](docs/BUILDERAD_TEST_EXECUTION_RATIONALIZATION.md#canonical-verification-commands) for guarded PHP integration and browser entry points.

Do not run the entire Foundation suite automatically after every edit.

#### 6. Reuse valid evidence and avoid redundant testing

Passing evidence applies to the actual tested file state, including uncommitted changes, configuration, dependencies, and relevant test conditions. A commit identifier alone does not establish equivalence for a dirty working tree.

- Reuse passing test evidence when the relevant code, dependencies, configuration, and test conditions remain unchanged.
- After a correction, rerun the failed or affected checks rather than automatically repeating all previously passing checks.
- Invalidate earlier evidence only when subsequent changes affect what that evidence establishes.
- Do not rerun expensive tests merely to produce a fresh log.
- Do not skip relevant regression or security tests solely to save time.

A focused test failure should lead to a focused investigation, not an automatic full-suite rerun.

#### 7. Final acceptance and CI

Development verification and final acceptance are separate stages.

- Before final acceptance, identify all mandatory gates applicable to the candidate.
- Run required gates on the final candidate revision, or rely on authoritative CI evidence covering that exact revision under applicable repository policy.
- Prefer existing official GitHub CI workflows for final comprehensive verification when they provide the required coverage.
- Do not repeat equivalent comprehensive tests locally without a concrete reason.
- Focused passing tests do not replace mandatory final gates.
- A failed, blocked, or unexecuted required gate is not PASS.
- Do not claim deployment readiness or full acceptance solely from focused test results.

The complete hosted gate is `.github/workflows/foundation.yml`; the isolated Windows entry point is `pwsh -NoProfile -File scripts/Test-GoalsSpa.ps1 -Phase Foundation`. Browser lifecycle checks use a separate phase and are not implied by either Foundation result.

If CI requires a Push or Pull Request, respect the task's Git authorization boundaries. Do not publish or merge without permission.

#### 8. Engineering integrity and execution discipline

- Never weaken assertions, disable guards, bypass permissions, or conceal failures to obtain PASS.
- Never alter timeouts or concurrency expectations merely to hide an unresolved failure.
- Preserve unrelated changes and existing worktree state.
- Avoid speculative fixes, unrelated refactoring, and prolonged trial-and-error without evidence.
- Stop task-started servers, workers, watchers, and disposable test processes when finished.
- Do not expand the task's product scope or change protected architectural decisions without authorization.

#### 9. Completion report

Report concisely:

- What changed and why.
- The relevant baseline and investigated regression, when applicable.
- Tests executed, results, and the revision they cover.
- Mandatory gates completed, pending, or blocked.
- Material unresolved risks or acceptance conditions.

Stop testing when the requested behavior is verified and all applicable acceptance requirements are satisfied.

**Operating rule:** Investigate regressions from the last relevant passing baseline, use the smallest sufficient verification during development, preserve database and security contracts, and establish final acceptance through the required gates—without unnecessary restrictions on legitimate engineering work.
