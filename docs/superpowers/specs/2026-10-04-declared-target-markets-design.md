# Decision Context Foundation 001 — Declared Target Markets

Date: 2026-10-04. Status: written architectural specification for Owner review; implementation is not authorized by this document.

## 1. Authority, scope and accepted baseline

Authoritative baseline: `8ebd9a1f1ffd7b23307d277304203c706e67d95d`.

Worktree: `C:\dev\builderad-worktrees\decision-context-discovery-001`.

Branch: `codex/decision-context-discovery-001`.

Governing preflight: [decision-context-foundation-001-preflight.md](../../decision-context-foundation-001-preflight.md). The Owner approved its architecture and supplied the binding Written Specification Gate clarifications. Those clarifications take precedence over the preflight wherever they make a contract more precise.

Business identity, Workspace isolation, Business Context, Business Type foundation, advertiser type surface, internal type administration, existing context mutation authority and Source Guidance linkage are accepted complete for DTM. This specification preserves them. No repair or redesign of these foundations is included. A direct material contradiction discovered during later implementation must be reported before expanding scope.

Baseline Foundation CI passed: https://github.com/42Huss/builderad/actions/runs/37189260851. Local preflight tests did not start because Composer dependencies were absent; zero executed tests is not a passing result. This document specifies future proof, not completed implementation or verification.

Only this formal documentation artifact is created in this work package. No product source, migrations, routes, controllers, Vue components or tests are changed. No commit, push or merge is permitted. Do not invoke writing-plans until the Owner reviews and approves this written specification.

## 2. Purpose and product meaning

Builderad needs the minimum facts that materially change outcome interpretation, waste diagnosis or the recommended next action. The governing principle remains: ask less, learn more, and use context only when it changes a decision.

Declared Target Markets (DTM) means **the countries from which this Business explicitly says it wants customers or business outcomes**. It is an optional, editable, user-declared Business-level set with cardinality 0..N. This slice stores country identity only. It makes no claim about economic attractiveness, present campaign configuration, observed results or recommended targeting.

Business Type, Business Location, DTM, Goal, campaign geography, result geography, research-supported hypotheses and discovered opportunities remain independent facts even when their values coincide. A real-estate business located in Jordan may declare Saudi Arabia. That declaration neither relocates the business nor establishes Saudi campaign targeting, superior results or an opportunity recommendation.

No location is required to declare a market. Business Type cannot produce geography, and geography cannot produce a provider preference. Declared intent may differ from future observations or learned views; disagreement never authorizes rewriting intent.

## 3. Canonical ownership and system boundaries

| Fact or boundary | Contract |
| --- | --- |
| DTM semantic owner | Existing Business Context domain, represented by `App\Business\BusinessContext`. |
| Physical Business identity | Existing `workspaces.id`; no new Business identity or parallel profile. |
| Country identity owner | Neutral reference data; owns country codes and localized labels only. |
| Commercial | Retains its current ownership; no DTM FK, availability filter, country synchronization or mutation dependency. |
| Provider/campaign/result evidence | Retains existing evidence meaning; no dependency or write authority over DTM. |
| Business Type / Source Guidance | Preserved as independent canonical facts and guidance. Their read, edit, internal-admin and Provider identity contracts remain intact. |
| Declaration state | Derived from the canonical persisted DTM relation set; never separately persisted. |

Use the existing Business Context aggregate/read-write boundaries. The relationship table below is its explicit child relation, not another Workspace-level source of market intent.

## 4. Neutral country reference contract

Use a neutral table named `country_references`, with `code` as the primary key and nonempty `name_ar` and `name_en` as localized display metadata. `code` is a two-character uppercase ISO 3166-1 alpha-2 identity; uniqueness and canonical membership come from the reference rows, not from a shape check alone.

The initial reference dataset is checked in and versioned. Its country-code membership is controlled only by the accepted canonical identity snapshot of all officially assigned ISO 3166-1 alpha-2 entries at implementation time, including their standard territory entries. Reserved, user-assigned and region shortcut codes are excluded. GCC, Gulf and MENA are not country identities. The UI may say "countries"; inclusion is an identity fact, not a market eligibility policy.

