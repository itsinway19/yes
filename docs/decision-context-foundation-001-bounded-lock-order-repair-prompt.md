BUILDERAD — DECISION CONTEXT FOUNDATION 001
BOUNDED BUSINESS-CONTEXT LOCK-ORDER REPAIR

OWNER DECISION:
APPROVED

==================================================
0. AUTHORIZATION
==================================================

The reported PostgreSQL `40P01` lock-upgrade cycle is accepted as a REAL
implementation blocker.

You are authorized to perform a BOUNDED lock-order / sequencing repair for
Business Context mutation.

This is NOT authorization to redesign Foundation authority.

The goal is:

All Business Context writers sharing `business_context_version`
must serialize through one deterministic Workspace lock order without a
FOR SHARE -> FOR UPDATE mutual-upgrade deadlock.

Preserve all accepted semantic, authority, concurrency and audit contracts.

==================================================
1. WHAT MUST REMAIN UNCHANGED
==================================================

Do NOT weaken or redefine:

- signed Workspace context
- signed human actor/session requirement
- exact `business.context.manage`
- active membership requirement
- lifecycle generation validation
- responsible-owner validity
- session expiry/revocation semantics
- grant expiry/revocation semantics
- effect-time revalidation
- Workspace isolation
- `business_context_version` as the single shared aggregate revision
- same-set / same-value authorized no-op semantics
- stale different-change conflict semantics
- atomic mutations
- audit history requirements
- no lost updates
- SQLSTATE `40001` as the approved stale-write conflict
- DTM semantics
- Business Type semantics

`40P01` remains FAILURE.

Do not add automatic deadlock retry to convert it into apparent success.

==================================================
2. REQUIRED LOCK ANALYSIS BEFORE CHANGE
==================================================

Before editing production SQL, record the actual lock sequence for the
relevant current-main paths.

At minimum inspect:

- `builderad_source_actor`
- `builderad_business_context_write`
- the proposed DTM writer
- `builderad_select_workspace_context`
- exact Business Context grant/session/lifecycle checks
- grant revocation/expiry path involved in effect-time proof
- responsible-owner / Workspace lifecycle paths that take overlapping locks

Produce a concise BEFORE lock-order map.

Confirm the demonstrated cycle that produced `40P01`.

Do not speculate from source only where the real test already provides
PostgreSQL lock evidence.

==================================================
3. REPAIR PRINCIPLE
==================================================

Establish one monotonic lock order for Business Context mutation.

The solution must ensure that two Business Context writers cannot both:

1. acquire a weaker Workspace row lock,
2. then mutually wait while upgrading that same Workspace row to `FOR UPDATE`.

The existing name/type writer and the new DTM writer must use a compatible
serialization order because they share:

`workspaces.business_context_version`

and must safely conflict/serialize with one another.

Prefer the SMALLEST repair to the Business Context mutation path.

Do not introduce:

- a generic locking framework
- a new authority resolver
- a new revision
- a new retry subsystem
- a separate DTM serialization mechanism
- a second Business Context transaction model

==================================================
4. IMPORTANT — DO NOT EDIT LANDED HISTORY
==================================================

Do NOT rewrite the landed baseline migration or historical
`database/sql/business_context.sql` payload.

Any correction to the existing canonical Business Context writer must be
installed FORWARD through the new authorized implementation migration /
forward SQL payload.

Fresh installation:

historical baseline migrations
→ new forward migration
→ repaired final function

Populated baseline upgrade:

exact baseline
→ same new forward migration
→ same repaired final function

must converge to the same final definition.

==================================================
5. PREFERRED BOUNDARY
==================================================

Prefer repairing sequencing in the Business Context mutation writers rather
than globally changing `builderad_source_actor` semantics.

A forward redefinition of the existing Business Context writer is allowed
when required to establish the shared monotonic lock order, provided its
business/authority semantics remain equivalent and are re-proven.

`builderad_source_actor` is shared authority infrastructure.

Do not broaden its locking behavior for every provider/source caller merely
to solve this Business Context mutation cycle.

If a change to shared actor-helper semantics appears necessary:

