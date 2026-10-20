# Administration improvements delta closure

Date: 2026-10-21. Baseline: `4fa894c9e72ee4e9f01d5edaf38b01d50cbbeb64`.
The closure commit containing this report is the review candidate; compare its delta with the baseline. All six Owner-requested uncommitted frontend files were preserved. No backend, route, schema, dependency, command, identity/session or authorization implementation changed.

## Improvements and files

| File | Original issue | Final change |
| --- | --- | --- |
| `resources/js/internal-operations/administration.css` | Service grant action aligned against label tops; shrinking fields and account flex items could exceed available space; mobile utilities were crowded | Bottom-align grant action, use shrinkable grid columns, bound account controls, isolate technical strings, arrange mobile controls in two rows with usable targets |
| `resources/js/spa/main.ts` | Long internal identity utility label competed for mobile space | Short visible translated label with complete accessible name; existing RouterLink target/callbacks retained |
| `resources/js/internal-operations/ServicesView.vue` | Unresolved-command confirmation and default rejection remained Arabic in English | Translate both strings using current locale; command/outcome semantics unchanged |
| `resources/js/commercial/PreparationView.vue` | Unbounded generic forms and inconsistent sections/actions; currency, expiry and approval identifiers inherited Arabic direction | Bound forms/controls, scope section/action presentation, explicit LTR technical values and expiry isolation |
| `resources/js/commercial/OperationsEditor.vue` | Technical field names inherited surrounding Arabic direction | Isolate technical labels with LTR bdi |
| `resources/js/appearance/AppearanceView.vue` | Numeric-only HEX values could adopt RTL | Explicit LTR bdi for published HEX values; token/publication owners unchanged |
| `tests/Support/admin-ui-browser.mjs` | Previous four-page checks did not establish final eight-route bilingual history/layout acceptance | Optional `ADMIN_UI_DELTA=1`: eight actual pages, keyboard links, both histories/directions, 64 screenshots, restricted-employee business denial, mobile header bounds; isolated fresh sessions for screenshot batches; production asset hashes collected from actual proxy response streams |
| `docs/BUILDERAD_ADMIN_IMPROVEMENTS_DELTA_CLOSURE.md` | Closure/provenance not recorded | This report |

## Link corrections and actual destinations

No href or route was changed in this delta. The previously reported broken links were persistent-preview prerequisites corrected by separately approved operations before this closure:

- Customers `/internal/administration/customers`: missing `builderad_customer_directory_witness()` caused the existing SPA witness request to fail. Owner separately authorized existing migration `000390`; it was applied before closure. The real controller still uses the canonical customer administrator check.
- Business types `/internal/business-types`: preview founder lacked `business.manage`; Laravel correctly denied it. Owner separately authorized one narrowly scoped founder grant, recorded with audit. No authorization bypass or role change was added to Vue.

These operations are not Git changes and were not repeated here. This closure did not modify persistent preview data, apply migrations or issue grants. Appearance migration `000400` remains a separate preview limitation: isolated publication proof does not establish persistent-preview publication availability.

Production-built browser verification on guarded synthetic sessions reached all eight authorized destinations through the existing Vue Router, using keyboard Enter, matching resource/witness 200 responses and exact active links. Back and Forward traversed the full sequence in Arabic and English while a document marker remained unchanged. Navigation/history emitted no non-GET commands.

| Destination | Page |
| --- | --- |
| `/internal/administration` | Team and permissions |
| `/internal/administration/customers` | Customers |
| `/internal/business-types` | Business types |
| `/internal/services` | Service identities |
| `/internal/commercial` | Commercial operations |
| `/internal/administration/commercial-markets` | Market preparation |
| `/internal/administration/appearance` | Global appearance |
| `/internal/security/devices` | Account and security |

A synthetic employee without `business.manage` received 403 and retained the admitted administration source; private business content never mounted. Real preview browser sessions were not exported or forged.

## Verification and evidence

Evidence root (ignored local artifacts): `storage/framework/testing/goals-spa-7b13b1e4-6e83-406f-b984-76670a4de948/`.
Closure logs: `storage/framework/testing/admin-rtl-wnfaywrz.0zl/`.

- Final candidate production build: PASS (`scripts/builderad.ps1 npm run build`, TypeScript + Vite). Runtime production asset guard: PASS.
- Affected JavaScript tests: 98/98 PASS: admin-ui, internal-operations-spa, business-type-editor-concurrency, appearance, account-spa, commercial-preparation-spa, commercial-editor-concurrency. Test-execution checks: 4/4 PASS. Logs `closure-focused-tests.log`, `closure-runner-tests.log`, `closure-build.log`.
- Administration browser: eight functional groups PASS on final runtime candidate, retained explicitly in `admin-ui-delta-closure/retained-functional-results.json`; fresh asset pass in `admin-ui-delta-closure/results.json` matched 43 served JS/CSS assets against checkout. Runtime source/lock/manifest hashes were checked before retaining functional results. Do not describe retained groups as freshly rerun in the asset-only pass.
- Matrix: 64 actual page screenshots: 8 pages x 1440/1280/768/390 x RTL/LTR, no viewport horizontal overflow; published primary token unchanged; mobile header control bounds/target heights asserted. Root inspected contact sheets and representative full-page captures, including RTL/LTR mobile and desktop plus tablet RTL. No claim of exhaustive per-pixel or assistive-technology audit.
- Existing browser behaviors re-executed: modal focus containment with Tab/Shift+Tab, underlying inertness, Escape/backdrop/close/navigation/resize, restored toggle focus, appearance/service/invitation draft preservation, real customer filters/details, existing 422 and synthetic successful invitation, advertiser separation and restricted employee denial.
- Global Appearance browser: fresh 8/8 PASS, zero page errors, production asset hash match; `appearance-browser-results.json`. Includes local preview isolation, published fresh-document application, stale editor, lost acknowledgement, semantic states and restore defaults.
- Both official guarded browser runs: `source-unchanged.txt=True`. PostgreSQL 5433 and Redis 6397 disposable only; no tests touched persistent PostgreSQL 5432. Owned test servers, proxy, Chromium and cluster stopped; Owner-requested preview restored at localhost:8137 (PID 20740 at completion).
- `git diff --check`: PASS. No Push, PR, merge or deployment.

Reproduction: build once, set `ADMIN_UI_DELTA=1`, then `pwsh -NoProfile -File scripts/Test-GoalsSpa.ps1 -Phase Browser -BrowserSelection admin-ui`; run the existing appearance selection separately. Official runner requires free declared ports, including preview 8137; identify ownership before temporarily stopping it. Never reuse persistent preview data as a test database.

Two harness findings were localized without product patches: expanded capture outlived the deliberately two-minute synthetic cookie lease (fresh isolated sessions per batch), and CDP response-body eviction broke late asset collection (hash actual response stream in the existing test proxy). Functional receipts from the second attempt remain retained; only asset collection was repeated after the latter correction. No timeout/security assertion was weakened.

## Acceptance boundary

This is local delta verification for independent review, not full Foundation/deployment acceptance. Hosted Foundation was not requested or published. Reuse unaffected baseline evidence documented in `BUILDERAD_ADMIN_UI_MODERNIZATION.md`; do not promote focused browser/JS checks into a new complete backend, physical passkey, BFCache, provider or deployment claim. Subsequent independent review must compare this closure commit against `4fa894c`, assess the integrated candidate and reuse unaffected evidence rather than rerun unrelated comprehensive suites.
