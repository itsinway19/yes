# Final Inertia/N1 runtime retirement — Slice 7

## Baseline and acceptance contract

Approved base: `e579d01e74f3fa6c480cafd9348181791569f038`. One new worktree/branch: `C:/dev/builderad-worktrees/ba-inertia-retirement-007`, `codex/ba-inertia-retirement-007`. Base/main were fetched and matched; starting checkout was clean. No earlier worktree or evidence archive may be changed. No publication is authorized.

Acceptance is six independent gates: A zero display routes; B zero runtime consumers; C N1 sources/artifact/aliases removed; D direct dependencies absent from definitions, locks and clean installation; E existing security/command contracts preserved; F clean single production build and actual HTTP/browser journeys. An unexecuted or failed mandatory gate is not PASS.

## Security ownership before deletion

| Old guarantee / responsibility | Previous owner | Current owner retained | Required proof |
| --- | --- | --- | --- |
| Signed document binding, principal/boundary separation, current lifecycle and session | Laravel IdentityDocument/IdentityTransition/SessionAuthority; legacy identityProtocol client evidence | Same Laravel services, AdmitIdentityDocument and SPA transport | ProductionIdentityTest, IdentityRenewalTest, PasskeyBoundaryTest; real account switching/expired/revoked HTTP |
| Terminal logout, consumed login replies and no stale document adoption | Laravel identity writers plus Inertia clearHistory hint and N1 cache encryption | Same terminal transitions, HTTP-only credentials, SPA transport close/owned and native document navigation | InertiaHistorySecurityTest (general HTTP semantics retained), SPA transport tests and real logout/late response journeys |
| Probe context validation, current authority, no passive idle extension | HandleInertiaRequests custom prelude, not Inertia parent authority | Existing SpaPresentation middleware plus the same ClientVisibility/SessionAuthority checks | ClientVisibilityTest exact statuses/metadata and unchanged deadlines; restoration browser witness |
| Initial private data absent until admitted read+witness | Legacy Blade visibility gate and clientVisibility swap checks | SpaPage script-free projection-free shell, transport status, router presentation loaders and witness | HTML/bootstrap checks, held witness, actual cancelled/late reads |
| Private DOM hidden during pagehide/background and guarded JS restoration | Legacy clientVisibility plus N1 history admission | SPA synchronous status watcher and CSS gate, pagehide/visibility suspend, pageshow passive read | Separate active transition and JS-restoration cases; never infer pre-JS confidentiality from pageshow/no-store |
| Pre-JavaScript cached compositor frame confidentiality | No accepted cross-browser guarantee in prior slice evidence; N1 encrypted history protects serialized history, not compositor snapshots | Existing no-store private documents, empty initial HTML and synchronous departure hiding; browser behavior remains a separate boundary | Must investigate local browser behavior and state the exact proven boundary. A mandatory missing guarantee blocks closure, not a claim that no-store alone solves BFCache |
| Frozen business commands, unknown ACK and exact receipts | Existing Laravel/SQL writers, component retention | Same services/SPA command components; no automatic replay | Actual commercial editor/preparation cases and operator-followup Laravel/Chromium journey |

`Inertia::clearHistory()` queues a legacy page-history instruction; it never revokes a server credential or encrypts a cached compositor frame. Its removal is conditional on preserving the actual terminal server transition and current SPA document closure, not substituting a renamed history shim. The SPA already contains these owners; no parallel runtime is permitted.

## Baseline dependency classification (46 marker-bearing files)

