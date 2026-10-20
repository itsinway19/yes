# Global appearance settings — local implementation handoff

## Baseline and scope

- Worktree: `C:\dev\builderad-worktrees\ba-design-customization-001`.
- Baseline: `c7825aa09bd2ca616f08e25dfba3e112bbf08476`; detached HEAD (no branch); initially clean.
- Execution: local implementation and focused verification. No Push, PR, Merge or Deployment.
- Product owner decision: the supplied Global Appearance Settings brief. Exactly one global published identity; primary and secondary only. No per-user/Workspace identity, layout editor, optional radius/background settings, new dependencies or authentication flow.
- Final local commit/status: the immutable resulting SHA is reported in the final handoff and recorded after commit in ignored `storage/framework/testing/appearance-design/handoff.json`. This avoids a self-referential commit hash inside its own committed document. The receipt includes the observed Git status and build-manifest hash.

## Existing architecture and invariant ownership

The current runtime is `resources/js/spa/main.ts`, Vue Router, `OperationsShell.vue`, typed application API, `SpaPage` and `resources/views/spa.blade.php`. Appearance administration is a new route in that existing application. Existing document-bound requests, CSRF, current principal, current security session, fresh authentication and staff grants remain authoritative. No transport, identity transition, Session Lease, router admission or presentation security implementation was changed.

`App\Appearance\GlobalAppearance` owns the public visual configuration. Its table has no tenant or provider fields. Applicable Executable Architecture rules are INV-001/002 (no provider dependency or mandatory source), INV-004 (visual configuration owns no identity/business truth), INV-005 (failed publication/read is not success/default certainty), and INV-007 (preview is not authority/publication; publication requires current authorized action). The existing Core dependency and source optionality guards passed. No future capability or provider acceptance is implied.

## Values, persistence and authorization

| Key | Default | Validation |
| --- | --- | --- |
| `primary` | `#155f53` | Exactly six HEX digits after `#`; normalized lowercase |
| `secondary` | `#334b73` | Same validation |
| `revision` | `0` before first publication | Required expected integer, 0–2147483646 |

No suitable platform settings store was found in the inspected administration/configuration boundary. Additive migration `0001_01_01_000400_create_global_appearance.php` creates one table, `global_appearance`, constrained to `id=1`. It contains both colors, monotonic revision, publisher and publication time. Database constraints also enforce HEX bounds. The ordinary runtime role can SELECT only; the existing authority role can SELECT/INSERT/UPDATE. There is no general CSS storage or dynamic selector.

GET/POST `/internal/administration/appearance` reuse existing internal routes and typed API conventions. Both require an active Founder and current `grant.manage` platform grant. Advertisers, employees (even with that grant), inactive/revoked principals and founders without the grant cannot publish. POST also uses the existing fresh-authentication middleware. There is no newly delegated action or parallel authority framework.

Publication locks the singleton row, compares the editor revision, saves both colors and inserts `global_appearance_published` into the existing security audit log in the same authority transaction. A stale revision returns 409 without writing. A failed audit rolls back the entire publication, including initial singleton insertion. Concurrent first insertion uses the same constrained row and locking path. There is no general history/approval system or automatic retry.

An absent relation or record gives server-defined defaults. Other database failures propagate; they are not silently converted into a successful default read. Normal migration ledger behavior provides repeatability; fresh installation and adding the table to an initialized database while preserving an existing account are tested.

## Preview, outcomes and first paint

The editor creates its unpublished draft in local Vue component state. Only the dedicated preview container receives inline preview variables. The surrounding application/root is never mutated while experimenting. The preview includes actual shared button and active navigation classes, an illustrative dashboard card, disabled control and fixed success/warning/error examples. Restore defaults changes the draft and requires the same explicit publication action.

An acknowledged publication reloads the document. Stale replies preserve the draft and original revision. A lost acknowledgement remains UNKNOWN and blocks resubmission until an explicit current read and explicit draft replacement; readback is never called acknowledgement. Leaving discards the component draft; a late response after unmount cannot reload another route. No writes occur on color input changes.

For native HTML documents only, `SpaPage` reads the public configuration separately from identity/bootstrap metadata. Blade emits validated fixed-name CSS variables in the head after the existing stylesheet registration and before application rendering. Configuration is absent from identity receipts, route witnesses and session records. Normal route navigation fetches no global appearance. Other already-open documents retain their colors until their next full load. No polling, BroadcastChannel, WebSocket or theme store was added.

