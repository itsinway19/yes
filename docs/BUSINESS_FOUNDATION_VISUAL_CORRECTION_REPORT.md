# Owner visual correction — 2026-10-03

The user approved reference 2's recognizable provider identity/action hierarchy with reference 1's restrained composition. This pass changes presentation, installs the exact supplied assets, and documents the existing legitimate internal grant path. Source, business type, prior, OAuth and authority semantics remain unchanged. No commit, push or merge.

## Live internal authority findings

Read-only actual PDO identity confirmed `builderad_test` on `127.0.0.1:5432`, using the existing app/authority/owner connections. Actual personnel: one active, current, verified founder, **super builderad laith**, public id `01a0df5d-bb72-729b-99cd-bf71af86b351`, lifecycle generation 3, two passkeys. Existing actions include `grant.manage`, `staff.invite`, `staff.attest`, `staff.activate`, `staff.view` and `service.manage`. Live `business.manage` grants: 0. No employee exists.

The existing `GrantAuthority::issueStaffGrant` requires a distinct active ordinary **employee**. It rejects self-grants and founder targets. The actual UI already submits `business.manage` through the existing grant form; there is no missing issuer UI or missing supported action. There is an eligible-target gap: the sole founder cannot grant this action to himself. Bootstrap is one-time and is not rerun. No permission bypass, fake session, fake user, direct permission insertion, new permission route, or automatic elevation is used.

Legitimate route: sign in with the founder's passkey at `http://localhost:8140/internal/login`; Administration → invite a real employee. That employee completes existing verification/passkey enrollment. Founder attests/activates them through the existing workflow. Administration → issue a grant → select that active employee → **إدارة أنواع النشاط (business.manage)** → provide reason → issue. The employee signs in with their own passkey and opens `http://localhost:8140/internal/business-types`. The founder's own new-section access is not implied by this grant. Actual internal screenshot validation remains pending until this legitimate workflow is completed; simulated screenshots must not be labelled authenticated review.

## Asset provenance

The entire owner-supplied directory `C:/xampp/htdocs/builderad-app/public/images/integrations/` was copied unchanged to `public/images/integrations/`. SHA-256 source/destination equality verified for all nine files:

```text
google_ads.webp
meta_ads.webp
salla.webp
shopify.webp
snapchat_ads.webp
tiktok_ads.webp
website.webp
whatsapp.webp
zid.webp
```

Only the four supplied currently-supported provider images are connected to provider brand presentation. Unsupported integrations are installed assets only, not source/domain registry entries or connectable cards. **No X image was supplied**; X uses its accessible text identity rather than an invented or fetched logo. Original multicolor artwork and proportions remain intact. The reusable brand component is a presentation mapping, not an alternative backend Provider registry.

## Verification and review status

```text
BUSINESS_TYPES_ADMIN_VISIBLE_WITH_REAL_AUTHORITY = NO
BUSINESS_MANAGE_AUTHORITY_BYPASSED = NO
SOURCES_VISUAL_REDESIGN_COMPLETE = YES
ONLY_SUPPORTED_SOURCES_SHOWN_AS_CONNECTABLE = YES
BUSINESS_TYPE_SOURCE_EFFECT_PRESERVED = YES
OWNER_PROVIDER_ASSETS_INSTALLED = YES
CANONICAL_PROVIDER_BRAND_COMPONENT_CREATED = YES
PROVIDER_ASSETS_REUSED_ACROSS_EXISTING_SURFACES = YES
DUPLICATE_PROVIDER_DOMAIN_REGISTRY_CREATED = NO
SOURCE_TRUTH_CHANGED = NO
AUTHORITY_CHANGED = NO
DESKTOP_VALIDATED = YES
MOBILE_WEB_VALIDATED = YES
ARABIC_RTL_VALIDATED = YES
FINAL_RELEVANT_TEST_RUN = PASS_109_TESTS_1177_ASSERTIONS_ZERO_FAILURES_ZERO_WARNINGS
BUILD = PASS_VUE_TSC_AND_VITE_764_MODULES_12_46_SECONDS_EXIT_0
```

Screenshots generated using controlled API fixtures prove the real Vue components' presentation only; they do not prove a real provider exchange or a legitimately opened live internal page. The only incomplete owner-review requirement is the legitimate internal Business Types screenshot/access, for the actual eligibility gap documented above.

Final complete relevant command:

```powershell
pwsh -NoProfile -File scripts/Test-BusinessFoundation.ps1 -Filter 'BusinessFoundationTest|WorkspaceCreationTest|InternalAuthorityTest|ProviderCallbackIngressTest|SourceRegistryTest|WorkspaceIsolationTest|AuthenticationTest|RegistrationTest|EmailVerificationTest'
```

Result: **109 passed, 1177 assertions, 132.52 seconds, exit 0, no warnings**. JUnit: `storage/framework/testing/business-db-ae46d382-b9c9-44c1-b4b9-79513de117cd/results.xml`; transcript: same directory `tests.log`. Test PostgreSQL stopped; its disposable data removed while logs are preserved. The existing real preview database was not reset or used by the test runner.

## Final design and frontend verification

At the owner's latest direction, all five sources form one equal-width row at desktop widths >=1280px. Identity is stacked logo/name for legibility. Narrower desktop uses three columns, tablet two, mobile one. Business context remains visible. No marketing hero, KPI fraction or unsupported connector cards. All existing operational panels mount once and expose their current response as a conservative presentation summary; a card opens the original operational controls in a native dialog. Existing callback fragments reveal their associated panel without changing OAuth.

