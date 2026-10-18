# Sources unified SPA: local journey acceptance

## Scope and provenance

- Worktree: `C:\dev\builderad-worktrees\ba-inertia-retirement-001`.
- Baseline: `a94f6f209a11a0b296bfd6d0507ba37055862071`, following contract corrections `0f22557dcd322263dacce2b9d2730292a27421c1`.
- The browser tested the uncommitted candidate, not merely that baseline SHA. Its complete source/asset hash map is `storage/framework/testing/goals-spa-6a9529fb-e663-470b-b182-affc27d12d4d/source-manifest.json`, SHA-256 `0F3942EFB71B8673954663ACCBB8F0C1393A38DBE4DB3C7C82626A5FBE99C188`.
- Final browser run: `storage/framework/testing/sources-final-browser-acceptance.log`; **18/18 PASS**, zero page errors/findings, `source-unchanged.txt=True`, exit 0. This acceptance note is added after that run; it does not change executable files.
- Real Chromium, Laravel kernel, isolated PostgreSQL runtime/RLS roles, dedicated Redis and canonical queued jobs were exercised. Only native provider authorization handoffs and provider HTTP responses are synthetic. Stray external provider requests are forbidden.

## What moved

The canonical `GET /workspaces/{workspace}` now returns the existing unified SPA shell or its authorized JSON resource through `SpaPage`. The existing Vue Router, generated API client, identity-aware transport and Laravel business/security owners remain the only implementation boundaries.

The migrated experience includes:

- Source grant requests, exact registration, scope declaration, rename, revocation and independent audit-delivery recovery.
- Existing workspace service authorization/revocation; no service credentials are exposed or new administration capability introduced.
- Meta, Google, TikTok, Snapchat and X connection status, native OAuth return, discovery, exact account selection and local disconnection.
- Google hierarchy; Google/Snapchat renewal; X campaign discovery/selection.
- All five report requests, authoritative period status, canonical workers and Google metric-selectable CSV download. Six deliveries include the exact-original Google replay after the first real 202 acknowledgement is lost.
- Same-document navigation, Arabic/English shell, Arabic mobile RTL without horizontal overflow, loading, unavailable/error states and opaque callback restoration.

Unknown commands are frozen before delivery and stored under the current account/session in this tab. Remount/reload never dispatches them automatically. Explicit retry sends the original workspace/source/version/command tuple. Report drafts preserve their original generation/deadline and archived unresolved commands; input changes cannot silently replace them. Terminal closure removes the private presentation and in-memory drafts; only unresolved originals remain recoverable by a later authorized document in the same session. A different account/session cannot restore them. Storage failure prevents new delivery.

## Contract and security preservation

- Google `recover_offline_grant=true` travels through the generated client and existing Laravel command. Normal OAuth does not force consent; explicit recovery after a native missing-refresh-token failure uses `prompt=consent`. Both callback journeys passed in Chromium. No Google OAuth server code changed.
- Snapchat actual HTTP 200 has a required receipt. Actual HTTP 502 retains `PROVIDER_ACTION_REQUIRED` and has no receipt; it is thrown unchanged and the UI requests reauthorization, never success.
- Commercial context retains the server's required UUID `market_id` when context exists and the actual empty-context shape. No nullable/default market value was added.
- Real permission/validation/version failures, fallback CSRF 419, expired-session 401, account-switch conflict, denied cross-workspace selection, superseded reads and delayed provider responses were exercised. Same-origin Fetch Metadata acceptance and CSRF fallback behavior were not changed to satisfy a test.
- No changes to `SessionAuthority`, `WorkspaceContext`, SQL/RLS, N1, shared transport, OAuth state validation or provider-secret custody. Callbacks remain native Laravel routes; opaque return fragments reopen the appropriate panel.

The browser exposed a pre-existing Meta reconnect defect: a new configuration Source UUID collided with the immutable native configuration identity. The bounded correction reuses the RLS-visible exact configuration Source under the existing fresh-owner/A003 grants. It re-establishes the existing Source read claim after narrower account reads. A revoked Source is not revived, foreign authority remains denied, and no authority predicate or SQL object changed. Nine affected Meta server tests passed (179 assertions).

## Verification