Tokens are `--ba-brand-primary`, `--ba-brand-secondary`, `--ba-primary-text`, `--ba-secondary-text`, `--ba-primary-ink`, `--ba-secondary-ink`. PHP and preview TypeScript use the same WCAG sRGB computation. Filled buttons choose the higher-contrast black/white foreground (at least 4.5:1). Link/focus inks darken the chosen color to at least 7:1 against white, providing headroom for existing light neutral tints. Background colors stay exactly the chosen values. Existing Tailwind/shared CSS variables map onto these tokens; local preview aliases recompute inside its scope. Hover uses border/shadow rather than reducing foreground contrast.

## Visual surfaces and limits

Brand roles were updated in the native administration navigation/catalog/services, advertiser workspaces and creation, goals, business context, object purpose, account/authentication, source navigation/dialog/report commands, and shared PrimaryButton, SecondaryButton, TextInput and Checkbox. Provider branding/icons, neutral page/card surfaces, spacing/radius, semantic status badges, error/warning/success and neutral disabled styling remain fixed. Remaining provider/info indigo blocks express informational/connected states, not customizable brand actions. No blanket HEX replacement was performed.

Representative browser inspection covers the real editor, shared preview controls, administration route changes and fresh advertiser workspace documents at 1280×900 and 390×844. It does not claim exhaustive visual inspection of every provider dialog, every viewport, physical-device WebAuthn or native BFCache. Desktop/mobile screenshots were inspected by the implementing agent. A separate external design-model evaluation was unavailable with the exposed model providers; no independent approval is claimed.

## Verification and retained evidence

Evidence root: `storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948` (ignored, local only). Final browser receipt is `appearance-browser-results.json`; screenshots are `appearance-desktop.png` and `appearance-mobile-rtl.png`. The final browser run reports `source-unchanged.txt=True`, zero page errors, and byte hashes for every observed built asset matched this checkout's production files. Synthetic guarded sessions demonstrate application/authorization behavior, not real authenticator login.

| Acceptance | Evidence/result |
| --- | --- |
| Authorized read, publish and same-path restore | PHP + production browser PASS |
| Advertiser, unauthorized employee and revoked Founder grant denied | PHP PASS; cross-boundary advertiser rejected by existing `409 identityConflict` before controller |
| Malformed HEX, arrays, raw CSS/HTML, unsupported keys, invalid revision | PHP and JS PASS; no publication |
| Missing configuration/relation defaults | PHP + fresh browser document PASS |
| Preview isolation; abandoned draft discarded | Actual browser + JS PASS |
| Fresh documents share the publication; open document retains old colors | Two advertiser browser contexts PASS |
| Shared primary/secondary button and active nav tokens | Browser computed style + inspected previews PASS |
| No ordinary navigation theme request or identity coupling | Browser request log; unchanged transport/authority files; PHP lifecycle/grant preservation PASS |
| Stale publication and lost ACK | Browser and JS PASS; original draft/revision preserved, no replay |
| CSRF, current document and fresh authentication | PHP document/fresh checks and actual HTTP CSRF fallback PASS |
| RTL/mobile/keyboard focus/semantic states | Browser + screenshot inspection PASS |
| Atomic audit and database runtime write prohibition | PHP PASS |

Final development checks:

- `scripts/Test-GoalsSpa.ps1 -Phase Focused -TestFilter 'GlobalAppearanceTest|AppearanceColorTest|InternalOperationsSpaTest|CoreDependencyBoundaryTest|ArchitectureSourceOptionalityTest'`: **29 passed, 260 assertions** on guard-verified disposable PostgreSQL 5433 and isolated Redis 6397. After the final ink headroom adjustment, the two color unit tests passed again (36 assertions); database behavior did not change.
- `scripts/builderad.ps1 npm run build`: **PASS**, TypeScript plus production Vite assets (no proof-mode instrumentation).
- `scripts/builderad.ps1 npm test`: **323 passed**, none failed/skipped.
- `npm run api:check`, `npm run inertia:check`, `node scripts/runtime-retirement.mjs --assets`: **PASS** within their declared scope.
- Pint for all affected PHP files and `git diff --check`: **PASS**.
- `scripts/Test-GoalsSpa.ps1 -Phase Browser -BrowserSelection appearance`: **8/8 groups PASS**, production assets, synthetic isolated sessions; see receipt. Supports a fresh run, or reuse the recorded task-owned run with `-ReuseRun`.

