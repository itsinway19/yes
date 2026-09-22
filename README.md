# Builderad

Builderad is a Laravel 13 modular monolith. This working tree currently contains only the Foundation boundary: Laravel-native identity, explicit Workspace membership, and PostgreSQL row-level security.

## Runtime

- PHP 8.5.x and Laravel 13
- PostgreSQL 18.x only
- Redis for cache, sessions, and queues
- Inertia, Vue 3, TypeScript, Tailwind CSS 4, Vite 8, and Node 24.x

Load the scoped local toolchain before running commands:

```powershell
. C:\tools\builderad-env.ps1
```

## Database authority

Create or reconcile the two local/test databases and their authority model from a PostgreSQL superuser connection:

```powershell
psql -X -U postgres -d postgres -f database/bootstrap/postgresql.sql
```

The script is deliberately password-free. For local use, assign credentials afterward through PostgreSQL's hidden interactive `\password builderad_owner` and `\password builderad_app` prompts. CI must inject ephemeral credentials through its protected secret mechanism; never place them in this repository or command examples.

The artifact is restricted to local Foundation provisioning and CI ephemeral provisioning for the fixed `builderad` and `builderad_test` databases and the `builderad_owner` and `builderad_app` roles. It fails before mutation unless the server is PostgreSQL 18.x and the session identity is a PostgreSQL superuser. This is not the future production provisioning contract. The runtime settings preserved by the artifact are `search_path=public`, `statement_timeout=15s`, `lock_timeout=3s`, and `idle_in_transaction_session_timeout=15s`.

Normal application queries use `pgsql` as the restricted `builderad_app` role. Schema changes use `pgsql_migrations` as `builderad_owner`:

```powershell
php artisan migrate --database=pgsql_migrations
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

The RLS proof suite prepares `builderad_test` through the owner connection, then executes application assertions as `builderad_app` without an outer test transaction.