Country identity provenance and localization provenance are independent concerns. Identity provenance records the accepted identity source, its relevant snapshot/revision date, the exact canonical identity count and a deterministic digest of identity content only. Localization provenance records the English-label source/revision where distinct, the Arabic-label source/revision and deterministic localization-content evidence/digest. The checked-in dataset may combine the accepted identity source with accepted localization sources; one source is not required to authoritatively govern both code membership and display names.

The later implementation plan must identify and verify the actual accepted sources and record each obligation separately:

| Plan evidence field | Required content |
| --- | --- |
| `IDENTITY_SOURCE` | Accepted source governing canonical ISO code membership, with a traceable source reference. |
| `IDENTITY_SNAPSHOT_OR_REVISION` | Identifiable identity snapshot/revision and relevant source/snapshot/revision date. |
| `IDENTITY_COUNT` | Exact number of canonical identities in that snapshot. |
| `IDENTITY_DIGEST` | Deterministic identity-content digest covering canonical code membership only, excluding labels. |
| `ENGLISH_LABEL_SOURCE` | Accepted English display-label source; explicitly state if it is the same source as English identity naming. |
| `ENGLISH_LABEL_REVISION` | Identifiable English-label revision/snapshot and relevant date. |
| `ARABIC_LABEL_SOURCE` | Accepted Arabic display-label source with traceable provenance; do not claim ISO authority unless that source establishes it. |
| `ARABIC_LABEL_REVISION` | Identifiable Arabic-label revision/snapshot and relevant date. |
| `LOCALIZATION_DIGEST_OR_EQUIVALENT` | Deterministic evidence covering the accepted code-to-English/Arabic-label content, independently of the identity digest. |

Record the digest algorithm, canonical ordering/serialization and any text normalization rules so identity and localization evidence can be reproduced separately. A label-only correction must leave the identity count/digest unchanged while updating the applicable localization provenance/evidence. Verify membership against the accepted identity snapshot and labels against their respective accepted localization sources before installation. Source selection belongs to the later implementation plan; no undocumented dataset is selected merely to complete this specification. Keep this evidence with the checked-in dataset; it adds no database fields, tables, localization service, runtime network lookup, country-admin subsystem or second registry.

Localized labels are presentation metadata. Corrections to Arabic names, English display names, spelling, translation or localization metadata must never create/delete a country identity, remap a country code, change/create a DTM declaration or reinterpret Business intent. They must preserve the declared relation set and the meaning of existing DTM audit events. Identity changes and label changes are different classes of maintenance. Do not automatically delete or remap a referenced code when a later standard snapshot changes; existing declared identities must round-trip and preserve history. Such reference maintenance is outside this slice and is not a reason to build a country-admin module.

The catalog is independent of `commercial_market_countries` and `config/commercial.php`. Existing Commercial code strings can coincide with ISO identities without becoming the owner of this catalog. Do not change Commercial modeling or restrict DTM to the seven Commercial countries.

Reference rows imply none of: market intent, Commercial availability, provider support, campaign or result geography, opportunity, intelligence or recommendation eligibility. Do not add currency, provider, scoring, supported-market or assignability attributes.

Runtime can read the reference metadata needed for the product. It cannot insert, update or delete reference rows. Installation uses the established migration role; there is no advertiser or internal-admin reference mutation API.

## 5. Physical persistence and integrity

The selected persistence design is:

| Table | Required fields and constraints | Meaning |
| --- | --- | --- |
| `country_references` | `code` primary key, canonical uppercase two-character constraint, `name_ar`, `name_en` | Neutral country identity and display reference. |
| `business_target_markets` | `workspace_id` FK to `workspaces.id`, `country_code` FK to `country_references.code`, composite primary key `(workspace_id, country_code)` | One current declared country per physical Business; no other source of current DTM truth. |
| `business_target_market_events` | Identity primary key; Workspace and actor-user FKs; positive `version`; canonical `before_codes`, `after_codes`; `occurred_at`; unique `(workspace_id, version)` | Immutable audit of actual DTM set transitions, using the shared context revision. |

Use restrictive deletion for referenced Business/country/actor identities, matching the existing protected Business Context history convention. No destructive Business deletion or retention policy is introduced. The set relation's leading Workspace key supports scoped reads and replacement without a second tenant key.

The audit sets are concrete text arrays of canonical country codes, sorted and duplicate-free; they contain no arbitrary context metadata. Events require different before/after sets and a positive resulting revision. They are not queried as a second current-state store. Retained country references preserve audit code interpretation. Historical events cannot be edited or deleted by runtime callers.