| Files | Classification / disposition |
| --- | --- |
| app/Http/Middleware/HandleInertiaRequests.php | Active shared probe prelude plus obsolete parent/share/version behavior. Preserve checks in existing SpaPresentation; delete Inertia middleware, not rename its adapter. |
| app/Http/Middleware/AdmitIdentityDocument.php | Active security admission; legacy request-header exclusion is a fail-closed input discriminator, not a framework import. Preserve rejection semantics until independently tested. |
| app/Security/IdentityDocument.php; app/Security/IdentityAuthentication.php; app/Security/ExpiredSecuritySession.php; app/Http/Controllers/Auth/PasskeySessionController.php; app/Http/Controllers/ProfileController.php | Active server owners; remove only clearHistory imports/hints after replacement proof. Do not change transactions, session/identity logic or response contracts. |
| bootstrap/app.php | Active middleware registration; remove adapter while retaining middleware order and existing SPA owner. |
| app/Providers/Ads/ProviderCredentials.php; resources/js/goals-spa/drafts.ts | Historical explanatory markers, not runtime imports. Update wording without behavior changes. |
| resources/js/app.ts; resources/js/clientVisibility.ts; resources/js/identityProtocol.ts; resources/js/inertiaNavigation.ts; resources/js/navigationSettlement.ts | Legacy app/runtime unreachable from production SPA. SPA's only remaining identityProtocol use is a type import: retain data type independently, not runtime. Retire old execution after guarantee mapping. |
| resources/js/Components/DropdownLink.vue; resources/js/Components/LanguageSwitcher.vue; resources/js/Components/NavLink.vue; resources/js/Components/ResponsiveNavLink.vue | Legacy navigation components; SPA uses Vue Router and existing locale owner. No new navigation stack. |
| resources/js/Layouts/AuthenticatedLayout.vue; resources/js/Layouts/GuestLayout.vue; resources/js/Layouts/InternalLayout.vue | Legacy layout consumers. AdvertiserLayout/logout/bootstrap companions are also reviewed for reachability, even if not marker-bearing. |
| resources/js/Pages/Business/Index.vue; resources/js/Pages/Dashboard.vue; resources/js/Pages/Internal/Commercial.vue; resources/js/Pages/Internal/CommercialMarketPreparation.vue; resources/js/Pages/Profile/Partials/UpdatePasswordForm.vue; resources/js/Pages/Workspaces/Index.vue | Historical display implementations, no production render routes. General behavioral guarantees need current-component/HTTP coverage; do not claim their old tests exercise the new app. |
| resources/js/localization/business.ts; resources/js/types/global.d.ts; resources/js/types/inertia-n1.d.ts | Legacy form adapter and type augmentation; current localization/businessCopy and SPA command owners remain. |
| resources/views/app.blade.php | Legacy bootstrap/directives; production SpaPage uses spa.blade.php. |
| compat/inertia-n1/README.md; compat/inertia-n1/build.mjs; compat/inertia-n1/review-patch.mjs; compat/inertia-n1/source.patch; compat/inertia-n1/source/eventHandler.ts; compat/inertia-n1/source/initialVisit.ts; compat/inertia-n1/source/navigation.ts; compat/inertia-n1/source/request.ts; compat/inertia-n1/source/response.ts | Nine marker-bearing files of the hash-pinned legacy runtime. Remaining compat files are implementation companions; remove the entire runtime only after owner/proof mapping. |
| vite.config.js | Legacy build consumer; default build/dev must target existing unified SPA, without old entry/aliases. |
| package.json; package-lock.json; composer.json; composer.lock | Direct and transitive dependency definitions/locks; regenerate with official package managers and prove clean installs. |

## Test migration policy

N1 source/artifact/queue/swap/encryption implementation tests have no runnable subject after runtime deletion. Their general identity/history/cancellation requirements must remain covered by actual SPA router/transport tests, Laravel authority tests and the A/B/C browser evidence. Legacy commercial-component tests are not permission to keep a dead production app; generic command/selection/receipt/unknown/storage/locale requirements must be mapped to the actual commercial components and real HTTP proof before retiring their old harness. Shared source capability components remain if imported by the SPA.

## Execution status

**BLOCKED checkpoint, not a final accepted retirement candidate.** Preflight and security-owner classification were recorded before runtime deletion. Two mandatory closure conditions remain open: native pre-JavaScript BFCache proof, and completion/approval of the legacy-test migration without losing generic protection. Successful partial gates below do not override either condition.

