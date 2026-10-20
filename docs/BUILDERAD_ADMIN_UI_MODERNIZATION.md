# Native Vue administration UX and visual modernization

## Baseline, authorization and dependency

- Authorized worktree: `C:\dev\builderad-worktrees\ba-design-customization-001`.
- Exact starting HEAD: `376e4c93f24819579502ccf0c3f099e9d08947c9`, detached HEAD, initially clean. No branch/worktree was created.
- This is the required integrated Global Appearance implementation. Its storage, publication/revision, defaults, first-paint tokens and isolated draft implementation were present before editing and remain unchanged.
- Local `origin/main` pointed to `c7825aa09bd2ca616f08e25dfba3e112bbf08476`; no local branch containing the appearance commit was present. These are inspected local refs, not a fresh GitHub merge-status claim. No merge, rebase, cherry-pick, reconstruction or remote fetch was used.
- No Push, PR, Merge or Deployment. The local commit's immutable SHA and final observed Git status are reported in the handoff response and ignored `storage/framework/testing/admin-ui-design/handoff.json`. A commit cannot contain its own SHA without changing that SHA.

The product owner supplied the modernization brief. This task changes administration presentation only. Applicable Executable Architecture boundaries remain INV-001/002 (no source/provider dependency), INV-004 (presentation owns no business or identity semantics), INV-005 (feedback retains actual acknowledged/unknown/failed truth) and INV-007 (navigation/preview confer no execution authority). No accepted backend semantics changed and no new capability or invariant was invented.

## Inspected UI and real deficiencies

The actual runtime is `resources/js/spa/main.ts`, the existing Vue Router registrations, `SpaPage` and `spa.blade.php`. `OperationsShell.vue` previously rendered eight unconditional links in a wrapped horizontal list underneath a separate root header. Unused legacy `.admin-shell` CSS was not the active SPA layout.

Observed problems in the retained baseline screenshots:

- Two competing navigation areas and little orientation as links wrapped on narrow screens.
- Team administration displayed an empty outcome/review panel above the actual task even before an operation existed.
- Page titles, form widths, padding, containers and status presentation varied between administration, customers and services.
- Customer records lacked desktop column headings. Actions and relationships were harder to scan.
- Services nested a separately padded RTL-only page and unrelated rounded cards inside the administration shell; visible template labels remained Arabic in English layouts.
- There was no responsive administration drawer or related focus behavior.

These are observed layout/usability deficiencies. Preferences about exact spacing or visual restraint are aesthetic judgments, not correctness defects.

## Navigation inventory and structure

One inventory, `administrationNavigation.ts`, drives both desktop and mobile `AdministrationNavigation.vue`. It does not define or authorize Laravel routes.

| Group | Existing destination | Responsibility and real actions |
| --- | --- | --- |
| Administration | `/internal/administration` | Staff eligibility, invitations, grants and bounded provider setup status |
| Administration | `/internal/administration/customers` | Search/filter customer and Workspace records, inspect relationships, existing status decisions |
| Catalog and services | `/internal/business-types` | Existing business-type definitions, guidance and priors |
| Catalog and services | `/internal/services` | Service identities, one-time credentials, approved scopes and grants |
| Commercial operations | `/internal/commercial` | Existing admitted commercial operations |
| Commercial operations | `/internal/administration/commercial-markets` | Existing market preparation and operator delegation |
| Platform | `/internal/administration/appearance` | Existing global appearance editor and publication |
| Platform | `/internal/security/devices` | Existing sessions and passkey management |

The baseline's eight navigation links were **unconditional**. That visibility is preserved; no unavailable capability inventory was fabricated and no extra administration request was added just to build a menu. Existing action-level `v-if` permissions remain in their pages, and Laravel still denies unauthorized destinations/actions. An employee with `staff.view` sees no invitation/grant form and is denied the Founder-only appearance page in actual browser verification.

