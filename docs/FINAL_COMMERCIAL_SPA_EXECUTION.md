# Final commercial SPA — Slice 6

## Execution boundary

Approved base: `be49b3745c3dcc8c908de5cb3c7f4ab8fe4ffa49`. Branch: `codex/ba-inertia-retirement-006`. The named branch and worktree were absent and created from the exact fetched approved origin/main. Previous worktrees remain unchanged.

Scope is the existing `/internal/administration/commercial-markets` and `/internal/commercial` displays. Laravel controllers, MarketRegistry, ApprovedCatalogReference, RegisterCommercialScope, CommercialAuditDelivery, GrantAuthority, SessionAuthority and PostgreSQL/RLS remain the execution owners. INV-004/005/007 require original writers, honest command outcomes and server-only authority. No provider evidence or capabilities are added.

## Acceptance before cutover

Group A must preserve the founder-bound encrypted intent, target and configuration-child identities; current authority/freshness; direct creation, delegated approval/operator and existing-market delegation; explicit new intent; known ACK versus failed readback versus unknown delivery. Display reads must not replace unresolved attempts or imply approval.

Group B must preserve frozen command identifiers, payloads and expected revisions, exact original-command receipt provenance, explicit contract-specific retry, audit recovery and grant/mandate contracts. New reads cannot rebase dirty drafts. A→B→A while a request is pending must reject callbacks from the earlier selection lifetime.

Group C requires actual Laravel/PostgreSQL/RLS/Redis/Chromium journeys across both commercial screens and existing operational screens, authority denial via direct HTTP, session loss, pending response suppression, language/navigation/restoration, targeted contracts/security/SPA/frontend checks, TypeScript, both builds and actual inventory. Neither display is cut over before its replacement evidence passes.

## Delivered scope and ownership

Both GET displays now return the existing `SpaPage`: HTML contains no commercial projection, binding or private page payload; admitted same-path JSON reads supply the existing projections. Both canonical records use the existing application and Vue Router. Operational links use that router. No second application, router, transport, identity protocol or writer was created.

Preparation preserves direct country/currency preparation, delegated approval, eligible operator selection, existing-market delegation, approvals/history and explicit new intent. The founder's encrypted composite binding remains issued and checked by Laravel; intent, target and child configuration identities survive reload and explicit identical-command retry. Founder accounts remain excluded from operator selection, as in the original screen.

Commercial operations preserve the original registry, approved catalog reference, immutable configuration, assignability, exact beneficiary/configuration registration, workspace reference/context, receipt inspection and audit recovery interfaces. An unsolicited read cannot replace a dirty draft's expected revision. Starting a new command is explicit. Local preferences remain presentation-only.

**Owner scope decision:** do not add new commercial grant or owner-mandate forms. Those existing Laravel APIs and authority services remain unchanged; generated bindings do not constitute a migrated user journey. This slice preserves the existing display scope, not a new sensitive command interface.

The only production PHP changes are the two display controllers: replace their renderer, preserve the exact legacy visibility-probe JSON representation after existing checks, and acknowledge `newIntent` as `{saved:true}` for the actual JSON consumer while retaining its native redirect. Original authorization, freshness checks, transaction order, SQL writers, grants, audit and RLS are unchanged. There are no changes in `database`, `app/Security`, `app/Foundation`, `compat`, route files or bootstrap middleware.

## Command outcome and concurrency contract

- Known ACK and failed refresh remain separate. A failed readback does not make a completed write fail or enable an implicit second write.
- Lost ACK retains the original principal-bound command identifier, version, payload and preparation binding. Neither reload nor current-state reads mean success. There is no automatic replay.
- Explicit identical-command retries use the existing supported idempotency contracts. New-intent uncertainty has no implicit retry: reviewing current state and explicitly abandoning the original unresolved attempt are separate actions, never proof that it succeeded.
- Receipt settlement requires the original command, principal, effect, target, reason and request fingerprint. The presentation helper mirrors the existing SQL scalar signature, but grants no authority; Laravel/PostgreSQL still own receipts and effects. Real PostgreSQL receipts validate positive matches and reject a changed reason. Raw PDO receipt `result` is a JSON string, not an invented response object.
- Shared identity ownership plus local selection lifetimes invalidate pending callbacks even for A→B→A. Cancellation, observed identity closure and component disposal clear private displayed data; they do not assert that server effects were cancelled.