Do not add declaration-state booleans, ranks, weights, primary/secondary markers, expansion status, a polymorphic owner, generic scope columns or a location field. Lexicographic ordering in persistence/read results is deterministic presentation of a set, not market priority.

An empty `business_target_markets` set means `NOT_DECLARED / UNKNOWN`. It never means an explicit instruction to target no countries. Removing the last row returns to that same meaning. The initial empty state requires no event or placeholder row.

## 6. Dedicated explicit write contract

Add one DTM-specific set-replacement operation inside `BusinessContext`, served by the existing Business Context controller and `WorkspaceContext`. It is separate from the current name/type write, so neither form can accidentally replace the other's facts.

The proposed advertiser route is `POST /workspaces/{workspace}/business/target-markets`, named `workspaces.business.target-markets.update`, in the existing authenticated/current-principal route group with a UUID selector and normal session/CSRF protections. It uses the same established mutation authentication requirements as the current context edit; it creates no new step-up or authority policy.

Request fields are exactly:

| Field | Contract |
| --- | --- |
| `country_codes` | Required list of uppercase canonical reference codes; may be `[]`; at most the installed reference count in submitted entries. Each member is a string of exactly two canonical characters and must exist in the neutral reference. |
| `version` | Required nonnegative integer: expected shared `business_context_version` from the canonical projection. |

Missing, null, associative/object-shaped lists, invalid or unknown codes are rejected without mutation. Repeated valid codes are accepted and collapsed to one canonical entry. Array order is ignored. Lowercase/localized names, region names and inferred country substitutes are rejected. No client-supplied actor, owner, permission, declared boolean or different Workspace identifier participates in the write. Validation and canonicalization are repeated in the protected database boundary, not trusted solely to PHP.

The dedicated writer is `builderad_business_target_markets_write(p_workspace, p_session, p_codes, p_version)`, using a typed code array and returning the resulting shared revision. Its PHP adapter takes the Workspace resolved by `WorkspaceContext`; it must not accept a foreign physical owner from the request body.

Successful Inertia submission redirects to the existing Business Context edit page so both the authoritative set and revision are refreshed. Errors are attached to `country_codes` or `version`, never incorrectly reported as a Business Type error. Authorization denial remains forbidden; invalid input remains a validation error; genuine stale-change conflict requires reload/review and explicit resubmission. Do not silently retry a replacement over newer intent. Unexpected storage errors are not disguised as success.

### 6.1 Authority and transactional sequence

Reuse the existing signed Workspace/session proof and exact `business.context.manage` action. An active authorized nonowner may edit. Being the owner without a current exact grant is insufficient. `business.manage` is internal configuration authority and does not authorize advertiser declarations. Provider/service identities receive no DTM mutation authority.

The operation owns no nested transaction: `WorkspaceContext::run` supplies the existing outer transaction. Within its protected database writer:

1. Verify the runtime caller and existing signed human actor/session against the selected Workspace using the existing actor proof.
2. Lock that Workspace row for update, sharing the same serialization point as name/type writes. Require the resolved active Workspace to exist; invalid/cross-Workspace calls are denied even if the desired set is unchanged.
3. Lock and verify the actor's exact current Workspace-scoped management grant, current lifecycle generation, membership/session validity and existing owner validity using the established authority mechanisms. Match current grant status, revocation and expiry checks; do not implement an alternate resolver.
4. Validate all codes against the neutral reference and canonicalize desired and persisted sets as sorted unique arrays while protected by the Workspace lock. Reference identities are not mutable through runtime.
5. If the sets are equal, return the actual current context revision after current authority validation. Do not change relation rows, timestamps, context revision or audit history. This mirrors the current name/type writer's same-value-before-version-check behavior.
6. For a different set, require exact equality between `version` and the locked Workspace's shared context revision. Otherwise fail without side effects.
7. Replace the set atomically, increment `business_context_version` by exactly one, update the Workspace context timestamp consistently with the existing writer, and insert exactly one DTM audit event with canonical before/after sets and the resulting revision.
8. Revalidate existing actor/session and live management authority before returning the effect, matching the existing writer's grant-expiry protection. Any failure rolls back relation changes, revision and audit together.

