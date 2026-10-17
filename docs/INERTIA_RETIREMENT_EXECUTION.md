# Inertia retirement execution

Owner authorization: Definitive Inertia Retirement & Unified Vue SPA Execution, 2026-10-17. The target is one Vue SPA, Vue Router, generated OpenAPI clients and the existing Laravel business/security owners. The decision is accepted; this record tracks execution, not architectural selection.

## Provenance and acceptance

- Worktree: `C:\dev\builderad-worktrees\ba-inertia-retirement-001`.
- Branch: `codex/ba-inertia-retirement-001`.
- Starting HEAD, local `origin/main`, merge base and independently read remote main: `505da99f1c551f2f6143980ad624d1b591a6824d`.
- Starting tracked/staged/untracked state: clean. PR #36 merge is the starting HEAD. The Owner reports its post-merge Foundation passing; that report is not candidate evidence.
- No push, merge, deployment or production opt-in is authorized. Coherent local commits are allowed only after their tests pass.

Milestone 1 acceptance requires a complete runtime-backed route/journey inventory, one shared SPA foundation, passing focused integration and browser behavior, and explicit remaining ownership. A static inventory or successful build alone cannot close it. Later milestones require complete advertiser journeys, then authentication/internal/integration boundaries, then dependency/N1 deletion and comprehensive verification. No migrated journey may retain two active business presentations after its cutover is accepted.

## Current execution

The first foundation extraction moves the existing Goals SPA entry into `resources/js/spa/main.ts`, with one shared router factory, QueryClient and transport. Goals registers its routes and authorized presentation loader through Vue Router metadata. The shell owns no Goals projection or API calls; its transport import forwards to the shared singleton. Proof instrumentation is isolated in the Goals feature. Existing bootstrap/root DOM identifiers and the build directory are retained for the current proof consumers; they do not instantiate another app. The existing opt-in flag remains disabled by default. This foundation is not a claim that the remaining web journeys have migrated.

`scripts/inertia-inventory.mjs --runtime` generates the route matrix and component import graph. It records actual Laravel route registration with Goals disabled (167 routes) and enabled (169), with zero registered routes absent from the matrix. Local delegated controller methods are followed, including Object Purpose's private reader. The matrix covers 22 rendered page names. Controller imports and test references remain discovery leads, not accepted semantic ownership or passing coverage. Conditional routes are not claimed to be enabled in production. Runtime authorization behavior and arbitrary dynamic dispatch still require their tests. Every unaccepted journey remains explicitly pending.

`scripts/inertia-retirement.mjs` checks a per-file ceiling across application PHP, routes, configuration, resources, compatibility code, dependency manifests/locks and Vite configuration. New files with Inertia references or increases in existing files fail. `--ratchet` can only lower the ceiling. Historical documents are excluded. The temporary guard is not the final proof that Inertia has been removed.

## Owners and invariants

SessionAuthority, IdentityDocument/IdentityLifecycle/IdentityCookies, existing identity-admission middleware, WorkspaceContext and PostgreSQL RLS retain authority. The client consumes signed evidence and closes presentation; it grants no scope. Existing Laravel business services and canonical SQL writers remain the only owners of business writes.

Applicable executable architecture rules include INV-001/002 (source optionality), INV-003 (failure isolation), INV-004/006 (canonical ownership and declaration independence), INV-005 (honest missing/unknown/error states), INV-007 (decision and effect authority). No domain semantics or protected authority contracts are changed by this extraction. CoreDependencyBoundaryTest and ArchitectureSourceOptionalityTest remain applicable along with security, identity, isolation and journey gates.

## N1 responsibility disposition

| Responsibility | Target owner | Required evidence before deletion |
|---|---|---|
| History, route matching, lazy component loading | Vue Router | Deep links, Back/Forward, cancelled/error navigation and chunk failure |
| Late response/component publication | Bounded shared presentation/transport gates | Out-of-order reads, route replacement, identity/Workspace changes and delayed component resolution |
| Identity/session authority | Existing Laravel identity/session owners | Expiry, revocation, account switching, old receipts, 401/409/419 and real cookie behavior |
| Workspace/business authorization | Existing WorkspaceContext/RLS/services | Membership/grant withdrawal, cross-Workspace denial and actual writer boundaries |
| Presentation restoration | Authoritative bounded reads | Hidden/visible, pagehide/pageshow and available browser restoration; real-device gaps remain explicit |
| Draft/save conflicts and missing ACK | Feature draft state and existing versioned writers | Preserve drafts, review CAS conflict, no automatic mutation retry or claim that missing ACK means no write |
| Inertia candidate hooks, encrypted-page History and patched core artifact | Remove with Inertia | Replace behavior-level security tests; delete only implementation-specific tests after parity |