## Baseline executed acceptance (reviewed candidate)

Official isolated runner: `scripts/Test-GoalsSpa.ps1`, PostgreSQL 18 disposable database and three verified roles, Redis, actual Laravel HTTP and Chromium `145.0.7632.6`. Virtual UV factors and synthetic operator-independence fixtures are not physical-factor acceptance. No real mail or advertising accounts are used.

The original production-asset run before the P2 follow-up correction: **10/10 browser cases PASS, zero unhandled page errors**, plus the two preparation PHP cases **2 PASS / 37 assertions**. These are baseline evidence, not execution of the later corrected component. It navigates actual rendered RouterLinks, without requiring the proof-only router hook:

1. Actual signed internal UV login reaches the unified operational view without private bootstrap.
2. Stable founder binding and actual atomic direct country/currency preparation.
3. Known preparation ACK followed by failed refresh; explicit review does not replay.
4. Committed preparation with lost ACK; reload preserves the binding and only explicit retry resends the identical command.
5. Fresh authentication expires after load; direct HTTP cannot bypass the current server check or create a market.
6. Vue records an approved reference, immutable configuration and assignability through the original writers.
7. Lost assignability ACK is not settled by a current revision; matching original-command receipt provenance settles it.
8. Exact beneficiary/configuration registration, context read and acknowledged audit recovery.
9. Operational/commercial navigation, local Chromium Back/Forward, English/LTR and Arabic restoration, and native reload.
10. Actual A→B→A with a held context response, followed by real session revocation: old private responses cannot publish after owner closure.

Delegated/operator branches retain their existing server tests and visible controls; the ten browser cases above do **not** claim an additional end-to-end delegated multi-person ceremony. Grant/mandate APIs are not new browser forms or claimed browser acceptance.

## Focused gates and reused evidence

| Gate | Actual result and scope |
| --- | --- |
| Affected PHP commercial/security classes | Initial combined run: 52 PASS, 1 FAIL, 717 assertions. Sole failure was the legacy visibility-probe representation. After its narrow fix, all five visibility tests plus two preparation tests PASS: 7 tests / 60 assertions. Unaffected passing cases are reused, not falsely reported as a fresh full-suite run. |
| Real receipt/SQL contract | Focused new commercial projection/provenance test PASS / 27 assertions, including stale-version refusal preserving the newer value, separate receipt-read permission, actual response schema and positive/negative fingerprint comparisons. Included in the affected coverage, not an additional disjoint count. |
| Architecture | 14 PASS / 69 assertions, official isolated entry point; no Core/provider or authority expansion. |
| Final SPA suite | 160 PASS, including eight new preparation-state/actual commercial-editor component cases for frozen versions, ACK/readback, lost ACK, explicit retry, denial and late-response ownership. Controlled-port component tests are not called browser or authority proof. |
| Frontend compatibility suite | 290 PASS. Existing legacy-component and N1/history/visibility tests remain; their success alone is not evidence for the new UI. Reused because their relevant source/dependencies did not change afterward. |
| Contracts | Generation and `api:check` PASS. Seventeen paths/schemas added for actual Laravel responses; semantic comparison found no changes to existing paths/schemas despite generated ordering churn. |
| Formatting and TypeScript | Scoped Pint PASS; `vue-tsc` PASS in both production builds. |
| Builds | Legacy and unified SPA production builds PASS. Final browser proof serves the production SPA build, not the proof-mode bundle. |
| Inventory | Runtime generation and `--runtime --check` PASS; Inertia ceiling PASS; `git diff --check` PASS. |

Relevant official PHP filters were `CommercialScopeTest|CommercialMarketPreparationTest|ClientVisibilityTest|InertiaHistorySecurityTest`, then the affected visibility/preparation recheck. No complete Foundation rerun was made locally merely to refresh logs.

## Investigated failures and corrections

- The post-cutover legacy visibility probe returned HTML rather than the existing metadata JSON. The unchanged exact-JSON security assertion exposed it. Both controllers now preserve that representation; authority and idle-deadline assertions pass without middleware/N1 changes.
- An actual-component test exposed receipt-selection invalidating the editor's own callback before ACK publication. Ownership is now captured after local receipt selection; known ACK remains distinct from readback failure.
- Receipt reads correctly returned 403 when the fixture possessed writer authority but lacked independent read authority. The test now first asserts that denial, then explicitly grants read authority before positive receipt checks; no production permission was loosened.
- Browser fixture reuse after promoting an advertiser to staff and an invalid beneficiary choice were corrected in test orchestration using the official empty-state reset and the eligible advertiser fixture, not by weakening server eligibility or assertions.
- Label selection and an aborted test proxy response exposed harness issues. Explicit control labels and the existing proxy hold/release mechanism correct them. No security timeout, assertion or authority semantics were relaxed.