first prove why a Business-Context-local ordering repair is insufficient.

If the required shared change would materially affect source/provider
authority or locking behavior:

STOP and return that evidence before making the broader change.

A bounded backward-compatible helper adjustment is allowed only if its
effects are demonstrably limited and all affected authority/provider
regressions are included.

==================================================
6. PRE-LOCK SECURITY
==================================================

Do not solve the deadlock by allowing an unsigned/unscoped runtime caller to
lock arbitrary Workspace rows before any trustworthy context proof.

If Workspace `FOR UPDATE` must occur before the full actor helper:

reuse the existing signed-context/runtime primitives to establish the minimum
non-mutating proof necessary before acquiring the serialization lock.

Then perform the full existing actor/session/membership/grant authority checks
inside the serialized transaction.

Do NOT duplicate or reimplement the authority model.

The resulting sequence must still ensure that an unauthorized caller can
produce no Business Context effect.

==================================================
7. EFFECT-TIME AUTHORITY
==================================================

Waiting for the serialization lock must not freeze an earlier authority truth.

After every material wait required by the final lock sequence, time-sensitive
authority must still be evaluated on the database clock according to the
accepted contract.

In particular preserve proof for:

- session expiry during wait
- grant expiry during wait
- revocation committed before authority acquisition
- invalid lifecycle generation
- inactive membership
- responsible-owner invalidation where applicable

No unauthorized effect may commit.

Do not demand impossible "time travel":

if a revocation operation legitimately linearizes after an already-authorized
writer according to the final lock order, document that ordering precisely.

==================================================
8. REQUIRED REGRESSION OF THE EXISTING WRITER
==================================================

Because the repair may redefine the existing name/type Business Context
writer, prove semantic equivalence for that writer.

At minimum preserve:

- authorized name/type update
- authorized identical-value no-op
- stale different-value conflict
- exact `business.context.manage`
- authorized nonowner behavior
- unauthorized owner/no-grant denial
- revision increment exactly once for real change
- existing Business Context audit event exactly once
- no audit/revision mutation on no-op
- effect-time authority expiry/revocation behavior

Do not treat "DTM tests passed" as proof that the pre-existing writer remains
correct.

==================================================
9. REQUIRED REAL CONCURRENCY PROOF
==================================================

After the repair, rerun REAL PostgreSQL concurrency tests.

Required scenarios:

A. NAME/TYPE vs NAME/TYPE
- simultaneous material writes
- deterministic serialization
- no `40P01`
- no lost update
- stale loser receives approved conflict where applicable

B. DTM vs DTM — EQUAL
- one actual transition
- one revision increment
- one DTM event
- both obtain the accepted current result
- no `40P01`

C. DTM vs DTM — DIFFERENT
- one winning whole set
- one approved `40001`
- no partial set
- no `40P01`

D. NAME/TYPE vs DTM
- same Workspace serialization point
- one writer cannot erase the other's fact
- shared revision behaves exactly as specified
- stale material loser conflicts
- test BOTH winning schedules
- include synchronized entry, not only pre-ordered execution
- no `40P01`

E. AUTHORITY CHANGE DURING WAIT
- real lock overlap
- accepted linearization
- no unauthorized committed effect

Use actual independent PostgreSQL connections/processes.

Retain:
- backend PIDs
- blocking relationship
- lock graph/evidence
- resulting SQLSTATEs
- before/after state
- revision/event counts

Arbitrary sleeps are not proof.

==================================================
10. PROOF MUST NOT BE WEAKENED
==================================================

Do NOT make the test green by:

- removing simultaneous entry
- scheduling writers so they never materially overlap
- replacing DB concurrency with mocks
- accepting `40P01`
- accepting lock timeout
- broad retry
- weakening result assertions
- testing only final rows while ignoring revision/audit
- skipping authority-expiry overlap
- dropping the mixed name/type ↔ DTM race

The purpose is to eliminate the real lock cycle, not avoid reproducing it.

==================================================
11. REQUIRED LOCAL GATE AFTER REPAIR
==================================================

