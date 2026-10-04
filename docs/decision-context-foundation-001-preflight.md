# Decision Context Foundation 001 — preflight and proposed design

Status: preflight only; Declared Target Markets has not been implemented.

## Verified execution prerequisites

- Worktree: `C:\dev\builderad-worktrees\decision-context-discovery-001`.
- Branch: `codex/decision-context-discovery-001`.
- HEAD and authoritative baseline: `8ebd9a1f1ffd7b23307d277304203c706e67d95d`.
- Initial worktree: clean. No reset or discarded changes.
- Merged-main Foundation CI: success, completed on this exact SHA.
- CI evidence: https://github.com/42Huss/builderad/actions/runs/37189260851.

## CURRENT_SYSTEM_STATE_MAP

Here COMPLETE means the inspected implementation exists. Local execution evidence is recorded separately below; this is not a claim that the requested full regression matrix has run.

| Boundary | Classification | Evidence and implication |
| --- | --- | --- |
| Business identity | COMPLETE | Current Business Context is attached to `workspaces`; there is no separate Business model in the inspected boundary. Reuse that canonical identity. |
| Workspace isolation | COMPLETE | `WorkspaceContext::run` owns the transaction and verifies current lifecycle/session, active membership, owner validity and exact `workspace.view`; signed selection establishes the RLS scope. |
| Business Context | COMPLETE | `BusinessContext` provides the projection and write adapter; `builderad_business_context_write` owns authorized, locked, versioned database mutation. |
| Business Type foundation | COMPLETE | FK on `workspaces.business_type_id`, reference taxonomy, active/inactive semantics, versioned context events and protected configuration. |
| Advertiser read/edit | COMPLETE | `Business/Context.vue` edits name/type; `Business/Sources.vue` displays the declared type and links to edit; controller resolves the Workspace scope. |
| Internal-admin type management | COMPLETE | `InternalBusinessTypeController`, `Internal/BusinessTypes.vue` and the type editor support existing type/guidance/prior operations. Routes require `business.manage`; mutations additionally require fresh authentication and a protected internal writer. |
| Business Context mutation authority | COMPLETE | Existing exact `business.context.manage` grant plus the existing signed Workspace/session checks. An authorized nonowner is supported; ownership alone is insufficient. |
| Source Guidance linkage | COMPLETE | Guidance is keyed by Business Type and canonical `Provider` enum keys. The projection orders source guidance and filters approved priors; it does not mutate provider configuration. No additional provider registry is introduced. |
| General country reference identity | MISSING | No independent general country reference was found in the inspected Business/Commercial schema and models. |
| Commercial country representation | COMPLETE | `commercial_market_countries` and `config/commercial.php` use seven ISO-shaped country codes tied to Commercial operations. These are not a complete neutral country catalog and cannot own advertiser intent. |
| Provider/campaign geography | PARTIAL | X report handling recognizes provider country/segmentation fields. Such evidence retains provider-report meaning and does not establish declared Business intent. No generic configured-geography layer is proposed. |
| Business Location | MISSING | No independent Business Location field found in the inspected Business, Workspace models and migrations. Currency/timezone/Commercial country must not substitute for it. |
| Declared Target Markets | MISSING | No current declared-market persistence, mutation or projection in the inspected boundary. |

No inspected Business Type/Source Guidance gap currently requires repair to integrate DTM. This does not certify unrelated admin or presentation behavior.

The landed creation flow requires Business Name and Business Type. Existing unclassified Workspaces are sent to context completion. DTM must remain optional and must not change either rule.

## Proposed architectural choice

Implement the advertiser's explicit country-level desired customer/outcome markets as one optional Business-owned set. Business Context remains the semantic owner; the existing Workspace row remains its physical Business identity.

Recommended representation: a small neutral ISO 3166-1 alpha-2 country reference table and one `business_target_markets` relation keyed by `(workspace_id, country_code)`, with FKs to the current Business identity and the country reference. Country labels are localized independently from code identity. Reference data includes all supported standard country identities, independent of Commercial availability. No currency or Commercial market association is added.

Alternatives considered:

1. Store the set in a JSON/array Workspace column: fewer tables, but weaker per-country FK integrity and less explicit relational uniqueness. Not recommended.
2. Reference Commercial Market rows: reuses an existing table but makes intent depend on Commercial availability and imports the wrong ownership. Rejected by the Owner's requirements.
3. Use the explicit relation and neutral country reference above: small, clear ownership with database-enforced integrity. Recommended.

Extend the existing Business Context boundary with a dedicated explicit set-replacement operation. Reuse `WorkspaceContext`, signed actor verification and exact `business.context.manage`; do not reuse `business.manage` or invent a permission. Protect reads with the established signed Workspace RLS scope; deny direct runtime writes and provider/service mutation paths. Serialize mutations using the existing Workspace lock and context revision, with audited before/after sets and no-op/idempotent behavior for the same canonical set. Preserve existing name/type writes and their event contract; they must never erase market declarations.

Expose `targetMarketsDeclared` and canonical country values through `BusinessContext::projection` and the existing context editing surface. Zero rows means `NOT_DECLARED`; there is no explicit-none state. Sorting is deterministic and has no ranking meaning. No inferred defaults or historical backfill are allowed.

