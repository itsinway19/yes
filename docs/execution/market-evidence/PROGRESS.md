# D1 — qualified market evidence execution

**Final local decision: `D1_LOCALLY_VERIFIED — OWNER_REVIEW_PENDING`.**

The final corrected uncommitted candidate passed the official isolated **Foundation** entry point, exit **0**:

| Mandatory gate | Final evidence |
| --- | --- |
| PHP (all Unit/Feature, including 18 D1 tests) | **1226 passed / 18,696 assertions**, 2381.41 seconds |
| JavaScript complete canonical selection | **335 passed**, no failures/skips |
| Generated API contracts/types | Goals and Application match |
| Pint | passed |
| Composer validate / audit, npm audit | valid / no security advisories / zero vulnerabilities |
| `vue-tsc` and production Vite build | passed |
| Inertia ceiling and runtime asset guards | passed within their declared scope |
| Final runner file manifest | `source-unchanged.txt = True` |
| Task-owned PostgreSQL/Redis | runner confirmed `PROCESSES=STOPPED; EXIT=0` |

Evidence directory: `storage/framework/testing/goals-spa-cdafb587-c2fb-4613-888f-eebd190d8f43`; final `tests.xml`, `events.log`, `tests.log`, `gate-*.log`, source manifest and independent-worker evidence remain there. At local acceptance the branch HEAD was the baseline SHA; no commit/push/merge or real-data migration had occurred. This final status paragraph was added after the gate; executable source/tests/contracts/build inputs were not changed. Earlier pending statements below describe retained attempts, not the final decision.

**What is proved:** actual scoped intake/correction/read; separate maintenance/runtime privilege; independent Business rights and ending a binding after withdrawal; versioned meaning/history and exact no-op/retry behavior; failure rollback, real contention and release-time freshness; fresh and populated upgrades preserving client behavior. **What is not proved:** real market accuracy, legal licence validity, real source readiness, advertiser UI/browser integration, D2/D3 findings/publication/provider substitution, deployment or Owner merge approval. Full platform expansion remains future implementation, not a blanket compatibility promise.

Initial Owner scope: implement D1 with corrected A3 and complete market data-use lifecycle, without publication, commit, push, merge, deployment or production migration.

Publication authorization, 2026-10-30: the Owner subsequently requested commit, push to GitHub and CI verification, explicitly without merge. Publish the exact accepted candidate on its dedicated branch and open a Draft PR to `main`, because the existing Foundation workflow is triggered by pull requests (branch pushes alone do not trigger it). No merge, deployment, real-data migration or D2/D3 implementation is authorized. Hosted CI is a separate gate; record its actual checkout SHA, parents, tree and result before claiming success.