Before resuming the normal 13-task plan, run the complete affected focused
matrix.

At minimum:

- BusinessFoundationTest
- existing Business Context authority tests
- Workspace isolation relevant tests
- repaired name/type writer tests
- DTM writer database tests if already present
- real concurrency suite
- effect-time authority tests

Report exact:

tests
assertions
failures
errors
skips
SQLSTATE outcomes

The previously observed `40P01` scenario must be rerun and explicitly PASS
with the approved semantic outcome.

If any affected Foundation behavior regresses:
STOP.

==================================================
12. PLAN CONTINUATION
==================================================

If and only if the bounded lock repair and affected proof PASS:

LOCK_ORDER_REPAIR = PROVEN

then resume the approved implementation plan from the earliest incomplete
task and continue through Tasks 2–13 without another Owner approval.

Do not restart discovery/spec/planning.

The approved DTM spec and plan remain governing.

==================================================
13. DOCUMENTATION
==================================================

Update:

`docs/decision-context-foundation-001-implementation-proof.md`

with:

- original failing scenario
- original `40P01`
- BEFORE lock graph/order
- exact bounded repair
- AFTER lock graph/order
- why authority semantics are unchanged
- why shared revision semantics are unchanged
- exact affected files
- exact affected tests
- actual post-repair results

Explicitly classify this as:

BOUNDED_FOUNDATION_LOCK_ORDER_REPAIR

not a Business Foundation redesign.

==================================================
14. SOURCE CONTROL / DATABASE SAFETY
==================================================

DO NOT COMMIT.
DO NOT PUSH.
DO NOT MERGE.

DO NOT CONNECT TO OR MODIFY PRIMARY BUILDERAD.

Continue to use task-owned disposable environments only.

Stop all task-owned processes after each proof phase.

==================================================
15. STOP CONDITIONS
==================================================

STOP again only if:

- removing the deadlock requires changing the authority meaning itself
- shared `builderad_source_actor` semantics must materially change across
  provider/source operations
- one monotonic Business Context lock order cannot be established without a
  wider Foundation redesign
- effect-time authority cannot be preserved
- `business_context_version` semantics must change
- audit semantics must change
- real overlap still yields `40P01`/unclassified deadlock after the bounded
  repair
- a HIGH/CRITICAL regression appears

Return the smallest exact Owner decision required.

==================================================
16. REPAIR HANDOFF
==================================================

After the repair gate, report:

LOCK_ORDER_BLOCKER =
CONFIRMED

ORIGINAL_SQLSTATE =
40P01

ROOT_CAUSE =
...

REPAIR_SCOPE =
...

HISTORICAL_MIGRATIONS_EDITED =
NO expected

SHARED_ACTOR_SEMANTICS_CHANGED =
NO expected

BUSINESS_CONTEXT_AUTHORITY_SEMANTICS_CHANGED =
NO expected

BUSINESS_CONTEXT_VERSION_SEMANTICS_CHANGED =
NO expected

AUDIT_SEMANTICS_CHANGED =
NO expected

NAME_TYPE_WRITER_EQUIVALENCE =
PASS / FAIL

NAME_TYPE_CONCURRENCY =
PASS / FAIL

DTM_EQUAL_CONCURRENCY =
PASS / FAIL / NOT_YET_APPLICABLE

DTM_DIFFERENT_CONCURRENCY =
PASS / FAIL / NOT_YET_APPLICABLE

MIXED_CONTEXT_CONCURRENCY =
PASS / FAIL / NOT_YET_APPLICABLE

EFFECT_TIME_AUTHORITY =
PASS / FAIL

DEADLOCK_40P01_PRESENT =
NO expected

AFFECTED_FOCUSED_TESTS =
exact counts

PRIMARY_BUILDERAD_CONNECTIONS =
0 expected

PRIMARY_BUILDERAD_WRITES =
0 expected

COMMIT =
NO

PUSH =
NO

MERGE =
NO

SAFE_TO_RESUME_APPROVED_PLAN =
YES / NO

If YES:
resume Tasks 2–13 automatically.

Do not stop for another Owner approval.