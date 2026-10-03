# New Commercial Market authority preparation — pre-write findings

2026-10-03. Read-only trace of the requested authority boundary. No material implementation, grant/configuration change, migration, new test process, commit, push or merge was performed for this request.

```text
CHECKPOINT_BASELINE = 9db511bdd813e49d68deacf2bec110e388094af1
CURRENT_HEAD = 9db511bdd813e49d68deacf2bec110e388094af1
CURRENT_NEW_MARKET_TARGET_AUTHORITY_MODEL = Exact UUIDv7 target embedded in a trusted Owner mandate; mandate may establish target-bounded SA01CommercialGrantAdministrator; that administrator may issue ConfigureCommercialMarket to a distinct eligible principal for the identical target
TARGET_IS_PERSISTED_ENTITY = NO — no separate prepared-target entity; UUID is persisted in the issued grant, then as the market primary key upon recording
CURRENT_TARGET_IDENTITY_GENERATION = No runtime new-market target generator exists; Owner mandate supplies target_id. Existing server UUIDv7 pattern is Str::uuid7(); tests use it for target IDs
TARGET_IDENTITY_FORMAT = UUIDv7, enforced by database constraints on commercial grants and commercial_markets
CURRENT_AUTHORIZED_PREPARER = No dynamic runtime preparer is defined; exact target approval originates in trusted Owner deployment configuration
CURRENT_GRANT_ISSUER = Current/fresh internal principal holding the exact target/workspace/version SA01CommercialGrantAdministrator grant; issuer must differ from operational grantee
CURRENT_GRANT_ISSUANCE_PATH = GrantAuthority::issueCommercialGrant → bounded authority check → eligible/current grantee → grant insert plus security audit in pgsql_authority transaction
CURRENT_OWNER_MANDATE_ROLE = Sole exceptional administrator assignment/revocation source; exact trusted configuration, bound executor/grantee generations, target, expiry and approval_reference; request selects an existing mandate, never authors it
PREPARATION_AND_GRANT_ARE_ATOMIC = NO — no combined runtime preparation operation exists. Existing grant issuance itself is transactional; Owner mandate execution is separately transactional
IF_NOT_ATOMIC_FAILURE_STATE = A preselected UUID without an operational grant is not executable. Failed grant transaction rolls back grant/audit; no prepared-target database entity is orphaned. Failed mandate execution rolls back administrator grant and execution evidence
CURRENT_TARGET_AUTHORITY_SCOPE = Exact principal + lifecycle generation + commercial action + target UUID + workspace/version tuple. ConfigureCommercialMarket has workspace_id=NULL and version_id=NULL; it is not Workspace-owned or global wildcard authority
CURRENT_CONSUMPTION_SEMANTICS = Insert market with the exact granted target UUID as PK, initial assignability, receipt and audit obligation atomically. Grant persists as historical/current authority and also supports configuration versions; it is not a disposable creation token
CURRENT_BACKEND_ALREADY_SUFFICIENT = NO for a new from-scratch UI journey requiring no configuration edits; YES for executing already-approved exact mandates and issuing their bounded operational grants
MISSING_PART = AUTHORITY_PATH decision for dynamic target preparation/approval + APPLICATION_PATH + bounded presentation, not UI_ONLY
CORRECT_UI_LOCATION = Existing Internal Administration authority area, independently gated by the approved Commercial preparation/mandate authority; no new top-level module and no self-grant in Commercial
SEPARATION_OF_DUTIES = Mandate executor != administrator grantee; operational issuer != operational grantee. Administrator authority does not inherit ConfigureCommercialMarket. Other actor overlap requires independently established exact grants; there is no universal three-distinct-person rule in current code
ORPHAN_TARGET_RISK_IN_CURRENT_MODEL = NO separate persisted target can be orphaned; ungranted UUID is non-executable. Existing unused/revoked/expired grants retain their actual lifecycle and evidence
CONCURRENT_CONSUMPTION_PROTECTION_EXISTS = YES — market primary-key uniqueness plus command advisory lock, fingerprint/replay rules and atomic writer; new journey concurrency proof has not been run in this read-only phase
ARCHITECTURAL_CONTRADICTION = NO internal contradiction identified in the existing exact-target model
UNRESOLVED_AUTHORITY_DECISION = YES — which accepted runtime evidence may authorize a previously unknown server-generated target before a target-specific administrator grant exists?
REQUEST_CONFLICT_WITH_EXISTING_AUTHORITY_CONTRACT = Dynamic target approval cannot be inferred from founder/staff.view/grant.manage/Internal access; current trusted Owner mandates predefine the exact target
NEW_COMMERCIAL_MARKET_END_TO_END_JOURNEY_COMPLETE = NO
STATUS = STOPPED BEFORE MATERIAL IMPLEMENTATION PENDING OWNER AUTHORITY DECISION
```

## Actual evidence

