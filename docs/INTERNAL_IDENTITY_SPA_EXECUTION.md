# Internal identity and staff onboarding SPA — Slice 4

## Candidate and acceptance boundary

- Worktree: `C:/dev/builderad-worktrees/ba-inertia-retirement-004`; branch: `codex/ba-inertia-retirement-004`.
- Approved base: `92d50cdcc41d6167608ac675c1968420ed8929ca`. The existing branch, checkout and local main were clean at this base; origin/main was independently checked at task start and matched. No reset, replacement branch or worktree was used.
- Implementation commit: `5085f31fd5482cf30daed003ffb4c3918bb3faef`. The following documentation/inventory-only commit completes the delivery; its SHA is available from `git log -1 --format=%H -- docs/INTERNAL_IDENTITY_SPA_EXECUTION.md`.
- Slice 3's checkout remains preserved at `64027a72af0b4d5161ec8239ec206d5fe44df6c3`, with clean status when checked after implementation.
- Acceptance criteria established before implementation: actual four-journey parity, Laravel authority/guard isolation, real signed UV ceremonies, pending eligibility denial, protected factors/freshness, revocation, cancellation/uncertain outcomes/late replies, affected shared regressions, zero unhandled page errors, contracts/architecture/TypeScript/builds and actual runtime inventory. No physical-device, native BFCache, B/C or release acceptance is inferred.

## Four actual display cutovers

| Route | Result |
| --- | --- |
| `GET /internal/login` | Same unified AuthEntry view/router; explicit internal guest document, passkey-only login, no advertiser password/registration form. Laravel verifies the signed assertion and chooses the native destination. |
| `GET /internal/identity/switch` | Authenticated internal entry uses the existing identity/passkey options and writer, not initial guest login. Real A→B→A switching replaces the document; old resource publication is rejected. |
| `GET /internal/security/devices` | Existing Account view and generated client use internal device/session paths. Actual registration, deletion, protected-factor rejection, selected-session revocation, expiry and logout retain their existing owners. No internal password editor is introduced. |
| `GET /staff/onboarding` | Typed enrollment projection, signed-email return and real passkey registration in the unified SPA. Pending staff keep the existing web `staff_enrollment` session and advertiser document boundary; enrollment never promotes it to an internal session. |

The three former renderers `Pages/Internal/Login.vue`, `Pages/Internal/Onboarding.vue` and `Pages/Security/Devices.vue` were deleted only after production consumers were shown to be absent and real internal journeys passed. Commercial/administrative displays remain outside this slice.

## Authority and HTTP ownership

Laravel retains IdentityAuthentication, SessionAuthority, LifecycleAuthority, StaffAuthority, AuthorityVerifyPasskey, WebAuthn verification, guard selection, signed callbacks, transactions, eligibility, factor minima/independence, freshness, canonical deadlines, revocation and audit. No authority, SQL, RLS, guard configuration, route middleware, timeout or N1 implementation file changed. INV-002/004/005/007 retain their existing semantic owners and declared coverage; this migration does not introduce new business capabilities.

Server changes are limited to three display controllers returning SpaPage/AuthEntryPage, the shared shell deriving presentation state from existing identity metadata and a route-only enrollment flag, and internal JSON acknowledgements for existing device writers. Those commands still call the same transactions and return native redirects for non-JSON consumers. Actual Laravel tests verify the JSON shapes, current/foreign-session rejection, web/internal revocation separation, audit and retained native redirect.

SelectSessionContext already chooses the correct request guard; inspection did not establish a default-guard authority defect. Staff Pending needs neither a third guard nor a new identity boundary. HTML bootstrap contains identity evidence/presentation flags, not private device/enrollment projections or passkey secrets. The browser never adopts an authentication principal from a JSON result: the existing captured lineage/boundary/epoch/nonce checks close the old document before native navigation. Renewal/logout use the existing endpoints for the signed document boundary.

Employee verification and enrollment remain insufficient for activation. The browser proof invokes the actual existing administrative attest/activate HTTP commands and observes lifecycle change and old enrollment-session denial. Ordinary employee activation of a founder remains forbidden even after two real virtual UV registrations. The active founder fixture has explicitly synthetic operator attestation; neither virtual key count nor that fixture proves physical factor independence. Existing founder-bootstrap/authority PHP tests remain part of the focused gate.

## Local evidence and provenance

All run paths below are under this worktree's ignored `storage/framework/testing/`; logs are retained in `storage/logs/`. PostgreSQL 18 disposable databases, the real application/owner/authority roles, forced RLS and dedicated Redis are guarded by `scripts/Test-GoalsSpa.ps1`. No real advertising accounts, mail delivery or owner data were used.

