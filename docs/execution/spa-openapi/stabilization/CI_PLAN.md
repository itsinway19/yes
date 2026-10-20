# CI stage — prepared, not dispatched

## Exact candidate and prerequisites

Local branch: `codex/ba-spa-openapi-001`, based on `580837d334ac703209644ffff54bb6d05a62a0c0`. The final documentation commit is discoverable with `git log -1 --format=%H -- docs/execution/spa-openapi/stabilization/STABILIZATION_REPORT.md`; no self-referential commit SHA is embedded in that file.

Before publication, obtain explicit authorization for **branch push and private GitHub Draft PR creation**. Verify repository visibility and current base/head remotely at that point; cached local refs do not prove current GitHub main. Check Foundation secrets/service prerequisites through approved read-only access without printing secret values. No new credentials, repository settings or branch protection changes are authorized here.

## Trigger and checks

`.github/workflows/foundation.yml` runs on pull requests targeting main and pushes to main. Merely pushing this feature branch does **not** trigger the declared push event; a Draft PR targeting main is the intended trigger. The workflow has no workflow_dispatch trigger. Do not push to main or add a dispatch workflow just to obtain a run.

Foundation retains the official Ubuntu/PostgreSQL18/Redis7 bootstrap, disposable database marker and all three database roles. Required commands:

1. `composer validate --strict`, `composer audit`, `npm audit --audit-level=high`.
2. `php artisan test --compact`, then `npm test`.
3. `npm run api:check`, `php vendor/bin/pint --test`.
4. One `npm run build`, then `node scripts/runtime-retirement.mjs --assets`. The native SPA is now the default build; `build:goals-spa` is its alias. `npm test` includes the SPA subset. See [current verification policy](../../../BUILDERAD_TEST_EXECUTION_RATIONALIZATION.md).

The workflow was reviewed and its YAML/commands checked locally; no GitHub run for these commits has been requested or observed. Do not convert a prepared workflow into a PASS. Do not change assertions, lock ordering, authority semantics or critical timeouts to obtain green CI.

## Provenance to retain when authorized

Record repository/base/head, PR URL/number, head SHA, base SHA, tested merge ref and the actual actions/checkout SHA, run URL/id, job/check name, conclusion and relevant artifacts. A PR merge-ref run proves that checkout, not automatically the local head, future main or production. Reconcile content if the tested merge checkout differs from the candidate. A stale or missing required check is not PASS.

If a gate fails, compare the tested candidate with the last relevant passing revision and environment first. Use existing logs and the smallest useful reproduction. The already sealed local Foundation evidence applies to the unchanged source state and need not be repeated simply because commits were created.

The local browser/closure harness depends on a dedicated Windows runtime and is not claimed to execute in this workflow. CI Foundation success will not close native BFCache, device/pre-JavaScript or long-lived real-time delivery gates. Keep **A=PASS(local), B/C=BLOCKED**, keep the PR draft, and do not merge or deploy without a separate decision.
