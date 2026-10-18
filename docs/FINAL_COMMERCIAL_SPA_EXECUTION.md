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

## Executed acceptance

Official isolated runner: `scripts/Test-GoalsSpa.ps1`, PostgreSQL 18 disposable database and three verified roles, Redis, actual Laravel HTTP and Chromium `145.0.7632.6`. Virtual UV factors and synthetic operator-independence fixtures are not physical-factor acceptance. No real mail or advertising accounts are used.

Final production-asset run: **10/10 browser cases PASS, zero unhandled page errors**, plus the two preparation PHP cases **2 PASS / 37 assertions**. It navigates actual rendered RouterLinks, without requiring the proof-only router hook:

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

- `browser-results.json`: SHA-256 `5237f231fa1ab2c786855ebebcba9f991f1e380402c578c04e8fe7000f549019`.
- `source-manifest.json`: SHA-256 `7916aa8efea6a24be9de599cbd1c55b4530bdc4b70b2e40a6b652713949bf3a3`.
- `source-unchanged.txt` is `True` for the final browser run. Its reported Git HEAD is the approved base because execution tested the dirty implementation state, not a nonexistent clean commit. File hashes establish what was tested.
- All 42 distinct delivered production asset files were compared against the current `public/build` files: zero hash mismatches. Proxy body/sent hashes match; independent browser URL/status observation is recorded separately, not misrepresented as a CDP body hash for aborted navigations.
- Commercial JS: `OperationsView-DO0Q51bx.js` SHA-256 `e765616a29d6c30af7449c2d68fba033c97217d79c53325d1a33c3861b32dd94`; `PreparationView-OVFHWk6P.js` SHA-256 `6e4cb7766530a60546ba5763a47059ccdc0c143fc37fa0f5fdf35ec0cb35834c`.
- Subsequent inventory/status metadata and this report do not change the tested executable files. Final committed executable state must match this manifest; final commit identity is recorded separately below.
- Archived `12fdf941-6131-4c48-9b6a-a0a91269b966-tests.log` contains the combined PHP run; `c64a4d8e-ee79-44c8-8eaa-1b5313cf8fd6-tests.log` the corrected seven-test run; `15199a10-bae6-45dd-9056-1a97045210ac-tests.log` architecture results. `storage/framework/testing/slice6-final-spa.log` contains the final 160-test result.

The official runner reports `GOALS_SPA_PROCESSES=STOPPED; EXIT=0`; browser/proxy are closed and task-owned listening ports are absent. Previous worktrees and evidence are preserved.

## Final inventory and dependency separation

**ZERO_INERTIA_DISPLAY_ROUTES: VERIFIED.** Actual runtime/source inventory records **173 registered routes, zero legacy render routes, 142 frontend modules**, both Goals opt-in states. The two final GET displays left Inertia; POSTs, callbacks and legitimate redirects were not subtracted as displays. Generated inventory discovery leads remain discovery leads, not proof that every listed test passed.

**DIRECT_PRODUCTION_CONSUMERS: RETAINED.** The dependency ceiling reports **46 files**. Twenty-one JS/TS/Vue files directly import Inertia packages/helpers (including declarations): legacy `app.ts`, visibility/identity/navigation/history adapters, shared layouts/link/language components, localization, retained legacy Pages and type declarations. Six PHP files directly import Inertia: `ProfileController`, `Auth/PasskeySessionController`, `HandleInertiaRequests`, `ExpiredSecuritySession`, `IdentityAuthentication`, `IdentityDocument`. Static dependency presence is not a claim of reachable production display rendering.

**DEPENDENCY_REMOVAL_READINESS: NOT_READY / SEPARATE AUTHORIZATION REQUIRED.** Active shared middleware, identity/logout/history and visibility responsibilities remain. Legacy `resources/views/app.blade.php`, the `app.ts` page resolver, Vite's hash-pinned N1 artifact integration, retained test/build-consumed Pages, composer/npm packages and lockfiles still exist. Old commercial Pages are no longer route-rendered but remain compiled/test consumers. No package/runtime/file deletion is performed under this slice. Zero displays is not zero dependencies or proof that deleting shared protections is safe.

## Limits and disposition

No physical keys/devices, native mobile BFCache, pre-JavaScript cached-frame confidentiality, cross-platform B/C, SSE acceptance, production performance, deployment or release acceptance is asserted. Foundation CI and independent review of this candidate remain separate future gates requiring their applicable authorization. Native fallback/new-intent responses and existing authority tests are retained, not replaced by client assertions.

No Push, PR, Merge, deployment, production enablement or Slice 7 work is performed. No previous worktree is removed. Local implementation and its focused acceptance are ready for independent review; this does not claim acceptance of dependency removal or the overall retirement project.

**READY FOR INDEPENDENT REVIEW**