| Check | Result and evidence |
| --- | --- |
| Final Sources browser journey | 18 PASS; `sources-final-browser-acceptance.log` and the recorded run's `browser-results.json` |
| Canonical report workers | 6 deliveries handled; all five qualified period statuses COMPLETE for synthetic results; real Google CSV response/download |
| SPA foundation/client/retention tests | 112 PASS; `sources-final-spa-tests.log` |
| Existing frontend suite, including actual migrated registry/report components | 288 PASS; `sources-final-frontend-tests.log` |
| Affected PHP projection/service/architecture checks | 55 originally passed; 11 projection checks failed because the new test adapter used nonexistent `AssertableJson::scope`; corrected to `has('data', ...)`, then only those 11 reran and passed, 311 assertions. `sources-php-affected.log`, `sources-php-projection-final.log` |
| Meta reconnect/callback/authority server regression | 9 PASS, 179 assertions; `sources-meta-web-final.log` |
| OpenAPI generation and drift | `api:generate`, `api:check` PASS; the corrected contracts/generated outputs remain unchanged |
| TypeScript and legacy production build | `npm run build` PASS; `sources-final-legacy-build.log` |
| Unified SPA production and proof builds | Both PASS, including `vue-tsc`; `sources-production-spa-build.log`, `sources-proof-spa-build.log` |
| Targeted PHP formatting | All other affected files passed; the one fixture blank-line correction passed separately; `sources-pint-final.log`, `sources-pint-correction.log` |
| Retirement and source hygiene | Ceiling ratcheted, runtime inventory regenerated, protected-file diff empty, `git diff --check` PASS |

Evidence log filenames in this table are relative to `storage/framework/testing/`. Earlier failed browser/fixture attempts remain preserved and are not presented as acceptance. In particular, the earlier all-cases run passed its 17 cases but failed a final test-only assertion incorrectly demanding consent on normal Google OAuth; the final run additionally proves actual explicit recovery instead of changing production behavior.

Reproduction uses the repository toolchain and guarded runner, after building proof assets:

```powershell
./scripts/builderad.ps1 -Action npm -PassThrough @('run','build:goals-spa:proof')
$env:SOURCES_SPA_STAGE='all'
pwsh -NoProfile -File scripts/Test-GoalsSpa.ps1 -Phase Browser -BrowserSelection sources
```

The runner creates a disposable database and synthetic identities, records source/served-asset provenance, and stops its PostgreSQL, Redis, PHP and browser processes in `finally`. Do not run destructive PHP tests against a retained browser fixture database. Test-only report admission uses the existing operator command with explicitly synthetic, exact-scope artifacts; it does not establish real provider support/privacy approval or advertiser outcomes.

## Actual retirement and remaining gates

- Deleted the dead `Pages/Business/Sources.vue`, `Pages/Workspaces/Show.vue` and their eight Source-only registry/scope/provider/report components. The shared pure capability presentation remains consumed; other journeys and their N1 dependencies remain untouched.
- Exactly **one legacy render route** left Inertia: `GET /workspaces/{workspace}`. Provider JSON endpoints and native callbacks were not falsely counted as newly retired render routes.
- Runtime matrix: **173 registered routes, 20 remaining legacy render routes**, down from 21. Six journeys are locally accepted; the dependency ceiling is 80 remaining files. These are separate measures, not equivalent completion counts.
- Ready for independent review of this Sources slice. No known unresolved functional/security defect was observed within the exercised isolated scope.
- Full final Foundation/remote CI, other browser/device families, real mobile background/pre-JavaScript cache behavior, production performance and real provider admission remain unexecuted here. Local journey acceptance is not final platform/release acceptance, deployment readiness or a claim that all Inertia has been retired.
- At this local acceptance checkpoint, no push, merge or deployment was authorized or performed. Subsequent publication authorization and its CI evidence are recorded below.

## Authorized publication: CI portability correction

The owner subsequently authorized branch publication and Draft PR #37, but not merge, deployment or production activation. GitHub confirmed main/base `505da99f1c551f2f6143980ad624d1b591a6824d`; its Foundation run `39042703135` passed. The first candidate run `39106156930` checked the synthetic merge of that base and accepted head `1cf09fbe0447561ff5d9740b77c00b3d5c0ca47e`.

That run passed 111 SPA-foundation tests and failed the X query-parser test before PHP execution: its fallback executable was a Windows-only path (`ENOENT` on Ubuntu). This test was new since the passing base; locked dependencies and the asserted X contract were unchanged. The bounded correction preserves `BUILDERAD_PHP`, uses the existing Windows default on Windows and PATH `php` on other platforms. Actual PHP `parse_str` and every payload assertion remain required; no test is skipped or weakened, and no application/security code changes. Later Foundation results and exact published/checkout SHAs belong to the PR checks, not to the earlier local browser run.

The next run `39106350505` passed all 112 SPA tests and dependency audits, then passed 983 Laravel tests and exposed one missed presentation consumer in `CustomerAdministrationTest`: the restored-owner test still required an Inertia response from the migrated `/workspaces` chooser. Its correction reads the canonical JSON projection and preserves the one-reassessable-workspace assertion, additionally checking the exact Workspace public ID. Session reactivation, denial before explicit reassessment, success afterward and denial after grant revocation remain unchanged. No customer-administration implementation or authorization expectation is modified.
