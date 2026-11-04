# PR56 independent-review correction plan

Base: `881091bfd1b33dca86e8c6d7187c0d7ded6a8268`, with the pre-existing uncommitted retirement candidate preserved. Governing specification: Owner's five corrections and final-schema requirements in this chat; accepted semantics remain in `docs/execution/business-foundation/PR56_RETIREMENT_MATRIX.md` and Executable Architecture INV-001/004/005/007.

## Acceptance and constraints

Close all five defects with executable evidence on one final candidate. Preserve original identities, private history, historical digests, source/data-use separation, exact permits, RLS and existing local work. Test fresh installation and populated synthetic upgrade. Verify basic advertiser and agency browser journeys. Record failed attempts honestly. Prepare, but do not execute, official Foundation; no preview/production migration, publication or merge.

## Tasks

1. **Preserve and reproduce.** Capture initial status, tracked binary patch and full source hashes under `storage/app/pr56-independent-correction-20261104`. Reproduce original market-origin, publication and codec tests through the guarded Focused runner. Compare shared SQL reloads against the accepted market definitions, not only the latest commit.
2. **Purpose privacy.** Change `database/sql/business_scope/purpose.sql`, the `BusinessScope::purposes`/`ObjectPurpose` projection and necessary strict API/UI consumers. SQL must distinguish qualified private history from a sanitized current declaration. Add real HTTP tests for direct and internal employees delegated only after declaration/correction, alternate temporal/ID reads and creator history. Run `BusinessPurposePrivacyTest` plus affected Purpose consumers.
3. **Shared SQL contract restoration.** Restore the platform-market permit branch in `purpose_retirement.sql`, historical six-field versus qualified extended dependency encoding, and internal operational session/final-effect dispatch across `authority.sql` reloads. Use forward migration for an already-initialized candidate; do not change receipt fingerprints/history. Add a final-schema behavioral composition test and a populated upgrade test. Exercise Business, market operator, market evidence, Purpose and source/data-use boundaries. Inventory every shared reload and its final owner in the existing progress record.
4. **Team rejection recovery.** Change only `OrganizationTeam.vue` and actual compiled Vue component regressions. A definitive 422 releases the rejected command for editing; 409 reloads the revision before a new explicit command; unknown outcomes retain and reconcile the exact original command without automatic replacement. Preserve authority-denied behavior. Run canonical affected JS selection.
5. **Affected verification and review.** Run the five correction selections and cross-SQL consumers on the final migrated schema, with focused architecture/authority checks. Run API drift, scoped formatting, production build and the two basic browser journeys. Review the correction diff for authority widening, hidden history and historical-codec drift. No comprehensive Foundation.
6. **Freeze and hand off.** Update existing progress/matrix with exact results, prior failures and remaining limits; record all tracked/untracked/deleted candidate files and dependency/build identities in a new manifest, compute SHA-256 and verify unchanged before/after final evidence. Prepare an Owner-run helper which validates this manifest then invokes the official Foundation runner without touching preview. Report `READY_FOR_OWNER_FOUNDATION` only if all required bounded acceptance passes; otherwise `CORRECTION_REQUIRED`.

## Review focus

- Delegation after a private correction must not expose the prior statement, reasons, confirmations or revision IDs through collection/detail/pagination/time queries.
- A refreshed conflict must use a new command ID and current revision; an unknown outcome must reuse the exact old payload.
- Reloading Business authority must preserve market operator final authorization without giving staff Business/Workspace rights.
- A platform-market insert must consume the exact permit, while direct role writes and native-source crossover remain denied.
- Six-field historical dependency values and receipts must remain byte-for-byte stable after every migration, alongside the distinct extended data-use encoding.

## Execution ledger

- Initial worktree/status/hash snapshot and tracked patch captured before edits; prior candidate evidence preserved separately.
- First sandbox runner attempt stopped before tests because Windows CIM port inspection was denied; escalated official runner used.
- First elevated attempt stopped before cases because the wrapper rejected a `Class::method` filter. Retry uses supported test-name alternation. Neither attempt is a product PASS or defect reproduction.
- All six tasks completed. Four shared SQL collisions were restored, including the additional market data-use fence. Old-schema testing also corrected absent-custody NULL handling and typed access before the market column exists; historical Workspace fixtures use their actual canonical creator.
- Final disjoint PHP selections:3/202 upgrades,87/1129 market/source consumers,132/1453 Business/privacy/codec/real contention; all PASS without errors/failures/skips. Final JS81 PASS, production build/API/format/retirement/diff PASS, actual browser5 PASS.
- Earlier memory failures, initial session-bound replay expectations and the invalid staff-as-advertiser fixture remain documented in the existing progress record; no product authority was weakened to fix them.
- Final status: `READY_FOR_OWNER_FOUNDATION`, not comprehensive acceptance. Complete manifest/fingerprint, runtime equivalence and syntax-checked Owner helper are in `storage/app/pr56-independent-correction-20261104`. Foundation was not executed. No publication or real-data operation occurred.