Vue Router's `isExactActive` supplies one current-section indication, including avoiding a second active parent link on administration subpages. Route paths, query semantics, admission, history and transport remain unchanged.

## Header, layout and drawer

`AdministrationLayout.vue` wraps authenticated internal views, including internal account/security. The existing root header's VNodes/callbacks are passed into its header slot: BuilderAd, administration context, security/identity access, locale and logout retain their actual behavior. Guest and advertiser rendering keep the existing header branch. No second administration application or additional router was introduced.

`OperationsShell.vue` now owns only its page heading/content surface. The single layout owns grouped navigation, header, sidebar and drawer. Its drawer state is component-local; no theme store, navigation store or global drawer event protocol was added.

- Desktop: 248px logical-start sidebar, right in RTL and left in LTR. The navigation is sticky and independently scrollable for long pages.
- Below 1024px: a named native modal `dialog` is opened from the header toggle. It supports close control, Escape, outside click and selection dismissal. Resize back to desktop closes it.
- Native modal inertness prevents background form interaction. Focus returns to the toggle. A bounded local Tab/Shift+Tab handler wraps currently visible, enabled focus targets at the dialog boundaries; it does not intercept Escape or normal in-list traversal.
- Page content remains mounted while the drawer opens/closes. Appearance, service and invitation drafts survive those interactions. No pending command is replayed.
- The only document-level structural rule is `html:has([data-admin-drawer][open]) { overflow: hidden; }`, which locks page scroll solely while this administration modal is open and automatically releases when it closes/unmounts.

## Page-level and CSS changes

The new `administration.css` uses administration-specific names or the actual `.ba-admin-layout[data-admin-shell]` scope. Shared advertiser/authentication components, `app.css`, the appearance token owner and shared business logic were not edited.

- Stronger page-title hierarchy, a coherent content width/spacing scale, neutral canvas and deliberate section boundaries.
- Team page: a permission-guarded primary invitation shortcut opens the existing disclosure and focuses its original first field. The empty outcome box is omitted until an actual outcome/review/readback state exists. Validation, grants, commands and UNKNOWN messages retain their existing contracts.
- Customers: aligned Record / Status and relationships / Actions headings, readable record spacing, fixed semantic status badges, clear filters/details, and mobile column collapse without page overflow. Records and all identifiers/actions remain real data from the existing resource.
- Services: bounded two-column desktop forms, stacked mobile controls, consistent labels/near-field errors/action styling, meaningful empty states and bilingual visible template copy. One-time credential handling remains transient and unchanged.
- Business types and appearance: existing components gain administration-scoped spacing, bounded forms, clearer grouping and consistent surfaces. The appearance preview remains independently scoped and publication still requires explicit authorization/current revision.
- Internal security and commercial pages inherit the coherent shell and applicable scoped primitives; their implementations were not rewritten.

Primary/Secondary and foreground/focus inks reuse the existing published Global Appearance variables. Success, warning, error, neutral surfaces and disabled meanings remain separate. No new theme configuration, notifications, search, metrics, presets, dependencies, gradients or dashboard features were introduced. The existing appearance preview's clearly illustrative sample card remains a preview, not a new administration metric.

## Browser evidence and before/after comparison

Evidence root (ignored, local only):

`storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948`

The actual production-built SPA was served through the official guarded browser runner with PostgreSQL 5433 / Redis 6397 and synthetic admitted Founder, advertiser and employee sessions. This proves the tested application/authorization flows; it is not real-device WebAuthn evidence. Persistent preview data was not used for administrative writes.

Before/after matrix: the same four actual routes, comparable unchanged fixture records before functional writes, default published colors `#155f53` / `#334b73`, widths 1440/1280/768/390 in RTL and all four routes at 1280 LTR: **20 corresponding screenshots per version**. The before matrix was captured on the required baseline before implementation. Actual customer filtering, invitations and permission scenarios ran after the screenshot matrix.