No intermediate empty or partially inserted set is observable as a committed state. A denied no-op is still denied. An unchanged set may return success with an older expected revision because it overwrites nothing; the returned revision is always current. A differing stale set is always rejected. This is an explicit compatibility rule, not permission to ignore concurrency.

### 6.2 Shared revision and audit behavior

Retain `workspaces.business_context_version` as the one aggregate concurrency token. DTM must not add a competing context revision. Current `business_context_events` is specifically shaped around name/type transitions, including a nonnull new type; do not fabricate name/type events to represent a DTM change.

A DTM transition records its own bounded audit event using the same resulting revision. A name/type transition continues recording the existing event shape. Both writers lock the same Workspace, so one actual change consumes one revision; revisions belonging to DTM may be absent from the name/type event table and vice versa. No caller may assume either individual event table contains every aggregate revision. Do not create a generic event framework or change the existing event payload.

Preserve the original writer's authorization, type selection, no-op and optimistic concurrency behavior. Its requests must never read from or write to DTM rows. Audit metadata is the only new history; actor identity remains the existing human user identity.

For concurrent different replacements from revision V, one obtains the lock and commits V+1; the other sees a mismatch and fails. For concurrent identical replacements, the second sees the committed set, returns V+1 without another event, and creates no duplicates. A concurrent name/type change serializes the same way: a stale differing DTM replacement conflicts rather than discarding the newer context revision. Failed writes leave the entire prior set and all unrelated facts intact.

## 7. Security, isolation and non-overwrite proof

Enable and force RLS on the two Business-owned DTM tables. Runtime SELECT policies use the existing signed Workspace-phase context to expose only that Workspace's rows/events, consistent with current Business Context history. Read visibility still requires the existing active membership, current session/lifecycle and `workspace.view` path; RLS isolation is not a substitute for those checks. Neutral reference reads carry no advertiser intent and need no Workspace ownership.

Only the established migration/owner role has installation privileges. `builderad_app` receives scoped SELECT and execution of the protected writer, not direct INSERT/UPDATE/DELETE/TRUNCATE rights on either DTM table. The protected function follows the existing SECURITY DEFINER owner/search-path/revoke/grant conventions. PUBLIC and unrelated authority/service execution access is denied. Granting execution to the app role is insufficient to bypass the signed human session and exact grant proof.

The prohibition on implicit overwrites applies to every current runtime application path, including privileged application connections and synchronization jobs. Later proof must inventory actual references to the DTM tables/writer and inspect executable grants, RLS policies and function definitions: the only intended runtime mutation entry is the explicit advertiser Business Context operation. Migration/DB-owner privileges are administrative capabilities, not an advertiser, service or inference authority; migration code must not create declarations for existing Businesses.

DTM must never be populated or replaced from Business Location, Workspace metadata, currency, billing/provider country, Commercial country, IP/device/browser location, connected providers, campaign configuration/history, conversions, observed results, research, learned signals or discovered opportunities. Reads never synchronize writes. No trigger, listener, job, provider adapter or Commercial operation may perform that synchronization.

The DTM writer changes only relation rows, its audit event and necessary context revision/timestamp metadata. It does not change name/type, independent location facts, Commercial scopes/configuration, source registry, provider credentials/connections or campaign/evidence state. Independence is proven both by state-preservation assertions and by inspection/testing of write access and actual synchronization entry points; absence of a feature alone is not sufficient proof.

## 8. Canonical advertiser read model

Extend `BusinessContext::projection` without changing the meaning of `type`, `version` or `canManage`:

| Field | Meaning |
| --- | --- |
| `targetMarketsDeclared` | Boolean derived exclusively from whether scoped persisted DTM relations exist. Never a column, request field, cached independent authority or user-set flag. |
| `targetMarkets` | Deterministically code-sorted list of objects `{ code, name_ar, name_en }` joined from the canonical scoped relation and neutral reference. |

Zero rows produces `targetMarketsDeclared = false` and `targetMarkets = []`. One or more rows produces true with exactly those countries. Localized names are presentation; the round-trip mutation sends `code`. Do not rebuild membership from names or geography signals.

The context editing GET must use this same projection for current DTM and version, adding the neutral `countryOptions` catalog for the picker. `WorkspaceController` already consumes the Business projection for the advertiser Business surface; it receives the same DTM meaning. Frontend types must accept the additive projection and display its explicit semantic flag, rather than independently determining whether the Business has declared intent from array length.