Failed attempts and passing reruns remain archived in the same local evidence directory; they have not been deleted or relabeled PASS.

## Provenance

Evidence root: `storage/framework/testing/goals-spa-c228efd9-54a9-44cf-93e1-ceaae28e19f2/` in this worktree. Local test evidence is ignored by Git and is not claimed as published.

- Preserved `slice6-accepted-browser-results.json`: SHA-256 `5237f231fa1ab2c786855ebebcba9f991f1e380402c578c04e8fe7000f549019`.
- Preserved `slice6-accepted-source-manifest.json`: SHA-256 `7916aa8efea6a24be9de599cbd1c55b4530bdc4b70b2e40a6b652713949bf3a3`.
- Preserved `slice6-accepted-source-unchanged.txt` is `True` for that baseline browser run. Its reported Git HEAD is the approved base because execution tested the dirty implementation state, not a nonexistent clean commit. File hashes establish what was tested.
- All 42 distinct delivered production asset files were compared against the current `public/build` files: zero hash mismatches. Proxy body/sent hashes match; independent browser URL/status observation is recorded separately, not misrepresented as a CDP body hash for aborted navigations.
- Commercial JS: `OperationsView-DO0Q51bx.js` SHA-256 `e765616a29d6c30af7449c2d68fba033c97217d79c53325d1a33c3861b32dd94`; `PreparationView-OVFHWk6P.js` SHA-256 `6e4cb7766530a60546ba5763a47059ccdc0c143fc37fa0f5fdf35ec0cb35834c`.
- Subsequent inventory/status metadata and this report do not change the tested application executable files. The staged whitespace check also removed one redundant trailing blank line in `tests/commercial-editor-concurrency.test.mjs`; its four cases were rerun and passed. That test-file hash difference is not concealed as byte-identical evidence. All application/server/browser-runner source hashes still match the production browser manifest.
- Archived `12fdf941-6131-4c48-9b6a-a0a91269b966-tests.log` contains the combined PHP run; `c64a4d8e-ee79-44c8-8eaa-1b5313cf8fd6-tests.log` the corrected seven-test run; `15199a10-bae6-45dd-9056-1a97045210ac-tests.log` architecture results. `storage/framework/testing/slice6-final-spa.log` contains the final 160-test result.

The official runner reports `GOALS_SPA_PROCESSES=STOPPED; EXIT=0`; browser/proxy are closed and task-owned listening ports are absent. Previous worktrees and evidence are preserved.

## Final inventory and dependency separation

**ZERO_INERTIA_DISPLAY_ROUTES: VERIFIED.** Actual runtime/source inventory records **173 registered routes, zero legacy render routes, 142 frontend modules**, both Goals opt-in states. The two final GET displays left Inertia; POSTs, callbacks and legitimate redirects were not subtracted as displays. Generated inventory discovery leads remain discovery leads, not proof that every listed test passed.

**DIRECT_PRODUCTION_CONSUMERS: RETAINED.** The dependency ceiling reports **46 files**. Twenty-one JS/TS/Vue files directly import Inertia packages/helpers (including declarations): legacy `app.ts`, visibility/identity/navigation/history adapters, shared layouts/link/language components, localization, retained legacy Pages and type declarations. Six PHP files directly import Inertia: `ProfileController`, `Auth/PasskeySessionController`, `HandleInertiaRequests`, `ExpiredSecuritySession`, `IdentityAuthentication`, `IdentityDocument`. Static dependency presence is not a claim of reachable production display rendering.

**DEPENDENCY_REMOVAL_READINESS: NOT_READY / SEPARATE AUTHORIZATION REQUIRED.** Active shared middleware, identity/logout/history and visibility responsibilities remain. Legacy `resources/views/app.blade.php`, the `app.ts` page resolver, Vite's hash-pinned N1 artifact integration, retained test/build-consumed Pages, composer/npm packages and lockfiles still exist. Old commercial Pages are no longer route-rendered but remain compiled/test consumers. No package/runtime/file deletion is performed under this slice. Zero displays is not zero dependencies or proof that deleting shared protections is safe.