| Representative route | Before desktop | After desktop | Before mobile | After mobile |
| --- | --- | --- | --- | --- |
| Team/administration | [1440 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-before/administration-1440-rtl.png) | [1440 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-after/administration-1440-rtl.png) | [390 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-before/administration-390-rtl.png) | [390 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-after/administration-390-rtl.png) |
| Customer records | [1440 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-before/customers-1440-rtl.png) | [1440 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-after/customers-1440-rtl.png) | [390 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-before/customers-390-rtl.png) | [390 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-after/customers-390-rtl.png) |
| Service forms | [1440 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-before/services-1440-rtl.png) | [1440 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-after/services-1440-rtl.png) | [390 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-before/services-390-rtl.png) | [390 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-after/services-390-rtl.png) |
| Global appearance | [1440 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-before/appearance-1440-rtl.png) | [1440 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-after/appearance-1440-rtl.png) | [390 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-before/appearance-390-rtl.png) | [390 RTL](../storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/admin-ui-after/appearance-390-rtl.png) |

See `admin-ui-before/results.json` and `admin-ui-after/results.json` for the complete route/viewport/direction matrix. Additional final screenshots are `drawer-390-rtl.png`, `drawer-390-ltr.png` and `advertiser-1440-rtl.png` under `admin-ui-after/`.

Objective results: no page-level horizontal overflow on the tested matrix, one administration header, eight exact active-route links, working keyboard/drawer/drafts/forms, consistent default tokens and served asset byte hashes. Visual assessment: stronger hierarchy, more scannable records and clearer separation of editable forms and published appearance. Screenshots were inspected by the primary agent and a separate same-provider evaluator. Its visual verdict was PASS; it is internal implementation review, not independent approval. An external different-provider evaluator was unavailable in the exposed tools.

The screenshot matrix preceded a focus-only correction. No visual markup or stylesheet source changed in that correction; a production rebuild confirmed the complete stylesheet asset set was unchanged. The matrix was retained, and focus was tested on the final production build. Evidence explicitly distinguishes retained matrix/functional results from the final fresh asset-verification pass.

## Verification and failure classification

| Check | Result and actual scope |
| --- | --- |
| `scripts/builderad.ps1 npm run build` | PASS, including TypeScript; final production build after the focus correction |
| `scripts/builderad.ps1 npm test` | 325/325 PASS on the initial stable candidate; after the focus-only correction, final affected selection passed 54/54 including the new third administration test. Unchanged unrelated passing evidence was reused |
| `node --test tests/admin-ui.test.mjs tests/internal-operations-spa.test.mjs tests/appearance.test.mjs tests/spa-router.test.mjs tests/spa-transport.test.mjs` | Final 54/54 PASS; actual compiled component focus/draft behavior and current route/transport/appearance checks |
| `npm run api:check` | PASS; no generated API contract or server endpoint changed |
| `node scripts/inertia-retirement.mjs` and `node scripts/runtime-retirement.mjs --assets` | PASS within their declared retirement/asset coverage |
| `scripts/Test-GoalsSpa.ps1 -Phase Browser -BrowserSelection admin-ui` | Six actual functional groups PASS; retained receipt in `admin-ui-after/retained-functional-results.json`; final fresh asset pass and source stability receipt also PASS |
| `scripts/Test-GoalsSpa.ps1 -Phase Browser -BrowserSelection appearance` | Final 8/8 groups PASS, zero page errors and served hashes match the final production checkout |
| PHP production boundary | No application PHP, SQL/migration, configuration, routes or authorization implementation changed. Relevant 29-test/260-assertion PHP evidence from the appearance baseline remains retained evidence, not a new PHP run on this candidate. Current browser tasks used actual existing Laravel endpoints |
| Test fixture PHP / formatting | `php -l tests/Support/AdminUiBrowserFixture.php`, affected-file Pint and `git diff --check` PASS |

