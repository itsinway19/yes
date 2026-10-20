# Builderad

Builderad is a Laravel 13 modular monolith. The security foundation uses Laravel-native identity and notifications, explicit action grants, and PostgreSQL row-level security for Workspace authority.

## Runtime

- PHP 8.5.x and Laravel 13
- PostgreSQL 18.x only
- Redis for cache, sessions, and queues
- Vue 3, Vue Router, TypeScript, Tailwind CSS 4, Vite 8, and Node 24.x

Load the scoped local toolchain before running commands:

```powershell
pwsh -NoProfile -File scripts/builderad.ps1 preflight
. .\scripts\Enter-BuilderadShell.ps1
```

`scripts/builderad.ps1` reads the supported PHP and Node ranges from
`composer.json` and `package.json`, and the PostgreSQL range from the database
bootstrap. It uses the approved local executables explicitly, including Node 24
for npm. Run project tools through the wrapper in Codex or a plain PowerShell
session:

```powershell
pwsh -NoProfile -File scripts/builderad.ps1 test --compact
pwsh -NoProfile -File scripts/builderad.ps1 build
```

The test action and the PHPUnit base test case both reject the preview database.
Destructive tests require a separate PostgreSQL 18 cluster on `127.0.0.1:5433`,
an actual database named `builderad_test_disposable`, and a matching UUIDv4
database comment and `BUILDERAD_TEST_ISOLATION_TOKEN`. All three resolved Laravel
connections (`pgsql`, `pgsql_authority`, `pgsql_migrations`) must have the expected
roles and actual server identity. Connection URL overrides and cached Laravel
configuration are rejected. Before running tests, initialize the separate cluster
with `database/bootstrap/postgresql.sql`, set up its authority role with
`database/bootstrap/test-authority-role.sql`, then run
`database/bootstrap/disposable-test.sql` from its `postgres` database using
`psql -v isolation_token=<new-uuid-v4>`. Set the same token and the three
connection credentials in the ignored `.env.testing` or process environment,
with the disposable database name and port. Never point this command at the
preview database on port 5432. The VS Code workspace terminal and tasks use
the same preflight; no machine-wide `PATH` change is needed.

When package registries are unavailable, prepare a checkout from another local
checkout whose `composer.lock` and `package-lock.json` match exactly:

```powershell
pwsh -NoProfile -File scripts/Prepare-BuilderadDependencies.ps1 -Mode Seed -SeedRoot C:\path\to\matching-checkout
```

The seed is read only. The command checks installed Composer and npm package
versions against the locks and copies only missing dependency directories into
this checkout. On a network-enabled machine, use `-Mode Online` to install from
the locks with temporary files and caches scoped to this workspace.

## Database authority

Create or reconcile the two local/test databases and their authority model from a PostgreSQL superuser connection:

```powershell
psql -X -U postgres -d postgres -f database/bootstrap/postgresql.sql
```

The script is deliberately password-free. For local use, assign credentials afterward through PostgreSQL's hidden interactive `\password builderad_owner` and `\password builderad_app` prompts. CI must inject ephemeral credentials through its protected secret mechanism; never place them in this repository or command examples.

The artifact is restricted to local Foundation provisioning and CI ephemeral provisioning for the fixed `builderad` and `builderad_test` databases and the `builderad_owner` and `builderad_app` roles. It fails before mutation unless the server is PostgreSQL 18.x and the session identity is a PostgreSQL superuser. This is not the future production provisioning contract. The runtime settings preserved by the artifact are `search_path=public`, `statement_timeout=15s`, `lock_timeout=3s`, and `idle_in_transaction_session_timeout=15s`.

Normal application queries use `pgsql` as the restricted `builderad_app` role. Schema changes use `pgsql_migrations` as `builderad_owner`:

```powershell
pwsh -NoProfile -File scripts/builderad.ps1 php artisan migrate --database=pgsql_migrations
```

Application code must never use `pgsql_migrations`. The runtime role has no schema `CREATE`, owns no application table, and cannot bypass RLS.

Prepare each schema with `builderad_owner` through the migration connection. Run application and RLS proof through `builderad_app`:

```powershell
php artisan migrate:fresh --database=pgsql_migrations --force
php artisan test
```

The policy dependency graph is intentionally non-circular:

```text
authenticated User
  -> transaction-local app.principal_user_id
  -> transaction-local principal authorization phase
  -> own active workspace_memberships and authorized Workspace candidates
  -> exact public_id candidate resolution and membership authorization
  -> transaction-local app.workspace_id
  -> transaction-local workspace authorization phase
  -> Workspace-owned rows
```

`WorkspaceContext` owns the outer transaction. Both PostgreSQL settings are transaction-local and disappear after commit or rollback.

Direct account deletion is intentionally unavailable at Foundation baseline. It remains deferred until an identity/Workspace ownership, transfer, erasure, and retention contract is approved.

## Verification

```powershell
php artisan test
npm run build
composer audit
npm audit
```

The RLS proof suite prepares only the disposable test database through the owner connection, then executes application assertions as `builderad_app` without an outer test transaction.

## Transactional security email

Registration verification, password recovery, and necessary account security notices use Laravel Notifications and the configured Laravel mail transport. The default queue remains Redis; security notifications are encrypted in queue payloads, dispatched after commit, and limited to three attempts with short backoff. A worker must be running for messages to leave the queue. For local testing, the disposable test suite uses Laravel's database queue and array mail transport; it never sends to real users.

Configure `MAIL_MAILER`, `MAIL_FROM_ADDRESS`, and provider credentials through protected environment configuration when selecting a transactional mail provider. Do not use the development `log` transport for real delivery. Run a Laravel queue worker under the same scoped toolchain with a process supervisor, for example `pwsh -NoProfile -File scripts/builderad.ps1 php artisan queue:work redis --queue=default --tries=3 --timeout=30`, and monitor Laravel's failed jobs and `security_mail_transport_failed` logs. A successful queue job or provider acceptance is not confirmation of inbox delivery. Recovery messages are rechecked before sending; stale or consumed tokens are skipped.

Before production mail activation, authenticate the sender domain with the selected provider's SPF and DKIM DNS records, publish a DMARC policy and reporting address, and verify alignment and delivery using provider evidence. Provider selection, DNS changes, and production validation are separate operational steps outside this worktree task.
