# Authentication and public entry SPA — Slice 3

## Scope and acceptance

Worktree `ba-inertia-retirement-001`, branch `codex/ba-inertia-retirement-003`, approved base `d5e24491b4ebfed46e3b09f4c2ad796f29ccd5e8` (PR #38). No replacement branch/worktree, publication, merge or deployment. INV-002/004/005/007 preserve source optionality, server authority and truthful unknown outcomes.

Acceptance requires actual isolated Laravel/PostgreSQL with app/owner/authority roles and RLS, Redis and Chromium, real signed virtual UV assertions and isolated array-mail delivery, remaining internal consumers, generated contracts, focused security tests, architecture guards and both builds. Local journey acceptance does not waive platform/B/C/release gates.

| Display route | Actual replacement | Laravel remains owner of |
| --- | --- | --- |
| `GET /` | Static bilingual native Welcome, no Vue application or private bootstrap | Public entry links |
| `GET /login` | Unified SPA guest entry | LoginRequest, rate limits, IdentityAuthentication, session rotation, intended destination |
| `GET /register` | Unified SPA guest entry | Derived password policy, registerPending transaction, session and verification notification |
| `GET /forgot-password` | Unified SPA guest entry, native POST | Generic response, staff restrictions, Timebox and broker/mail |
| `GET /reset-password/{token}` | Unified SPA guest entry, native POST | Token expiry/single use, password transaction and session revocation |
| `GET /verify-email` | Unified SPA authenticated entry, native resend | Signed verification GET, auth/throttle, lifecycle, rebind/Verified event and staff destination |
| `GET /confirm-password` | Unified SPA authenticated entry, native POST | Web guard, staff rejection, fresh-password authentication and intended return |
| `GET /identity/switch` | Unified SPA authenticated entry, not a guest alias | Existing password/passkey identity transition and server destination |

## Presentation and identity boundaries

One existing SPA/router, generated OpenAPI client and shared transport. Explicit guest bootstrap has principal 0 and no workspace/private user projection. Guest availability expiry does not start authenticated renewal/rebind. Configuration is ephemeral, not Query Cache; route cancellation, serial/ownership checks and a matching server witness fence late reads. Closing clears presentation/query state and secrets.

Password/register/passkey writers retain their original JSON protocol. The existing transport verifies captured nonce, lineage, boundary and next epoch before closing the old document and making the server-selected same-origin native departure; it never adopts another principal into that document. Originally native recovery/resend/confirmation writers remain native forms, capturing existing document evidence and closing before submission. There is no second writer, transport, session store or identity protocol.

Reset tokens stay in the existing URL and short-lived native submission, not HTML bootstrap, query keys, drafts or production telemetry. Sensitive responses use no-store/no-referrer. Isolated test mailbox evidence contains synthetic links, not real customer mail. The shared SpaPage bootstrap is assembled before Blade serialization, fixing the multiline inline-JSON compilation boundary without adding private data.

An early native-login presentation experiment was rejected by real-browser evidence: failed login invalidates its input session. All experimental writer changes were reverted. Original login/register/passkey security transactions and JSON responses remain unchanged; rejected/unknown commands clear secrets and require explicit native recovery instead of automatic replay.

## Narrow N1 unblocking correction

Independent `storage/framework/testing/n1-investigation/REPORT.md`: **BASELINE REPRODUCED — ROOT CAUSE ESTABLISHED**. Its raw evidence is retained. The old baseline and candidate both emitted an unowned bootstrap rejection during a legitimate newer internal device action; this was not established as a Slice 3 regression or security bypass.

Local commit `ba1a6c9` adds only the owning `InitialVisit.handleDefault()` chain catch: expected HttpCancelledError does not enter the preceding success continuation; other errors remain rejected. No changes to NavigationToken, page.set, navigation.wait, commit/settlement or authority semantics. Review patch and overlay hashes were regenerated with the official tool; upstream pins remain unchanged. Production artifact SHA-256: `b01f98460dcb6ea54d57adef37ea98e9d499be581fef1f5da69070dea1bfbef1`.

The regression reaches real page.set/navigation.wait with the initial token preparing, starts a newer request token, proves initial cancellation/no commit/mount/events, completes the newer settlement and releases the old resolver without publication or unhandled errors. Its unexpected-failure case observes the exact rejected owner chain without requiring an unhandledrejection event. `auth-entry-n1-gates.log`: 47 PASS; the refined diagnostic assertion also passed in `auth-entry-n1-initial-final.log` and the final frontend suite.

Resume gate passed before further acceptance: `goals-spa-f03bcb92-a51e-4368-91eb-6a31cf54aee3` / `auth-entry-n1-real-browser.log`, real internal login button and signed passkey, visibility ready, last-factor deletion rejected, factor retained, internal boundary and freshness 403 preserved, zero page errors. 176 forwarded/173 browser asset responses verified; source unchanged; processes stopped.

## Integrated browser evidence

Final post-deletion/build run on executable candidate `b3064dcac3f6bb94014aacc01e97fc1e4f866ad2`: `goals-spa-0a9d7a42-06e0-4d71-aa11-e8f65502eb00` / `auth-entry-final-cutover-browser.log`: **15 PASS, zero unhandled page errors**, 1,550 forwarded/1,505 separately observed browser asset responses verified, source unchanged, wrapper EXIT=0 and processes stopped. This is the authoritative final-assets authentication run. Deletion changed Tailwind/build asset fingerprints, so earlier byte equivalence was not assumed: the full authentication gate was rerun after both production builds and the proof build.

The following earlier combined run established functional cutover before deleting the unused components:

`goals-spa-1ec991c4-8d96-49a5-af40-b2130dc2553c` / `auth-entry-final-integrated-browser.log`: **15 PASS, zero unhandled page errors**, 1,577 forwarded/1,543 browser asset responses verified against the served local files, source unchanged, processes stopped. These are real Laravel/browser journeys, not mocked authority results:

- Native public entry and explicit guest/no-workspace projection, mobile RTL overflow; rejected password clears secrets; successful intended login and A-to-B switch use new documents.
- Real resident UV enrollment and login, consumed document denial, cancellation with zero login POST, actual 61-second expired challenge and malformed signature rejection.
- Committed lost password-login and registration ACKs delivered once; no retry, disabled uncertain form and explicit native recovery to actual server identity.
- Actual registration mail/resend; invalid and independently signed expired verification URLs rejected; valid link activates advertiser lifecycle and rebinds the native document.
- Actual broker mail/reset, expired and reused token rejection, changed password and canonical session revocation. Lost request/reset/resend ACKs commit once and never replay automatically.
- Pending staff uses an actual staff-enrollment password session, signed verification returns to existing Inertia onboarding, dashboard remains 403; email verification does not activate staff.
- Wrong native confirmation clears password and leaves freshness denied; correct confirmation restores the actual fresh-only gate (403 before, 200 after).
- Independent internal passkey/minimum/freshness scenario remains green. Held older real Laravel guest read cannot republish after navigation; local Back/Forward restores the actual preceding recovery route and register route safely.

Development failures are retained, not counted as acceptance: the initial staff fixture changed class after issuing an advertiser session and correctly received 403; it now obtains a real staff-enrollment session. A test initially parsed Inertia 3's script identifier as JSON, then read the actual JSON script. A Back assertion initially assumed login instead of the actual preceding recovery entry. Only fixture/observation expectations changed; no authority assertion or production policy was weakened. The final combined run above passes all corrected cases.

## Legacy closure and verification ledger

All eight display routes leave the old renderer. Deleted seven unused components: Welcome and Auth/{Login,Register,ForgotPassword,ResetPassword,VerifyEmail,ConfirmPassword}. No remaining renderer/import consumes them. Internal/Login, internal identity switch, Internal/Onboarding, Security/Devices and other internal administration remain on their existing owner. Affected locale/logout/history tests now inspect the true SPA or remaining legacy consumer rather than deleted screens.

N1 ownership: A retains real internal history/visibility consumers; B keeps framework-independent identity exclusion, late-response fences and no replay in Laravel/shared SPA; C removes only the seven dead public components. No blanket clearHistory/runtime removal. Actual runtime inventory: **173 routes, 10 remaining legacy render routes, down from 18**. The dependency ceiling ratchets to **63 files**; this is a dependency count, not acceptance by itself. Migration ledger records LOCAL_JOURNEY_ACCEPTED for these actual journeys.

### Final local gates

| Gate | Result / recorded evidence |
| --- | --- |
| SPA foundation | 122 PASS, `auth-entry-final-spa-tests.log` |
| Frontend | 290 PASS, including the final N1 regression, `auth-entry-final-frontend-tests.log` |
| Focused PHP/security/integration | 135 PASS / 1,519 assertions, `auth-entry-final-php.log`; exact JUnit/source manifest in the PHP-only `goals-spa-2b223200-363a-400b-8d82-c8a889fad7a2` run |
| Architecture / authority | Included above: CoreDependencyBoundary 13 + ArchitectureSourceOptionality 1; DatabaseAuthorityBoundary 6, all PASS |
| Generated OpenAPI drift | PASS, `auth-entry-final-api-check.log` |
| Scoped Pint / diff whitespace | PASS, `auth-entry-final-pint.log`; git diff --check |
| TypeScript + legacy production build | PASS, `auth-entry-final-legacy-build.log` |
| TypeScript + SPA production/proof builds | PASS, `auth-entry-final-production-build.log`, `auth-entry-final-proof-build.log` |
| Actual inventory / non-growth guard | 173 routes / 10 legacy display routes / 63 dependency files; `auth-entry-final-inventory.log`, checked and ratcheted by official tools |
| Final authentication browser | 15 PASS in one final-assets run above, source unchanged and EXIT=0 |
| Account/Security browser regression | 18 cases covered as 15 reusable prefix cases + 3 targeted tail cases, detailed below; not claimed as one green combined run |

Account/Security provenance: `goals-spa-2bf78401-1ba3-4271-9ad4-606ac0f3e0e9` passed its first 15 cases, including real virtual enrollment/expiry/ownership, native confirmation, selected/other-session revocation, expiry/revocation closure, actual Laravel CSRF 419, unknown writes/logout, cross-tab switch with late reply exclusion and terminal logout. Case 16's setup then read identity metadata from Welcome, which is intentionally static now. Only that later setup was corrected; the 15 preceding case bodies/application bytes were unchanged. `auth-entry-final-account-prefix-verification.json` independently verifies those 15 recorded passes, zero observed page errors and 25 forwarded unique asset hashes against final files; it explicitly does NOT relabel the failed whole run as PASS.

The remaining email/error/unknown-delivery and internal cases passed in `goals-spa-0c34a156-4d50-4427-92b3-1e05cb2ef71f` / `auth-entry-final-account-tail-browser.log`: 3 PASS, source unchanged, EXIT=0 and processes stopped. This includes canonical email revocation before departure, Back/Forward denial, committed lost native ACK without replay, actual internal passkey login/enrollment, factor minimum and freshness denial. Its inherited account asset collector records zero independent browser asset entries; 326 forwarded asset responses were hash-verified. Do not confuse that with independent browser body hashing. The final authentication harness separately records browser URL/status entries and verifies the actual final assets/internal scenario.

### Operational observations, not hidden passes

- First Account attempt `goals-spa-04ab2418-6278-4b5f-9a70-7c2922a9d913` timed out observing registration inside the unchanged 15-second response budget (the preceding options request alone took about 7.53 seconds). The unchanged rerun passed that case and the next 12 cases. No security code, assertion or timeout was altered. The initial failure is retained; an isolated successful rerun does not establish flakiness or a production performance guarantee.
- PHP assertions all passed, but its wrapper returned EXIT=1 **after** tests because PostgreSQL fast-stop observation exceeded 60 seconds. The server completed its natural shutdown at 16:24:06 +03 on 2026-10-18; the shutdown checkpoint took about 87.5 seconds. `auth-entry-final-php-cleanup.json` records independent confirmation: owned PID file absent, no proof-port listeners, temporary synthetic environment removed only after exact-content matching. No forced kill or timeout change. This is a recorded wrapper cleanup failure, not a claimed green wrapper result or a failed PHP security assertion. Subsequent final browser wrappers exited 0 and stopped normally.

The ten remaining displays are `/internal/{administration,administration/commercial-markets,administration/customers,business-types,commercial,identity/switch,login,security/devices,services}` and `/staff/onboarding`.

## Local revision and provenance

Local commits: `ba1a6c9` (narrow N1 fix and regression), `3b5eb7fc86cc345ce848ac6f63df05efd6def86b` (Slice 3 cutover/contracts/tests/dead-component removal), `b3064dcac3f6bb94014aacc01e97fc1e4f866ad2` (account test setup for static Welcome). The final report is committed separately without executable changes. The final authentication run covers b3064dc with this report pending; its source manifest pins actual file bytes rather than treating an earlier HEAD as proof of dirty content. Final provenance comparison allows only this report to differ. All proof evidence stays under ignored storage/framework/testing; no investigation evidence was deleted.

## Limits and final status

No changes to app/Security, SessionAuthority, IdentityAuthentication, LifecycleAuthority, WorkspaceContext, SQL/RLS, WebAuthn verification, security timeouts, clientVisibility or identityProtocol. Auth controller writer bodies retain the approved base behavior; changes there are display response ownership only, plus formatting. N1 correction is limited to the specified Promise owner; no Token/authority change. No confirmed functional/security defect remains in the exercised scope; the operational observations and evidence limits above are not erased by later passes.

Native BFCache admission, pre-JavaScript cached-frame confidentiality, physical authenticators/iOS/Android, platform B/C, production distributions, deployment/activation and central Foundation are **NOT_EXECUTED/UNVERIFIED**, not PASS. Local Back/Forward and a Virtual Authenticator do not close those gates. No Slice 4 work.

**READY FOR INDEPENDENT REVIEW** for Slice 3's local functional scope, including the explicitly authorized N1 correction. This is not merge, central Foundation, platform or release acceptance. Git is clean after the evidence-only report commit; no Push/PR/Merge/Deployment or Slice 4 work.