## Limits and disposition

No physical keys/devices, native mobile BFCache, pre-JavaScript cached-frame confidentiality, cross-platform B/C, SSE acceptance, production performance, deployment or release acceptance is asserted. Foundation CI and independent review of this candidate remain separate future gates requiring their applicable authorization. Native fallback/new-intent responses and existing authority tests are retained, not replaced by client assertions.

No Push, PR, Merge, deployment, production enablement or Slice 7 work is performed. No previous worktree is removed. Local implementation and its focused acceptance are ready for independent review; this does not claim acceptance of dependency removal or the overall retirement project.

## Local commit closure

Implementation commit: `ca7649423ea54057095185a3c3cd71860806d14f`; parent is exactly the approved base `be49b3745c3dcc8c908de5cb3c7f4ab8fe4ffa49`; tree `eb9ef8bb48196762a3f9f98ba39c373b230b6af6`. Git status was clean after that commit. A following documentation-only commit records this closure without changing tested implementation. Inventory check and ceiling passed again after committing. The final HEAD and clean Git status are supplied with delivery, not attributed retroactively to the earlier dirty-state test run.

## P2 operator follow-up remediation

Reviewed baseline: `8391d8d3d6733f8833372f10e1efa733c16ec80c`, verified on `codex/ba-inertia-retirement-006` with a clean starting worktree. The prior readiness marker is superseded by this targeted remediation and its required independent re-review.

### Root cause and exact correction

The old `Pages/Internal/CommercialMarketPreparation.vue` supplied `reviewRetiredTarget()`. The SPA replacement retained the original unresolved operator request and correctly did not turn a retry's 409 into success, but omitted the explicit way to end local follow-up after the matching approval was consumed. `pending` therefore locked the forms indefinitely. This is functional parity, not a change to grant authority.

Only `PreparationView.vue` changes production behavior. A successful explicit review now binds an operator-only candidate to the original pending command object, the exact currently admitted projection and the existing transport ownership stamp. Review holds the existing busy lock. Changed ownership, a failed/unadmitted/superseded read, a different or unconsumed approval, a changed command, or an in-flight operation cannot authorize local archival. A 409 alone never enables the action. Confirmation rechecks ownership before saving.

Archive records retain `{command: originalFrozenRequest, outcome: 'unknown'}` in the existing principal-bound sessionStorage namespace. A deterministic key from the original approval/operator/reason makes re-entry idempotent. Saving precedes active-state deletion. A failed save retains pending. If saving succeeds but cleanup fails, the same archive is reused; retry cannot duplicate it. On reload an exact matching archived request is recognized and cleanup is resumed without restoring/replaying it as an active command. Unknown history is available in a local read-only disclosure and clears from the view on identity closure. It is not an authority receipt or proof that operator authorization succeeded.

The `newIntent` archive behavior is retained. The operator action does not apply to prepare/delegate/newIntent, issue a grant, create an intent/command, call a writer, or resend anything. The original `preparationState` transport, admission and witness implementation is unchanged. No SQL/RLS, Laravel controller/authority, SessionAuthority, N1, freshness policy, package or contract changes are made. No grant/Owner Mandate form is added.

### Red/Green and focused checks

- Same final component-test file, with `COMMERCIAL_OPERATOR_BASELINE=reviewed`, compiles the actual component from `git show 8391d8d...:resources/js/commercial/PreparationView.vue` without replacing files: **6 PASS / 5 FAIL**. The primary case fails because the end-follow-up action is absent; another failure proves the missing review busy lock. Log: `storage/framework/testing/slice6-operator-red-final.log`.
- Without that baseline switch, the actual corrected component plus the adjacent commercial editor tests: **15 PASS / 0 FAIL** (11 preparation tests, including seven new actual-component cases, and four existing editor cases). Log: `storage/framework/testing/slice6-operator-green-final.log`.
- Cases cover first operator form submission with lost ACK, identical explicit retry returning 409, matching consumed review, explicit archival, unknown outcome, unlocked forms, reload/history, failed/unconsumed/different/unadmitted/superseded reads, cancellation of confirmation, identity closure including during confirmation, pending/late reads, archive write failure, interruption after writing, failed deletion, duplicate prevention and reload cleanup. Original requests and write counts remain unchanged on refusals. Existing new-intent controls remain available only through their original behavior.
- `npm run build:goals-spa` PASS, including TypeScript and production SPA compilation. Log: `storage/framework/testing/slice6-operator-build.log`. Scoped Pint and JS syntax checks PASS. No legacy build or complete Foundation/Chromium rerun was needed for this component-local change.
- The official acceptance runner also executes the existing isolated preparation PHP reset/contract cases: **2 PASS / 37 assertions**. No new server test was needed because no writer changed; the browser additionally exercises actual operator authorization, consumption and conflict.