This table is a disposition requirement, not proof that each replacement currently passes.

## Environment incident

Initial dependency installation failed with ENOSPC: C: had zero free bytes. Both dependency directories were absent before this task. Task-started installers were stopped; only their partial `node_modules`, `vendor` and task-local npm/Composer caches were removed, recovering approximately 229 MB. The Owner then freed space; a subsequent observation showed approximately 6 GB free, and locked installation was restarted. No other worktree or Owner data was removed.

## Acceptance boundaries

Migration completion does not authorize release. Prior local A acceptance keeps only its original scope; B/C remain blocked until separately satisfied or changed by the Owner. Real iOS/Android evidence, deployment and any unavailable restoration coverage must remain explicit. Do not alter no-store or enable Goals in production to obtain a pass.

## Milestone 1 local verification

Status: shared foundation locally verified; no legacy business journey has been cut over or deleted. Subsequent journey migration is authorized to continue. The entire retirement Definition of Done remains OPEN.

- Approved locked dependencies installed after the disk incident; npm reported zero vulnerabilities. No lock/dependency versions changed.
- Shared foundation: 13 Node tests passed, covering abort/duplicate navigation recovery, current versus superseded component errors, delayed response fencing, identity/CSRF closure, CAS/403/422 distinctions, uncertain writes and same-origin evidence.
- Existing JavaScript suite: 285 passed. Its legacy components, domain logic and generated Goals client were not changed by the later shell-only correction.
- TypeScript and both web builds passed; the final proof build includes the feature-independent shell and corrected router. OpenAPI generation/drift passed; the Goals contract and writer are unchanged.
- Isolated affected PHP gate: 79 passed / 873 assertions. Includes Goals SPA integration/proof, canonical Goals writer, production identity, renewal, document/session authority, DB authority and architecture guards. Evidence: `storage/framework/testing/goals-spa-4dc8dbdc-0e98-43f6-9e6c-c17c3886e014`. The whole-tree unchanged marker is false because independent client/tests/docs were developed during the run; PHP evidence applies only to unchanged relevant PHP/contracts/configuration, not the entire final tree.
- Real Chromium comparison: 36 PASS / 2 UNVERIFIED / zero findings, with served-asset byte verification. The two unverified cases are native SPA BFCache expiry/switch: no trusted persisted event was observed. Fresh-document safety passed. This comparison predates the final shell-only correction; it is not relabeled as a run of the final bundle. Raw full comparison is retained in `browser-results-attempt-1.json` in the browser run below.
- Final shell's focused browser gate: 6 PASS, source-unchanged=true, served-asset byte verification, actual held/rejected built component network requests, aborted route recovery, newer-Workspace and current-route recovery, native reload/deep link and RTL width 390 with zero horizontal overflow. Screenshot visually inspected. Evidence: `storage/framework/testing/goals-spa-fbb3ec9d-ad58-4edc-a9cd-ebcd777296b9/browser-results.json`. A desktop viewport is not real-phone evidence. Browser subset result is REQUIRED_GATES_REVIEW, not launch acceptance.
- All task-started proof servers, browser/proxy, Redis and PostgreSQL processes stopped. The runner's source manifest now records intentional tracked deletions as tombstones rather than failing before tests.
- Full final Foundation, complete migrated journey parity, final no-Inertia guard, real-device gates and remote CI remain pending. No push, merge, deployment or production activation occurred.

The corrected router follows Vue Router's official success/aborted/duplicated/error hooks. A single pending normalized-route reference suppresses an obsolete component error; it implements neither History nor identity recovery. Regression probes reproduced suspended presentation after aborted navigation, closure by an obsolete component error, and closure when returning to the current URL during a pending import. Behavior-level tests and real asset failures cover the corrections.
