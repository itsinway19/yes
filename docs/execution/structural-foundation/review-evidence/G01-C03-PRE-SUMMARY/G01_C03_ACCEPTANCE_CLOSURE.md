# G-01-C03 — Operational Journey Closure & Final Foundation Readiness

## 1. Baseline and candidate identity

Review date 7 November 2026. The isolated worktree is `C:/Users/stoma/.codex/worktrees/ba-foundation-product-audit-001/builderad`. HEAD remains detached at `e2fd972985be3c1bb7c9d6a80b73b23a45281096`. All prior tracked and untracked work was retained. The C02 reference `0ec7ea4c27ee9288a028017e937fe982a70f8c93688499157af222f43413f3db` hashes a candidate manifest. Its 1,344 source entries and 58 build assets matched with zero drift before C03. The Git revision is the detached HEAD identified separately above.

C03 adds migration690, corrects the existing agency path verifier and read projection, and extends existing HTTP, component and browser acceptance. It adds no producer or permission owner. The final SQL/browser execution state was fixed during each run. Subsequent documentation-only changes are explicitly identified in the freeze record. Final source hashes, production assets, evidence hashes and the previous manifest comparison are retained in `storage/framework/testing/g01-c03-candidate`.

| Capability | Classification | Evidence and limit |
|---|---|---|
| Individual Business and native Source flow | IMPLEMENTED / VERIFIED | Actual browser login, independent Business, Meta OAuth/callback and queued report, mandate, licence, coverage, binding, material read and withdrawal |
| Agency relationship and current operating mandate | IMPLEMENTED / VERIFIED | Separate client principal and agency principal, explicit admitted prerequisites, actual relationship and mandate commands, actual licensed read, rival denial and replacement denial |
| Named beneficiary assignment and revocation | IMPLEMENTED / VERIFIED | Actual Vue selector, exact recipient/limits, missing-response reconciliation across Business A/B, revoked/replaced Business grant and assignment withdrawal |
| Beneficiary assignment summary without Source read | IMPLEMENTED / VERIFIED | Read projection exposes only licence reference, operation, purpose and bounded assignment deadline. Source identity, report keys, selectors and original values are omitted |
| Beneficiary read of native originals without independent Source read | MISSING / BLOCKED | Current original reader correctly denies. The new assignment does not supply Source read |
| Finite registered derived outputs | IMPLEMENTED / PARTIAL | Existing `market.knowledge` and `market.business_finding` owners support qualified `finding` display, with independent output scope and continuing ancestral policy. They do not implement a native spend-summary output for this licence |
| Legal adjudication or manual review queue | MISSING / intentionally deferred | Ambiguity prevents issue. No assertion of completed review or verified legal ownership |
| Exact-candidate Foundation | NOT_VERIFIED / OWNER_GATE_PENDING | Reserved for the Owner. This task did not run Foundation |

## 2. Authority and trust boundaries

The existing Business grant graph, qualification receipts, signed session and principal, Source custody, data-use scope, boundary, fences, permits, command receipts and material registry remain the owners.

- Business is independent of Workspace. Workspace membership and payment do not establish client authority.
- Source management, Source read, Business read, Business data use, licence authority and advertising action authority are separate.
- The finite explicit operating mandate issues only `business.data.use` and the licensed-binding use of `business.link.manage`. Organizational link commands are denied under this mandate.
- Named assignment uses the existing finite consent and a current explicitly delegated Business use grant. It cannot exceed the consent or its parent/beneficiary authority period. It is nondelegable. The original licence roots remain unchanged and nondelegable.
- Every material read requalifies its exact Business, licence, Source where required, session and registered consumer. A permission reference or return hint supplies no authority.
- The new agency condition binds usable grant edges to the current relationship incarnation through its retained revision and the qualification's precise validity start. Recreating a relationship does not revive preexisting agency edges. Existing history and original digests are retained.

### Corrections