Resolved development failures were test-fixture/orchestration or candidate issues, not concealed passes: advertiser rejection was initially asserted as 403 instead of the existing cross-boundary 409; PHP testing skips CSRF while real Laravel accepts same-origin Fetch Metadata independently of tokens (the final HTTP test exercises invalid-token fallback without that browser header); one new Vue fragment had mixed encoding and a mismatched closing tag, both fixed before the successful build; local Chromium was selected from the same installed path as the existing browser runner; synthetic browser sessions were updated to include the existing required committed lineage/selector. No identity middleware, protected timeout, permission check or assertion was weakened.

## Operational boundaries and remaining gates

- Persistent preview `builderad_test` on 5432 was **not modified** for this task. Neither the new appearance migration nor the previously pending customer-directory migration was applied there. The preview therefore uses defaults; actual publication requires the additive appearance migration in an authorized initialized environment. Do not use the persistent preview to exercise publishing before that migration is authorized/applied.
- Test PostgreSQL/Redis/PHP/browser/proxy processes were stopped by the guarded runner. The previously requested preview is restored separately on `http://localhost:8137` and intentionally remains running for the owner.
- Hosted `.github/workflows/foundation.yml`: **NOT EXECUTED for this candidate**; publishing is prohibited. No full Foundation acceptance, deployment readiness or independent approval is claimed. Separate broader lifecycle/physical-device evidence is not implied by this feature's browser run.
- Local implementation is ready to hand to an independent reviewer. Source-control publication and persistent-environment migration remain separate owner decisions.

## Actual file changes

See the exact local commit file list below. Generated OpenAPI/type changes belong to the same API contract; built assets, `.env`, fixtures/cookies/logs and screenshots are ignored and not committed.

- `app/Appearance/GlobalAppearance.php`
- `app/Http/Controllers/GlobalAppearanceController.php`
- `app/Http/Responses/SpaPage.php`
- `contracts/application.openapi.json`
- `database/migrations/0001_01_01_000400_create_global_appearance.php`
- `docs/BUILDERAD_GLOBAL_APPEARANCE_SETTINGS.md`
- `docs/BUILDERAD_TEST_EXECUTION_RATIONALIZATION.md`
- `package.json`
- `resources/css/app.css`
- `resources/js/account/AccountView.vue`
- `resources/js/api/generated/application.d.ts`
- `resources/js/appearance/AppearanceView.vue`
- `resources/js/appearance/colors.ts`
- `resources/js/appearance/state.ts`
- `resources/js/auth-entry/AuthEntryView.vue`
- `resources/js/business-context/BusinessContext.vue`
- `resources/js/Components/Checkbox.vue`
- `resources/js/Components/PrimaryButton.vue`
- `resources/js/Components/SecondaryButton.vue`
- `resources/js/Components/TextInput.vue`
- `resources/js/goals-spa/GoalsEditor.vue`
- `resources/js/goals-spa/GoalsReview.vue`
- `resources/js/internal-operations/BusinessTypesView.vue`
- `resources/js/internal-operations/OperationsShell.vue`
- `resources/js/internal-operations/routes.ts`
- `resources/js/internal-operations/ServicesView.vue`
- `resources/js/object-purpose/ObjectPurposes.vue`
- `resources/js/sources/DeclareSourceScope.vue`
- `resources/js/sources/GoogleConnectionPanel.vue`
- `resources/js/sources/MetaConnectionPanel.vue`
- `resources/js/sources/ProviderReportRequest.vue`
- `resources/js/sources/SnapchatConnectionPanel.vue`
- `resources/js/sources/SourceRegistryPanel.vue`
- `resources/js/sources/SourceView.vue`
- `resources/js/sources/TikTokConnectionPanel.vue`
- `resources/js/sources/XConnectionPanel.vue`
- `resources/js/workspaces/BusinessCreate.vue`
- `resources/js/workspaces/workspaces.css`
- `resources/views/spa.blade.php`
- `routes/web.php`
- `scripts/internal-operations-openapi.mjs`
- `scripts/Test-GoalsSpa.ps1`
- `tests/appearance.test.mjs`
- `tests/Feature/GlobalAppearanceTest.php`
- `tests/Support/appearance-browser.mjs`
- `tests/Support/AppearanceBrowserFixture.php`
- `tests/Unit/AppearanceColorTest.php`
