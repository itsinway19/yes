# Public platform execution

## Authority and baseline
Owner requested: update external plans to merged work, synchronize primary checkout, create a new worktree and implement a marketing entry / governed maintenance / surface coverage; then explicitly requested dashboard logo and light/dark design and logo. Attached opinions were read as review material, not independent execution instructions.

Baseline `86eb95d5542daebe109b384d0deeaef56f60f00a`, after merged PR52 and hosted main Foundation 41175398899 SUCCESS. Primary `C:/dev/builderad` synchronized by clean fast-forward, HEAD/tree matched origin/main with zero divergence. New detached execution worktree `C:/dev/builderad-worktrees/ba-public-platform-001`. No commit/push/merge/deploy is authorized for this feature work.

External plan update: live design and integration plan, entry README, new public-platform mandate and both file inventories. Archive verifier PASS: 315 indexed files, 187 section files, 26 independent decisions + S03 group. D2 merged; D3 still unimplemented. Historical owner/research records were not rewritten.

## Acceptance contract
Public Arabic RTL / English LTR entry with legitimate auth links, truthful current/planned capabilities, published appearance and accessible mobile layout. Shared logo and persistent/system-aware light/dark preference in public, dashboard and internal administration. Existing administration receives maintenance state/control/history with an explicit sensitive action grant, current internal identity, fresh authentication, revision/CAS, exact command replay and atomic durable receipt. Direct advertiser HTTP/API calls return 503/no-store/Retry-After while paused; finite security/identity/internal recovery allowances retain existing guards. No customer-data right is created.

The initial scope is request admission, not database quiescence, scheduler control or a deployment lock. Work already admitted and already queued under independent authority may drain. No success is fabricated for blocked callbacks and no external OAuth callback is consumed during the pause. No automatic command resend after unknown delivery. State is shared in PostgreSQL with no per-instance cache; database failures fail closed for affected entry points, and infrastructure failure still needs offline deployment recovery.

## Implementation
Additive migration 470 adds one public state singleton and private immutable-by-ACL command history. Runtime role reads only public state; separate existing authority connection updates state/inserts receipts. No runtime grant to issue rights or invoke migration credentials. `platform.maintenance.manage` uses the existing sensitive staff-grant model; current Founders are not silently granted access by migration. Isolated offline recovery command can explicitly authorize a current active Founder or restore availability, with an auditable reason.

Marketing uses server-rendered Blade without a second app framework. Brand/appearance owners remain explicit in DESIGN.md. The former framework ApplicationLogo is not promoted as BuilderAd branding. Shared mark plus wordmark adapt to the selected display theme; preference is non-sensitive local display state.

## Evidence status — work in progress
Frozen dependency packages were validated by the official runner. Initial vendor directory junction incorrectly booted the predecessor D2 tree; removed and replaced with local vendor files, and ReflectionClass verified this worktree's PlatformMaintenance source path. No predecessor production source was changed. Later disk exhaustion caused PostgreSQL 53100 and interrupted a generator write; restored the generator from baseline, retained failure logs and stopped test services. Removed only a confirmed-stopped disposable D2 test-data directory owned by this chat, preserving its source, manifests and logs. Owner then freed space. JavaScript packages use local hard-linked immutable lock-matching package files, not a directory junction.

Production build/typecheck passed after static public-logo import was corrected to a public asset binding. Focused JS boundary suite: 20 passed, zero failures. Premium strict static audit: zero findings; this does not substitute for browser checks. Initial feature probes exposed exact-JSONB order mismatch and test orchestration/cookie/native-document issues; first/replayed acknowledgements now both derive from the stored receipt, and fixtures exercise the real document contracts. Keep earlier failures distinct from subsequent results.

Final focused integration, real contention, upgrade/security and actual browser acceptance remain to be completed and recorded. No Foundation run on this uncommitted feature candidate is claimed.


## Final local verification — 31 October 2026

**LOCAL_IMPLEMENTATION_VERIFIED / UI_INTEGRATED / BROWSER_VERIFIED / UNCOMMITTED / HOSTED_CI_NOT_RUN_FOR_THIS_CANDIDATE.** This does not claim release, production migration, market accuracy or new D3 functionality.