### New real integration evidence

Exactly one remediation journey plus the existing signed-founder authentication precondition executed: **2/2 cases PASS, zero unhandled browser errors**. PostgreSQL 18 disposable database/three roles/RLS, Redis and Laravel HTTP are real. Three distinct browser contexts use real virtual UV factors. Personnel classification is an explicit isolated fixture, not proof of production provisioning or physical independence.

The founder approves administrator B through the existing Vue form. B authorizes distinct operator C through the existing operator form; Laravel commits HTTP 200 and the test deliberately drops its ACK. The frozen original stays unknown. C records the market through the existing protected Laravel HTTP endpoint (not a newly claimed Vue registration ceremony). B explicitly resends the identical operator payload and Laravel returns 409. Only a new admitted review of the matching consumed approval exposes follow-up closure. User confirmation archives it as unknown; reload restores review history, not an active command. The HTTP trace contains exactly the original 200 and explicit 409 for operator authorization before and after archival/reload. Commercial grant, approval and market counts do not change during archival/reload.

Three failed orchestration attempts are retained, not counted as acceptance: a new synthetic advertiser was incorrectly expected to have a business-start grant; UTC text was incorrectly filled into a local datetime input (Europe/Istanbul made the submitted expiry already past, correctly rejected 403); and the test expected commercial navigation immediately after staff login although Laravel correctly sends staff without `staff.view` to Security Devices. Corrections use the protected devices intended entry, a genuinely future browser-local expiry, and the existing post-login destination followed by explicit navigation. No authority, grants or timeouts were weakened to fix these assumptions.

Stable copies in the same evidence root:

- `operator-followup-browser-results.json`, SHA-256 `7dfad16d8aaa7bc857e05d1bc80da26da470e81bb2c2c4ea2eb06ee04b9d63d9`.
- `operator-followup-source-manifest.json`, SHA-256 `773724d9f96198b23604e23cda0dd1c31925d2c1e71434a89279091b248ee0cf`.
- `operator-followup-source-unchanged.txt`: `True`. Tested HEAD is the reviewed baseline plus the captured dirty correction; it is not misreported as execution of a later clean commit. Application/test sources matched the manifest after completion; subsequent report and generated discovery-lead changes are metadata only.
- Forty-two distinct delivered production asset files match current disk hashes, with no body/sent mismatches. New `PreparationView-C-n77yFO.js` SHA-256: `a14e23137498631c90d7e265b20c7ce57cd6ac29c659f00db8e1594bbd98ff6c`.

The previous ten-case Chromium evidence remains baseline evidence and is not attributed to the corrected component. The new narrow journey replaces the affected preparation proof; unchanged SQL, authority and unrelated journey evidence is reused only for its original scope. Central Foundation, physical devices, native BFCache/pre-JavaScript and B/C remain unaccepted separate gates.

### Inventory and handoff

New test discovery references caused inventory drift. Regeneration changes only `testLeads` and their matrix representation; runtime check and ceiling PASS again: **173 registered routes, zero Inertia display routes, 46 dependency files, 142 frontend modules**. Contracts are unchanged, so contract regeneration/rechecks were not repeated. Dependency removal is still NOT_READY and unauthorized.

One independent local correction commit contains the component, targeted tests/fixture, discovery-only inventory synchronization and this report. Its SHA is supplied in delivery. No Reset/Rebase/Force, previous-worktree modification, Push/PR/Merge/Deployment, new grant forms, dependency removal or next-slice work occurred. The official runner reports `GOALS_SPA_PROCESSES=STOPPED; EXIT=0`; browser contexts/proxy are closed. Final Git status and stopped task ports are verified at delivery.

**READY FOR TARGETED INDEPENDENT RE-REVIEW**
