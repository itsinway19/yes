# Final Inertia/N1 runtime retirement — Slice 7

**Current status: READY FOR TARGETED INDEPENDENT RE-REVIEW — S7-R01.** The reviewed checkpoint received S7-R01/P2; its targeted correction and evidence are recorded at the end. This is not executor acceptance of Slice 7. The Owner limits local evidence to the documented Chromium 145 configuration; no production, other-browser or real-device acceptance. Earlier readiness/blocker narratives remain historical, not the current review verdict. No historical failed run is rewritten as successful.

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

## Targeted closure follow-up — 2026-10-19

### Starting state and acceptance criteria

Verified branch `codex/ba-inertia-retirement-007`, clean checkout, HEAD `24ffeb843325588c3db53685ee32da5a45d12088`, parent `e579d01e74f3fa6c480cafd9348181791569f038`. No new worktree, reset, rebase or repeated runtime removal. Criteria: map each old test's live guarantee before retirement, pass the actual-owner frontend gates, verify affected server/browser contracts and current production assets, and assess Security Cases A/B/C independently. BFCache functioning is **not** an acceptance requirement; confidentiality is. A fresh navigation or synthetic event is not native cached-frame proof.

### Test migration and narrow production correction

Full auditable matrix: [SLICE7_TEST_MIGRATION_MATRIX.md](SLICE7_TEST_MIGRATION_MATRIX.md). It lists the ten former failing entries, individual legacy identity/visibility/N1 cases, both InitialVisit scenarios and **all 46** old Commercial/Preparation cases. Current Intent, Target Markets, Object Purpose and localization retain their behavioral assertions against actual production components and `businessCopy`. Actual `spa/locale` tests replace the deleted switcher's transport/remount assumptions. Current transport/router/account/auth tests protect binding, CSRF, finite lease ownership, closure, late JSON/results, cancellation and non-replay. Framework queue/encryption/artifact tests retire only after generic replacements are proved. Six obsolete harness files and `test:inertia-n1` are removed; the checkpoint's Git history and every execution archive remain intact. Official npm entry points change last, after proof/mapping, not merely to hide imports.

One current UI defect was exposed: a definitive Commercial 409 entered `idle`, hiding its explicit review action; it also advertised a refresh that it never requested. Existing server writers and optimistic guards were correct and unchanged. The smallest correction is confined to `commercial/OperationsEditor.vue`: display the conflict section, request one existing admitted read after a **definitive** 409, enable explicit review only after readback, and allow explicit read retry after readback failure. Fields and original revision stay frozen until the user's review. An already-uncertain write is not resolved by 409/readback and is never automatically replayed. No new forms, transport, writer or command framework. Authority remains Laravel; INV-007 decision/authority separation is preserved. No PHP, SQL/RLS, SessionAuthority, WorkspaceContext, N1 or security-timeout change in this follow-up.

Failing-before evidence: `slice7-followup-commercial-editor-localized.log` (missing review action), `slice7-conflict-read-before.log` (zero admitted reads). Passing actual-SFC replacements cover the held read, explicit revision adoption, failed-read recovery, original command/receipt provenance, storage failure, principal isolation, unknown ACKs and late responses. Harness-only select-element initialization failures are retained in earlier `slice7-followup-*` logs, not hidden or attributed to production.

### Final executed gates and affected browser proof