## Implemented changes

- Removed the unreachable legacy application entry, its layouts/navigation/form adapters, six old display components, the old Blade bootstrap, and every tracked file in `compat/inertia-n1`. Removed the separate legacy/default Vite path and the second Vite configuration. Default `build` and `dev` now select the existing unified SPA. `build:goals-spa` is only an alias of `build`, not another application; proof mode uses the same Vite configuration.
- Moved the unchanged identity metadata type declarations into `resources/js/spa/identity.ts`. The sole active consumer was a type import; no new identity runtime/protocol was created. Removed Inertia global type augmentation.
- Removed all server `Inertia::clearHistory()` imports/hints, retaining the original identity/session transitions, cookie ownership, redirects and JSON responses. A first focused test exposed one missed anonymous-document call; it was removed without changing its surrounding lineage logic, and the affected cases were rechecked.
- Deleted `HandleInertiaRequests`. Its context/current-session/nonrenewing probe checks now execute in the already-existing `SpaPresentation` middleware. The Inertia parent adapter, version negotiation, shared-props, page response conversion and render protocol were not copied into a renamed middleware. Relative order of the remaining middleware is unchanged. The existing header exclusion in `AdmitIdentityDocument` remains a fail-closed discriminator for unsupported legacy requests, not an Inertia consumer.
- Direct package removal uses npm/Composer. Composer removed only `inertiajs/inertia-laravel`; npm removed `@inertiajs/core`, `@inertiajs/vue3` and their now-unused `es-toolkit`/`laravel-precognition` transitive packages. Semantic lock comparison found **zero added packages and zero version updates**. Other dependencies, including axios, Ziggy and passkey libraries, were not opportunistically removed.
- Added an independent runtime/lock/build guard with negative reintroduction tests. Foundation receives **additional** guard commands. No existing Foundation step or existing security assertion/test was removed. The legacy N1 build generator command is gone; the old implementation-test commands/harnesses remain pending the blocked migration and currently fail rather than being silently skipped.
- Kept `Pages/Workspaces/SourceCapabilityStatus.vue`: it is a real shared component imported by the current `sources/SourceRegistryPanel.vue`, not a legacy display route. Removed companions include legacy `logout.ts`, `bootstrap.ts` and `AdvertiserLayout.vue`, which belonged to the removed entry.

No changes in SQL/migrations, RLS, `GrantAuthority`, `SessionAuthority`, `WorkspaceContext`, commercial writers or identity transition semantics. `main` and all prior worktrees remain untouched except Git's required registration of this new worktree. No new commercial form, grant or Owner Mandate interface was added.

## Before/after inventory and actual build

| Property | Before | Current checkpoint |
| --- | --- | --- |
| Registered runtime routes | 173 | 173 |
| Inertia display routes | 0 | 0 |
| Marker-bearing ceiling files | 46 | 1: only `AdmitIdentityDocument` rejecting `X-Inertia` as a native-document discriminator |
| Runtime framework imports / Inertia server calls | Present in legacy runtime/server adapters | 0 under the production-source guard |
| Frontend modules discovered | 142 | 120 |
| Production build entries | Legacy entry plus SPA build | Exactly `resources/js/spa/main.ts` |
| Direct framework packages | PHP Inertia, Vue adapter and core | Absent from definitions, locks and fresh installation |

`node scripts/inertia-inventory.mjs --runtime` and `--runtime --check` PASS. The old ceiling is ratcheted down, not weakened upward. `node scripts/runtime-retirement.mjs --assets` PASS checks imports, server calls, package definitions/locks, the production entry, compiled assets and absence of extra `public/build` outputs. Historical documentation and an explicitly rejected header are not falsely counted as runtime execution.