Read-only members see the existing Business/type context and DTM declaration summary, including the localized unknown state. This is a bounded read presentation within the accepted context surface, not a new permission or internal-admin module. Provider/source guidance and source availability are unaffected.

## 9. Advertiser UX contract

Use the existing Business Context / "نشاطي" flow. Preserve current Business Name/Type creation requirements and the current classification gate. DTM is not a prerequisite for creation, provider connection, returning-user access or context completion.

Show the optional DTM editor once the physical Business exists, including after normal creation through the existing edit path. Do not add DTM fields to the atomic creation operation, auto-open a mandatory step or build an onboarding wizard. Returning users reach it through the existing edit activity navigation. The ordinary Business summary displays saved declarations or the canonical not-declared state.

Question: **"أين تريد الحصول على عملائك؟"**

Supporting copy: **"اختر الدول التي تريد الحصول منها على عملائك أو نتائجك."**

The interaction contract is:

- Search a compact country list by Arabic/English label or canonical code; search changes visible options only.
- Select multiple countries, with selected values visibly retained even when the search changes; remove each with a named accessible control.
- Use canonical codes as keys and form values. No defaults from locale, Commercial country or currency; selection is never automatic.
- Provide a separate DTM Save action. Only that explicit submit replaces the declared set; search, selecting/removing locally, blur, navigation and loading never persist.
- "ليس الآن" discards unsaved DTM edits and navigates within the existing advertiser flow without calling the mutation endpoint. It neither declares anything nor clears a persisted set. Saving an explicitly emptied selection is the sole UI way to remove the final declared country.
- A member without management authority sees the saved declaration/unknown summary and no active mutation controls. UI capability follows `canManage`; backend enforcement remains mandatory.
- Display field-specific invalid-country/conflict errors and a success state only after server success. Preserve local draft on failure; resolve conflicts through refreshed canonical context and explicit user review, never automatic overwrite.

Keep name/type and DTM forms independent. Successful context responses refresh the shared version before a subsequent submit; a stale draft from another tab must safely conflict when it represents a different set. Unsaved unrelated form values are not posted by DTM Save or skip.

Arabic/English labels, error/status copy and the unknown state use the existing localization conventions. Arabic/RTL/mobile are acceptance requirements: logical layout spacing, wrapping selected values, no horizontal overflow at a 320px viewport, keyboard search/selection/removal, visible focus and properly labeled controls. Use native checkbox/list semantics or an equivalently accessible multi-select; announce save/errors through appropriate status/alert roles. Render country codes in an isolated LTR span when shown in RTL text.

Avoid a large country-card wall, geographic ad-set controls, Ads Manager vocabulary, country scoring, suggestions and hierarchy/ranking language. DTM is desired customer/outcome origin, not Business Location. No location editor is added.

## 10. Migration and data durability

Use a new additive forward migration after the authoritative baseline's final migration, with checked-in DTM SQL/reference data following existing repository conventions. Do not edit previously landed migrations or their SQL payloads to insert DTM into historical installation steps.

Install the country reference, relation, audit storage, protected writer and necessary grants/policies. Initial reference insertion is reference installation only: it creates zero Business declarations and zero DTM audit events. Do not backfill, inspect geography to produce defaults, revise existing context versions or change any historical Business/source/Commercial record.

Fresh installation and populated exact-baseline forward installation must yield equivalent schema, function/grant and RLS contracts, and separately reproduce the same accepted identity catalog/count/digest and localization snapshot/evidence. Existing Businesses with no DTM remain valid and read `NOT_DECLARED`. Historical memberships, context events, Business Types, Commercial and provider evidence survive. Any failed migration must not leave usable partial authority or fabricated intent.

Follow the established protected disposable reset mechanism. Do not introduce destructive rollback that erases live declared intent/history; forward repair is the default existing standard. Disposable reset durability is a separate test-only capability guarded by the existing isolation mechanism.

Never connect to, migrate, write, reset or modify the primary Builderad database. Database verification later runs only through accepted task-owned disposable installations and guards. Stop every task-started database/server/helper process after testing; retain useful results/logs according to existing harness behavior.

## 11. Semantic acceptance contract A–Q

These are required future implementation checks, not results of this documentation task.