| Verification | Actual outcome / evidence under `storage/framework/testing/` |
| --- | --- |
| Official `npm test` | **200 PASS / 0 FAIL**, `slice7-official-frontend-closure.log` |
| Official `test:spa-foundation` | **199 PASS / 0 FAIL**, `slice7-official-spa-closure.log`; includes **39** current commercial SFC/state cases, not the retired 46 counted again |
| TypeScript and clean single production build | PASS, `slice7-followup-production-build.log`; Vite empties the output directory. Later changes are test/report/inventory only, not build inputs |
| OpenAPI drift | Goals and Application match; no contract change |
| Runtime/dependency negative guards and assets | PASS; no production import/call of removed runtime and no package in final locks; prior clean installs remain applicable because dependency definitions/locks did not change |
| Inventory/check and legacy ceiling | PASS: **173 registered matrix routes, 0 Inertia displays, 120 frontend modules**. One deliberately rejected `X-Inertia` request discriminator remains; it is not a runtime consumer |
| Laravel selected security/commercial aggregate | **92 PASS / 1 ERROR**, 3,337 reported assertions, 297.68s; `slice7-followup-php-console.log`. This aggregate remains FAILED |
| Failed race, exact isolated rerun | **1 PASS / 72 assertions**, 47.63s; `slice7-followup-race-focused.log`; actual row blocker and loser `STALE AUTH_CALLS=0` observed, unchanged timeouts |
| Browser preparation per isolated run | **2 PASS / 37 assertions**, official PostgreSQL/three-role/RLS/Redis runner, disposable synthetic fixtures only |
| Affected Commercial Chromium | **7 PASS / 0 FAIL / 0 unhandled page errors**; `slice7-commercial-recovery-browser-results.json`. Real UV internal login; reference/configuration/assignability writers; committed lost ACK/full receipt fingerprint; beneficiary/context/audit; definitive stale revision **4 → server 5 → real 409**, held admitted read, no write until explicit new command; shared-router Back/Forward; A→B→A and session-revoked late response closure |

The commercial proof verifies all delivered asset bytes against this tree: **273 forwarded responses / 270 observed browser asset responses**, no mismatch. `slice7-commercial-recovery-source-unchanged.txt` is **True**. Its source manifest records the exact dirty executable state, not a fictitious clean commit. Only report/ledger metadata and regenerated inventory may follow that manifest; production code/assets stay unchanged. WebAuthn HTTP 500 from the earlier checkpoint did not recur in the newly executed signed-UV prerequisite. The prior isolated expiry success is reused with its original 61-second/422 assertions; a complete auth-suite rerun is not claimed.

### The PHP failure is not reclassified as green

