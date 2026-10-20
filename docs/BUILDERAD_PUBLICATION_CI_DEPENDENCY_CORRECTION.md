# Publication CI dependency correction

Accepted application candidate: `963b64920079acca5f7fd34c1c2a1d1b5889020b`.
Integration reference: `c7825aa09bd2ca616f08e25dfba3e112bbf08476`.
Draft PR: https://github.com/42Huss/builderad/pull/47.

The first hosted Foundation run, https://github.com/42Huss/builderad/actions/runs/39507034438, checked out synthetic merge `f9f992f33ee33919e0862fbeda1bc3b39c6809e2` with tree `a5db19751ebb9ffbc210877a7cd7af6f004958c1`, exactly matching the accepted candidate tree. It passed setup, PostgreSQL authority bootstrap, disposable role/isolation preflight, Inertia ceiling, Composer validation and Composer audit. It failed at `npm audit --audit-level=high` with one high-severity Vite finding covering GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw and GHSA-587h-7wmj-h5pm. PHP, JavaScript, API, Pint and build commands after that audit did not execute in this run; they are not PASS.

The accepted candidate and remote main use the same package-lock.json, pinning Vite 8.3.0. Main's earlier successful run 39475149361 is retained historical evidence, not proof of current audit status. The published advisory data now includes this locked version. This is a dependency security-gate failure, not evidence of a Vue administration behavior regression or a Git integration conflict.

The official advisories identify Vite 8.3.3 as the first patched version in this minor line. The correction raises the declared minimum to ^8.3.3 and records the resolved version/integrity. Its required Rolldown range changes from ~1.2.6 to ~1.2.11; the lock resolves Rolldown 1.2.14, its matching platform bindings, and @oxc-project/types 0.154.0. No other package entries changed. No broad `npm audit fix`, ignored advisory, audit threshold change, timeout change, assertion weakening, retry or application-code change was introduced.

Sources:
- https://github.com/advisories/GHSA-698v-4r3x-3vcv
- https://github.com/advisories/GHSA-r825-w8mm-5pxw
- https://github.com/advisories/GHSA-587h-7wmj-h5pm
- https://github.com/vitejs/vite/releases/tag/v8.3.3

The correction commit containing this note is an additional reviewed-candidate delta after the three accepted commits, which remain intact. It must not be described as the original accepted SHA. Local verification uses the corrected locked install: high-threshold npm audit, affected frontend tests and TypeScript/Vite production build plus runtime asset guard. Hosted Foundation is evaluated on the new PR checkout identity and is recorded in the publication handoff separately; this note makes no premature claim of hosted success.

Earlier browser receipts establish the accepted application behavior under its original Vite build. They are not relabelled as newly executed browser acceptance of the dependency correction. Dependency correction review and complete required CI results remain part of the separate merge-authorization decision. No merge or deployment is authorized; no persistent preview data is changed.

Local correction verification completed: npm ci --ignore-scripts PASS; npm audit --audit-level=high reports zero vulnerabilities; affected frontend selection 98/98 PASS; vue-tsc + Vite8.3.3 production build PASS; runtime production asset guard PASS; lock-entry allowlist and git diff --check PASS. Logs are retained under storage/framework/testing/github-publication-963b649 (ignored). Complete hosted Foundation remains separately required on the resulting commit.