1. A decisive HTTP regression proved that a read rejected after ending an agency relationship returned200 once that relationship was recreated. The current SQL verifier now rejects the old grant, its dependent mandate and its prior licence after replacement. A fresh independently admitted context can issue a fresh mandate, while the previous licence remains rejected. The first full affected C03 run exposed a same-second boundary case in renewed qualification. At that stage, grant creation timestamps had second precision. The verifier now compares the canonical qualification's full-precision validity start against the relationship validity start. The acceptance fixture deliberately puts the new grant at the beginning of that same second and requires its fresh qualification to succeed while the old licence remains denied.
2. The C02 journey projection could expose native Source/report selector metadata to an assigned recipient without Source-read qualification. It now returns only an assignment summary in this case. Its deadline is bounded by the assignment, and its visibility ends after loss of the exact qualifying Business grant.
3. A second decisive HTTP regression proved that a staff departure and rejoin within the same second could restore an old Business grant. Both grant creation and membership start had second precision. Migration690 retains microsecond precision for future writes to those two columns. The existing clock-ordering and requalification checks now distinguish the real ordered commands. The actual organization/team/Business writers and reader exercise this boundary.
4. Review of the selected licence now shows its actual licensed fields and record count before assignment, using the existing compact Arabic/English surface.

Migration690 verifies the incoming catalog seal, preserves microsecond precision for future grant-creation and membership-start timestamps, replaces the canonical affected function definitions, closes temporary CREATE privileges and reseals the catalog. Upgrade tests compare historical values, receipts and digests before and after. Existing timestamps keep their exact stored instants.

## 3. Individual journey evidence

The guarded production-asset browser creates a real synthetic account and independently creates a Business. It uses the existing Meta OAuth and account-selection writers across fresh documents. Vendor HTTP replies and support/privacy evidence are explicitly synthetic. A real queued report job stores a finite native observation.

From Business Sources the user confirms an operating mandate, chooses the report, issues a field-bounded display licence, attaches coverage, binds the licence and reads 12.50 USD through the actual material endpoint. A committed issuance response is deliberately lost, then the same command is reconciled. The existing licence remains unique. Withdrawal subsequently denies the former material-read request with403.

The 390px Arabic viewport preserves RTL, exact money strings, currency isolation, readable purpose/operation/deadline and no horizontal overflow. Source assertions remain source assertions.

## 4. Agency browser evidence

The browser creates the client's Business with a separate advertiser identity. The agency principal creates its own organizational Workspace through the existing writer.

Only preexisting independently admitted agency context/link qualifications, independently owned Source rights and an immutable original report are synthetic prerequisites. They are explicitly labelled fixture data. The browser performs the real relationship, Source adoption, operating mandate, limited client-representative licence, explicit coverage/binding and field-filtered read. A responsibility declaration incompatible with the agency realm is denied.

A second agency principal with its own Workspace receives403 for the first client's journey and material read. Ending the original relationship denies further use. Recreating the relationship at its next revision leaves the prior context, mandate, licence and read denied. Creation or re-creation of the relationship supplies no new Source or Business ownership.

## 5. Beneficiary browser evidence

The declarant explicitly delegates Business read through the existing direct-team owner and Business data use through the finite mandate. A separate synthetic principal is selected by identity in the real Vue beneficiary control.

The test rejects an unqualified recipient, an expiry exceeding the recipient's authority and a payload attempting to substitute export. It verifies the licensed field and record-count review. A server-committed assignment response is held while the user switches from Business A to Business B. The late response does not expose A's form or result in B. Returning through the original A/read path allows explicit reconciliation of the exact original command. It creates no replacement assignment.

The beneficiary logs in independently. The UI shows the minimal assignment summary and exposes no original-data reader or issuer controls. A read of the native original without Source read and an onward assignment attempt both return403.

The issuer revokes the exact Business use child and creates a new qualified child. The old assignment does not reappear under that child. The issuer then revokes the assignment itself. The beneficiary refresh loses its summary and its material read remains denied. No Source-read right is injected into this flow.

## 6. Beneficiary consumption contract and protected decision

### Existing supported behavior

