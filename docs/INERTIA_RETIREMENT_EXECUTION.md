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

Local foundation commit: `8b1e247b5cbbaa50a7956490891581a0abf5dada`.

## Advertiser slice 1: selection and creation

Local journey acceptance covers `/workspaces`, owner reassessment, and `/business/start` creation. They use the same main SPA, Router, QueryClient, document transport and generated private application client. Laravel's existing WorkspaceAuthority, GrantAuthority and BusinessContext remain the writers/authority owners. No SQL, RLS, grant, session, identity-admission, freshness or CSRF semantics changed. `contracts/spa-migration.json` records the precise local cutover and remaining HTTP boundaries.

WorkspaceController's chooser and BusinessContextController's creation view no longer return a legacy page. Their former rendering branches are unreachable; the shared legacy Context editor remains for the separately pending edit journey. The old chooser source remains disconnected and will be removed during final cleanup. Choosing a business or finishing creation enters the still-unmigrated canonical destination through a native server document. The entire advertiser group remains IN PROGRESS.

The JSON resource contains data, locale and the existing ClientVisibility evidence, without a server-selected component or client authority. The HTML shell contains no business projection. Passive/witness GETs retain all existing route authorization and preserve canonical idle/absolute deadlines. Locale JSON acknowledgements use the existing encrypted host-scoped HttpOnly cookie; native redirects remain supported. The new creation DTO omits the unused always-null legacy `workspace` selector; this is presentation shape, not a business or security change.

The exact original creation key/body is retained after uncertain delivery. No request is automatically replayed; explicit original retry uses the existing idempotent atomic writer. Browser interception discarded the ACK only after a real 200 server commit, then withdrew creation permission: the actual 403 retry preserved the original unknown outcome and kept replacement fields locked. After restoring the synthetic fixture grant, another identical retry confirmed the same single Workspace. Three deliveries did not produce a second business. Drafts survive Arabic/English changes and same-document chooser traversal; document closure clears them.

A temporary native-document response marker lets redirected legacy requests leave for an already-migrated shell without attempting to mount a second business renderer. Existing legacy entry handling consumes it only after its existing identity response checks and validates origin/boundary before closing the old document. This adapter and the old entry are removed with final retirement. Direct links to migrated destinations use native anchors: a controlled browser comparison reproduced `Navigation preparation cancelled` when a legacy Link prepared the migrated route, while direct native departure did not. Final full journey rerun retained the strict empty-JavaScript-error assertion and passed; no N1 implementation or authority contract was changed to hide the error.

Verification:

- 47 affected PHP tests / 508 assertions passed: new resource/creation integration, existing Workspace creation, owner reassessment, Business Foundation, locale and architecture guards. The final DTO/header then passed the focused 4 integration tests / 38 assertions; the changed creation test, including post-commit permission withdrawal, passed again with 21 assertions. Raw runs are archived under `goals-spa-4dc8dbdc-0e98-43f6-9e6c-c17c3886e014`.
- 16 foundation/client tests passed; 285 existing JS tests passed. After the final native-link correction, 45 affected localization/display/independent-draft tests passed.
- Both typed contracts passed generation/drift; TypeScript, legacy production build and final SPA proof build passed.
- Final real browser gate: 5 cases PASS, zero page errors, source-unchanged=true, exact forwarded asset bytes verified. Covers sole-renderer cutover, real 200/403/409/419/422, private contract 404, creation idempotence, draft/locale/traversal, width 390 RTL/LTR without horizontal overflow, actual lost ACK/explicit retry and session revocation. Arabic screenshot visually inspected. Raw current result: `goals-spa-fbb3ec9d-ad58-4edc-a9cd-ebcd777296b9/browser-results.json`; previous attempts remain archived.
- The guard now excludes generated declaration JSDoc descriptions only, while preserving checks on actual imports and type references; positive/negative probes cover this distinction. A copied legacy description is not a new runtime dependency. The per-file ceiling only decreased.
- Required final Foundation/remote CI, remaining business/context/source/Goals/auth/internal journeys, final dependency/N1 deletion and B/C/device gates are not accepted by this slice. No push, merge, deployment or production Goals activation occurred.