| Case | Scenario | Required observable proof |
| --- | --- | --- |
| A | Valid Business with zero DTM | No onboarding/access invalidation; no declaration rows; projection false/empty. |
| B | Business without independent location declares SA | Authorized save and read round-trip succeed; no location requirement or synthesized location. |
| C | Business located in Jordan declares SA | JO location and SA intended market retain different meanings; declaration never changes or reads location to choose its set. See the evidence limitation below. |
| D | Declare SA + AE + KW | Three unique canonical relations and exact code-sorted round-trip `AE, KW, SA`; no rank meaning. |
| E | Duplicate Business + country | Composite key rejects direct duplicate storage; repeated request values yield one relation. |
| F | Business A caller attempts mutation of B | Denied without relation/revision/event changes; include foreign selected Workspace and forged request-owner attempts. |
| G | Unauthorized caller | Missing, revoked, expired or wrong-generation management grant, inactive membership/session and invalid signed scope deny changes; unchanged-set calls do not bypass authority. |
| H | Authorized existing nonowner manager | Active membership plus exact existing grants/session permits save; neither owner role nor internal `business.manage` substitutes for that proof. |
| I | Remove a market, including final country | Only declared set and necessary audit/revision metadata change; compare type/name, independent location where present, Commercial and provider configuration/evidence before/after. Final removal reads false/empty. |
| J | Set/change Commercial country | Neither initial creation nor subsequent Commercial mutation populates or replaces DTM; test with empty and populated declared sets. |
| K | Import/change provider/campaign geography | Neither observed/imported geography nor existing callback/report/synchronization paths populate or replace DTM; test empty and populated sets. |
| L | Populated baseline migration | Existing Businesses acquire zero declaration/event rows, preserve historical data and remain valid. |
| M | Canonical identity round-trip | Codes survive create/read/reorder/remove and language changes; labels are independent; unknown and noncountry region codes are rejected in PHP and protected SQL calls. |
| N | Arabic/RTL interaction | Search, multi-select, visible values, remove, Save, skip, read-only state, errors, focus and mobile layout work through rendered interaction. Skip leaves prior persisted intent unchanged. |
| O | Deterministic/idempotent mutation | Reorder/duplicate values and identical retries are no-ops; exact same stale-set retry returns current revision; concurrent equal/different sets and name/type writes obey section 6 with atomic audit and no lost update. |
| P | Observed/derived/discovered/provider/Commercial signals | Inspect every actual DTM write reference and database execution policy; direct runtime DML and service/unsigned SQL writer calls are denied; exercise existing signal paths and show no declaration overwrite. No future intelligence layer is implemented merely to test it. |
| Q | Empty persisted set | Projection means `NOT_DECLARED`, including after final removal; no explicit-none meaning or persisted declaration boolean. |

Case C evidence limitation: the accepted preflight found no independently persisted Business Location in the current Business boundary. Preserve the Jordan-versus-Saudi example as a semantic contract and demonstrate the DTM operation has no location input, read dependency or output mutation. Any scenario fixture representing an independent Jordan location must be explicitly identified as test-only and must not become a production field or be confused with Commercial/provider geography. Do not claim a persisted Business Location integration test passed when that capability is absent. If an independent location is found within the allowed boundary during implementation, exercise the exact JO/SA scenario without redesigning it. Absence of location is not a blocker for B or for this DTM design.

Include rollback proof for invalid countries, stale different sets and authority expiry: no partial relation set, consumed revision or misleading event may remain after failure. Inspect audit immutability and read isolation as well as mutation denial.

## 12. Durability and regression proof contract

Dependencies must be available before implementation verification can be credited. The preflight's missing `vendor/autoload.php` is a setup limitation, not test success. Use locked dependencies and the accepted guarded disposable harness; no primary-database connection is permitted even for diagnostics.