Use the existing advertiser context page for country search, multiple selection, visible selections, removal, Save and "ليس الآن". The same surface remains usable in Arabic, RTL and mobile. Read-only members can see declared context through the accepted read boundary. Skipping must not persist a new meaning or alter existing declarations. Country options are not drawn from Commercial market availability.

DTM writes affect declared intent and its necessary revision/audit metadata only. No Business Type, Business Location, Commercial, provider, campaign, observed-result, research or recommendation mutation is part of the operation. Future Goal-specific declarations can be a separate relation; the present Business-level meaning will remain unchanged.

## Verification required after implementation

- Required semantic cases A–Q from the Owner's brief, including nonowner explicit authority, revoked authority, cross-Workspace denial, direct runtime/service write denial, FK/unique integrity, multi-market deterministic round trips, empty semantics and no inferred values.
- UI interaction verification for search, selection, removal, Save, skip and read-only rendering in Arabic/RTL/mobile.
- Disposable `EMPTY -> HEAD`, populated exact authoritative baseline `-> HEAD`, historical data preservation, no fabricated rows, repeated protected reset and independent N1 installation.
- The existing durability proof starts from historical migration `000010`; that proof alone does not establish the specifically requested populated current-main baseline upgrade. Add a focused exact-baseline proof.
- Full PHP and JS regressions, frontend typecheck/build and dependency audits with exact results retained. Primary Builderad database must not be used.

## Decision Context compatibility map

Deferred owners/scopes below are semantic candidates, not approved future implementation designs. No deferred schemas, APIs or UX are specified.

Decision abbreviations: O = outcome interpretation; W = waste diagnosis; A = recommended action.

| Name | Semantic meaning | Likely scope | Canonical owner / domain | Durability | Declared / observed / derived | Ask / learn / import | User-editable? | Can learned view differ? | Decisions | Implement now / defer |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Business Type | What kind of business this is | Business | Business Context; taxonomy managed internally | Durable | Declared | Ask | Yes, existing authority | Yes, without rewriting declaration | O/W/A | Preserve existing |
| Declared Target Markets | Countries the advertiser wants customers/outcomes from | Business | Business Context | Durable, editable | Declared | Ask optionally | Yes, existing context authority | Yes, without rewriting declaration | O/W/A | Implement now |
| Business Location | Where the business independently operates/is located | Business | Business Context, if accepted | Durable | Declared or independently imported | Ask/import only when relevant | Likely yes for declared truth | Yes | O/W/A | Defer |
| Goal | Current desired business result | Current intent | Goal / Current Intent | Changeable | Declared | Ask | Yes | Yes | O/W/A | Defer |
| Audience / Customer Role | Whose role matters for the current desired result | Goal/current intent | Goal / Current Intent | Intent-specific | Declared; observations separate | Ask/learn when relevant | Yes for declared intent | Yes | O/W/A | Defer |
| Outcome / Conversion Path | Desired outcome and how it is reached | Goal/current intent | Goal / Current Intent; evidence remains separate | Intent-specific | Declared; observed path separate | Ask/import/learn | Yes for declared intent | Yes | O/W/A | Defer |
| Customer Relationship | Relationship relevant to interpreting value | Conditional Business/Goal context | Business Context / Current Intent, unresolved | Conditional | Declared or observed | Ask/learn/import when relevant | Yes for declarations | Yes | O/W/A | Defer |
| Deal / Order Economics | Economic value/cost facts relevant to a result | Conditional business/outcome | Business economics context, distinct from Builderad Commercial | Changeable | Declared/imported; derived view separate | Ask/import/learn when justified | Yes for declared facts | Yes | O/W/A | Defer |
| Capacity / Inventory | What the business can currently fulfill | Conditional business/outcome | Business operating context | Time-sensitive | Declared or imported | Ask/import only when relevant | Yes for declarations | Yes | W/A | Defer |
| Sales Cycle | Time/path from interest to valuable business result | Conditional business/goal | Business / outcome interpretation context | Conditional | Declared or observed/derived | Ask/learn/import | Yes for declarations | Yes | O/W/A | Defer |
| Qualification Definition | What makes a result commercially relevant | Conditional goal/outcome | Business / Current Intent | Editable definition | Declared; qualification evidence separate | Ask; learn/import evidence | Yes for definition | Yes | O/W/A | Defer |
| Seasonality | Time dependence that changes expected outcomes | Conditional business/market/time | Business context; evidence ownership separate | Recurring/changeable | Declared or observed/derived | Learn/import; ask only when relevant | Yes for declarations | Yes | O/W/A | Defer |

## Local preflight execution evidence

Attempted `scripts/Test-BusinessFoundation.ps1 -Filter BusinessFoundationTest` using a fresh task-owned PostgreSQL cluster on port 5433. The first attempt was blocked by sandbox local TCP restrictions; its server was stopped and data cleaned. The permitted retry reached database provisioning, but the disposable database guard could not load `vendor/autoload.php`: this worktree has no installed Composer dependencies. No test or migration started. Executed tests: 0; local Business Foundation verification remains unverified. No implementation regression, migration durability or DTM semantic result is claimed.

Both task PostgreSQL instances were stopped and their temporary database data cleaned by the harness; logs were retained under `storage/framework/testing`. No task-owned server remains running.

No commit, push, merge or primary-database mutation is authorized or performed. The only tracked-surface change is this preflight report.
