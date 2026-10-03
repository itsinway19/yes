# Business-aware foundation implementation — 2026-10-03

Baseline: `a63d713234c51932245f71cea23f0e230eeed10e`. Worktree: `codex/admin-experience-foundation-001`. Prior Admin/CSS/localization changes are user-approved, understood and preserved. No commit, push or merge performed.

## Required status matrix

```text
BASELINE_VERIFIED = YES
AUTHORITATIVE_BASELINE = a63d713234c51932245f71cea23f0e230eeed10e
WORKTREE_STATE = DIRTY
WORKTREE_CHANGES_CLASSIFICATION = DIRTY_UNDERSTOOD_NON_CONFLICTING
ARCHITECTURAL_CONTRADICTION_FOUND = NO
BUSINESS_NAME_CANONICAL_OWNER = workspaces.name
BUSINESS_TYPE_CANONICAL_OWNER = workspaces.business_type_id → business_types.id (stable key)
BUSINESS_TYPE_IS_DECLARED_DURABLE_CONTEXT = YES
LEARNING_CAN_SILENTLY_REWRITE_BUSINESS_TYPE = NO
NEW_BUSINESS_PROFILE_TABLE_CREATED = NO
IF_YES_JUSTIFICATION = NOT_APPLICABLE
DUPLICATE_BUSINESS_NAME_TRUTH_CREATED = NO
DUPLICATE_BUSINESS_CONTEXT_AUTHORITY_CREATED = NO
CANONICAL_PROVIDER_REGISTRY = App\Providers\Ads\Provider
CANONICAL_CHANNEL_PRODUCT_MODEL = Existing catalog absent; minimal advisory PromotionProduct google.search
DUPLICATE_PROVIDER_REGISTRY_CREATED = NO
PROVIDER_CHANNEL_SEMANTICS_SEPARATE = YES
WORKSPACE_ISOLATION_PRESERVED = YES
MEMBERSHIP_AUTHORITY_PRESERVED = YES
BUSINESS_TYPE_SERVER_AUTHORIZATION_VALIDATED = YES
BUSINESS_TYPE_PERSISTENCE_INTEGRITY_VALIDATED = YES
BUSINESS_TYPE_REFERENCE_INTEGRITY_VALIDATED = YES
BUSINESS_TYPE_DEACTIVATION_VALIDATED = YES
BUSINESS_TYPE_STABLE_IDENTITY_VALIDATED = YES
EXISTING_USER_TRANSITION_VALIDATED = YES
FAKE_DEFAULT_BUSINESS_TYPE_CREATED = NO
BUSINESS_TYPE_HAS_REAL_PRODUCT_EFFECT = YES_CONFIGURED_SOURCE_ORDER
BUSINESS_TYPE_EFFECT_IS_MEANINGFUL = YES_CONTROLLED_REAL_ESTATE_ECOMMERCE_PROOF
BUSINESS_TYPE_EFFECT_CONFIG_DRIVEN = YES
SOURCE_GUIDANCE_USES_CANONICAL_PROVIDERS = YES
PROMOTION_PRIOR_PROVIDER_CHANNEL_MODEL_VALID = YES
PRIOR_BASIS_SEPARATE_FROM_APPROVAL = YES
PRIOR_APPROVAL_SEPARATE_FROM_ACTIVATION = YES
UNAPPROVED_PRIOR_VISIBLE_TO_ADVERTISER = NO
INACTIVE_PRIOR_VISIBLE_TO_ADVERTISER = NO
ADMIN_HYPOTHESIS_REQUIRES_DOCUMENTED_BASIS = YES
ADMIN_HYPOTHESIS_REQUIRES_APPLICABILITY_CONDITIONS = YES
ADMIN_HYPOTHESIS_REQUIRES_LIMITS = YES
SOURCE_GUIDANCE_SEPARATE_FROM_PROMOTION_GUIDANCE = YES
QUALIFIED_EVIDENCE_PRECEDENCE_PRESERVED = YES_BOUNDARY_PRESERVED; QUALIFIED_OUTCOME_ENGINE_NOT_IMPLEMENTED
MATERIAL_PRIORS_EVIDENCE_BOUNDED = YES_CONDITIONAL_BASIS_APPLICABILITY_LIMITS; NOT_VERIFIED_PERFORMANCE
UNSUPPORTED_PRIORS_INVENTED = NO
FIRST_ENTRY_MODEL_IMPLEMENTED = YES
BUSINESS_CONTEXT_GATE_SECURITY_EXCEPTIONS_VALID = YES
BUSINESS_CONTEXT_MUTATION_AUTHORITY_VALID = YES_MEMBERSHIP_PLUS_EXPLICIT_GRANT
INTENDED_DESTINATION_PRESERVED = YES_SCOPED_LOCAL_URL
ROUTING_PRECEDENCE_DETERMINISTIC = YES
REDIRECT_LOOP_TESTED = YES_ROUTING_CASES
RETURNING_ENTRY_MODEL_IMPLEMENTED = YES
PARTIAL_RETURN_RESUMES_NATURALLY = YES
OAUTH_CONTEXT_RESTORATION_VALIDATED = YES_LOCAL_AUTHORIZED_CALLBACK_TESTS; NO_LIVE_PROVIDER_EXCHANGE
MULTI_BUSINESS_CONTEXT_ISOLATION_VALIDATED = YES
ADVERTISER_APP_SHELL_IMPLEMENTED = YES
DESKTOP_VALIDATED = YES_1440_AND_768
MOBILE_WEB_VALIDATED = YES_390
ARABIC_RTL_VALIDATED = YES
FAKE_LEARNING_CREATED = NO
TODAY_IMPLEMENTED = NO
GOAL_IMPLEMENTED = NO
```