Last authoritative passing baseline was independently fetched **read-only** from GitHub: [Foundation 39213089180](https://github.com/42Huss/builderad/actions/runs/39213089180), success for exact `e579d01e74f3fa6c480cafd9348181791569f038`, runner **ubuntu-24.04**. Current execution is Windows, PHP 8.5/PostgreSQL 18/isolated Redis. The failed IdentityTransition race test, worker, fixture, `IdentityTransition.php`, `SessionAuthority.php` and SQL bootstrap match that base byte-for-byte; this follow-up's only production edit is the Vue conflict UI. However, the earlier checkpoint removed middleware/providers/dependencies relative to the base, and baseline CI uses a different OS: those comparisons do not exonerate the checkpoint or establish materially identical worker bootstrap. Historical CI is not current-candidate acceptance.

The aggregate error is in `test_same_base_race_observes_real_row_blocker_then_rejects_loser_before_authentication`, rollback after PostgreSQL terminated an idle transaction (`13:12:16`, PostgreSQL connection PID 11900; the failed proof did not retain a holder PID). Preserved failed evidence `slice7-followup-same-epoch-failed.json` has worker `BOOTED 13056` but **no ATTEMPT** and no observed row blocker. Thus the failed attempt does not prove real lock contention. On the exact focused rerun, holder **22004**, waiter **19332**, `actual_blocker_observed=true`, `pass=true`, output `BOOTED → ATTEMPT → STALE AUTH_CALLS=0` are recorded. No timeout, assertion or authority was changed. The reason the first worker stalled before ATTEMPT remains **UNRESOLVED**; neither a product regression nor an unrelated/flaky environment cause is established by a passing retry. Original aggregate logs/XML and PostgreSQL logs remain archived by the official runner. Do not describe this as a fresh 93/93 successful aggregate.

### Security Cases A/B/C and restoration investigation

**A — Active transition:** current transport/router/component gates PASS, plus the new real commercial A→B→A/session-revocation proof and unchanged earlier account-switch held-response proof. Closed/superseded ownership cannot publish late data. This is presentation integrity, not client authority.

**B — JavaScript-controlled restoration:** earlier actual-witness hold/offline synthetic restoration remains applicable: JS must obtain current admission before reveal. Current account/auth witness tests and real SPA Back/Forward pass. Synthetic `persisted=true` is labeled synthetic, never native BFCache evidence.

**C — Native pre-JavaScript restoration: UNVERIFIED.** No trusted native cached restoration with pre-admission/compositor confidentiality has been accepted. The new focused harness keeps production assets and protected response `Cache-Control: no-store, private`; it does not change caching, cookie or authority policies to force eligibility. Intended controls are passive observation, explicit Page/CDP observation, and script-disabled return, each for unchanged identity, logout, identity switch, session revocation and offline return.

Actual browser: **Chromium 145.0.7632.6**, Windows headless executable `chromium-1208/chrome-win64/chrome.exe`. The second artifact records the actual task-owned OS process command line, including `--headless`, `--remote-debugging-pipe`, the automation default feature switches, and absence of both `--disable-back-forward-cache` and `--enable-automation`. This is not stock headed Chrome or production hosting evidence. Even the passive variant is Playwright/CDP underneath; no assertion of a CDP-free observation is made.

Two attempts are preserved, not promoted into an accepted 15-case matrix:

1. `slice7-restoration-passive-attempt.json`: five passive cases reached measured fresh-navigation/offline outcomes; changed document markers, trusted `persisted=false`, and Chromium `notRestoredReasons` included `response-cache-control-no-store` and `response-cache-control-no-store-with-js-network-request` (plus `masked`). These observations support bounded exclusion for those executions, not a no-store-only theorem. **The aggregate failed**: the observer called `crypto.randomUUID` in initial `about:blank` where unavailable, producing observer page errors; `Browser.getBrowserCommandLine` then refused because enable-automation is absent. The observer is corrected with a non-authority correlation fallback and OS read-only flag capture, not browser-flag changes.
2. `slice7-restoration-network-failure.json`: corrected unchanged-identity passive case PASS, no unhandled page errors, actual protected headers and process arguments recorded. Next case failed **before authentication**, `page.goto('/profile') → net::ERR_NETWORK_IO_SUSPENDED`. Later CDP/script-disabled comparisons are **NOT_EXECUTED** in that attempt. A read-only Windows power-event probe supplied no wake-event corroboration; a sleep/environment cause is not established. The separate commercial browser run succeeded afterward but does not establish the cause or make this failed restoration aggregate green.

Observer microtasks are explicitly labeled **not an all-event-handlers or compositor barrier**. The initial artifact's `after-handlers` label was inaccurate: an init observer's microtask may precede later application listeners. Its `privateRendered` sample is neither proof of disclosure nor proof of closure before a cached frame. The final harness corrects the label. The prior checkpoint's script-disabled DOM snapshot was a fresh-document observation with no native persisted event, not native cached-frame proof. No screenshot/pageshow/empty fresh HTML is substituted for Security C.

Stop expansion of this affected investigation rather than patching application security to overcome an unestablished browser cause. The next bounded technical work is to reproduce the network-suspension boundary in a stable isolated Chromium run and finish the same security-config control matrix, preserving OS/browser/asset provenance. If supported production configurations can restore cached frames, independent pre-JavaScript/compositor evidence is still needed. Alternatively the Owner/security reviewer must explicitly define and accept a supported configuration boundary where exclusion is demonstrated. Such a boundary would not certify other browsers/devices/hosting. No residual-risk acceptance, new encryption runtime, blanket cache-policy change or BFCache functionality requirement is adopted here.

### Evidence fingerprints and effect on earlier evidence

Stable files under `storage/framework/testing/`:

- `slice7-commercial-recovery-browser-results.json`: SHA-256 `16e6533a412ae856a1ddc7f0a515b72dc0997506ca5219514b4ed9479d0e23de`.
- `slice7-commercial-recovery-source-manifest.json`: SHA-256 `fa14528ced3ba7b547d6204086679adfa02d8d717d79974a336e34f954da9a2b`.
- Sole entry `resources/js/spa/main.ts` → `assets/main-DTcOrnAu.js`, SHA-256 `de974a722dca8ec7c71da530b4e41395f2e335e57bec9fa38d6d3d6b0eca8091`; **42 distinct delivered production assets** verified.
- `slice7-restoration-passive-attempt.json`: SHA-256 `09441c7e14bbff22c2c31492f75ebf3975388573dd64947d5b4bc48097c80c7b`.
- `slice7-restoration-network-failure.json`: SHA-256 `bc6f3a4f404691ffb0cce686cc9b9f4609d75a92b349aee1e5b3c77b9f368549`.

Both restoration source-unchanged records and the final commercial record are True. Previous application evidence is reused only for unchanged ownership/security paths, not the corrected Commercial 409 path or final asset hashes. The fresh commercial execution covers that correction. Earlier HTTP 500, PHP aggregate error, observer failures and network-suspension failure remain visible; none is deleted or assigned an invented cause. No live provider, real device, native mobile background, stock-browser BFCache or production performance acceptance is claimed.

### Acceptance gates before resumed closure

| Gate | Current result |
| --- | --- |
| A — ZERO_INERTIA_DISPLAY_ROUTES | VERIFIED: 0 / 173 |
| B — ZERO_INERTIA_RUNTIME_CONSUMERS | VERIFIED for production sources, default build, delivered/guarded assets |
| C — N1_RUNTIME_REMOVED | VERIFIED; obsolete implementation harnesses/official command retired after mapped replacements |
| D — DIRECT_DEPENDENCIES_REMOVED | VERIFIED: no restored package/alias/compatibility shim; locks unchanged from clean-install checkpoint |
| E — SECURITY_CONTRACTS_PRESERVED | **BLOCKED** overall: Cases A/B locally verified within stated bounds; Case C UNVERIFIED; no authority change or demonstrated disclosure |
| F — PRODUCTION_BUILD_AND_REQUIRED_TESTS_VERIFIED | Build/TypeScript/contracts/guards/frontend/SPA/affected commercial browser PASS; **not an unqualified green aggregate**: PHP 92+error with focused recovery PASS, and restoration comparison incomplete/failed |

An independent local follow-up commit descends from `24ffeb8`; its exact SHA and final Git cleanliness are supplied in the execution handoff, not attributed retroactively to a dirty test run. All task-started PostgreSQL/Redis/PHP/Chromium/proxy processes are stopped. No Push, PR, new Foundation invocation, Merge, Deployment, worktree/archive cleanup or next slice. Historical Foundation was read only.

**BLOCKED — UNRESOLVED ACCEPTANCE GATES**

## Resumed closure and final local gates — 2026-10-19

### Owner-approved acceptance boundary

Owner response: **local acceptance for this environment only; no production or other-device acceptance**. Tested configuration: Windows, Chromium **145.0.7632.6**, the recorded headless/remote-debugging-pipe launch arguments, actual production SPA assets and unchanged Laravel security/cache policy on the isolated local HTTP origin. This is the scope of the local gates below, not a general claim about supported browser/device/hosting combinations. Independent review remains required; this report does not authorize publication, merge or deployment.

### Restoration comparison completed, not waived

Final attempt: **15 PASS / 0 FAIL / 0 unhandled page errors / 0 HTTP 5xx**. All five actions (unchanged identity, logout, identity switch, session revocation, offline return) executed in all three modes (passive, Page/CDP-observed, scripts disabled). Source-unchanged record: **True**. Actual served asset verification PASS: **1,645 forwarded / 1,604 browser-observed asset responses**. The earlier `ERR_NETWORK_IO_SUSPENDED` did not recur; its cause remains unestablished and the failed artifact is preserved, not declared flaky or retrospectively green.

| Mode | Actual return | Native evidence / visibility |
| --- | --- | --- |
| Passive | 4 fresh documents + 1 offline navigation failure | Trusted pageshow events are not persisted; fresh-navigation/document markers recorded; offline private presentation absent |
| Page/CDP observed | 4 fresh documents + 1 offline navigation failure | Frame type `Navigation`, never `BackForwardCacheRestore`; actual not-restored reasons recorded |
| Scripts disabled | 4 fresh documents + 1 offline navigation failure | Same navigation classification; all five DOM snapshots have **0 private roots / 0 rendered private roots**, before script re-enablement |

Observed not-restored reasons include `MainResourceHasCacheControlNoStore`, `JsNetworkRequestReceivedCacheControlNoStoreResource`, and `BrowsingInstanceNotSwapped`; passive PerformanceNavigationTiming supplies the corresponding browser reasons where available. This is measured exclusion in these executions, **not** an assumption from no-store alone. No eligibility difference was observed between the three modes on these routes. That does not prove CDP/script disabling never affects an otherwise eligible page. Script-disabled resource failures are recorded as expected observer-induced script suppression, not as changed production CSP.

Security Case C is therefore accepted locally as **BFCache exclusion for the tested paths/configuration**. Native cached restoration itself was **NOT_EXERCISED**; confidentiality of a truly restored cached frame outside this configuration remains **UNVERIFIED**. Lack of `persisted=true` is not treated as a functional failure or forced by weakening production policy. Owner scope confirmation, rather than an executor waiver, bounds this conclusion.

### PHP readiness blocker: controlled reproduction and bounded correction

The two-test predecessor/race probe passed **2 tests / 82 assertions** without changes, refuting an immediately reproducible predecessor-only failure. A controlled worker preparation delay then exposed the orchestration defect: the old test starts/prepares a worker **while the publishing transaction already holds its row**. A **16-second fixture preparation delay**, with original PostgreSQL **15-second idle-in-transaction** and worker **25-second** timeouts, reproduced the same rollback/connection failure. PostgreSQL explicitly terminated the idle connection; the worker subsequently committed after the expired parent released its row. Preserved before-fix proof: `BOOTED → ATTEMPT → COMMITTED 1 AUTH_CALLS=1`, with no valid observed-blocker proof. This establishes the preparation-under-lock failure mechanism. It does not invent a physical cause for the original worker's startup latency.

Correction is confined to `tests/Feature/Security/IdentityTransitionTest.php` and its existing `tests/Support/IdentityTransitionWorker.php`. The existing Symfony Process/InputStream pattern is used to prepare the real worker/session first, emit `READY`, then let the parent acquire its real row and explicitly release `CONTEND`. Only then does the worker emit `ATTEMPT` and attempt the real authority operation. No authority, SQL, test timeout, authentication outcome or original contention assertion changes. No production identity protocol, writer or command framework is introduced. The interrupt-worker path remains covered by the full affected class.

Regression data runs both immediate preparation and **16 seconds of real preparation delay**. A monotonic elapsed-time assertion confirms the delay was actually exercised. The test also asserts zero authority transaction nesting at readiness, observes the exact parent PID in `pg_blocking_pids(waiter)`, requires worker success with `STALE AUTH_CALLS=0`, and still requires exactly one canonical security session.

Final affected verification: **24 PASS / 1,308 reported assertions / 67.37s**, including the complete IdentityTransition class and existing RowLockReadiness unit cases. Both immediate and delayed cases have `worker_ready_before_publish=true`, `actual_blocker_observed=true`, `pass=true`. Immediate holder/waiter **23036/18824**; delayed holder/waiter **3532/10752**. Delayed measured preparation **17,153.4202ms**; worker output `BOOTED → READY → ATTEMPT → STALE AUTH_CALLS=0`. Pint on both changed PHP test files PASS.

The initial **92 PASS / 1 ERROR** aggregate and all before-fix failures remain FAILED historical evidence. Final verification reuses its unaffected passing cases and reruns the entire affected class plus readiness unit protection after correction. No fresh 94/94 aggregate or central Foundation run is claimed. Production authority and security deadlines are unchanged. This is a verified test-orchestration correction, not a relaxation of PostgreSQL or a claim that arbitrary slow workers are harmless in production.

### Final evidence and reuse boundaries

Under `storage/framework/testing/`:

- `slice7-restoration-resumed-browser-results.json`: SHA-256 `00be94379629aa2cdb68fd2fa8b97deec486d675114d670cb703ac29fd9c7aff`.
- `slice7-restoration-resumed-source-manifest.json`: SHA-256 `b74748bee00051f229b67330c9daf27d23af9e5fc8bf78153f6c4126fd49576b`; corresponding source-unchanged **True**.
- `slice7-race-delayed-before-proof.json`: SHA-256 `d1606218b709caa82b5af20dc98b306b86f509d441a66907fe0e633de16fee67`; failed execution/XML in `slice7-race-delayed-before.log` and `.xml`.
- `slice7-race-corrected-0-proof.json`: SHA-256 `b4831385384113a1c66e3c757ac7066bd17d7bf13f0f8757c4069f4adb73f011`.
- `slice7-race-corrected-16000-proof.json`: SHA-256 `3f75e59c61290617a65d4898abfeb0bf5247a2d1403519b612a9898d797975b9`.
- `slice7-identity-readiness-corrected.log` / `.xml`: 24 passing cases; `slice7-identity-readiness-inputs.json`, SHA-256 `96c10b489181e41ea8f49d68ddca45ed3f00638786ab3ebb3e53bc5a3967fb3e`, explicitly labeled **post-run digests**, with no source edits during the execution. It is not mislabeled an automatic browser manifest.

All **401 checked production/runtime/build inputs** still match the accepted restoration manifest. The later PHP edits affect only the test worker/class, not any browser route/controller or build input. The earlier 7-case commercial proof remains valid for unchanged application code and its own harness. The complete 15-case restoration run covers the revised restoration harness; old observer/network-failure artifacts are retained separately. Frontend **200 PASS**, SPA **199 PASS**, contracts, TypeScript, clean production build, runtime/dependency guards and inventory evidence remain applicable to unchanged relevant inputs. No redundant full-suite/build rerun substitutes for this file-state comparison.

### Final gates

| Gate | Result in Owner-approved local scope |
| --- | --- |
| A — ZERO_INERTIA_DISPLAY_ROUTES | PASS: **0 / 173 registered routes** |
| B — ZERO_INERTIA_RUNTIME_CONSUMERS | PASS: source/default build/current production assets |
| C — N1_RUNTIME_REMOVED | PASS: no runtime/build/alias; generic protection mapped before old harness retirement |
| D — DIRECT_DEPENDENCIES_REMOVED | PASS: definitions/locks/previous unchanged clean installs; no compatibility shim |
| E — SECURITY_CONTRACTS_PRESERVED | PASS locally: Security A/B real admission/late-response proofs; Security C measured exclusion for documented configuration. Native cached frames elsewhere remain UNVERIFIED and outside accepted scope |
| F — PRODUCTION_BUILD_AND_REQUIRED_TESTS_VERIFIED | PASS locally using exact-state evidence and corrected affected PHP class. Historical failed aggregates preserved; no claim of a new full Foundation/aggregate run |

All task-started database/Redis/PHP/browser/proxy processes are stopped before handoff. Independent local follow-up commit and its exact SHA/clean Git status are reported in the handoff; the commit does not retroactively change test provenance. No Push, PR, new CI run, Merge, Deployment, production enablement, worktree/evidence deletion or next slice.

**READY FOR INDEPENDENT REVIEW — LOCAL CONFIGURATION ONLY**

## S7-R01 — human market draft renewal remediation — 2026-10-19

### Baseline, cause and precise production scope

Preflight: branch `codex/ba-inertia-retirement-007`, clean working tree, HEAD **`100883f8c6fb79916d2914aa0fdc56d8223723f4`**, matching the independently reviewed candidate. S7-R01 applies unchanged. Historical `CommercialMarketPreparation.vue` at `3c82c41` saved `country_code`, `denomination_code`, `label`, `reason` before logout, keyed by user, and restored human text/valid current options. It used no stored authentication authority. `PreparationView.vue` instead called `logout()` directly; its retained-command contract did not replace this legitimate unsent-draft capability. Historical case 26 was incorrectly classified RETIRE.

Only production change: `resources/js/commercial/PreparationView.vue`. No Laravel controller/authority, SessionAuthority, SQL/RLS, commercial writer, transport, identity runtime, dependency or PostgreSQL deadline is changed. Current single SPA and existing logout/login/admission/read contracts remain owners. INV-004 canonical ownership, INV-005 honest outcome states and INV-007 decision/authority separation are preserved: retained text cannot authorize an effect.

### Draft lifecycle and identity boundary

- The explicit renewal action serializes only the four human fields, UI `mode`, schema version and Principal/Boundary metadata under `commercial-preparation-draft:v1:<boundary>:<principal>` in `sessionStorage`. The separate existing `commercial-preparation-spa` pending/UNKNOWN keys are unchanged. No intent/binding, command, revision, receipt, credential, grant, fresh-authentication or passkey evidence enters the human record.
- `setItem` and matching readback must succeed before existing logout starts. Storage failure leaves the live fields and displays an error without starting renewal. Inflight writes/restoration cannot race renewal; a confirmed renewal departure avoids the unrelated pending-command departure prompt without retrying that command.
- The component does not read/apply the record before the current document is ready. It then makes the existing real witnessed `readPreparation(false, true)` and requires current ownership, active component, ready status, identical admitted projection, `canPrepare`, and an unchanged saved record before publishing fields. A stale, denied, closed or unmounted completion cannot publish or consume it.
- Record keys, owner, version, mode, string types and lengths are checked strictly; corrupt/extra-authority-bearing records are not used and remain available for explicit discard. Country/currency are filtered against the current server options; removed choices become empty, not a guessed default. Intent/binding for a later explicit command come only from the current read.
- Successful application removes the saved record; explicit existing draft discard also removes it before clearing fields. Delete failure is surfaced, not called consumption. A document-local one-shot check prevents repeated application over new edits in the same document. Another document may restore the retained record if deletion failed; the UI explicitly warns of this limitation. No physical JavaScript memory-erasure guarantee is claimed.

### Red/green and final required gates

Before the production correction, the new actual-SFC focused tests produced **9 FAIL / 2 PASS** in `s7-r01-red.log`. The positive renewal/restoration, storage-failure, admission/read, consumption and UNKNOWN-isolation assertions failed; already-absent cross-owner/corrupt restoration negatives passed. This is retained as a failed run, not rewritten. Final actual-component suite has **30 PASS**: original 17 preparation cases plus **13** targeted cases (T1–T10, deletion-failure once-per-document behavior, latest admitted intent, delegated label/note without administrator/expiry). No assertion was weakened to obtain green.

| Gate | Executed result |
| --- | --- |
| Actual preparation + transport + router + auth closure focus | 56 PASS before two additional final preparation cases; final official suites below include all 13 new cases |
| `scripts/builderad.ps1 npm test` | **213 PASS / 0 FAIL**, `s7-r01-final-frontend.log` |
| `scripts/builderad.ps1 npm run test:spa-foundation` | **212 PASS / 0 FAIL**, `s7-r01-final-spa.log` |
| `scripts/builderad.ps1 npm run build` | PASS: `vue-tsc` and clean production Vite build, `s7-r01-build.log` |
| Runtime source/assets guard and its negative/lock tests | PASS; **2/2** guard tests |
| Inertia ceiling and runtime inventory/check | PASS: **173 registered routes / 0 Inertia displays / 120 frontend modules**; generated diff only new discovered testLeads and its rendered references |
| Existing PHP projection/witness prerequisites | **2 PASS / 37 assertions**, official isolated acceptance runner; no new PHP code or additional unrelated PHP suite |
| Actual targeted Chromium | **3 PASS / 0 errors / 0 HTTP 5xx**: real UV setup plus the two journeys below |

### Actual Chromium journey, not mocked authentication

Official guarded `Test-GoalsSpa.ps1 -Phase Acceptance -BrowserSelection auth -TestFilter test_spa_preparation_ -CreateBrowserFixture -ReuseRun <recorded owned run>`, with `INTERNAL_OPERATIONS_SLICE5=enabled`, `INTERNAL_OPERATIONS_CASES=draft-renewal`. PostgreSQL 18 disposable database/three roles/RLS, dedicated Redis 6397, actual Laravel HTTP and production SPA assets; only synthetic users, virtual UV keys and isolated mail. Runner reported **STOPPED / EXIT=0**.

1. Account A with stale fresh authentication enters **JO / USD / Review retained human note**, selects renewal, receives actual HTTP 200 logout, and reaches a new guest login document. Exact saved record has only permitted fields/metadata. Actual virtual-UV login admits A in a new internal document. A held real commercial GET proves no private draft display or early consumption while admission/read is incomplete. Releasing it restores the choices/note, consumes storage, and reload does not reapply. Existing UNKNOWN key remains absent; **0 commercial writes** and unchanged server approval/grant/market counts.
2. Account B is enrolled with two real virtual-UV ceremonies and existing isolated staff/founder fixture, not a mocked authority result. A retains a second human draft through actual logout; the same original tab then signs in using B's actual resident credential. Laravel admits B; B's commercial UI has empty human fields, A's record remains unconsumed under A's key, and **0 commercial writes** / unchanged commercial server counts.

Both journeys use Chromium **145.0.7632.6**, local HTTP configuration only. Served-byte verification PASS: **669 forwarded / 636 browser-observed asset responses**. No page errors or HTTP 5xx. No real device, native cached-frame restoration or production environment is accepted.

### Fingerprints, exact-state attribution and prior evidence

Artifacts under `storage/framework/testing/`:

| Artifact | SHA-256 |
| --- | --- |
| `s7-r01-red.log` | `80246300abda3116fc250df09dff7693a47db23529d1a2fa199c51c40527ec32` |
| `s7-r01-final-browser-results.json` | `316e031cc6488b34c4facdb5de86b12960db724386240a3a27d5a3f7344374ea` |
| `s7-r01-final-source-manifest.json` | `d830b16424d2e6ebf20eefa9e6fb082b12d35447ae55e7a48e1cf05cbbd469b0` |
| `s7-r01-final-frontend.log` | `b886c206402ec7b1d1d2ebc008042dd1482a65ffb5a628e155160baeb080123e` |
| `s7-r01-final-spa.log` | `3d23e2f834a14f3b22f748134f8681d6333948f902c178e247f0c2a8515322ec` |
| Production `PreparationView.vue` | `39010070ca6a47e035705704e37f2b327cce019ca6e31dec24d033eb83dfa787` |
| Targeted `commercial-draft-renewal-browser.mjs` | `d754f4cb223fe3b6d104e1d1b4749a2d111bdbc61b535d81d9075bcb60e3c19e` |
| Production `assets/main-BrTLNuYj.js` | `39cc0a177c701feacb13469867c06e3fd8d0d0f2b28efa1fcf1ebadcc861e4ac` |

`s7-r01-final-source-unchanged.txt` is **True**. Browser evidence identifies the reviewed HEAD plus its exact dirty-source manifest, not falsely a clean post-commit run. **404** production/runtime/build/targeted-harness inputs match that manifest with **0 mismatches**. After the browser run, two additional SFC cases and documentation/generated testLeads were added; official final suites then verified the final test file. Application/build/browser-harness bytes were not changed after their proof. The earlier non-final `s7-r01-browser-results.json` copy was made before runner completion from the prior reused run; it is **not S7-R01 execution evidence**. Only the explicitly final artifacts above are claimed here; archives remain intact.

Earlier browser evidence does **not** cover this newly restored storage/renewal path. The two new journeys own that proof. Previously demonstrated 15-case BFCache exclusion retains only its exact old-path/configuration meaning; it is not promoted into acceptance of cached restoration with this new draft or physical-device confidentiality. New logout clears/unmounts private fields, admission gates their next publication, and the tested new-document paths are covered above. Native pre-JavaScript cached-frame behavior remains UNVERIFIED/outside the Owner's local acceptance scope. Unchanged PHP authority/contention evidence is reused, not rerun or presented as a new comprehensive aggregate. No central Foundation, publication or Slice 7 self-acceptance.

### Handoff

S7-R01 implementation and focused evidence are ready for the independent reviewer to decide closure. The incorrect historical case-26 retirement is replaced, not waived. A–D runtime/dependency/display removal remains protected by the final guards; affected E/F identity/presentation/build evidence is updated as scoped above. All task-owned database/Redis/PHP/browser/proxy processes are stopped. The independent local remediation commit and final clean Git state are supplied with the handoff. No Push, PR, Merge, Deployment, worktree cleanup or next slice.

**READY FOR TARGETED INDEPENDENT RE-REVIEW**