| Proof | Acceptance evidence |
| --- | --- |
| Identity reproducibility | Reproduce canonical code membership from the accepted identity source/snapshot; verify exact identity count and deterministic identity-only digest using its recorded algorithm/serialization. |
| Localization traceability | Reproduce or trace every English/Arabic display label to its accepted source/revision; verify the recorded deterministic localization-content evidence independently of identity membership. |
| Label-only preservation | Using controlled disposable fixtures under the existing migration/owner mechanism, change only presentation labels/metadata and verify unchanged country codes, identity count/digest, `business_target_markets`, DTM events/audit meaning, context revision and Business intent. Localization evidence changes as appropriate. No runtime maintenance API or product capability is added to exercise this check. |
| EMPTY -> implementation HEAD | Independent empty database installs all migrations/reference data; record final migration inventory, accepted identity count/digest, localization snapshot/evidence and schema/authority checks. |
| CURRENT AUTHORITATIVE MAIN POPULATED -> implementation HEAD | Install the exact baseline SHA's schema/SQL, populate representative historical Businesses/context, memberships, Commercial and provider evidence, record before snapshots, then apply only new forward migrations. Preserve facts and obtain zero fabricated DTM rows/events. |
| Historical preservation | Compare canonical historical keys/values, existing declarations of other facts and relationships; counts alone are insufficient. New optional read fields must not invalidate the existing records. |
| Fresh/forward equivalence | Compare final tables/columns/constraints/indexes/RLS/policies/functions/grants; separately compare the accepted identity catalog/count/digest and accepted localization snapshot/evidence, excluding runtime fixture data and incidental sequence positions. |
| Repeated protected disposable reset | Two successive guarded resets/reinstalls succeed, reproduce the accepted identity catalog and localization snapshot/evidence separately and leave no stale DTM data; non-disposable reset remains denied. |
| N1 independent installation | A second independently initialized disposable database with its own installation/isolation marker succeeds, without reusing the first catalog or data. Resetting the same database alone is not N1. |
| Full PHP regression | Complete relevant repository PHP matrix, including Business Foundation/Type/authority, Commercial, provider evidence, OAuth/connections, Source Guidance, internal Admin and RLS/database authority, plus DTM checks. Retain exact executed/pass/fail/skip counts and commands. |
| Full JS regression | Existing complete JS regression plus DTM interaction coverage; retain exact counts and commands. Rendered Arabic/RTL/mobile checks supplement assertions on source or markup. |
| Frontend typecheck/build | Run the established build/typecheck chain and retain each outcome; current `npm run build` runs `vue-tsc` then Vite. |
| Dependency audits | Existing Composer validation/audit and npm audit gate, with exact severity/outcome evidence; do not silently waive failures or update unrelated dependencies. |

The existing `MigrationDurabilityProof.php` starts at historical migration `000010`. Keep its value, but it alone cannot satisfy the explicitly required populated authoritative-current-main proof. Obtain the exact baseline payload read-only without resetting this worktree, importing later implementation SQL or creating another worktree merely for convenience.

For every required proof retain command, isolated target, exact result/counts, relevant logs and material warnings/skips. A skip or unexecuted setup-dependent proof is not PASS. Implementation may be declared proven only after required evidence actually runs. Report any remaining limitation precisely rather than treating source inspection as full regression success.

## 13. Future compatibility and explicit non-goals

Current Business-level DTM always means the Business's declared desired country markets. Future Goal-specific intent may use its own separate canonical relation; it need not migrate, replace or reinterpret this relation's ownership/meaning. No present Goal schema, scope discriminator, polymorphism or fallback rule is specified.

The Decision Context compatibility map in the approved preflight remains semantic/governance context only. Business Location, Goal, Audience / Customer Role, Outcome / Conversion Path, Customer Relationship, Deal / Order Economics, Capacity / Inventory, Sales Cycle, Qualification Definition and Seasonality remain deferred. Their probable scopes and decision relevance do not introduce tables, migrations, APIs, services, validation rules, UX or acceptance tests for those capabilities.

Preserve Business Type and Source Guidance. Do not create a generic DecisionContext base class, registry, interface, persistence abstraction, EAV store, dynamic schema, rule DSL, questionnaire engine, market-scope framework or geography engine. There is no parallel Business Profile, new Provider abstraction, authorization resolver or country-admin bureaucracy.

This slice does not collect age, gender, interests, devices, placements, detailed targeting, retargeting/lookalikes or ad-set settings as Business Context. It does not implement market scoring, Saudi-specific rules, research, suggestions, opportunity detection, provider preference, budget allocation or comparison to configured/result geography. INTENDED is implemented later under this spec; CONFIGURED, OBSERVED, ECONOMIC, DISCOVERED and ACTION retain independent future meanings.

