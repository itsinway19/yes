# Inertia 3.7.1 N1 compatibility artifact

Inertia remains the navigation owner. This artifact moves candidate acceptance to
the final synchronous History write and checks actual Vue settlement afterward.
It does not authenticate requests or replace BuilderAd's identity admission.

## Provenance and build

`provenance.json` pins both installed 3.7.1 packages, their compiled JavaScript and
source maps, the core declaration file and the existing Rolldown version. The
core source map contains 52 complete upstream TypeScript sources. Its omitted
type-only `types.ts` is supplied by the independently pinned published declaration;
it introduces no runtime implementation. Upstream MIT licensing is retained.

`source/` contains only the reviewed overlays. `source.patch` is their diff against
the pinned upstream sources. `build.mjs` checks the exact upstream and overlay
file sets and hashes before extracting and building a single ESM runtime under
ignored `storage/framework/inertia-n1/`. Generated files are not source of truth.
No manual `node_modules` edit, package version change or new dependency is used.
Rolldown's working directory is the extracted source directory, so generated
region comments and artifact bytes are independent of the checkout/output path.
The reproducibility test builds into two different directories and compares bytes.

Vite builds the artifact first and aliases the exact `@inertiajs/core` import,
including the Vue adapter's import, to that single runtime. It excludes both
Inertia packages from dependency prebundling. The application bridge rejects a
stock runtime rather than silently falling back. Production builds do not expose
the internal exports used by the contract tests.

```sh
npm run build:inertia-n1
npm run test:inertia-n1
npm test
npm run build
```

After deliberately reviewing an overlay change, regenerate its review diff and
hashes with `node compat/inertia-n1/review-patch.mjs`. This command still verifies
the original upstream pins. Build never regenerates or approves changed hashes.
Validate the diff with `git apply --check --directory=<extracted-upstream-src>
compat/inertia-n1/source.patch`; do not apply it to installed dependencies.
An upgrade requires a separate review and new provenance, not a guard bypass.

## Behavioral boundaries

- Request tokens distinguish preparation, native commitment and settlement.
  Cancelled preparation releases its async wait, so the Response queue can accept
  a newer response even if old crypto never completes. Old continuations cannot
  publish keys, core state or History.
- Encryption/key preparation is local. Final validation, key installation and
  native write run synchronously; native failure preserves the accepted entry's
  material when its identity is still valid. Crypto errors have no plaintext fallback.
- Accepted-entry remember/scroll operations retain their own ownership. They do
  not belong to the outgoing navigation candidate.
  Scroll reads the latest native state inside its queue turn, so it cannot put
  pre-remember encrypted bytes back. Native popstate immediately revokes native
  write permission, separately from the accepted in-memory entry. Only an owned
  successful commit/restoration grants fresh permission; matching URLs do not.
  Outgoing remember work may retain its draft in the old accepted memory during
  restoration, but never writes that snapshot or its keys into the target entry.
  Cancelled/failed restoration cannot reactivate old writers. A traversal during
  postcommit settlement prevents compensation onto the traversal target.
- Postcommit failure gets one same-identity compensation or an explicit fresh
  document recovery. A postcommit cancellation is not a precommit acknowledgment.
- Vue settlement observes actual mounted instances, document receipts and render
  errors in addition to the flush. Native/core/URL agreement is checked separately.
  The first mount is observed too: a failed initial render closes the document,
  retains existing key material and requires explicit fresh-document recovery.
- Optimistic baselines and rollback are scoped to the accepted entry. Response
  baseline changes are staged until commitment; `preserveUrl` is response-local.
- Bootstrap keeps the admitted identity metadata object. InitialVisit captures
  restoration ownership before decrypt; a delayed initial restore cannot start
  a new visit over a newer accepted page. Explicit History clear cancels pending
  preparation before removing its key material. Each correction has a direct
  reproduction test rather than relying on neighboring cancellation coverage.
- Consumed prefetches acquire ownership before waiting for their cached response,
  expose cancellation and emit finish after settlement. Concurrent same-page
  deferred/partial GETs retain serialized acceptance; a genuine navigation or
  clear cancels their uncommitted work. BuilderAd's presentation sequence follows
  the same distinction, while retaining its identity and fresh-context checks.
- Native asynchronous view transitions remain disabled for page publication
  until they can satisfy the same settlement contract. Optimistic updates,
  remember, deferred requests and restoration remain enabled.

## Browser fixtures and rollback

`tests/Support/inertia-n1-browser.mjs` runs against an externally built isolated
Laravel/PostgreSQL/Redis candidate. Its History/crypto interception is test-only.
The optional PHP fixture server registers a throwing Vue page only with its
explicit test flag and disposable database guard. Production routing never
loads this fixture. The observer decrypts History read-only and checks served
asset hashes; it does not repair state during observation.
The isolated fixture build explicitly resolves the fault SFC from `tests/Support`
and selects its matching manifest entry in its private Blade copy. These two
fixture branches are absent from the separate exact-source production build.
Production smoke uses `public/index.php` and verifies its own served bytes.
Native hidden/visible tab evidence is counted only when the browser actually
emits both events; headless or headed activation without those events is UNVERIFIED.

Rollback is a reviewed reversal of these task-owned source changes and the Vite
alias/bridge integration together. Do not reset the worktree or alter unrelated
files. Stock Inertia restores the known N1 limitation, so rollback restores prior
containment rather than proving N1 acceptance. Never disable only the alias while
leaving the artifact-required application bridge enabled.

Local evidence does not establish Safari/iOS, genuine BFCache or Firefox native
WebAuthn acceptance, nor production deployment readiness.
