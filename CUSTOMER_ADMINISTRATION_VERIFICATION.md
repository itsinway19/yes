# Customer and workspace administration

Owner-requested scope: registered advertiser accounts and workspaces; search, status,
details, reversible suspension/reactivation. Only a verified current active Founder
can read or execute this administration. Existing staff permissions do not confer it.

## Boundaries

- Read projection excludes passwords, provider credentials, campaign/source contents.
- Signed principal context and a current fresh internal passkey session are checked at
  the SQL effect boundary under shared authority locks.
- Account suspension uses the existing disabled lifecycle, advances generation, and
  revokes sessions. Reactivation advances generation again; old sessions and grants
  cannot become current. Pending accounts remain pending without email verification.
- Only an administrative suspension with matching generation can be reversed here.
  Security-disabled accounts and internal personnel cannot be reactivated here.
- Workspace suspension is independent, preserves memberships/data, and serializes with
  existing active-workspace selectors/writers. Archived workspaces cannot be revived.
- Owner workspace grants require fresh explicit reassessment. Continuity can cross
  only the immutable proven administrative suspension cycles; revoked grants and
  arbitrary generation gaps remain denied. Session time uses second precision while
  the current-generation and revoked-session checks exclude prior sessions.
- Command identity and payload remain fixed through lost replies and reauthentication.
  Same-principal tab storage contains intent only. HTTP authority refusal after an
  uncertain response does not discard that original command.
- Reasons, actor, before/after status, revisions and timestamps are immutable and saved
  in the same transaction as the change. Audit failure rolls back the effect.

## Executed verification

- CustomerAdministrationTest and OwnerGrantReassessmentTest: **12 passed / 180 assertions**.
- WorkspaceIsolationTest: **37 passed** in the preceding combined execution; its only
  failure was the new Owner recovery test, subsequently corrected and verified above.
- Localization and existing Commercial operator regression: **48 passed**.
- vue-tsc and production Vite build passed; git diff --check passed.
- Final inline details disclosure: opens inside the selected row, closes with the same
  button, and works at desktop and 390px widths with no page errors. Final build passed.
- Disposable Chromium browser: actual workspace suspension/reactivation, lost committed
  reply followed by synthetic 403, reload and identical command replay; desktop and
  390px viewport checks passed with no page errors. Browser authentication uses real
  SessionAuthority synthetic fixtures, not a physical WebAuthn ceremony.
- Preview migration 000310 applied only to verified local builderad_test:5432. Existing
  users/workspaces/grants/markets/configuration/catalog row counts preserved. The new
  continuity function was aligned with the verified second-precision definition.
- The localhost:8140 preview was restarted at Owner request and kept running. Temporary
  test HTTP/PostgreSQL processes are stopped after the browser check.

## Known wider gap

LifecycleActivationTest's existing registration test expects /dashboard HTTP 200, while
the current Business Foundation routes that account to onboarding with HTTP 302.
The wider run recorded **1 failed / 49 passed / 311 assertions**. This unrelated fixture
expectation was not hidden or changed. This report does not claim a green full suite,
release acceptance, or physical passkey testing.

No push, merge or commit was performed. Earlier Commercial review gaps remain in their
existing review documents.
