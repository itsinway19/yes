# Goals API contract — first migration slice

This is an internal, proprietary contract, not a published API or a mobile authentication implementation.

## Ownership and transport

`AdvertiserGoalPreferences` and `database/sql/advertiser_goal_preferences.sql` remain the canonical reader/normalizer/writer. No business semantics, SQL, migration, RLS rule, authority check or lock order changes in this slice. Applicable executable invariants: INV-002 zero-source operability, INV-004 canonical ownership, INV-005 truthful uncertainty, INV-006 neutral declarations. Goals preferences are not Current Intent or Object Purpose.

The existing `GET/POST /workspaces/{workspace}/goals` routes remain unchanged. JSON GET returns `{value, version}`; HTML GET still renders Inertia. POST requires an exact JSON object containing `value` and a nonnegative integer `version`, maximum 8192 bytes. SQL requires 1–4 unique catalog goal IDs, a selected priority, result IDs belonging to their selected goal, and no extra keys. SQL normalizes order/empty results. An initial GET may return empty goals/null priority; that initial state is not a valid POST.

`/spa/workspaces/{workspace}/goals[/review]` and `/spa/api/workspaces/{workspace}/goals` are opt-in through `GOALS_SPA_ENABLED=true` (default false). The contextual endpoint retains the existing proof's workspace/business/catalog/visibility projection; it does not introduce another writer. `X-Goals-Witness: 1` returns visibility only; `X-Goals-Passive: 1` and witness are passive admission checks. A witness must match the read before presentation. Contextual projection and canonical GET are both described and consumed through generated paths.

All routes enter the current `web` middleware stack. New names deliberately remain under `workspaces.goals.*`, so `EnsureCurrentPrincipal` delegates admission to the real outer `WorkspaceContext` transaction. No default `api.php` stack or parallel authentication system is introduced. Session cookie name is deployment-configurable (default `builderad-session`); document receipts and CSRF remain web transport concerns, not domain schema authority. The web transport also supplies a transition challenge for identity lifecycle endpoints; ordinary Goals admission does not require that extra challenge. The integration test explicitly clears it while preserving the document receipt.

## Responses and uncertainty

| Status | Actual meaning and required behavior |
|---|---|
| 200 | Authorized projection or canonical save acknowledgement. A no-op may preserve version/event count. |
| 401 | No authenticated principal after admissible anonymous document. A stale/missing document can fail earlier with 409. |
| 403 | Workspace/grant/principal eligibility denied. Preserve local draft; do not infer a successful write. |
| 409 | CAS mismatch: explicit review/adopt before retry. `identityConflict=true` instead means close the document. These are different failures. |
| 419 | CSRF rejection. Laravel 13 can accept trusted same-origin Fetch Metadata before token fallback. Without that evidence a valid token is required. |
| 422 | Invalid exact JSON or selection; normal Laravel `{message, errors}` with value errors. |
| 404 | Malformed UUID route, disabled SPA route, or absent public documentation. |

The specification records admitted error shapes; exception/debug fields may accompany generic errors. JSON headers and an admitted document are required. Expired authority may produce identity-conflict 409 before controller/auth, and loss of transport CSRF state may produce 419 before admission. There is no false guarantee that every expiration is 401. No automatic POST retry, optimistic duplicate writer, response field rename or synthetic success adapter exists. Abort only stops client observation; it does not establish server rollback.

## Generation and verification

- Source: `scripts/goals-openapi.mjs` (explicit reviewed description of the hand-validated Laravel/SQL boundary).
- Output: `contracts/goals.openapi.json` and `resources/js/api/generated/goals.d.ts`.
- Generate: `npm run api:generate`; deterministic comparison: `npm run api:check`.
- Client factory: `resources/js/api/goals.ts`, using official `openapi-fetch`; it knows nothing about browser sessions.
- Web binding: `resources/js/goals-spa/api.ts` injects the existing admitted fetch from `transport.ts`.
- Runtime proof: `tests/Support/goals-api-contract.mjs` uses actual Laravel HTTP through the same generated-path client, validates responses with Ajv 2020-12, and checks denied public documentation URLs. This complements type checking; generation alone cannot prove authorization.
- Foundation CI checks drift, full tests, and the unified production build. No CI success is implied until it actually runs on this candidate.

Scramble is not installed: this narrow, manually decoded JSON boundary benefits from an explicit schema plus real response conformance checks, without a second inference pipeline or documentation route. OpenAPI TypeScript and openapi-fetch are MIT; Ajv is MIT and development-only. Existing locked dependency versions are retained; see provenance for the version comparison.

Nothing registers specification/documentation routes; no schema is placed in `public/` or imported as a runtime schema by SPA. Production build omits proof globals. `build:goals-spa:proof` is exclusively the browser test instrumentation build. Native SPA cleanup unified the production build: run `npm run build` once; `build:goals-spa` is an alias. See [current verification policy](../../BUILDERAD_TEST_EXECUTION_RATIONALIZATION.md).

## Next-device boundary (not implemented)

React Native may reuse neutral schemas and typed endpoint meanings. It still needs an explicit device authentication/credential storage/revocation design, principal and Workspace authority admission, device lifecycle and offline/late-result policy, version compatibility, CSRF applicability analysis, and separate real-device evidence. Cookie/document admission from this web client is not automatically native authentication. No OAuth, device refresh-token flow, mobile application or second commercial slice is included.

## Acceptance criteria fixed before runtime verification

Actual typed GET/POST and 401/403/CAS409/419/422 must match the contract; old Inertia routes remain functional; real RLS, transaction-time revocation, lost acknowledgement, session renewal inversion, logout/revocation and late-read isolation must hold. Required Laravel/JS/build/Pint/audit/drift gates must be reported separately. Historical A=PASS must be reverified for imported code; B and C remain BLOCKED until their original native BFCache/device/pre-JavaScript evidence is satisfied or Owner explicitly changes acceptance scope. No cache policy relaxation is authorized.