The six administration functional groups cover: one header and advertiser separation; grouped navigation with Back/Forward and no ordinary appearance fetch; real customer filtering/details; modal focus/inertness/Escape/outside/selection/resize and drafts in RTL/LTR; real duplicate-email validation followed by a successful synthetic invitation; and existing employee permission visibility/denial. No external invitation email was sent (isolated array mailer). Appearance regression covers preview isolation, publish, fresh advertiser documents, stale publication, lost ACK with no replay, defaults and responsive/semantic states.

Resolved failures were classified rather than hidden:

1. New customer-filter test initially sampled the old DOM immediately after its network response. It now asserts the server's exact filter/total and waits for the actual row update. Production behavior was unchanged.
2. The shortened test-resume path initially tried the locale control before opening a document. Its setup order was corrected.
3. **Confirmed candidate defect:** repeated native Tab traversal escaped the modal focus boundary. A local forward/reverse containment handler fixed it; compiled-component and final real-browser checks passed. No assertion or timeout was relaxed.
4. Lazy CDP response-body reads failed after navigation during artifact collection, after functional groups had passed. Both browser collectors now read/have hash promises immediately on response. Valid functional results were preserved; a focused fresh asset pass completed without repeating invitations, and the complete appearance gate then passed with its corrected collector.

For a fresh reproduction, build production assets and run the two browser selections above. `ADMIN_UI_CAPTURE=before` records a baseline only; defaults run the final matrix/tasks. `ADMIN_UI_SKIP_MATRIX=1` reuses an unchanged recorded visual matrix. `ADMIN_UI_VERIFY_ASSETS_ONLY=1` verifies current served assets while retaining explicitly recorded functional evidence. These are test-runner controls only. Disposable sessions/keys/logs/screenshots are ignored and not committed.

## Remaining issues and acceptance boundaries

- The evaluator suggested optional polish to service-grant submit alignment and mobile utility-link spacing. These are non-blocking aesthetic/usability refinements, not confirmed functional defects. No extra redesign was performed after the acceptance conditions passed.
- Some inherited confirmation/server error strings can still be Arabic during an English session. Visible service template controls were translated; confirmation/command semantics and the wider localization catalog were not expanded.
- Representative visual/task evidence covers the four selected pages plus advertiser workspace separation; it does not claim exhaustive pixel inspection of every business-type editor, commercial workflow, provider dialog, physical device or native BFCache condition. Their existing route/security/business implementations remain unchanged.
- Persistent preview `builderad_test` on 5432 was not modified. Its previously missing appearance/customer-directory migrations are separate operational conditions; they were not applied. The preview uses appearance defaults until its authorized migration is performed elsewhere. This task creates no new migration.
- Test PostgreSQL/Redis/PHP/proxy/browser processes were stopped. The owner-requested preview is restored separately on `http://localhost:8137` and intentionally kept running.
- Hosted `.github/workflows/foundation.yml`: NOT EXECUTED for this candidate (no publication authorized). Independent review remains outstanding. No full Foundation acceptance, merge/deployment readiness or independent approval is claimed.

## Actual changed files

- `docs/BUILDERAD_ADMIN_UI_MODERNIZATION.md`
- `docs/BUILDERAD_TEST_EXECUTION_RATIONALIZATION.md`
- `package.json`
- `resources/js/internal-operations/administration.css`
- `resources/js/internal-operations/AdministrationLayout.vue`
- `resources/js/internal-operations/administrationNavigation.ts`
- `resources/js/internal-operations/AdministrationNavigation.vue`
- `resources/js/internal-operations/AdministrationView.vue`
- `resources/js/internal-operations/CustomersView.vue`
- `resources/js/internal-operations/OperationsShell.vue`
- `resources/js/internal-operations/ServicesView.vue`
- `resources/js/spa/main.ts`
- `scripts/Test-GoalsSpa.ps1`
- `tests/admin-ui.test.mjs`
- `tests/Support/admin-ui-browser.mjs`
- `tests/Support/AdminUiBrowserFixture.php`
- `tests/Support/appearance-browser.mjs`

READY FOR INDEPENDENT REVIEW