- `config/commercial_authority.php:5`: mandates are trusted deployment configuration, default deny, never populated from HTTP. The current tracked `owner_mandates` array is empty.
- `app/Security/GrantAuthority.php:144`: existing operational grant issuance is the sole A-003 writer; ordinary staff permissions do not inherit Commercial administrator authority.
- `app/Security/GrantAuthority.php:158`: operational self-grant/effect expansion is denied. Market effects require null Workspace/version. `requireCommercialAdministration` checks the identical target tuple, active state, generation and database-clock expiry.
- `app/Security/GrantAuthority.php:225`: exceptional Owner mandate execution requires an existing exact trusted mandate. Executor and administrator grantee must differ. Mandate execution advisory lock and fingerprint preserve deterministic replay; changed mandate content under the same ID is denied.
- `app/Security/GrantAuthority.php:324`: recipients require current lifecycle, verified email and, for staff, active personnel plus a passkey. Executors also require current protected internal authority and fresh authentication.
- `database/sql/sa01.sql:19`: the target grant requires UUIDv7. Market grants are not Workspace-owned. The operational grant unique index includes principal, effect, target, tuple and generation.
- `database/sql/sa01.sql:159`: SQL checks signed current principal context and the exact action/target/tuple grant; possession of UUID is insufficient. `builderad_sa01_require` locks and rechecks authority through effect time.
- `database/sql/sa01.sql:329`: the market writer does not generate IDs or issue grants. It inserts the granted UUID as the unique market primary key. Same command/same fingerprint replays; different payload conflicts; competing new commands for the same market hit primary-key uniqueness, mapped to HTTP 409. Failed transaction cannot leave a second market, receipt or partial obligation.
- `database/sql/sa01.sql:254`: reading the registry requires the independent ReadCommercialMarketRegistry grant (or existing permitted assignment visibility). Configure alone does not confer registry read access.
- `app/Http/Controllers/CommercialController.php:22`: page targets come from the current principal's active/current-generation grants. `newConfigurationId` generates configuration identity only, not a market authority target.
- `tests/Feature/Commercial/CommercialScopeTest.php:183`: existing test traces exact Owner mandate → separate administrator → operational grantee, denying wrong target, self-grant and administrator-effect expansion. This is prior source evidence, not a freshly executed new-journey acceptance claim.
- Current Internal Administration grants only STAFF_ACTIONS; Commercial actions are not among them. The shared Security/Devices surface does not provide Owner mandate authoring or new-market authority preparation. No dedicated `super_admin` permission was found in the inspected actor/model/authority surfaces; founder is an explicit personnel kind with bounded existing powers, not automatic Commercial administrator authority.

## Important consumed-target presentation boundary

The current Commercial selector distinguishes an unrecorded target by comparing Configure grant targets against the **readable** market list. If an operator has Configure without independent registry-read authority, an already-recorded target may still appear unrecorded in that presentation. Database uniqueness remains safe, but the desired consumed-choice/readable-market journey needs the independently authorized read grant in its bounded preparation path. Do not silently treat Configure as Read, delete historical grants, or weaken RLS to fix presentation.

## Safe failure/retry and pending proof

Operational issuance returns an existing active exact grant on retry; expired authority cannot be revived implicitly. Mandate replay requires matching fingerprint, current executor/grantee generation and current trusted mandate expiry. Revocation preserves grant/audit evidence. A generated but ungranted UUID never produces an executable Commercial choice.

There is no existing runtime target-generation operation whose identity retention, preparation retry or concurrent preparation can be honestly claimed as tested. Focused tests, runtime screenshots and build will follow implementation after the authority decision. No test suite or server was started merely to restate the established boundary.

## Smallest Owner decision

### Owner decision resolved — 2026-10-03

The Owner explicitly confirmed: “اقصد حساب المؤسس نعم؛ Founder يهيّئ ويعتمد سوقًا واحدًا، دون منح نفسه صلاحية Commercial.” This authorizes a narrow new runtime Founder approval boundary, not inherited operational Commercial authority. Implementation will retain one server-issued preparation intent on retry, atomically persist the approved UUIDv7 target and its exact administrator grant through GrantAuthority, and let that distinct administrator issue independent Configure and Registry-read grants to a distinct operator. Immutable approval evidence survives consumption; Commercial recording remains its existing separate writer. No generic grant engine, self-grant, Target Market, or operational semantic change is authorized.

UNRESOLVED_AUTHORITY_DECISION = NO (Owner resolved the decision above).
STATUS = AUTHORIZED FOR NARROW IMPLEMENTATION; prior STOPPED status describes the pre-decision trace.

The Owner asked to show the fields to the super admin. The unresolved mapping is whether that means the current active founder principal and **explicitly** authorizes a new narrow server-side Owner approval path for one generated market target. That is a new bounded authority decision, not mere field visibility; current target-specific administrator authority cannot authorize a fresh unrelated UUID.

If approved, the implementation must remain under an independently authorized Administration path, server-generate/retain one UUIDv7, establish exact authority through the approved Owner mechanism and existing GrantAuthority writer, avoid operational self-grant, issue/read only independently authorized truth, and keep Commercial recording separate. A general role/grant console remains out of scope.

Alternatively, displaying only preconfigured exact mandates preserves the current contract but does not close creation from scratch without deployment configuration work. The implementation must not label that narrower alternative as a completed end-to-end journey.

The explicit stop comes from section 14 of the Owner directive: “If an architectural contradiction or unresolved authority decision exists: STOP before material implementation and return the smallest Owner decision required.” Existing Phase A/micro-refinement changes were left intact and uncommitted. Only this report was added for the current request.
# Subsequent Owner decision — 2026-10-03

The Owner later explicitly authorized direct Founder Commercial operation without delegation, with employee delegation optional and separate. The preparation-only policy below is historical and has been superseded for Founder operational authority. See `FOUNDER_DIRECT_COMMERCIAL_REVIEW.md` for implementation and validation.