Current denial/error states are never green. CONNECTED is labelled separately from readiness. Only explicit READY for every represented connection is green. Failed status refresh invalidates green and the available-connect summary. Exact semantic prior, guidance ordering and route/workspace authority contracts remain unchanged.

Reusable `ProviderBrand.vue` is used in Sources, all five connection panel headings, existing Admin operator rows, business type guidance/prior rows and source-registry advertising-family rows. Only visual asset selection lives here; backend Provider remains authoritative. Native select options retain accessible provider text.

- `pwsh -NoProfile -File scripts/builderad.ps1 build`: **vue-tsc PASS, Vite PASS, 764 modules, 12.46 seconds, exit 0**.
- `pwsh -NoProfile -File scripts/builderad.ps1 npm run test:localization`: **6/6 PASS**.
- `C:/tools/node24/node.exe storage/framework/testing/sources-review-9008752e/render.cjs`: **28/28 fixture checks PASS**; Arabic/English 1440/1366/768/390, same-row five-card coordinates, image loads, zero overflow/page errors, one status fetch per provider, disconnect/error summary synchronization, configured Real Estate/Ecommerce ordering, neutral empty guidance, callback presentation and known-denial states.
- Independent fresh visual evaluation round 2: **PASS**, all four criteria 2/3; **32 fresh checks plus hover/keyboard PASS**, including 375px. Exposed tools did not provide a different-provider evaluator; review was independent with the same provider. The inherited operational panels remain Arabic even in English mode; this preexisting copy limitation was not expanded into a functional redesign.
- `git diff --check`: exit 0, existing line-ending normalization notices only.

Read-only real preview smoke: both login pages and all four mapped official images return HTTP 200 from `http://localhost:8140`. Latest identity inspection: 28 migrations, 8 users, 5 workspaces, 0 business.manage grants. This visual pass performs no database mutation. In-app browser remains at legitimate advertiser login; no authenticated live Sources/Admin screenshot is claimed. Preview uses `builderad_test` at5432, not the disposable test cluster.

## Screenshots and changed files

All screenshots below visibly identify controlled fixtures:

- Desktop five-wide: `storage/framework/testing/sources-review-9008752e/screenshots/fixture-sources-ar-1440.png` and `fixture-sources-ar-1366.png`.
- Mobile: same folder `fixture-sources-ar-390.png`; tablet `fixture-sources-ar-768.png`.
- Connected controls: `fixture-connected-panel-ar-1440.png`; unconnected/incomplete states also appear in the Sources screenshot.
- Configured ordering difference: `fixture-commerce-ar-1440.png` against Real Estate Sources fixture, backed by the passing real PostgreSQL integration configuration test.
- Internal component fixtures only: `fixture-business-types-ar-1440.png` and `fixture-business-types-ar-390.png`; **not legitimate authorized live Admin evidence**.
- Denial/empty/ready/callback fixtures, English counterparts and `checks.json` are in the same folder. Independent fresh screenshots/checks are in `storage/framework/testing/sources-review-9008752e/eval-screenshots-2/`.

Changed frontend files in this correction:

```text
resources/js/Components/ProviderBrand.vue
resources/js/Components/Business/providerPresentation.ts
resources/js/Components/Business/BusinessTypeEditor.vue
resources/js/Pages/Business/Sources.vue
resources/js/Pages/Workspaces/MetaConnectionPanel.vue
resources/js/Pages/Workspaces/GoogleConnectionPanel.vue
resources/js/Pages/Workspaces/TikTokConnectionPanel.vue
resources/js/Pages/Workspaces/SnapchatConnectionPanel.vue
resources/js/Pages/Workspaces/XConnectionPanel.vue
resources/js/Pages/Workspaces/SourceRegistryPanel.vue
resources/js/Pages/Internal/Administration.vue
resources/js/localization/business.ts
public/images/integrations/google_ads.webp
public/images/integrations/meta_ads.webp
public/images/integrations/salla.webp
public/images/integrations/shopify.webp
public/images/integrations/snapchat_ads.webp
public/images/integrations/tiktok_ads.webp
public/images/integrations/website.webp
public/images/integrations/whatsapp.webp
public/images/integrations/zid.webp
docs/BUSINESS_FOUNDATION_VISUAL_CORRECTION_REPORT.md
docs/BUSINESS_FOUNDATION_IMPLEMENTATION_REPORT.md
```

The existing worktree's earlier approved foundation/admin changes are preserved. Task-only brief, render harnesses, screenshots and read-only authority inspection launcher are ignored verification artifacts.

Fixture identities use the existing canonical seeded `real_estate` and `ecommerce` types for the ordering examples. These are controlled examples, not configuration written to the live database. The shared frontend and API behavior were independently evaluated; screenshots are evidence of rendering rather than evidence of accepted advertiser outcomes.

Final evidence-only rerender also passed 28/28. All task fixture servers and renderer/evaluator browser helpers are stopped; port5189 is closed. The preexisting user-requested real preview on8140 remains listening. No commit, push or merge.

```text
STOP_FOR_OWNER_VISUAL_REVIEW
SOURCES_VISUAL_AND_ASSET_CORRECTION = READY_FOR_OWNER_REVIEW
LEGITIMATE_ADMIN_VISUAL_REVIEW = PENDING_REAL_EMPLOYEE_ELIGIBILITY_AND_EXPLICIT_GRANT
```