## Verification status

Update during Owner visual correction: a clean final complete relevant run now passed **109/109, 1177 assertions, no warnings**, recorded in `BUSINESS_FOUNDATION_VISUAL_CORRECTION_REPORT.md`. Prior mixed-run evidence below is retained as history. Real-database activation and loopback smoke tests completed; legitimate internal visual access remains pending because no eligible employee currently exists.

## Authoritative findings and integration map

1. Existing identity, lifecycle, grants, signed WorkspaceContext, PostgreSQL RLS and provider custody remain the foundations. No accepted advertising-outcome learning engine exists in the inspected boundaries.
2. Integration: first-entry controller → canonical workspace creator → signed workspace selector → business context SQL writer. Returning entry → UUID-scoped context → source page → shared type guidance and approved priors. Internal editor → explicit staff grant → signed configuration writer.
3. Business Name remains `workspaces.name`; no duplicate name field or profile is introduced.
4. Business Type assignment is `workspaces.business_type_id`; shared stable identities are `business_types.id/key`. Assignment is declared durable context, never silently inferred or rewritten by learning.
5. Nullable assignment preserves legacy data. FK RESTRICT, monotonic context version and immutable context events preserve references/history. Three configuration relations separate types, source guidance and promotion priors. No business_profiles relation.
6. Small taxonomy: real estate, ecommerce, healthcare, hospitality, professional services and other. Six bilingual selectable identities; no automatic assignment, recommendation or prior seeded.
7. Context mutation requires the existing current lifecycle/session, active workspace membership and exact `business.context.manage` grant. It is not owner-only. Existing responsible Owners may explicitly request the narrow grant using fresh existing authority; revoked/expired grants are never silently revived. Internal configuration requires explicit platform `business.manage`, current eligible personnel and fresh internal passkey session. Existing staff are not automatically elevated.
8. Route Workspace UUID is authoritative. No session-selected workspace resolver is added; cross-workspace and membership checks remain in WorkspaceContext and SQL.
9. Reads occur under signed workspace context. Advertiser guidance excludes private documented basis/internal rationale columns; internal authorized configuration reads use the existing authority connection.
10. Writes use security-definer SQL functions, signed principal evidence, current grant checks, row locks and optimistic versions. Runtime raw table writes remain prohibited. Internal configuration owns the runtime transaction; middleware does not hold a conflicting authority-session lock across that writer. SQL checks authority again after the mutation.
11. Deactivation prevents new assignment, retains existing references and hides guidance/priors for inactive types. Stable type keys cannot change. Assigned inactive context remains visible/editable without pretending it is active.
12. Existing accounts/workspaces/sources are retained. Missing context routes to a short completion step. Users lacking the exact mutation grant receive a waiting/actionable state instead of an unauthorized form.
13. Canonical providers remain `App\Providers\Ads\Provider`: meta, google, snapchat, tiktok, x. Source connection/capability status stays in existing provider components and source registry.
14. No canonical channel/product catalog was found in the inspected provider boundaries. Minimal advisory product `google.search` belongs to provider `google`; it does not claim operational campaign-channel detection. No generalized channel ontology.
15. Source guidance is configured per type/provider: priority, bilingual rationale, activation. All five providers stay accessible; absent configuration has no invented rationale. Stable priority/provider sorting determines ties.
16. Promotion priors are separate type/provider/product records. They display only when type active, prior approved and active, and product belongs to provider. Public display includes applicability and limits.
17. Basis classification is distinct from approval and activation. Documented basis, applicability, limits and rationales are required. Semantic provider/product/basis/evidence/applicability/limits/rationale changes invalidate approval; whitespace formatting and activation-only changes preserve it. A semantic edit requires a subsequent approval decision.
18. Priors remain conditional cold-start considerations, never accepted performance evidence or instructions to spend. No learning loop or qualified-evidence selector is fabricated. Future qualified outcome integration remains deferred; current copy must not claim automatic learning.
19. Verified first entry goes to minimal name/type creation. Stable generated creation key supplies slug/idempotency without asking a third onboarding field. Canonical creation and classification are atomic.
20. Context gate protects the ordinary workspace source page. Security/profile/logout, provider operational routes and foundation remain usable. Authorization precedes context routing.
21. Intended destination is preserved only for a local URL bound to the exact authorized Workspace UUID; invalid/external destinations are rejected. Explicit selection, missing context and source entry use deterministic routing.
22. Provider callback authorization remains protected by existing provider state and WorkspaceContext. Successful local callback redirect receives a short-lived, exact-workspace, one-use restoration marker. Tests prove scope/expiry behavior; no real provider OAuth exchange is claimed.
23. One existing business resumes directly; multiple businesses show explicit selection. Missing context resumes completion while preserving existing identity and safe destination. Explicit `?new=1` starts another business.
24. Admin adds a conditional Business Types navigation entry and editor for types, source ordering and promotion priors. Existing Admin redesign stays preserved. A staff role or `staff.view` alone grants no mutation authority; live staff need an explicit grant through the existing grant workflow.
25. Controlled database tests configure Google first for Real Estate and Meta first for Ecommerce, proving actual configuration-driven product effects without type-specific code or fabricated production evidence.
26. Advertiser shell and forms support Arabic RTL and English across 1440/768/390 widths. Existing provider connection panels are reused; their inherited Arabic copy in English mode is a known limitation. Independent visual evaluation returned PASS. Fixtures are visual evidence, not real authenticated sessions.
27. BusinessFoundationTest covers atomic creation, persistence, grants including authorized nonowners, inactive references, history, raw-write/private-column denial, configuration differences, prior materiality, safe redirects and callback restoration. Existing workspace, source, internal authority, callback, auth and isolation tests are included in integration verification.
28. Deferred: Today/Goal, automatic learning, accepted outcome evidence, campaign allocation, generalized channels, operational Google Search detection and unsupported priors. No fake recommendations, users or injected login sessions are created for the real preview.
29. Screenshots: `storage/framework/testing/business-foundation/screenshots/` contains bilingual context/sources/admin/prior desktop/mobile/tablet images plus legacy, waiting, empty and readonly states. `checks.json`, `checks-tablet.json`, `checks-source-contract.json` record responsive/console checks.

