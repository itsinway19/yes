# Founder direct Commercial operation — Owner decision, 2026-10-03

## Approved policy

The Owner explicitly superseded the earlier Founder-preparation-only policy: an active, qualified Founder operates Commercial directly, without self-grants or another employee. Employee delegation is optional and separate. The old preparation/administrator/operator workflow remains available as an optional workflow; its historical evidence remains immutable.

## Result

- The direct creation form requires a market name only. A human note is optional. Canonical UUIDv7 target and command identities are issued by the server and retained for retries.
- Direct creation uses the existing SA-01 market writer, command fingerprint, receipt, audit obligation, signed context, RLS and effect-time locks. The human creation note is immutable and commits atomically with the command.
- Founder authority is an explicit SQL policy limited to known internal Commercial effects and their valid tuple shapes. It does not create self-grants, infer employee authority, grant advertiser access, change Business prerequisites, or weaken current/fresh/passkey checks.
- Founder projections expose server-generated reference/scope choices. A new reference remains stable in the session until consumed. Clearing a reference selection preserves its available option and blocks submission until reselected.
- Existing-market employee delegation offers read-only or configuration/availability management for one selected market, with an explicit reason and expiry. It excludes other markets, staff permission management and new reference recording. Matching retries reuse existing grants; changed terms return 409 rather than silently retaining a different expiry.
- Expired recent authentication explains why execution is disabled. The renewal action signs out through the existing endpoint and preserves only the market name/note, scoped to the same user in the same tab. No UUID, intent, permission or session authority is restored from a draft.

## Validation and limits

- `node --test tests/commercial-operator.test.mjs tests/localization.test.mjs`: **40 passed, 0 failed**, 2762.7928 ms. Includes name-only creation, uncertain request retention, independent optional delegation, reference clear/reselect, and human draft restoration after renewal.
- `SA01Proof.ps1 -TestOwnedMigration -Browser -Filter CommercialMarketPreparationTest`: **6 passed, 137 assertions**, 38.43 s. Covers Founder market/reference/configuration/assignability/beneficiary registration/receipt/context operations without Commercial grants; exact retries and conflicts; employee scope isolation; changed delegation bounds; revocation; forged targets; rollback and concurrent consumption.
- A preceding broader run passed **46 tests** across CommercialScope, InternalAuthority, OwnerGrantReassessment and preparation. Its one failure was the new test reading a `stdClass` fixture as an array, corrected and rerun in the six-test suite. That broader command is recorded as **1 failed, 46 passed**, not claimed as a fully green command. Existing SA-01 contention/expiry/RLS/raw-write proofs passed in that run.
- `npm run build` including type checking: **PASS**, final Vite build 20.88 s.
- PHP syntax and `git diff --check`: **PASS**.
- Independent focused review found two important correctness issues (delegation terms and reference selection). Both were fixed; the reviewer confirmed their resolution and found no remaining important issues within this scope. This is not a repository-wide security certification.
- Browser: real click created a synthetic market by name alone on the isolated server, showed success and made the market visible in Commercial. No real market or employee grant was created by the agent in the Owner preview.
- Actual Owner preview was inspected: valid name/note, disabled submit, `freshAuthentication=false`. The final renewal explanation/action is visible and the Owner's typed values were restored after refreshing the final bundle. The Owner must perform the real passkey ceremony.
- Full repository regression, N1 independent-installation durability rerun and an exhaustive new Founder expiry-under-contention matrix were not performed. Synthetic browser/test sessions use real SessionAuthority but are not a real-device WebAuthn proof.

## Environment separation and 500 repair

- Owner preview: `http://localhost:8140`, existing `builderad_test` database on **5432**.
- Isolated proof: `http://127.0.0.1:8765`, disposable database on **5433**.
- The Owner preview's 500 was localized to missing `builderad_sa01_founder_workspaces()`. Only migration `0001_01_01_000290_founder_direct_commercial_authority.php` was applied to the verified Owner preview using its existing owner role. Existing user/workspace/grant/market row counts were checked and preserved. Credentials were loaded in memory and not printed.
- The temporary 8765 tab/server/database were stopped. The pre-existing 8140 preview remains available.
- Generated screenshots, logs, build outputs and disposable databases remain ignored and are not staged. No commit, push or merge.

## Evidence

- `storage/framework/testing/founder-direct-ui.log`
- `storage/framework/testing/founder-direct-focused-runtime.log`
- `storage/framework/testing/founder-direct-final-runtime.log` (honest broader-run fixture failure)
- `storage/framework/testing/founder-direct-build.log`
- `storage/framework/testing/founder-direct-visual/01-market-created.png`
- `storage/framework/testing/founder-direct-visual/02-owner-renewal.png`

FOUNDER_DIRECT_OPERATION = YES

EMPLOYEE_DELEGATION_OPTIONAL_AND_SEPARATE = YES

FOUNDER_SELF_GRANTS_CREATED = NO

EMPLOYEE_AUTHORITY_WIDENED_AUTOMATICALLY = NO

FRESH_AUTHENTICATION_BYPASSED = NO

OWNER_PREVIEW_DATABASE_REPLACED = NO

FULL_REPOSITORY_GREEN_CLAIMED = NO

COMMIT_PUSH_MERGE = NO
