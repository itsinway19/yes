# Draft PR preparation — local only

Repository: `42Huss/builderad` (private). Base: `main`. Head: `codex/ba-spa-openapi-001`. State: DRAFT. No branch push or GitHub PR creation has been executed.

The ready-to-use body is `PR_BODY.md`; the title is `PR_TITLE.txt`. Publication instructions below are preparation only.

## Proposed title

Add opt-in Goals Vue SPA with a generated OpenAPI client and bounded session continuity

## Proposed body

Goals can run through an opt-in Vue Router/TanStack Query interface using an OpenAPI-generated TypeScript client, while preserving the existing Laravel Goals writer, CAS, WorkspaceContext, identity admission and RLS. Existing Inertia routes remain available; `GOALS_SPA_ENABLED` defaults to false. The internal specification stays outside public assets and no documentation endpoint is registered.

The coupled session correction makes renewal cookie-free and bounds owned login cookie retention by the canonical absolute deadline. It preserves legacy conservative bounds and SQL/Redis authority. Drafts, late-read rejection, explicit conflict review and uncertain-save messaging remain in place; writes are never automatically retried.

Validation on the exact pre-commit file state, reconciled against committed code blobs:

- Laravel: 971 tests, 13,858 assertions, no errors/failures/skips.
- JavaScript: 285 passing tests; three targeted client tests and deterministic generation check rechecked during stabilization.
- Composer validate/audit, npm audit, Pint, TypeScript and both production builds passed locally.
- Browser: 50 PASS, 2 UNVERIFIED, 0 FAIL. Includes real typed HTTP 200/401/403/409/419/422, transaction-time grant withdrawal and lost acknowledgement after commit.
- Stabilization changed no runtime code, tests, dependencies or acceptance policy; only version control and evidence/preparation documents were added.

Acceptance remains **A=PASS within the verified local continuity scope; B/C=BLOCKED**. Native SPA BFCache, physical-device/background and pre-JavaScript cached-frame evidence remain unresolved. Finite SSE is a test-only proof, not production real-time delivery. Production artifact builds passed; the comprehensive browser run used the explicit proof build. This PR does not authorize deployment, broad migration, removal of Inertia/N1 or a second business slice.

See `docs/execution/spa-openapi/SPA_GOALS_EXECUTION_REPORT.md` for execution limits and `docs/execution/spa-openapi/stabilization/STABILIZATION_REPORT.md` for commit provenance and remaining source-only evidence. GitHub CI for the new commits is pending; historical baseline CI is not attributed to this PR.

## Publication authorization boundary

The human authorized local commits and preparation. A `git push` and creation of the GitHub Draft PR still require explicit authorization to publish this branch to the private GitHub repository. No public repository, public API docs, uploaded database, uploaded raw browser/session artifacts or merge is authorized.

After that authorization, verify the repository remains private, the exact local head and intended base, then push only this branch and create a **draft** PR using this prepared body. Do not use a force push. Attach the created PR to this chat with the app artifact tool if/when creation succeeds. The heading and preparation metadata above are not part of the proposed PR body.