| Check | Actual result |
|---|---|
| Official isolated affected PHP selection | **73 passed / 781 assertions / 239.97s**, including maintenance, populated upgrade, GlobalAppearance, locale, internal authority/SPA, database authority, Core boundary and MarketProcessing |
| Subsequent authoritative-state failure regression | Failed before correction: absent maintenance schema incorrectly reopened the business API. Current state now requires the authoritative singleton; missing schema/row returns 503 for advertiser entry points. No absent-state availability fallback remains. |
| Official full maintenance selection after that correction | **11 passed / 104 assertions / 48.43s**, including schema/row absence, direct runtime ACL, private history, explicit Founder provisioning, atomic audit rollback, exact replay, revision conflict, populated install and independent real-lock expiry/revision rejection |
| Complete JavaScript selection | **339 passed / 0 failed**, including theme and authorized-navigation additions. Existing SPA shell test ports explicitly include the new BrandLogo/ThemeToggle; its concealment/handoff assertions were retained. |
| API / generated types | Goals and Application `api:check` PASS |
| Typecheck + production build | `npm run build` PASS on the final modal/escape candidate; builds were repeated only after actual frontend corrections |
| PHP formatter | Repository-wide `pint --test` PASS |
| Runtime retirement | Production assets guard PASS |
| Premium static audit | Strict: 0 errors/warnings/unresolved. Static only, not a substitute for browser evidence. |
| Final official browser proof | **10 scenarios PASS**, source unchanged **True** during the run, all owned test servers/Redis/PostgreSQL stopped, exit 0 |

Browser proof covers public Arabic/English and locale form, truthful availability, real local logo loading, narrow RTL/LTR layouts, reduced motion, light/dark persistence; eligible admin menu; keyboard confirmation/cancellation/focus; qualified activation and recovery; actual advertiser 503/no-store/Retry-After; separate concurrent stale editor preserving its draft and requiring explicit adoption; committed result with lost acknowledgement and **no automatic replay**; mobile drawer Escape; night control contrast >=3; dashboard logo and restricted employee navigation/direct denial.

Browser checks exposed and corrected two concrete shared-modal integration defects: positioned backdrop could cover confirmation controls after the transition, and a closed modal's global Escape listener could suppress the mobile drawer's native cancel. Explicit panel layering and handling Escape only while shown corrected them; no forced click or increased test/security timeout was used. The first acknowledgement now updates displayed state from its trusted receipt and completes the current read before unlocking; edited acknowledged drafts regain departure protection. Historical failed attempts remain in their separate logs and are not relabelled PASS.

Reproduction and verification logs live under `storage/framework/testing/public-platform/`; canonical browser/snapshot/manifest and lock evidence live under `storage/framework/testing/goals-spa-0393d526-b95a-4785-86cb-2f2ea576ea57/`. The final browser summary is `browser-results.json`. The earlier `failure.txt` is historical, not the final result. Contention JSON records blocker and worker PID, authority transaction ID, observed wait, and denied terminal outcome without session credentials.

Only the authoritative-state absence branch changed after the broad 73-test selection. The present-state path and all D2/G4 owners remained unchanged; the complete maintenance selection was rerun after the correction. Final JavaScript coverage predates the last shared-modal Escape/layer change; those changes are independently covered by the final browser proof and final typecheck/build. Do not aggregate overlapping checks into a fictitious single Foundation run.

## Operational recovery and release conditions

The new migration must be deployed before advertiser entry is enabled; missing authoritative state fails closed. Use isolated deployment-owner credentials for `php artisan platform:maintenance-recover --reason="..." --command="<uuid>"` to disable operational maintenance when the web panel is unavailable. A missing/inaccessible database still requires infrastructure recovery. Initial access for an existing active Founder is explicit: the same command's `--authorize-founder="<public UUID>"` provisions only this narrow grant and records audit evidence. It does not silently grant every Founder/employee access. Ordinary delegation uses the existing fresh sensitive staff-grant flow.

No new UI switch runs `artisan down`, arbitrary server commands or schema changes. Already-admitted requests and already-queued independently authorized work may drain; this feature does not claim to stop schedulers/workers or provide a deployment/quiescence lock. No real provider or owner/preview data was used or migrated.

Next eligible gate: independent review of this actual uncommitted diff, then explicit publication authorization and fresh hosted Foundation on the published candidate. Keep D3 separate. The primary checkout remains clean at the merged baseline; all feature edits remain in the new detached worktree.