Baseline: `ba045f99a3c4d59b46368b5c261b6c311ac36d16` (`main`/fetched `origin/main`, G4 PR #49; implementation parent `1092f41`). Worktree `C:/dev/builderad-worktrees/ba-market-intelligence-001`, local branch `codex/market-evidence-origin-d1`. Initially clean. Test evidence covers uncommitted file states through runner manifests, not the baseline SHA alone.

Authoritative baseline Foundation: [run 119 / 41049384647](https://github.com/42Huss/builderad/actions/runs/41049384647), push on that exact `main` SHA, completed successfully 2026-10-30 11:48:41 UTC. Verified live through GitHub; retained metadata is `baseline-ci.json` in the local evidence directory. Hosted baseline used Ubuntu/PHP 8.5/Node 24/PostgreSQL 18/Redis; the local candidate uses the repository's isolated Windows runner with the same locked dependencies and database roles. Baseline success is regression context, not candidate proof.

## Acceptance fixed before implementation

- E01: source and original outside any Business/Workspace; authenticated, independently qualified intake, no operating owner credential.
- E02: one original, two real distinct test principals/Businesses, independent grants; revoke A, deny A, permit ending its binding, retain B's read.
- E03/E04: explicit client/market domain, existing authority and exact selection; deny unauthorized source, record, field, operation, purpose and recipient.
- E05/E06: explicit evidence schema, meaning, source, times, coverage and unknown values; immutable revision references, semantic corrections, no-op without fabricated revision.
- E07: qualified exact replay, conflicting command/head rejected, failure rolls back original/head/transition/receipt.
- E08: independent worker PID/XID and actual blocker observation; withdrawal/expiry denies after the wait. Timeout alone is not evidence.
- E09: fresh installation and populated G4 upgrade; existing client rights, canonical digests and native replay preserved, repeat migration does nothing.
- E10: both runtime roles denied raw market read/write/truncate, admission and role escalation; forged context and unadopted-source genesis cannot bypass permits.

## Implemented boundary

`source_registry.custody_kind=platform_market`, existing `data_use_scopes.rights_domain=market` and a per-scope resource fence extend G4; no alternative identity, permission, receipt or invocation system. Old scopes default to `client`; old canonical digests retain their encoding. A shared scope describes a resource contract; independent recipient qualifications/grants convey separate rights.

Migration 430 installs schema, fixed SQL dispatch/locking branches, functions and restrictive ACL/RLS atomically. Protected updates continue to require the existing single-use row permits. Market sources are hidden from legacy runtime Source Registry access. Migration `down` deliberately refuses destructive erasure of evidence/rights/history.

Corrected A3 has two separate entry points:

- Isolated deployment maintenance: `scripts/market-evidence-maintenance.sql` calls `builderad_v2_market_admit` only as `builderad_owner`. It provisions/restricts/revokes a source or enrolls an explicitly named current recipient with bounded source policy, evidence reference/digest, operation/purpose/fields/records and deadline. It neither ingests nor corrects evidence. Use a separate `psql` service/credential profile outside the web deployment; never supply its secrets to a web worker. No credential/profile is created by this change. The operator's statement is recorded evidence, not automatic legal verification.
- Repeated intake/correction: `POST /api/market-evidence/{evidence}` runs as `builderad_app`, using a real signed current principal/session and an exact nondelegable `data_use.ingest` or `data_use.correct` grant. No Business is fabricated. The runtime role cannot invoke maintenance, own tables, alter schema, or grant itself rights. No generalized worker/service identity is implemented or claimed.

The original owner stores full typed snapshots for `market.offer:1` and `market.interest_signal:1`; the latter retains metric name/unit/definition version. Unknown scalar stays null, observed zero stays zero. Corrections retain previous revisions and identify field/metadata changes. Exact no-op corrections record a command without creating a revision. Source restriction blocks use and intake without deleting historical evidence. No provider connection is required by this boundary; provider-independent findings and tool substitution remain D2/D3 acceptance work.

The existing material-read API returns only licensed fields with source/schema/revision/time metadata and an independent validity classification. Processing requires a current, temporally qualified original with known deadline and usable coverage; qualified retained reading may return history/unknown evidence, explicitly labelled. Public market provenance contains only server-owned revision/source trace references; full source-supplied provenance remains in internal custody and lineage, not a separate disclosure entitlement or proof of real-world demand.

### PR #50 — metadata confidentiality correction

Baseline `d3090a9bbb46a0debdd34e2d454ad46abcf5f9fb` passed [Foundation run 41105021288](https://github.com/42Huss/builderad/actions/runs/41105021288), but a focused HTTP reproducer demonstrated a gap: a price-only reader received arbitrary nested provenance, including private source URL parameters and unlicensed values. The correction replaces only market-original delivery provenance in the scoped reader, preserving raw provenance, private resolution, dependency digests and stored receipts. Native and registered producer read branches are unchanged.

Forward migration 440 verifies the existing catalog seal, replaces the same reader under its existing executor ownership, closes temporary schema permission and reseals. Tests cover retained/display/process delivery and same-command replay; an installed-D1 upgrade retains original records, grants, digest and receipt while redacting an already-recorded read replay. Prior catalog drift is denied; repeated migration is inert. Two targeted tests passed 92 assertions, then the affected selection passed **82 tests / 1004 assertions** under the official isolated runner, with unchanged-source proof and process cleanup. Pint passed. Independent read-only investigation and candidate review found no concrete remaining bypass/regression within this boundary. Hosted CI must cover the new commit; baseline CI alone is not proof of this correction. No external plans, D2/D3, real-data migration or merge are included.

## Verification and investigation

Status: focused boundary suite PASS: 45 tests, 352 assertions, including all 16 new D1 tests, existing native material use, database authority boundaries and Core dependency guard. Full Foundation is the next required gate; focused PASS is not full acceptance or production readiness.

A subsequent complete Foundation candidate passed **1224 PHP tests / 18,577 assertions; 335 JavaScript tests; contract generation checks; Pint; Composer/npm audits; production build and retirement guards**. Final release-boundary inspection then demonstrated one additional D1 timing defect with an independent-worker reproducer: evidence expiry during the final receipt wait left `material.process` using a pre-wait freshness decision, although its use right remained valid. The expiry reproducer observed the actual receipt blocker and erroneous COMMITTED result; no claim was based on timeout alone. The first diagnostic's 10-second fixture exceeded the unchanged lock timeout and was inconclusive; a 3-second fixture reproduced the actual behavior without changing timeout policy.

Correction: `require_material_use` rechecks original market evidence freshness at both initial and final qualification, and the reader recomputes validity at release. The affected D1/native read/publication selection passed **38 tests / 416 assertions**. Four independent-worker cases then passed **4 tests / 46 assertions**, including source withdrawal, right expiry, denial of processing after evidence expiry, and allowed retained reading labelled `review_required` after that same expiry. Final comprehensive requalification of this corrected candidate is pending; there are now **18 new D1 tests**. No additional feature or authority contract was introduced.

The first comprehensive attempt was stopped after 14 reproducible failures were localized (12 qualified legacy-import cases, one strict resource-codec case, one historical-upgrade harness case). Its unchanged-source observation was `True`; `foundation-interrupted-events.log` retains the precise failures. Task-owned PHPUnit descendants were verified through the exact runner command/worktree before termination; the runner confirmed PostgreSQL/Redis cleanup. This interrupted attempt is not PASS. After corrections, the focused selection `MarketEvidence|BusinessQualifiedImportTest|BusinessScopeCodecTest|BusinessSyntheticUpgradeTest` passed: **33 tests / 551 assertions**, including all formerly failing cases and baseline-drift denial. Final comprehensive Foundation is pending.

Official isolated run: `storage/framework/testing/goals-spa-cdafb587-c2fb-4613-888f-eebd190d8f43` (PostgreSQL 18 :5433, Redis :6397, guarded disposable database). Runner retains attempts/manifests/logs and stops its processes. No real database has been migrated.

Initial six behavior tests passed (67 assertions). Expanded testing proved populated upgrade and ordinary client behavior, and localized the following corrections:

1. PostgreSQL checks trigger installation privileges. Temporarily grant trigger EXECUTE only inside atomic migration, revoke before commit.
2. Populated CHECK validation calls private codecs. Use a narrow definer check function instead of granting deployment access to internal codecs; revoke its temporary install permission before commit.
3. New endpoint must use the existing owned-transaction middleware branch; signed lifecycle/session/rights remain checked inside SQL.
4. Synthetic fixture uses `jsonb_populate_record`, which fills omitted columns with NULL instead of applying defaults. Explicitly supply `client` on the D1 schema, and preserve its pre-D1 form for the real upgrade test. No production nullability or assertion is weakened.
5. Official Windows runner's relocated Laravel storage lacked `framework/testing`. Create that directory so existing and new independent-worker tests can run; no lock/timeout expectation changes.
6. Authority role already lacks Source Registry SELECT. Security test must assert actual denial, while the application role asserts its restrictive RLS hides market sources. No permission is expanded to satisfy a test.
7. D1 legitimately changes the catalog protected by G4's installation seal. Migration now first refuses unqualified baseline drift and atomically reseals only its completed fixed installation, after closing temporary privileges. Upgrade tests inject pre-existing drift and require denial/rollback; existing legacy-import drift guards remain enabled.
8. Preserving the v1 resource digest must preserve its exact row-shape rejection too. Restore the explicit old key set before encoding, while allowing the new SQL-declared nullable scope column. Existing codec assertions remain unchanged.
9. The historical pre-G4 upgrade test excluded only migration 420 by name, inadvertently applying later migration 430 before G4. Select the actual pre-G4 prefix; retain all historical upgrade assertions and final latest-schema restoration.
10. Final evidence freshness must be checked separately from continuing access rights after receipt contention; see the bounded reproduction and correction above. No lock ordering, runtime timeout or assertion was weakened.

## User surface and continuation

D1 is an internal evidence primitive and an authorized API extension, not a new advertiser feature page. No browser/UI completion or commercial outcome is claimed. Existing Business read authority still applies to consumption; subscription/billing, real source policy admission, D2 methods, D3 publication/correction propagation and provider substitution are not implemented here. No real sources or paid tools are enabled.

After D1 passes, submit the diff and test evidence for Owner review. Publication and CI now follow the explicit authorization above. Do not start D2/D3, merge or deploy automatically.
