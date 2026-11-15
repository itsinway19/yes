# Builderad

Builderad is a Laravel 13 / PostgreSQL modular monolith with a native Vue SPA. Start from [AGENTS.md](AGENTS.md), the [governing architecture](docs/architecture/BUILDERAD_GOVERNING_ARCHITECTURE.md), [current implementation and evidence](docs/architecture/CURRENT_IMPLEMENTATION.md), and [engineering standards](docs/engineering/ENGINEERING_STANDARDS.md). The [interactive architecture](docs/architecture/BUILDERAD_GOVERNING_ARCHITECTURE.html) is a reading copy of the Markdown, not another authority.

## Runtime and dependencies

- PHP 8.5.x / Laravel 13; `composer.json` requires `>=8.5.0 <8.6.0`.
- PostgreSQL 18.x only. Redis supplies configured sessions, cache and queues.
- Node 24.x (`>=24 <25`), Vue 3, Vue Router, TypeScript, Tailwind CSS 4 and Vite 8.
- Version ranges, scripts and locked identities come from `composer.json`, `package.json` and their lockfiles. Protection: `tests/Feature/Foundation/BaselineContractTest.php`.

On Windows use the scoped toolchain; do not change machine-wide PATH:

```powershell
pwsh -NoProfile -File scripts/builderad.ps1 preflight
. .\scripts\Enter-BuilderadShell.ps1
```

For a matching offline dependency seed:

```powershell
pwsh -NoProfile -File scripts/Prepare-BuilderadDependencies.ps1 -Mode Seed -SeedRoot C:\path\to\matching-checkout
```

The seed is read-only; lockfiles and installed package identities must match. On a network-enabled machine, the same script's `-Mode Online` installs from the locks. Preparation is not a test result.

## Local configuration and database authority

Create the ignored `.env` from `.env.example` for an explicitly selected local environment. Set APP_KEY and credentials through protected local configuration; do not commit them. Checked-in defaults select PostgreSQL and Redis. Provision only an authorized local or disposable server:

```powershell
pwsh -NoProfile -File scripts/builderad.ps1 psql -X -U postgres -d postgres -f database/bootstrap/postgresql.sql
```

The bootstrap requires a PostgreSQL 18.x superuser and provisions fixed local/test databases and roles. It is not a production provisioning contract. Set passwords using PostgreSQL's hidden interactive `\password` prompt; never put credentials in command examples or the repository.

Application queries use `pgsql` / `builderad_app`; migrations use `pgsql_migrations` / `builderad_owner`; trusted evidence/session issuance uses separately restricted `pgsql_authority`. These roles are not interchangeable; runtime code cannot obtain schema ownership or bypass RLS. Test provisioning also uses `database/bootstrap/test-authority-role.sql` and `database/bootstrap/disposable-test.sql` under the guarded runner. See [authority and rights](docs/engineering/ENGINEERING_STANDARDS.md#authority-and-data-rights).

Do not run `migrate:fresh` or destructive tests on preview/shared databases. The normal migration wrapper deliberately invokes the disposable-database guard; it is not an instruction to migrate an owner's existing environment. A real installation or upgrade requires its explicitly authorized environment-specific procedure.

## Application entry and local development

`routes/web.php` owns public, advertiser and internal HTTP admission. Vue starts at `resources/js/spa/main.ts`; Vite builds that native SPA. There is no Inertia runtime.

For an already configured, authorized local environment, use separate terminals as needed:

```powershell
pwsh -NoProfile -File scripts/builderad.ps1 php artisan serve
pwsh -NoProfile -File scripts/builderad.ps1 npm run dev
pwsh -NoProfile -File scripts/builderad.ps1 build
```

Track and stop servers/watchers you start. `scripts/Start-StablePreview.ps1` is a separate existing preview lifecycle tool; inspect its target and authorization before starting, stopping or modifying it. Preview is never a disposable test target.

## Business and historical Workspace terminology

Business is the current operating entity, including practices, employee/agency paths and separately qualified Source/data-use relationships. Public Workspace routes and creation flows are retired. The current fresh PostgreSQL baseline also has explicit absence checks for Workspace objects and authority (`WorkspaceRetirementTest`, `WorkspaceOwnershipRetirementTest`); this is more than hiding navigation.

Historical documents, Git revisions, test names and generic UI/file-system uses of “workspace” may remain. They do not reintroduce an operating Workspace or grant access. Valid retained evidence, grant ancestry, receipts and source history keep their own owners and rights. Fresh-install tests do not prove an upgrade or data migration of an existing preview/production database. See [current implementation](docs/architecture/CURRENT_IMPLEMENTATION.md).

## Verification

Documentation-only changes require content/reference checks and `git diff --check`, not full Foundation. For PHP integration use the isolated entry point and select actual affected classes:

```powershell
pwsh -NoProfile -File scripts/Test-GoalsSpa.ps1 -Phase Focused -TestFilter 'BusinessFoundationTest'
```

The runner checks reserved ports, starts owned PostgreSQL/Redis resources, verifies all three database roles and the disposable identity token, retains evidence, and cleans task-owned processes/data. Direct `scripts/builderad.ps1 test` requires an already qualified isolated environment. It rejects preview/shared databases, connection URL overrides and cached configuration.

Other commands, selected according to impact:

```powershell
pwsh -NoProfile -File scripts/builderad.ps1 npm test
pwsh -NoProfile -File scripts/builderad.ps1 npm run api:check
pwsh -NoProfile -File scripts/builderad.ps1 php vendor/bin/pint --test
pwsh -NoProfile -File scripts/builderad.ps1 build
```

`npm test` is the complete JavaScript selection; `test:*` scripts are subsets. `build:goals-spa` aliases the production build; `build:goals-spa:proof` is distinct. Run one production build per comprehensive gate.

The complete hosted gate is `.github/workflows/foundation.yml`. Its isolated Windows counterpart is `scripts/Test-GoalsSpa.ps1 -Phase Foundation`; use it for an applicable comprehensive gate, not automatically. Browser lifecycle uses a separate Browser phase. [Engineering standards](docs/engineering/ENGINEERING_STANDARDS.md#verification-and-evidence) maps selections and evidence rules. A passing baseline does not certify unmerged changes or deployment.

## Security notifications

Laravel Notifications and configured mail transport own security email; Redis is the normal queue. Start a worker only for an authorized environment and delivery configuration, for example:

```powershell
pwsh -NoProfile -File scripts/builderad.ps1 php artisan queue:work redis --queue=default --tries=3 --timeout=30
```

Queue completion/provider acceptance does not prove inbox delivery. Do not use development log transport for real messages; sender-domain/provider configuration and production validation are separate work. Disposable tests use non-delivering transports.