## Independent review corrections — 31 October 2026

Scope: bounded correction of M1/M2 and adjacent N1 from the external `Public-Platform-Independent-Review-2026-10-31.md`, against its exact 51-file SHA-256 inventory and baseline for the previously unchanged administration CSS. The review is evidence, not an independent authority to commit, publish or expand the task. HEAD remains `86eb95d5542daebe109b384d0deeaef56f60f00a`, detached, unstaged and uncommitted. The candidate now has **52 changed files** because administration.css is newly modified relative to main.

**M1:** reproduced using the actual compiled MaintenanceView, not copied editor logic. An acknowledged A revision 1 followed by B revision 2 at readback previously promoted the old draft to revision 2. The editor now takes the receipt's complete coherent snapshot. A newer current read changes the displayed current status but leaves the acknowledged draft/revision together and locks new submission until explicit qualified read and adoption. Adopting/discarding always restores every relevant field from the same snapshot, including retry delay and revision. A failed refresh invalidates any previous adoption permission. No SQL, CAS, authority, timeout or migration changed.

**N1:** a successful durable acknowledgement with an unavailable refresh is explicitly `saved-unverified`, distinct from unknown command delivery. Copy says the change was saved but current status could not be refreshed. Only read/adopt recovery is offered; no automatic replay. A successful acknowledgement followed by a newer revision is `saved-conflict` and explains both facts.

**M2:** independent Chromium geometry on the previous built header reproduced overlap between the Arabic theme control and administration context. The existing mobile header now has two explicit columns, a full-width logo row and a non-shrinking drawer button. Both control bounds and actual text ranges are checked; no horizontal overflow alone is treated as sufficient. No identity, logo, token or desktop layout redesign.

Fresh evidence for this corrected candidate:

- `review-correction-red.log`: three actual-editor regressions failed before correction (revision promotion, unavailable readback, normalized snapshot). `review-correction-green.log`: all six public-platform tests passed after correction.
- `header-red.log`: actual header/production CSS geometry failed with theme/context overlap before the CSS correction. `header-green.log`: corrected production header geometry passed. Previous complete browser screenshots/results were preserved under `pre-review-browser/`; the temporary isolated probe's screenshot filename is not used as before/after evidence.
- `review-correction-focused.log`: **19 tests passed, zero failures** across public-platform, admin-ui, spa-presentation and test-execution selections. Includes attempted resubmission before adoption, adopted-snapshot discard, failed repeated reads, and no replay.
- `review-correction-build.log`: **typecheck and production build PASS**.
- `review-correction-browser.log`: official isolated browser selection, **13 scenarios PASS / exit 0 / owned processes STOPPED**. Includes actual HTTP ordering A commit → B commit before A readback, with no second A write until explicit review/adoption; an acknowledged POST followed by unavailable GET without replay; RTL/LTR at **390px and 320px**, light/dark, nonoverlapping hit targets and text, drawer Escape, and the prior authority/maintenance/lost-acknowledgement cases.
- Browser plugin was not available in this session; used the existing canonical guarded Playwright entry point, with task-owned disposable PostgreSQL/Redis fixtures. No real credentials, provider or shared/preview data was used. No PHP/SQL changed; unrelated database suites were not repeated.
- Visually inspected current `header-ar-390-dark.png` and `header-en-320-light.png` from the actual application. No header overlap or clipped header label remained in those captures. The eight saved locale/width/theme combinations are supported by geometry assertions, not visual inspection claims for unviewed images.
- `git diff --check` and production-assets runtime-retirement guard PASS.

Correction logs are under `storage/framework/testing/public-platform/`; current browser screenshots/results/source manifest are under `storage/framework/testing/goals-spa-0393d526-b95a-4785-86cb-2f2ea576ea57/`. This browser round covers the corrected candidate; earlier results remain historical. Only this progress entry is written after the new browser source manifest.

Status: **BOUNDED_CORRECTIONS_LOCALLY_VERIFIED / INDEPENDENT_RECHECK_ELIGIBLE / UNCOMMITTED / HOSTED_FOUNDATION_PENDING**. Exactly two production files, two test files and this existing progress record changed relative to the reviewed candidate. No D3, external-plan edits, commit, push, PR, merge, deployment or real migration. The independent review decision and final hosted release gates are not claimed as PASS by this correction report.