## Exact verification and preview

TESTS:

- `pwsh -NoProfile -File scripts/Test-BusinessFoundation.ps1 -Filter 'BusinessFoundationTest|WorkspaceCreationTest|InternalAuthorityTest|ProviderCallbackIngressTest|SourceRegistryTest|WorkspaceIsolationTest|AuthenticationTest|RegistrationTest|EmailVerificationTest'`: latest complete integration run: 109 tests, 107 passed, 2 failed, 1147 assertions, 358.92 seconds, no warnings. Failures were cross-guard error assertion and slow lock-wait worker deadline. Logs: `storage/framework/testing/business-db-066ec5c0-24f6-4d01-86ab-257b43eeba51/`.
- `pwsh -NoProfile -File scripts/Test-BusinessFoundation.ps1 -Filter 'BusinessFoundationTest|recovery_revocation_while_waiting'`: 15 tests, 14 passed, 1 failed, 148 assertions, 82.15 seconds. Recovery revocation now passed. Remaining JSON validation test lacked authenticated cookies. Logs: `storage/framework/testing/business-db-f3984984-c36c-4607-b19b-209002039228/`.
- `pwsh -NoProfile -File scripts/Test-BusinessFoundation.ps1 -Filter 'approval_activation_materiality'`: **1 passed, 29 assertions, 8.35 seconds**, no warnings, exit 0. Includes provider/product rejection, unapproved/inactive visibility, cosmetic approval preservation and semantic invalidation. Logs: `storage/framework/testing/business-db-da360ee0-7af4-4351-bb89-ac7b5c8273af/`.
- Earlier complete integration had 6 failures/103 missing-local-environment warnings. The internal configuration lock conflict was fixed by aligning transaction ownership; race harness deadlines were expanded while retaining mandatory observed overlapping locks. An empty ignored environment stub avoids Dotenv file warnings without storing credentials. Failed logs are retained, not presented as passing runs.
- `pwsh -NoProfile -File scripts/builderad.ps1 npm run test:localization`: **6 passed**, no failures.
- `git diff --check`: exit 0; existing CRLF normalization notices only. PHP syntax checks passed.
- Visual checks: 17 primary states, 11 tablet/additional states, 4 source-contract states; independent visual evaluation PASS. These fixture checks do not claim a real authenticated or provider OAuth session.