The task's newly created, unshared `vendor` directory was verified to be inside worktree 007, untracked and not a junction, removed, and reinstalled from the final Composer lock: **129 fresh installs**. `npm ci` recreated dependencies from the final npm lock: **190 packages**. No other worktree's dependencies or evidence were cleaned. Both installs and `npm run build` PASS; TypeScript and the single production Vite build succeed. `public/build` contains only `goals-spa`; its manifest has exactly one entry and no N1 alias/artifact/legacy entry. No test/dev server is left running.

## Executed gates and retained failures

| Gate | Result / exact interpretation |
| --- | --- |
| Existing SPA foundation suite | **167 PASS**, unchanged suite; no old generic assertion silently deleted |
| New runtime reintroduction tests | **2 PASS**, including rejection of server/import/build reinsertion and actual final lock checks |
| PHP identity/visibility/history/passkey/baseline filter | Initial **54 PASS / 2 FAIL, 1213 assertions**. Failures identified the remaining anonymous clearHistory call. Focused correction recheck **3 PASS / 52 assertions**. This is reused passing evidence plus a targeted recheck, not a falsely claimed new complete 56-test run. |
| Preparation PHP contracts/reset | **2 PASS / 37 assertions** through the guarded runner; rerun as required when creating new browser fixtures |
| Scoped Pint | PASS for the nine affected PHP files |
| OpenAPI drift | Goals and Application contracts/types match; no API contract modification |
| TypeScript / production build | PASS, including installation/build from the final lockfiles; declaration-only final EOF whitespace fix is separately typechecked |
| Official `npm test` | **FAIL: 73 PASS / 10 FAIL** counted entries. Missing old runtime/component subjects are not relabeled product passes. No assertion was weakened to hide these failures. |
| Full Foundation / remaining complete browser matrices | NOT_EXECUTED on this blocked checkpoint. Existing main CI proves only the approved base, not these changes. |

The official frontend failures are the old client-visibility, identity-protocol, three N1 harnesses, old commercial component harness, localization's old layout scan, and three current component harnesses that still load the deleted localization adapter/LanguageSwitcher. Some are obsolete implementation assumptions, others require retargeting to actual current components. They are deliberately visible failures until each generic requirement has an accepted equivalent.

An initial combined proposed change to test selection/build steps was rejected by the execution safety reviewer because replacement security coverage was not yet established. **That patch was not applied and was not bypassed.** The safer continuation kept every existing test/step, executed them, added stronger independent guards, and recorded the failing closure gate. Updating official test commands is still pending an explicitly reviewed migration map; no permission to delete valid security protection is requested.

One package-bootstrap failure occurred before PHP tests: the generated provider cache still referenced the removed service provider. Only the two generated cache files in this new worktree were cleared and package discovery regenerated. Fresh installation subsequently succeeds, proving this was not addressed by retaining a hidden Inertia dependency.

## Actual browser proof, separated by boundary

All runs use the official `scripts/Test-GoalsSpa.ps1` with the single reusable disposable PostgreSQL 18 cluster, three isolated roles/RLS, Redis and actual Laravel HTTP. Production assets are used, not a replacement app or proof-only router hook.

**A — Active identity transition: PASS locally.** Two real browser tabs transition A→B through the native switch/login UI. An original private profile response is held; the old SPA observes current identity denial and closes. Releasing the obsolete response does not restore A. Password rejection/intended navigation, native switching, signed virtual UV login and consumed-input rejection also pass. No unhandled page errors were observed.

**B — JavaScript-controlled restoration: PASS locally for the executed cases.** A deliberately synthetic persisted-pageshow event exercises the actual production handler, while a real admitted witness is held. No private component is visible until the witness completes. Offline restoration closes presentation and a later explicit reload recovers. This event is not called trusted native BFCache. The initial script-disabled fresh HTTP shell is empty, no-store and has no private account projection.