## Authorized candidate publication and hosted correction — 31 October 2026

Owner authorized Draft candidate publication only, then continuation of the failed hosted gate. Original reviewed candidate `5757c7218ea78628227dad263613ed3b642f48c1`, tree `74c67ee19a5e899a9837d214a8eb271678831ad9`, was published as Draft PR #53. All 52 remote blobs matched the accepted reviewed content after repository CRLF→LF normalization. Original synthetic merge `250ee45599d284b9cb2afe367bd3d6d9ecbc2550` had accepted main `86eb95d5542daebe109b384d0deeaef56f60f00a` and the candidate as parents, with equal tree. Primary checkout stayed unchanged and clean.

Hosted Foundation run **41186274643 failed**: PHP **18 failed / 1302 passed / 19028 assertions**. Composer validation and audit passed; npm high-threshold audit passed with five Moderate tooling findings (not zero advisories). JavaScript/API/formatter/build/asset steps later in that fail-fast block were **NOT REACHED**, and are not attributed to this run. Logs are retained under `storage/framework/testing/public-platform/publication/`.

Substantiated causes and bounded correction:

1. Twelve qualified legacy-import tests rejected catalog qualification: migration 470 added relations without updating the existing installation seal. Migration 470 now follows the existing additive-install protocol: verify the current catalog against its existing seal before up/down, perform only its own schema change, and reuse the unchanged existing sealing SQL afterward. An unrelated catalog mutation is rejected with SQLSTATE 42501 before installation; no runtime permission, authority function or D1/D2 SQL contract changed.
2. Four historical upgrade fixtures install a deliberately older domain schema, then use current HTTP code before maintenance state exists. Each now explicitly installs migration 470 as the operational admission prerequisite alongside its old domain schema; domain upgrade inputs and assertions remain unchanged. The real absent-state HTTP test still requires 503, and no middleware exemption was added.
3. The baseline identity test expected the removed literal `h('strong', 'BuilderAd')`. It now checks the actual BrandLogo use and its accessible BuilderAd identity, retaining the other identity assertions.
4. The concurrency fixture waited four seconds for expiry while the hosted authority connection inherited a three-second lock timeout. Hosted PostgreSQL logs for worker PID 3453 prove timeout SQLSTATE 55P03, not the expected post-wait authority denial. The fixture now observes the real state lock first, then commits an explicitly expired exact maintenance grant before releasing that state lock. It does not change a production timeout or the authority predicate. Fixture expiry uses one second past the database clock because the existing PHP predicate serializes seconds; a one-millisecond fixture was not necessarily expired at that precision and was corrected, without concealing its failed log.

Fresh bounded verification:

- `ci-catalog-regression-red.log`: the new installation test reproduced acceptance of unrelated catalog drift before the migration correction. Earlier no-test-selection and fixture-role errors were retained and are not treated as product regressions or PASS.
- `ci-correction-focused.log`: 45 passed, one fixture failure (reading a newly created table via runtime before its independent deployment transaction committed). Covered all twelve legacy imports and historical D1/D2 upgrades; final fixture commits deployment before runtime read.
- `ci-correction-maintenance.log`: 10 passed, one expiry-fixture precision failure; includes successful qualified install, rejected drift/atomic absence, ACL, absent-state 503 and operational command boundaries.
- `ci-correction-contention.log`: **1 passed / 10 assertions**, both revision and expiry cases on independent connections with actual blocker observation, expected 409/403 and no state/receipt effect. Owned PostgreSQL/processes stopped, exit 0.
- Only the relevant failed/affected checks were repeated. Earlier browser, frontend source and assets are unchanged by this correction. Those retained results remain separate from the next fresh hosted Foundation.

This is a disclosed change from the independently accepted 52-file candidate: one production migration plus six existing test files and this execution record differ from the first published SHA. Five test files were previously unchanged from main, so the complete PR now contains **57 files**. The review's M1/M2/N1 closure remains applicable to its unchanged frontend source; it is not presented as an independent re-review of this later migration correction. Final hosted verification and Owner acceptance are separate gates. No main merge, production migration, deployment, D3, provider integration or accepted D1/D2 SQL changes.