Stop before expanding scope if the accepted foundations are materially contradicted, authority cannot safely own the operation, neutral country identity requires cross-domain redesign, DTM requires changing Commercial semantics/new cross-domain writes, canonical ownership becomes ambiguous, deferred Goal/Audience work becomes necessary or a new high/critical defect appears. Historical overlap in code values alone is not a reason to stop or redesign. Report the smallest exact Owner decision needed.

## 14. Spec self-review and handoff

Self-review result: **PASS — documentation review only**.

| Review | Result |
| --- | --- |
| Placeholder scan | No unresolved placeholders or incomplete contracts. Actual identity/localization sources and revisions are separate required implementation-plan evidence fields, not undocumented dataset choices or permission to use arbitrary codes. |
| Internal consistency | One persisted current set; derived declaration flag; one shared context revision; dedicated audit events; authorized no-op precedes stale-change check; separate forms do not erase each other; no Commercial/provider coupling. Only the accepted identity snapshot governs code membership; label corrections leave identities, declarations and audit meaning unchanged. |
| Scope check | Detailed design is limited to DTM/reference identity and integration with accepted boundaries; all deferred concepts remain semantic compatibility only. Provenance evidence adds no data-model change, runtime service or maintenance capability. |
| Ambiguity check | Explicit missing-vs-empty input, duplicate/case behavior, same-set stale retry, differing-set conflict, audit revision gaps, skip behavior, post-creation optional UX, location-proof limitation and exact-baseline/N1 evidence. Identity and localization source/revision/digest obligations are independent; English sources may coincide if stated explicitly; Arabic ISO authority is not assumed. |

Material clarifications from the approved direction:

1. The declaration boolean is projection-only. Empty is unknown, including after deliberate final removal.
2. DTM uses the existing aggregate revision and a bounded DTM transition audit table; existing name/type events are preserved without fabricated changes.
3. Canonical duplicate/reordered sets are no-ops, including authorized same-set stale retries; stale different sets must conflict. Authorization always applies.
4. The editor is optional in the existing persisted-Business context flow; skip never submits, and creation requirements stay unchanged.
5. Country identity covers the neutral standard snapshot, not the Commercial subset. Identity provenance/count/digest and English/Arabic localization provenance/evidence are explicitly separate. Label corrections cannot create/delete/remap identities or change/create declarations, audit meaning or Business intent; fresh and forward installations reproduce both accepted snapshots independently.
6. Case C cannot be reported as a live persisted-location test on a baseline with no such fact. Exact-baseline forward migration and N1 each require distinct actual proof.

| Required handoff field | State |
| --- | --- |
| BASELINE | `8ebd9a1f1ffd7b23307d277304203c706e67d95d` |
| BRANCH | `codex/decision-context-discovery-001` |
| WORKTREE | `C:\dev\builderad-worktrees\decision-context-discovery-001` |
| PREFLIGHT_DESIGN | APPROVED |
| BUSINESS_TYPE_FOUNDATION | ACCEPTED_COMPLETE_FOR_DTM |
| SOURCE_GUIDANCE | ACCEPTED_COMPLETE_FOR_DTM |
| DTM_SEMANTIC_OWNER | BUSINESS_CONTEXT |
| PHYSICAL_BUSINESS_IDENTITY | WORKSPACE |
| COUNTRY_REFERENCE | NEUTRAL_ISO_REFERENCE |
| COMMERCIAL_COUPLING | NONE |
| AUTHORITY_MODEL | EXISTING_BUSINESS_CONTEXT_AUTHORITY |
| EMPTY_SET_SEMANTICS | NOT_DECLARED |
| PERSISTED_DECLARED_BOOLEAN | NO |
| GENERIC_DECISION_CONTEXT_FRAMEWORK | NO |
| GOAL_IMPLEMENTED | NO |
| AUDIENCE_IMPLEMENTED | NO |
| MARKET_OPPORTUNITY_IMPLEMENTED | NO |
| SPEC_PATH | `docs/superpowers/specs/2026-10-04-declared-target-markets-design.md` |
| SPEC_SELF_REVIEW | PASS |
| IMPLEMENTATION_STARTED | NO |
| WRITING_PLANS_INVOKED | NO |

STOP FOR OWNER SPEC REVIEW. Only after explicit Owner approval of this written specification may writing-plans begin. The Owner's no-commit/no-push/no-merge constraint remains in force.