**C — Pre-JavaScript native BFCache: UNVERIFIED, mandatory acceptance remains blocked.** After a real private document departs, the test revokes the session, disables script execution through CDP, navigates back, and captures DOM/layout atomically before re-enabling script. That observed path has no rendered private root. However, there is **no trusted persisted restoration**. Chromium reports `MainResourceHasCacheControlNoStore`, `JsNetworkRequestReceivedCacheControlNoStoreResource` and `BrowsingInstanceNotSwapped`. This proves a safe observed fresh-document path, not native cached-frame secrecy, not every compositor frame and not other browsers/devices. `pageshow` or no-store alone is not promoted to the missing guarantee. This is an evidence gap, not a proven data-disclosure defect or a demonstrated loss of an already-established N1 compositor guarantee.

The first CDP probe used separate document/query IDs that became stale during redirect. Its failure was retained; the correction uses one atomic DOM/layout snapshot after DOMContentLoaded. It changes test observation only, not production hiding behavior or security assertions.

**WebAuthn expiry:** the combined browser run later observed HTTP 500 instead of the existing 422 assertion. Laravel logged `Maximum execution time of 30 seconds exceeded` at PDO statement execution. No SQL blocker/causal attribution to the removal is established. The exact signed-UV precondition plus expiry/cancellation/invalid-signature case was rerun alone, with its original 61-second wait and 422 assertions: **2 PASS**, zero unhandled browser errors. The earlier 500 remains evidence; a passing retry is not labeled proof of flakiness or an invented root cause. The rest of the interrupted 15-case authentication sequence is not claimed executed successfully.

**Commercial unknown outcomes:** after clean installation/build, the real founder→administrator→operator sequence passes **2/2 cases** (including signed founder authentication). Actual authorization commits with a dropped ACK; the operator records the market; the original exact retry receives 409; a current consumed approval enables explicit unknown archival and reload restores history, not an active command. There is no automatic replay, archive grant effect or fabricated success. Zero unhandled browser errors. This is the targeted operator follow-up proof, not a claim that every commercial journey was rerun.

## Test migration map and remaining closure work

| Old subject | Generic guarantee / current proof | Disposition |
| --- | --- | --- |
| N1 queue, InitialVisit, encrypted history serialization, artifact hash and upstream patch tests | Framework-specific mechanics; current router's five actual cancellation/chunk ownership cases, transport ownership tests and active/restoration browser evidence protect generic navigation requirements | Old files retained for now; exact retirement selection must be reviewed, not hidden from failing `npm test` |
| Legacy clientVisibility/inertiaNavigation swap harness | Current presentation CSS/status, admitted witnesses, router ownership; real A/B proof above | General guarantee retained in current owners; native C still unproved, so no completed security migration claim |
| Legacy identityProtocol interceptor/renewal implementation harness | Same Laravel authority tests plus current shared transport closure, identity mismatch, abort and no-replay tests; actual native identity switch/UV/logout evidence | Protocol-specific interceptor assertions do not justify keeping a second runtime; the detailed generic renewal/closure mapping still needs closure review before deleting tests |
| Forty-six old Commercial/Preparation component cases | Current actual-component preparation/editor tests, SQL tests and real operator unknown/409/archive/reload proof | UI-specific props/remount assumptions must be distinguished from generic command/receipt/storage/selection protection; do not claim all 46 assertions have been ported |
| Current intent/target-market/purpose test harness imports of legacy copy/LanguageSwitcher | Current production components already use businessCopy and SPA locale; current tests must target those owners, including locale-only commands without posting drafts | Pending retarget, not permission to delete current business interaction tests |
| Baseline major-version-three package assertion | New stronger assertion forbids installed/locked packages and requires the actual SPA entry | Replaced because the old requirement contradicts this explicitly authorized retirement; no authority expectation changed |

## Evidence and checkpoint provenance

Evidence root in worktree 007: `storage/framework/testing/goals-spa-efd1ced1-5c9d-42ef-9fdb-f34156ae6a28/`. Failed runs and archived manifests are retained. These local ignored artifacts are not claimed published.