`database/sql/business_native_sources/materials.sql::material_use_plan` requires an exact independent Source-read path for each native original. The final `require_material_use` also binds a named assignment to the exact current Business use grant recorded by its qualification. Business context read admits the Business UI and this journey projection. The material API separately requires the explicit Business data-use path, the exact data licence and the independent Source-read path for a native original. Business context read alone cannot authorize spending data.

Existing registered derived owners are finite. `market_knowledge/dispatch.sql` dispatches only `market.knowledge` and `market.business_finding`. Their field reader exposes `finding` through an independently qualified output scope, strips original evidence references and rechecks continuing policy. `MarketBusinessFindingTest` covers actual D2/private publication and foreign-Business rejection. This is reusable lineage/output infrastructure, not an existing native-spend summary producer.

### Material gap

| Required item | Current evidence and proposed boundary |
|---|---|
| Affected consumer | Business Sources licensed expenditure read for a named beneficiary who lacks independent native Source read |
| Required fields | Exact already-licensed spending value, currency, unit/scale interpretation and coverage. Any period must come from the qualified original report contract. No account identifier, native namespace/key, campaign identifier, secret or raw payload |
| Current blocker | The licensed item is a native original. The material planner requires its separate Source-read grant. Assignment grants no such authority |
| Possible derived result | One same-Business expenditure projection from an explicitly licensed report, with immutable producer identity, exact input reference and field selection. No currency conversion, cross-period aggregation or inferred business outcome |
| Retention and withdrawal | Explicit finite output display scope and named recipient. Creation must have separately qualified derivation and any retain/forward obligations required by the registered producer. A display-only input licence stays display-only. Continuing input policy and exact Business/assignment are requalified on each read and replay. Withdrawal stops future delivery and in-flight publication/use according to existing locks and deadlines. Historical receipts may remain. No automatic claim of physical erasure or perpetual derived use |
| Required extension | A bounded registered producer, descriptor/field reader and continuing-policy contract using the existing registry, manifests, permits and consumer. An explicit output-use qualification bound to the original consent and beneficiary, with explicit lawful derivation/retention/delivery where needed. Historical and display-only licences are not silently expanded. No alternate native-original reader |
| Owner decision | Approve or decline this finite output semantics, the exact admitted input derivation/retention/delivery where necessary, and the authority to issue its bounded display licence. Existing original licences retain their original meaning. No such producer, storage or output authority was created in C03 |

The decision is reserved by section5 of the Owner's C03 request. Recommendation is to approve the bounded expenditure projection as a separately reviewed extension. Another valid operating policy is to keep beneficiary consumption restricted to independently Source-qualified readers. The present task cannot declare the complete beneficiary consumption journey verified while that choice remains unresolved.

## 7. Revocation and concurrency

The full affected PHP run includes actual observed Source-lock wait followed by authority withdrawal, final receipt-lock wait followed by fresh-auth expiry, Business surrender, exact original command replay, membership/agency requalification, source/Business substitution and current output/lineage policies.

The browser covers a committed assignment with a missing acknowledgement, context switching, late completion, exact-command reconciliation, grant replacement and assignment withdrawal. Broad and focused results retain their separate source manifests. No concurrency expectation, timeout, isolation level, RLS rule or permission assertion was relaxed.

## 8. Ambiguous and review-required states

Uncertain eligibility visibly blocks licence issue before any new right or request exists. The UI explains that it has not registered a legal review. SQL rejects unconfirmed declarations, incompatible agency responsibility, expired/withdrawn bases, invalid recipients and unsupported payload expansion.

The future human-review integration point is the existing qualification/consent admission boundary. A reviewer policy would need its own authorized identity, attributable evidence and explicit scope before creating a qualification through that owner. C03 introduces no reviewer role, self-approved legal status or universal queue.

## 9. Verification

The final affected PHP run passed **118 cases /3,044 assertions**, with zero failures, errors and skips. It includes OrganizationTeamTest and the same-second membership case. The final browser passed **5 cases**. The final Vue/advertiser/API-client selection passed **168 cases**. Production TypeScript/Vite, affected PHP formatting, both generated API checks, the production-asset guard and whitespace validation passed.

