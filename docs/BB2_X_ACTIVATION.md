# X Ads — technical activation handoff

This is an implementation handoff, not legal approval, platform approval or proof of live advertiser connectivity.

## Implemented scope

The responsible Workspace owner starts the official OAuth 1.0a flow with one button, authorizes on X, then explicitly selects an advertiser account. API v12 account discovery remains read-only. Selecting an account checks its native timezone and authenticated permissions; CAMPAIGN_ANALYST is sufficient. Account-admin or campaign-write rights are not required. Existing accounts reconnect into the same source identity. No guessed expiry, refresh token or OAuth2 substitution is used.

Credentials, temporary secrets, native cursors and asynchronous job tickets remain encrypted on the server. Public selection/continuation handles are tied to the current actor, session, Workspace, source epoch and configuration. Explicit source grants use the existing sole grant writer. Discovery never creates account sources or grants. Local disconnect removes local credentials and does not claim to revoke authorization at X.

The advertiser can select campaigns from the account, choose placement, and request campaign TOTAL observations. The short-period synchronous path supports up to seven elapsed days. Historical onboarding uses the native asynchronous job path, up to 90 elapsed days per request. Both currently process a bounded batch of up to 20 selected campaigns. Longer histories or additional campaigns require separate bounded requests. This does not claim coverage of every X API endpoint, segmentation or every reporting granularity.

Asynchronous creation, delayed polling, exact job/report checks, fixed-host gzip download and currency verification run in the encrypted background queue. PENDING contains no observations and no accepted coverage progress. Only a complete validated result enters the observation journal. Native currency is established per campaign/funding instrument; native money remains exact, with no assumed account-wide currency or conversion.

Committed command replay does not repeat native HTTP. A short-lived shared Redis creation fence additionally preserves the native job ticket across SQL rollback. An uncertain native create is never automatically resubmitted. This fence is technical correlation, not an advertiser-data retention policy. Losing or clearing that shared cache while in-flight work exists invalidates this operational guarantee; keep Redis shared and durable during execution. Failed transactions cannot undo an already-created native X report.

## Environment fields

Five empty fields were added to the existing ignored `C:/dev/builderad/.env` used by the local preview. Existing values and database settings were preserved. The same fields are declared in this worktree's `.env.example` and `config/services.php`:

```dotenv
BB2_X_CONSUMER_KEY=
BB2_X_CONSUMER_SECRET=
BB2_X_REDIRECT_URI=
BB2_X_PRIVACY_URL=
BB2_X_NOTICE_REVISION=
```

Use the application's API key and API secret for the first two fields. Do not put advertiser access tokens, account passwords or secrets into the browser/Admin interface. Advertiser token acquisition happens through OAuth.

The callback must be the exact public HTTPS application URL plus `/providers/x/callback`, registered identically in X. `APP_URL` must describe that same public application URL. The privacy field must point to the owner's actual approved public privacy notice. The revision identifies that notice. Merely entering syntactically valid links never approves the privacy/purpose verification axis.

The existing local preview launcher deliberately uses `http://localhost:8128` and verifies `builderad_test`. Therefore its localhost preview is a UI preview; it cannot prove the public HTTPS OAuth callback. Do not change its database to enable OAuth. Public callback testing needs the separately configured public test/deployment environment.

## External / owner / live-test requirements

1. Supply real application keys and exact callback/notice settings; never commit `.env`.
2. Establish the actual operating entity, service countries, approved privacy/use/retention/deletion obligations and the intended analytics use. These facts remain unestablished. No legal basis, retention duration, deletion SLA or residency decision was invented.
3. Obtain and verify X developer-account and Ads API application access. OAuth success alone does not establish Ads API approval or approval of the product's data use.
4. Review the official Developer Agreement and Developer Policy for the approved use. The published agreement includes restrictions on off-X advertising targeting and foundation/frontier-model training, and removal/termination obligations. This code does not implement or approve downstream targeting, exports, model training or a legal deletion workflow. Applicability and owner commitments must be resolved before real data activation; do not substitute the technical connection screen for consent or legal approval.
5. Verify the intended database and owner migration identity, then deploy forward migration `0001_01_01_000260_create_x_read_attempts.php` if pending. It was tested on disposable PostgreSQL only; this task did not migrate/reset/seed the service or preview database.
6. Verify shared Redis and the correctly configured database queue worker for `bb2.provider.x` (timeout 60 seconds). The local worktree has no standalone `.env`; do not run an unconfigured worker against default connection settings. Load the same approved environment and explicitly verify the actual database first. Configure HTTP access/request logging to redact OAuth callback tokens/verifiers; application-level query scrubbing does not control upstream web-server logs.
7. Test real OAuth, account selection, reconnect/local disconnect, a short report, historical async processing, native throttling and permission withdrawal. Confirm independent provider-support/privacy-purpose evidence before enabling report reads. UNKNOWN evidence remains blocked; keys alone do not turn Connected into Ready.

No live advertiser requests, service database writes or GitHub push/merge were performed by this implementation turn. Existing preview processes were not restarted.

## Official references reviewed 2026-10-02

- [Ads API access](https://docs.x.com/x-ads-api/introduction)
- [Authenticated Ads requests](https://docs.x.com/x-ads-api/fundamentals/authentication)
- [Analytics and asynchronous guide](https://docs.x.com/x-ads-api/analytics)
- [Campaign/account reference](https://docs.x.com/x-ads-api/campaign-management/reference)
- [Rate limiting](https://docs.x.com/x-ads-api/fundamentals/rate-limiting)
- [Developer Guidelines](https://docs.x.com/developer-guidelines)
- [Developer Agreement](https://docs.x.com/developer-terms/agreement)
- [Developer Policy](https://docs.x.com/developer-terms/policy)

The implementation evidence ledger records test results and local source snapshots. These references establish source requirements, not acceptance of Builderad by X or the owner.