- `slice7-runtime-browser-results.json` (also `browser-results-attempt-2.json`): SHA-256 `1e61bcc69cd7e6a7e8c54ce1cb8056c0132ecd8963a7509aa0ef5482ea20cc52`; includes the A/B PASS, C UNVERIFIED and retained expiry failure.
- `slice7-passkey-browser-results.json`: SHA-256 `4625ca9b2efa45f8f9eae21c4b0e3038865c9b30d9c00ce2f7a40d125127bd4a`; focused original UV/expiry cases PASS.
- `slice7-commercial-browser-results.json`: SHA-256 `9d10ff64743eb9335efa712125c3c32068d442e781756896ce0fcb27dd8bdac1`.
- `slice7-commercial-source-manifest.json`: SHA-256 `5fa46c8fd10e88fd35e4e222e8fc2b7ffe386a00df761937d2e1a07ab3fbc460`; corresponding source-unchanged record is `True`.
- The official source manifest records deleted tracked files as `<deleted>`; these are checked as absent, not incorrectly reported as missing executable hashes. Subsequent ceiling/inventory/report metadata and one trailing blank line in a declaration file do not change tested application bytes. No final clean commit is falsely attributed to an earlier dirty-state run.
- All **42 distinct delivered production assets** in the final commercial run match current on-disk hashes, with zero body/sent/disk mismatches. Sole production entry: `assets/main-DuloqTD3.js`, SHA-256 `4c2385d62582357d684ce1379c633f1df9d90a509de1782bcf3c3eea67204734`.
- `storage/framework/testing/slice7-clean-composer.log`, `slice7-clean-npm.log`, `slice7-clean-build.log`, `slice7-spa-foundation.log` and `slice7-frontend-closure.log` retain clean-install/build, SPA success and the failing official frontend gate respectively.
- PHP archives `74d3d218-921a-4441-95eb-b58b5ed95495-tests.log` and `0b54af36-79c0-4b8b-bec5-fe1d67b84976-tests.log` retain the initial identity failures and focused correction recheck.

## Acceptance and required decision

| Required result | Current judgment |
| --- | --- |
| A ZERO_INERTIA_DISPLAY_ROUTES | VERIFIED: runtime inventory 0/173 |
| B ZERO_INERTIA_RUNTIME_CONSUMERS | Production import/server-call guard and executed asset paths PASS; not an overall release claim |
| C N1_RUNTIME_REMOVED | Tracked runtime, generator and aliases removed; obsolete implementation-test entry points still require explicit migration closure |
| D DIRECT_DEPENDENCIES_REMOVED | VERIFIED for definitions/locks/fresh installs; no unrelated version changes |
| E SECURITY_CONTRACTS_PRESERVED | PARTIAL / BLOCKED: A/B and focused HTTP guarantees pass, C is not proved and generic old-test migration is not closed |
| F PRODUCTION_BUILD_VERIFIED | Clean single-entry build/assets PASS; full mandatory acceptance remains blocked by E and the failing official frontend gate |

Stop further retirement/acceptance changes at this checkpoint. The smallest next decisions are: establish the exact mandatory native pre-JavaScript acceptance boundary and a browser/device environment capable of exercising it (or an independently authorized policy/mitigation decision), and approve a concrete old-guarantee→current-proof migration for the remaining test harnesses. This is not a request to weaken assertions, skip mandatory C or accept no-store/pageshow as sufficient secrecy evidence. No authority redesign is proposed.

Progress may be committed only as an explicitly **BLOCKED local checkpoint**, not as the final accepted execution commit; the handoff supplies its actual SHA and clean/dirty status. The complete deletion/modified-file list is the checkpoint diff against the approved base; retained shared components and each active server modification are classified above. All task-started database/Redis/PHP/browser/proxy processes are stopped before handoff. No Push, PR, Merge, deployment, worktree/evidence deletion or next slice is performed.

**BLOCKED**