Final counts and exact file-state reconciliation are recorded in `storage/framework/testing/g01-c03-candidate/verification.json`. The required affected run is retained as `g01-c03-affected.log` with its JUnit and unchanged-source receipt. Vue tests are `g01-c03-js-final.log`, final production build is `g01-c03-build-final.log` and the final browser is `g01-c03-browser-final.log`. All earlier attempts remain preserved.

The core correction selection passed 3 cases /63 assertions before the full run. This is separate from final acceptance. The default premium static audit reported zero findings but its default source-root selection does not establish Vue coverage. Actual Vue mounts, production TypeScript and browser evidence govern this task.

## 10. Preserved failures and corrections

- C02 remains 85 passing /2 failing, followed by a separate4-case targeted recovery. Those records were not relabelled as a comprehensive successful run.
- C03's first PHP start failed before test execution with Windows VirtualAlloc/paging-file exhaustion. The user freed resources.
- The new agency reincarnation test then failed behaviorally with200 where403 was required. Its23 assertions and actual response are in `g01-c03-agency-red-retry.log`. Migration690 and the existing verifier correction address that defect.
- The same-second membership regression returned200 instead of403 with12 assertions in `g01-c03-membership-red.log`. It was discovered after a separate114-case passing affected run. That earlier run remains preserved and does not stand in for verification of the subsequent precision correction.
- The first extended browser attempt stopped because a name-only test selector matched two legitimate Business read paths. The corrected test selects the exact Business/read-grant/realm tuple. It preserves the path-specific restoration contract.
- The first full affected C03 run retained 113 passing /1 failing /2,937 assertions. Its failed renewed-agency case revealed the timestamp-precision issue above. It remains separate from the final complete rerun.
- Stopped disposable C02/C03 clusters were compressed and their archive inventories verified before removal of uncompressed copies. Logs, manifests, screenshots and the compressed synthetic data remain. The final C03 browser and PHP executions reuse one recorded disposable cluster sequentially, with the official historical-install/upgrade fixture before the browser and a clean test installation before the full PHP selection. No Owner/preview/production data was modified.

## 11. Remaining blockers and acceptance

**BLOCKED.** The native-original recipient contract is unresolved for a beneficiary who lacks independent Source read. The safe assignment, minimal metadata, denial, reconciliation and revocation flows are implemented and tested. The missing finite derived consumer and its explicit output authority require the Owner decision in section6. No claim of full operational-journey verification or Foundation readiness follows.

A complete new Foundation for the final accepted implementation remains reserved for the Owner. All prerequisite tests and evidence are independently recorded.

## 12. Final Foundation handoff

The prepared handoff in `storage/framework/testing/g01-c03-candidate` verifies the final source manifest and served assets before invoking the official runner. Verification-only mode is safe to run while the protected decision remains open. After resolving the material blocker and freezing the resulting candidate, the Owner may explicitly run the official Foundation.

Worktree command

```powershell
Set-Location -LiteralPath 'C:\Users\stoma\.codex\worktrees\ba-foundation-product-audit-001\builderad'
pwsh -NoProfile -File storage/framework/testing/g01-c03-candidate/Run-OwnerFoundation.ps1
```

The handoff verifies only unless `-RunFoundation` is explicitly supplied. Its execution entry remains `pwsh -NoProfile -File scripts/Test-GoalsSpa.ps1 -Phase Foundation`. Browser lifecycle proofs are recorded separately.

Requirements include frozen dependencies, sufficient Windows commit headroom, a few GiB of disk headroom for a fresh cluster/build/logs, and free guarded ports5433,6397,8136–8138. The runner refuses occupied ports before contacting any existing service, creates a token-marked disposable database with separated runtime/authority/migration roles, and stops only task-owned processes in its finally block. `GOALS_SPA_PROCESSES=STOPPED; EXIT=0` and the final source-unchanged receipt must be verified after execution. A failed or unexecuted gate remains failed or unexecuted.

At this freeze C had about290MiB free. The original Windows paging-file exhaustion and the current disk constraint remain documented resource limitations for the later comprehensive run. No load/capacity guarantee is inferred from the focused executions.

No commit, push, merge, deployment, production migration or Owner-data migration was performed.