| Gate | Actual result and retained evidence |
| --- | --- |
| Final production internal browser | **9 PASS, 0 unhandled page errors**: `goals-spa-c3470a12-551d-4f91-b01f-2b5266bff798`. Actual production SPA, signed virtual UV login/enrollment, A→B→A, late read, Back/Forward safe closure/restoration, cancellation, both staff kinds, factor/freshness denial, uncertain registration/revocation without replay, CSRF fallback 419, expiry and native logout. |
| Shared Account browser | **18 covered cases**: 17 passed in `goals-spa-93b6ebfc-28fa-47e7-a197-c3bdc992187c`; only its migrated internal selector failed. Corrected internal case passed separately in `goals-spa-3214671d-3a3f-4abd-8fe4-730a01307e10`. No combined green run is claimed. The retained 17 had zero page errors and 889 forwarded asset hashes independently rechecked in `slice4-account-retained-evidence-check.log`. |
| Shared Auth browser | **15 PASS**, zero page errors, `goals-spa-b319cfaa-3f1f-4904-8f7c-a038af3750d8`. After the passive-loader correction, all **4 affected completion/restoration cases PASS** in `goals-spa-dec2e2e2-8e15-443b-836c-903e897ed873`; unaffected journeys were not redundantly repeated. |
| Focused PHP/security | **118 distinct checks covered successfully**: initial 45/606 assertions; 69 unchanged checks from the subsequent 72-case gate; final 4/74 assertions in InternalIdentitySpaTest supersede its earlier three cases. The initial 72-case run had one incorrect 401 expectation, not a green overall result. Corrected cross-boundary response is exactly 409/identityConflict. All JUnit attempts are retained in the PHP-only `goals-spa-48c758e2-2d3a-42bb-a285-59699b04c33f` run. |
| Architectural boundaries | Within the preceding focused gate: **13 CoreDependencyBoundary checks + 1 ArchitectureSourceOptionality check PASS**, plus 6 DatabaseAuthorityBoundary checks. Existing remaining Administration owns actual N1 visibility probes/no-idle-touch/foreign-context rejection and encrypted history tests. |
| SPA | Final **126 PASS**: `slice4-spa-final-suite.log`; includes internal request verbs, captured nonce/destination/renewal/logout, passive restoration and cancellation/obsolete-response protections. |
| Frontend | **290 PASS**: `slice4-frontend-suite.log`. The only changed consumer-discovery test after legacy deletion was independently rerun: **6 localization checks PASS**, `slice4-localization-cutover.log`. Unchanged frontend evidence was reused. |
| Contracts/style/types/builds | OpenAPI/types regeneration and final api:check PASS; Pint changed/new PHP checks PASS; vue-tsc PASS through official builds. Legacy build: `slice4-cutover-legacy-build.log`; final production SPA: `slice4-production-final-build.log`; proof build: `slice4-passive-proof-build.log`. No dependency lockfile changed. |
| Inventory | Runtime generation and `--runtime --check` PASS; no-growth guard and its 4 tests PASS. Actual counts: **173 registered routes, 6 Inertia display routes, 57 dependency files**. |

Final production proof records `source-unchanged.txt = True`, 1,161 forwarded asset responses verified against local bytes and 991 browser-observed asset responses. `slice4-final-source-equivalence.log` verifies **803 runtime/test/artifact entries** against that source manifest immediately before the implementation commit; only documentation and the decreased dependency ceiling differ afterward. The recorded browser HEAD is the approved base with explicitly hashed working-tree implementation bytes, not a claim that the clean base contained this implementation. The implementation commit preserves those tested executable bytes. Proof and initial production artifacts were retained separately in `slice4-proof-artifacts`, `slice4-passive-proof-artifacts` and `slice4-production-initial-artifacts` before rebuilding.

Final SHA-256 manifests:

- Legacy `public/build/manifest.json`: `515E1869829087296A638FE45687A45B984714B49BC9B8A775C8989A68B4B963`.
- Production `public/build/goals-spa/manifest.json`: `D016B009F121ECBC1F0942044E903FB442CC8F7DC15AE1A1F83E073476B18D40`.

Arabic RTL mobile layout had zero horizontal overflow at 390×844; internal login/devices and employee/founder enrollment screenshots are retained. The devices mobile and founder enrollment captures were visually inspected. This is local Chromium evidence, not real-device acceptance.

## Findings and corrections

- A focused regression reproduced both AuthEntry and new enrollment loaders discarding the existing passive-restoration argument. The smallest correction forwards `X-Builderad-Passive` on restoration reads only. Unit tests first failed on the missing header and then passed; actual Laravel tests and both enrollment browser cases confirm unchanged `idle_expires_at`/`last_seen_at`. No SessionAuthority or security deadline contract was changed. Foreground-event injection is explicitly synthetic lifecycle orchestration, not native BFCache proof.
- Harness-only failures were localized to legacy selectors/default input attributes, waiting on old ready DOM before navigation/read completion, reusing an excluded virtual authenticator, exact elapsed-time timer equality, and assuming invalid CSRF alone overrides Laravel's valid same-origin branch. They were corrected without weakening server assertions or changing security code. Real 419 is tested through the established fallback request shape without Sec-Fetch-Site and without valid CSRF.
- No other confirmed product/authority defect or unresolved within-slice blocker was found. Earlier failed attempts and their logs remain retained; they are not represented as passing gates.

## Actual remaining legacy displays

1. `/internal/administration`
2. `/internal/administration/commercial-markets`
3. `/internal/administration/customers`
4. `/internal/business-types`
5. `/internal/commercial`
6. `/internal/services`

`contracts/spa-migration.json`, the dependency ceiling, generated inventory and migration matrix reflect the actual four render removals: **10→6**, not an administrative target adjustment. Native POST commands, signed callbacks and legitimate redirects are not counted as legacy display routes. N1 runtime, history clearing and remaining consumers are retained.

## Closure and unexecuted gates

Implementation and inventory/report are separate local commits. The documentation commit changes no executable file. Git status is checked clean after these commits; every task-started PostgreSQL/Redis/PHP/browser/proxy process was stopped by its runner, and proof service ports were verified not listening. No old worktree was modified, and no Push, PR, Merge, Deployment or Slice 5 was performed.

Central Foundation CI on a published merge candidate is **NOT EXECUTED**: publication is outside this authorization. Local focused acceptance does not substitute for that later independent CI/release gate. Physical factors/devices, iOS/Android, native BFCache and pre-JavaScript confidentiality, B/C, production performance/distribution and deployment remain **UNVERIFIED / NOT EXECUTED**, not PASS or waived. No SSE behavior was changed or accepted by this slice.

**READY FOR INDEPENDENT REVIEW**