BUILD = `pwsh -NoProfile -File scripts/builderad.ps1 build`: exit 0; `vue-tsc` passed, Vite 8.3.0 built 758 modules in 31.89 seconds. `public/build/manifest.json` generated; no stale Vite hot file.

MIGRATIONS = disposable migrations succeeded. `C:/tools/php85/php.exe storage/framework/testing/business-foundation/preview.php --migrate`: exit 0; only `0001_01_01_000270_create_business_context` applied atomically to the approved real preview database. Existing user/workspace/source counts preserved. Inspection after activation: 28 migrations, 8 users, 4 workspaces, new migration present.

PREVIEW_DATABASE = `builderad_test`, `127.0.0.1:5432`; existing app/authority/owner roles verified by actual PDO identity before and after activation. Before activation: 27 migrations, 8 users, 4 workspaces. No reset, backfill, account replacement or seeded promotion prior.

PREVIEW = **http://localhost:8140/login**; internal login: **http://localhost:8140/internal/login**. Legitimate advertiser and internal authentication only. Actual live `business.manage` grants: **0**; an eligible account needs an explicit grant through existing authority before opening `/internal/business-types`. No credential or account authority is synthesized. OAuth public-origin/provider exchange is not verified by localhost preview.

Live smoke results: `/login` 200; `/internal/login` 200; unauthenticated `/dashboard` 302 → advertiser login; `/internal/business-types` 302 → internal login. The preview was requested to open in Codex; the UI returned queued. Hidden preview parent PID 1944, listening only on 127.0.0.1:8140. `storage/framework/testing/business-foundation/preview-process.json` records the task process. Preview remains running for the user's review; disposable test PostgreSQL processes are stopped and data cleaned, logs preserved.

Restart command from this checkout: `C:/tools/php85/php.exe storage/framework/testing/business-foundation/preview.php`. Protected existing credentials load in memory from the accepted local environment; they are not stored in preview artifacts or printed.

Logical limitations: a configured recommendation is not performance evidence; real database starts without invented guidance. Prior semantic whitespace normalization treats formatting as cosmetic. Operational Google channel capability and qualified-outcome precedence require future accepted evidence integration.

## Changed files

```text
app/Http/Controllers/WorkspaceController.php
app/Http/Middleware/EnsureCurrentPrincipal.php
app/Http/Middleware/HandleInertiaRequests.php
app/Security/GrantAuthority.php
resources/css/app.css
resources/js/Layouts/InternalLayout.vue
resources/js/Pages/Internal/Administration.vue
resources/js/localization/messages.ts
resources/js/types/index.d.ts
routes/web.php
tests/Feature/Security/WorkspaceCreationTest.php
tests/Feature/Sources/SourceRegistryTest.php
tests/localization.test.mjs
app/Business/BusinessContext.php
app/Business/PromotionProduct.php
app/Http/Controllers/BusinessContextController.php
app/Http/Controllers/InternalBusinessTypeController.php
app/Http/Middleware/EnsureBusinessContext.php
app/Http/Middleware/RestoreBusinessAfterCallback.php
database/migrations/0001_01_01_000270_create_business_context.php
database/sql/business_context.sql
docs/BUSINESS_FOUNDATION_CURRENT_STATE.md
docs/BUSINESS_FOUNDATION_IMPLEMENTATION_REPORT.md
resources/js/Components/Business/BusinessTypeEditor.vue
resources/js/Layouts/AdvertiserLayout.vue
resources/js/Pages/Business/Context.vue
resources/js/Pages/Business/Index.vue
resources/js/Pages/Business/Sources.vue
resources/js/Pages/Internal/BusinessTypes.vue
resources/js/localization/business.ts
scripts/Test-BusinessFoundation.ps1
tests/Feature/BusinessFoundationTest.php
```

Preserved earlier work: `resources/css/app.css`, `resources/js/Pages/Internal/Administration.vue`, `tests/localization.test.mjs`; earlier changes in `InternalLayout.vue` and `messages.ts` remain, with only the business navigation/labels added. Generated preview/build/screenshot artifacts and the empty local environment stub are ignored, contain no stored credentials, and are not source commits.

```text
STOP
ADVERTISER_BUSINESS_AWARE_FOUNDATION_FIRST_VERTICAL = READY_FOR_OWNER_REVIEW
```
