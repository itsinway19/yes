# Current Intent Bounded Implementation Plan

> **For agentic workers:** After Owner authorizes implementation, use `superpowers:executing-plans` task by task; use `superpowers:subagent-driven-development` only if delegation is authorized. Steps use checkboxes. This document authorizes neither execution nor delegation.

**Goal:** Implement the accepted optional, business-wide Current Intent declaration inside existing Business Context, with confirmed Desired Outcome Meaning, coherent reads and immutable semantic transition history.

**Architecture:** Store the complete nullable declaration on the existing Workspace row and append a bounded before/after journal in the same transaction. Reuse the existing prepared Business Context mutation boundary, exact grant and shared revision. BusinessContext composes transport/projection; Desired Outcome Meaning remains a distinct confirmed value inside the declaration, without a separate entity or subsystem.

**Tech Stack:** Existing PHP 8.5, Laravel 13, PostgreSQL 18, Inertia, Vue 3, TypeScript, Node 24, PHPUnit and the repository's Vue/Playwright interaction proof patterns. No dependency additions.

**Spec:** `docs/superpowers/specs/2026-10-04-current-goal-intent-design.md` (accepted corrected design; SHA256 `3A881365BAA0EC34B2336B5055008BF0597F7AC4F799E14995550E17E5857200`). Discovery `docs/current-goal-intent-discovery-001.md` is historical evidence, not authority to override the corrected design.

## Global Constraints

- Planning only. The only artifact created in this phase is this plan. Do not implement, commit, push, merge, migrate or connect to primary Builderad.
- Authoritative baseline: `2cf51ea337b26e07bfcaff59ed9d3e81c632a50e`; branch `codex/current-goal-intent-discovery-001`; worktree `C:\dev\builderad-worktrees\current-goal-intent-discovery-001`.
- Discovery and semantic design are closed. Do not reopen them or modify their files.
- Workspace is physical identity; zero or one current declaration; absence is `NOT_DECLARED / UNKNOWN`, with no onboarding gate or inferred/default declaration.
- Reuse `business.context.manage`, `WorkspaceContext::runBusinessMutation`, prepared Business Context entry, and `business_context_version`. No new authority, revision domain, lock namespace or Foundation lock-order changes.
- `BUSINESS_WIDE_ONLY` first slice, explicitly confirmed. Narrow scopes are rejected/deferred; future narrower support is deferred, not ruled out permanently.
- Explicit bounded direction, descriptive prose, distinct confirmed outcome meaning, optional positive count and optional confirmed local business deadline/timezone. No provider/observed-result inference.
- Conservative equality; authorized identical stale requests can no-op; material stale requests conflict; no silent retries or rebases. One material bundle transition increments the shared revision once.
- History preserves full before/after meaning and audit provenance. Reads expose one committed bundle and its shared revision.
- Tests use a newly provisioned disposable PostgreSQL cluster and guarded synthetic fixtures only. Track and stop task-owned processes and remove only verified task-owned temporary data; preserve proof logs.
- Apply the cheapest affected checks after each increment; run the integrated broader gate once unless subsequent failures/changes justify repeating it. No promised passing counts.

## Review Focus

1. JSON `null`, SQL NULL, empty objects/arrays and partial patches must not manufacture a declaration or erase optional values accidentally: Task 1 database tests and Task 4 HTTP tests.
2. Large count strings, zero, fractions and outcome changes carrying old quantities must retain exact meaning and require reconfirmation; technical field/command bounds reject rather than truncate: Tasks 1, 2, 4 and 5.
3. Static date structure must remain separate from timezone-dependent civil validity; exposed UI zones must be accepted by the PostgreSQL writer: Task 1 static CHECKs, Task 2 write-time validation, Task 4 exhaustive set proof and Task 5 confirmation; no scheduler is introduced.
4. Redirect props arriving after a later writer, or user typing during an in-flight save, must not mix value/version or rebase the newer dirty draft: Tasks 3 and 5.
5. An audit-insert wait can outlive authority even after an earlier authorization check; rollback must cover value, history, revision and timestamp: Task 2, then integrated Task 6.

## 1. Repository facts that fix implementation locations

These are localized planning observations at the baseline, not new semantic decisions.

| Existing surface | Relevant fact | Planning consequence |
|---|---|---|
| `app/Models/Workspace.php` | Physical identity already exists; no Current Intent field or domain model. | Add the column through migration, not mass assignment or an Eloquent writer. |
| `app/Business/BusinessContext.php` | Owns Business Context marshalling, country replacement and composed projection. | Add one narrow declaration write adapter and compose its explicit nested projection here; no new PHP service/repository. |
| `app/Foundation/Tenancy/WorkspaceContext.php` | Owns outer transaction, signed principal, active membership/owner/view checks and prepared mutation entry. Re-reads Workspace after selection. | All writes enter `runBusinessMutation`; all reads remain within `run`. Do not modify this boundary. |
| `database/sql/business_context_lock_order.sql` | Existing prepared HMAC proof and `builderad.business.context.` advisory serialization precede selector/row locks. | New writer calls `builderad_business_context_require_prepared`; never acquires a second advisory lock or calls the ordinary selector before prepared entry. |
| `database/sql/declared_target_markets.sql` | Existing writer locks Workspace and exact grant, checks authority after waits, compares current before stale rejection, updates shared revision and immutable events. | Follow this bounded transactional pattern. Do not replace either existing writer. |
| `database/migrations/0001_01_01_000330_create_declared_target_markets.php` | Latest migration installs repository SQL and refuses destructive rollback. | Add one forward-only `000340` migration; do not rewrite old migrations. |
| `app/Http/Controllers/BusinessContextController.php`, `routes/web.php` | Existing edit page, prepared mutations and bilingual conflict handling. DTM preserves JSON list/object shape before associative decoding. | One sibling POST, one controller method, coherent edit props; reject malformed shapes explicitly. |
| `resources/js/Pages/Business/Context.vue`, `Components/Business/TargetMarketsEditor.vue` | Separate form baselines and version watchers already exist. `savedContextVersion` has existing name-save behavior; `marketsSaved` also updates name-form version. | Integrate a third editor without broadcasting Intent saves through those existing callbacks; prove dirty draft behavior with actual components. |
| `resources/js/localization/business.ts`, `Components/LanguageSwitcher.vue` | Existing ar/en copy and locale navigation with preserved state on Context. | Extend Business copy; preserve LanguageSwitcher/layout behavior. |
| Workspace timezone migrations `000010`, `000060` | Storage defaults/inserts UTC; no advertiser-confirmed business-deadline authority established. | Never derive horizon timezone from Workspace. |
| `tests/Feature/DeclaredTargetMarketsConcurrencyTest.php`, `tests/TargetMarketsCommandWorker.php` | Real process/connection races, `pg_blocking_pids`, synthetic audit-wait triggers and expiry/revocation schedules. | Reuse proof techniques, with a focused Intent worker/suite; no sleep-only or mocked-lock evidence. |
| `tests/Support/DeclaredTargetMarketsDurabilityProof.php`, `scripts/Test-DeclaredTargetMarkets.ps1` | DTM proof and runner explicitly pin older baseline `8ebd9a1f1ffd7b23307d277304203c706e67d95d`. | Do not relabel/reuse them as an Intent baseline proof. Add a bounded runner/proof pinned to the authorized baseline. |
| `tests/target-markets-interaction.test.mjs`, `tests/Support/declared-target-markets-browser.mjs` | Compile actual Vue SFCs; real browser proof uses guarded synthetic authenticated fixtures. | Add actual-component Intent interactions and bounded real browser verification; update existing harness import mapping for the new component. |
| `package.json`, `scripts/builderad.ps1`, `tests/TestCase.php` | `npm test` is the existing client aggregate; runtime wrapper and test setup guard disposable databases. | Extend `test`, retain wrappers/guards, do not run unguarded artisan tests or install dependencies. |

## 2. Persistence choice

Only the three plausible minimal repository-native options were considered.

| Option | Coherent current projection | Interpretability/atomicity | Durability/concurrency | Cost and decision |
|---|---|---|---|---|
| Nullable complete JSONB on Workspace + one before/after journal | One row/statement gives complete bundle plus shared revision. | Full values preserve historical meaning; row update, event and revision share one transaction. | Existing Workspace serialization and immutable-event/RLS pattern suffice. | One column + one table; selected. No separate current row or join lifecycle. |
| One current declaration table + one journal | Requires joining current row to Workspace/version; can be coherent in one SELECT. | Equally capable, but adds current-row creation/deletion/absence handling. | Extra row/FK/check surface without independent identity or lifecycle. | Two new tables; no correctness reason requires the extra table. Rejected. |
| Journal only; latest entry is current | Requires latest-event lookup together with Workspace revision; unrelated shared changes still need Workspace. | Full after-values can reconstruct state, but current reads become journal-dependent. | Immutability possible; same serialization still required. | Saves a column but makes ordinary current reads/history selection inseparable. No need for event sourcing. Rejected. |

The chosen journal is an audit record, not a domain Event Sourcing mechanism, Goal identity, Outcome identity or timeline. Separate semantic ownership does not require separate storage units.

### Exact canonical value

`workspaces.current_intent jsonb NULL`, initially SQL NULL for all existing and new Workspaces. SQL NULL is absence; JSON `null`, `{}`, `[]` and incomplete objects are invalid present declarations. A complete present value has exactly these keys (nullable keys are present):

```text
{
  direction: "increase_value" | "preserve_value" |
             "improve_efficiency_preserving_value" | "validate_business_direction",
  scope: "business_wide",
  description: string | null,
  outcome: { meaning: nonempty string, clarification: nonempty string | null,
             count_unit: nonempty string | null },
  quantity: positive decimal-integer string | null,
  horizon: { deadline_date: "YYYY-MM-DD", timezone: IANA-name-or-UTC } | null
}
```

- Direction tokens are a fixed first-slice allowlist in SQL/HTTP/UI; no dynamic registry, taxonomy or inference. Localized labels are presentation only.
- `scope` is a literal validated value, not a subject owner. Reject all other scopes and `subject_type`, `subject_id`, market/provider/offer/project slots; never coerce them to business-wide. No fake “all” record.
- Description is optional advertiser-readable confirmed prose. Outcome `meaning` and conditional `clarification` are separate semantic fields. Neither receives provider data or machine-derived acceptance.
- Quantity is represented as digit text end to end, avoiding PHP/JavaScript floating-point loss. Enforce the technical digit-length bound below BEFORE normalizing leading zeroes, then require a nonzero digit sequence; reject signs, decimal points, exponent notation, booleans and numeric JSON coercion. No business quantity ceiling is asserted; input representation is technically bounded. A quantity requires a confirmed `count_unit`; a unit can remain without a target. Absence is NULL, never zero or progress.
- Normalize only boundary ASCII whitespace in prose, and empty optional text to NULL. Preserve internal whitespace, line breaks, punctuation, letter case and Unicode; no paraphrase, synonyms or linguistic normalization. JSON key order is immaterial. SQL normalization is authoritative; the browser must not invent additional equality.
- Horizon stores a concrete date and an explicitly confirmed IANA zone or UTC. Static validation checks only ASCII `YYYY-MM-DD`, year 0001–9999, month/day ranges and Gregorian leap-year arithmetic derived from those digits; no date parser influenced by DateStyle, timezone, locale or tzdata participates in durable CHECKs. Recognized timezone and timezone-dependent existence of that civil date are separate controlled write-time checks. Reject abbreviations, offset-only guesses and unsupported names there; keep confirmed names without silently replacing aliases.
- The date covers its whole local day; its meaning ends exclusively at the next local midnight, not after 86,400 seconds. This slice stores/displays that meaning; it does not add a scheduler, expiration transition or observed-window calculation. Past dates remain valid declarations.
- Minimum UX offers a concrete date picker and explicit timezone selection/confirmation, without a relative-days shortcut. Raw “30 days” is not canonical input. If a later authorized UI adds such a shortcut, it must resolve/display/confirm a concrete date before submission; retries here simply retain the submitted date.
- Confirmation flags are transient command evidence, not canonical fields, stable identities or extra revision values. Labels, UI progress, provider metadata, status and observations are excluded from the bundle.

### F1 — Durable invariants versus controlled write-time facts

Durable CHECKs on current and historical bundles depend only on the stored row: JSON object structure/exact keys/types, fixed direction/scope allowlists, required/null relationships, canonical quantity lexical form, deterministic Gregorian date structure, timezone string lexical form/length and the technical bounds below. The static normalizer is genuinely row-intrinsic and IMMUTABLE; it performs no catalog lookup, timezone conversion, current-time access or environment-dependent calendar reasoning. In particular, a structurally valid but unrecognized timezone can satisfy a static CHECK; the writer denies its introduction or use in a materially changed horizon, but preserves an already accepted horizon carried exactly unchanged from serialized current state. CHECKs do not certify environmental validity.

Only the controlled writer checks recognized timezone names and whether the selected local date exists under the supported PostgreSQL tzdata, and only for newly introduced or materially changed horizon semantics. Its write-time horizon validator may be STABLE; it is never called by a CHECK, generated column, expression index, immutable wrapper or history/read validator. Static validation applies to every complete submitted bundle. Compare the canonical submitted date/timezone pair with the pair in the serialized CURRENT declaration: an exactly unchanged accepted horizon is carried without current catalog/tzdata revalidation, both for an authorized whole-bundle no-op and when quantity, direction or another field changes. Authority, technical bounds, conservative equality and stale-material checks still apply fully. Historical/current projection returns the confirmed date/name unchanged without rerunning environmental validation.

Introducing a nonnull horizon where current has none, or materially changing either date or timezone, requires full current PostgreSQL support/civil-date validation of the new pair and explicit horizon confirmation. Removing a horizon requires no catalog lookup. Reintroducing a previously historical horizon after clear/removal is a NEW introduction, not unchanged carryover. No client flag, draft baseline, edit history or old event can bypass this decision; only exact comparison against serialized current state determines carryover.

The smallest shared support predicate is `builderad_current_intent_timezone_supported(p_timezone text) RETURNS boolean`, STABLE, using exact PostgreSQL catalog membership and the full-name/UTC acceptance policy: UTC or a recognized IANA-style named path, excluding `posix/` and `right/` catalog variants, abbreviations and offset expressions, within the static lexical/length budget. The writer's write-time validator and the server-side option query use this SAME predicate. `BusinessContext::currentIntentTimezones()` intersects PHP's named IANA candidates plus UTC with names for which that PostgreSQL predicate returns true in the active supported runtime; PHP is only a presentation candidate filter, never the acceptance authority. Do not assume the catalogs match, copy a cached timezone registry or add a subsystem.

Proof in Task 4 must enumerate the actual list emitted by `edit`, assert uniqueness/nonempty list/UTC, and submit a valid independently confirmed full bundle through the actual PostgreSQL writer for EVERY option using a fixed ordinary reference date (`2026-10-05`), guarded fixtures and rolled-back per-option transactions. All must succeed. Separately assert date-specific rejection for a skipped date such as `Pacific/Apia` on `2011-12-30`; acceptance of a timezone option does not promise every civil date exists. Include a deliberately mismatched candidate in the option-filter proof and assert exclusion by the PostgreSQL predicate; the runtime catalogs need not actually differ for this negative proof. Assert equality of the actual emitted set to the supported PHP-candidate intersection. A runtime/catalog change requires rerunning this gate. A stale UI option is rejected when introducing/changing a horizon, rather than substituted; an exactly carried confirmed current pair remains usable. No runtime proof was executed during this planning correction.

### F3 — Adjustable technical input/resource limits

These limits bound request processing, repeated journal storage and exact string comparison. They are implementation safety budgets, not a Goal taxonomy, business quantity policy or required verbosity. Reject oversize input; never truncate it or normalize an oversized raw field into an accepted one.

| Input | Concrete bound | Repository evidence or safety rationale |
|---|---|---|
| `description` | Optional, at most 2,000 Unicode code points | Reuse the existing bounded Business Context explanatory-prose budget: `database/sql/business_context.sql:31–32` limits applicability/limits to 2,000. A short declaration needs no larger first-slice text/storage allowance. |
| `outcome.meaning` | Required nonempty after boundary trim, at most 2,000 code points | Same existing Business Context explanatory-prose convention; permits the confirmed business result without an unbounded definition document. |
| `outcome.clarification` | Optional, at most 2,000 code points | Same convention; enough for acceptance-changing conditions while bounding each immutable copy. No forced minimum beyond nonempty when required. |
| `outcome.count_unit` | Optional nonempty label, at most 120 code points | Existing human-readable Business type labels use 120 (`database/sql/business_context.sql:5–6`); Business name HTTP/UI also use 120 (`BusinessContextController.php:52,90`, `Context.vue:41`). A result-unit label uses that short-label budget rather than an essay. |
| `quantity` | At most 120 ASCII digits, including leading zeroes before normalization | No existing quantity-length convention was found in the inspected owner. Reuse the 120-character short-scalar budget above as a conservative adjustable technical representation limit: it admits the accepted 100-count example and exact counts far beyond practical first-slice use, without arbitrary-precision numeric conversion, while preventing a huge digit payload from bloating each before/after event. This does not assert a business maximum of 120 or introduce a business ceiling. |
| `horizon.timezone` | At most 120 ASCII characters, full-name lexical form or UTC | Same bounded short-scalar budget; PostgreSQL-filtered UI options must satisfy it. Date is exactly 10 ASCII characters; direction/scope and confirmation keys are fixed. No unbounded environment-dependent string slot remains. |
| Declaration value | At most 40,960 UTF-8 bytes in its PostgreSQL JSONB text representation, checked before feature normalization and again on the canonical result | Three 2,000-code-point prose fields + 120-code-point unit = 6,120 code points. Worst JSON escaping uses at most 6 bytes per code point in PostgreSQL UTF-8 JSONB output (36,720 bytes); digit/zone/fixed keys and JSON syntax fit within the remaining 4,240 bytes. This explicit budget bounds each journal value while admitting all simultaneous field maxima. Task 1 proves that calculation with worst escaping; do not silently lower the per-field contract if it fails. |
| Command transport | At most 81,920 raw UTF-8 bytes for HTTP body; SQL checks `octet_length(p_value::text)` (NULL counts as zero) + `octet_length(p_confirmations::text)` ≤ 81,920 before semantic normalization | JSON clients may encode a non-BMP code point as a 12-byte surrogate-pair escape: 6,120 × 12 = 73,440 bytes. The remaining 8,480 bytes cover fixed fields/boolean confirmations/digit and timezone escapes and modest transport formatting. This admits every field maximum even in escaped HTTP JSON while rejecting padding/extra-key floods. SQL's parsed-JSON budget is separately defined because the writer cannot see raw HTTP whitespace; it bounds the supplied JSON arguments without pretending to limit parsing of an already typed database argument. |

Length means Unicode code points, not graphemes, UTF-16 code units or UTF-8 bytes, except the explicitly ASCII/byte limits. Enforce raw field length before boundary-whitespace/leading-zero normalization and the canonical length afterward; empty optional text can still normalize to NULL within its budget. The bounds apply equally to stored current/history static validation; transient confirmation shape is exact and size-bounded by the controlled writer. Fixed Gregorian date/zone lexical validation remains catalog-independent.

Enforcement contract: SQL uses `char_length` and `octet_length` with explicit UTF-8 supported-runtime proof; HTTP uses UTF-8 code-point length (`mb_strlen(..., 'UTF-8')`) and raw body byte length before this feature's JSON decode, and mirrors digit/shape limits. UI uses `Array.from(text).length`, ASCII digit checks and `TextEncoder` for its serialized command bytes. Do not use native `maxlength` as the authority because it counts UTF-16 units and can silently restrict pasted text differently. Show a localized counter/error, retain the complete oversized draft and disable submit until corrected; server/SQL remain authoritative even if UI checks are bypassed. No substring clipping, rounding or silent truncation.

Canonical JSON byte budgeting is authoritative in SQL. HTTP/UI per-field and command checks must admit every valid field-boundary bundle; neither estimates PostgreSQL JSON formatting as exact. SQL remains the final byte-budget guard, with a localized error returned to the retained draft. These adjustable technical limits require coordinated SQL/HTTP/UI changes and boundary regression proof if changed; no semantic redesign is implied.

### Bounded journal

`business_current_intent_events`:

| Column | Contract |
|---|---|
| `id` | Repository-style bigint generated identity audit key; not an Intent/Outcome public identity. |
| `workspace_id` | Existing Workspace FK, `ON DELETE RESTRICT`. |
| `actor_user_id` | Verified current actor FK to users, `ON DELETE RESTRICT`; never client supplied. |
| `before_version`, `after_version` | Shared revision values, nonnegative before and after = before + 1; unique `(workspace_id, after_version)`. Gaps between Intent events are legal when name/type/DTM changes. |
| `before_value`, `after_value` | Nullable full canonical JSONB bundles; SQL NULL records absence. Both satisfy static row-intrinsic validation/bounds and are distinct using `IS DISTINCT FROM`; historical CHECKs do not rerun timezone-catalog validation. |
| `occurred_at` | Server-recorded transition/audit time captured just before journal insertion after preceding waits/checks, used for event and Workspace `updated_at`; a post-insert authority failure rolls both back. Neither a commit timestamp nor concurrency/linearization ordering authority. Ordering is the serialized mutation order and shared `before_version`/`after_version`/`business_context_version`, even if clocks repeat or move backward. |

Enable/FORCE RLS on the journal; owner policy and runtime SELECT policy use existing signed workspace scope. Revoke PUBLIC/runtime/service/authority direct mutation and sequence rights; allow only scoped runtime SELECT and the controlled writer. Attach Current-Intent-local `builderad_current_intent_event_immutable()` BEFORE UPDATE OR DELETE. Refuse migration `down`; no prune job, DELETE/UPDATE API or timeline UI. Existing privileged migration administration can change schemas, as in landed DTM; the durability claim concerns supported runtime/ordinary DML paths, not immunity to a database superuser dropping a table. Do not “repair” unrelated historical event tables in this task.

**F2 ownership decision:** Inspected `database/sql/sa01.sql:141–143`: `builderad_sa01_immutable()` is an unconditional trigger exception, with `SET search_path = public, pg_temp`, SQLSTATE `23514`, and literal message `SA-01 immutable historical fact`. It has no payload or authority lookup, but its observable error message is SA01-specific. Existing DTM reuse (`database/sql/declared_target_markets.sql:37–38`) does not remove that error-contract coupling. Therefore reuse is not selected. Define the smallest local trigger in `current_intent.sql`: no arguments/payload inspection, unconditional UPDATE/DELETE rejection with SQLSTATE `23514` and `Current Intent journal is immutable`; fixed safe search path, no new permission or SECURITY DEFINER elevation. Attach it only to the new journal. Do not modify/generalize SA01, DTM or Foundation helpers, and do not create a generic framework.

## 3. SQL command, authority and equality

New functions in `database/sql/current_intent.sql`:

- `builderad_current_intent_normalize(p_value jsonb) RETURNS jsonb`: IMMUTABLE static validator/canonicalizer only; SQL NULL returns NULL, malformed/oversized values raise `22023`. Validate exact keys/types, direction/scope, quantity/unit, technical bounds, deterministic Gregorian date structure and timezone lexical structure only. Use it in writer and canonical shape CHECKs on current/history values. Fixed safe search path, no catalog/tzdata/environment access or generic validation framework.
- `builderad_current_intent_timezone_supported(p_timezone text) RETURNS boolean`: STABLE PostgreSQL support predicate shared by the writer and option query, never a durable CHECK dependency. Exact recognized full-name/UTC acceptance; bounded lexical form; no implicit timezone. Permit runtime use for the option query without granting declaration mutation authority.
- `builderad_current_intent_validate_horizon(p_value jsonb) RETURNS void`: controlled write-time validation, STABLE, using the shared support predicate and PostgreSQL's timezone-dependent civil-date existence check; absent horizon returns without environmental inference. The writer invokes it only for a newly introduced or materially changed nonnull horizon, after comparison with serialized current state; never for unchanged carryover, a whole-bundle no-op, static constraints or historical projection. No PHP date/timezone validation can replace this boundary.
- `builderad_current_intent_event_immutable() RETURNS trigger`: local unconditional UPDATE/DELETE rejection as specified above; no existing helper changes.
- `builderad_current_intent_write(p_workspace bigint, p_session text, p_value jsonb, p_confirmations jsonb, p_version bigint) RETURNS bigint`: VOLATILE SECURITY DEFINER, fixed `search_path=public,pg_temp`, repository owner/ACL pattern; only runtime EXECUTE on the writer. Confirmation shape must be an object with recognized boolean keys; no “same meaning” field.

Transient recognized confirmations: `declaration`, `business_wide`, `outcome`, `acceptance_clear`, `clarification_required`, `quantity`, `horizon`. Unknown keys and truthy-string coercion are invalid. For replacement, require explicit declaration/direction summary and business-wide confirmation. For creation or changed full outcome value, require independent `outcome` and `acceptance_clear`; if the advertiser indicates a materially relevant qualifier needs clarification, require `clarification_required=true` and nonempty clarification. If no such qualifier is needed, a clear booking can use one outcome confirmation without a forced definition. Supplied clarification must be represented consistently in that confirmation. This is the human declaration boundary, not proof that software can classify prose or qualify observed events.

When outcome changes and the new quantity is present, require `quantity=true`, even if its digits match the previous target; display its unit with the changed meaning. Creation/new or changed horizon requires `horizon=true` covering date AND timezone. Clear requires explicit `declaration=true` and SQL NULL, with no contradictory replacement flags/value. Removing optional values is visible in the final summary. Unchanged outcome/horizon can be carried exactly; no new reinterpretation or date anchor. Confirmations are checked against the serialized current value, not a client-supplied baseline.

Writer sequence:

1. Require the existing prepared Workspace proof. Resolve actor through `builderad_source_actor`; lock the same Workspace row `FOR UPDATE`, obtaining current value and shared revision together. Do not establish another serialization point.
2. Lock exact Workspace `business.context.manage` grant rows `FOR SHARE` in the existing order. Re-resolve current source actor and active lifecycle/session/grant with `clock_timestamp()` after waits. Owner role, staff role, service identity and UI `canManage` are not substitutes.
3. Enforce SQL argument byte limits, nonnegative expected revision, complete raw-field lengths and confirmation shape/requirements; canonicalize the COMPLETE submitted bundle with static validation only. Deny oversized or statically invalid input without effects, including purported equal/no-op requests. Deny unauthorized identical input as well as material input. Do not perform catalog/tzdata validation at this step.
4. Compare canonical request with the serialized CURRENT bundle using null-safe JSONB equality. Equal: skip catalog/tzdata validation of the already accepted unchanged horizon, repeat current authority check immediately before return, return current shared revision, change no value/event/revision/time. This includes stale authorized create/replace or repeated clear when current is absent; catalog-support loss alone cannot turn an authorized identical no-op into a failure.
5. Different: if expected revision differs, raise `40001`; do not mutate or retry. Otherwise compare the submitted horizon to serialized current horizon using null-safe equality of the complete canonical date/timezone pair. Exactly unchanged: carry it without environmental revalidation. Newly introduced/materially changed and nonnull: require explicit horizon confirmation and run full current PostgreSQL horizon validation before effects; unsupported timezone or invalid civil date rejects without effects. Removed/null: no environmental validation. Then update full value and shared revision once, and insert one event with complete before/after values, actor and revision pair. All effects are in the existing outer transaction.
6. After any FK/audit-insert wait, recheck source actor and time-sensitive exact grant again. On denial, raise `42501` and roll back EVERYTHING. The event and Workspace timestamp from this attempted transition become durable only on successful commit. Do not add an UPDATE path to immutable history.

Implementation detail for step 6: an insertion can itself wait. Capture server audit time immediately before insertion, perform the required post-insert authority check, and commit only if authority remains current. `occurred_at` is neither commit time nor ordering authority. Do not correct it by mutating an inserted event, sort transitions by it, or assert monotonic timestamps as evidence of serialization. Tests use shared revision pairs and witnessed mutation schedules for order, while checking that time is server-supplied and denied transitions roll back. No commit-time infrastructure is introduced.

| Command/state | Effect |
|---|---|
| Create, absent → present | One row change, revision +1, one event with NULL before. |
| Replace/modify, present → different full bundle | Same full-replacement writer; no partial patch or Outcome endpoint; one revision/event. |
| Intent + changed outcome + reconfirmed quantity/deadline | One atomic bundle transition, one shared increment. |
| Clear, present → absent | Column SQL NULL, one increment/event preserving the old bundle. |
| Authorized identical, including stale | Current revision returned; zero history/timestamp effect. |
| Materially different stale | `40001`; zero partial effects. |
| A → B → A | Second change is material compared with B; fresh revision required; another event. |
| Expired/revoked/wrong authority, including equal input | Deny/rollback; equality is not authority. |

No modifications to name/type/DTM SQL functions, shared revision semantics or lock ordering are planned.

## 4. Coherent projection and HTTP contract

`BusinessContext::replaceCurrentIntent(Request $request, Workspace $workspace, ?array $value, array $confirmations, int $version): int` marshals the complete input with JSON_THROW_ON_ERROR and calls the SQL writer with `SessionAuthority::sessionKey($request)`. It is an adapter inside the existing composition boundary; it does not own Desired Outcome semantics or implement qualification. SQL is the final authority/validation boundary.

Extend `BusinessContext::projection(Request, Workspace): array` with one SELECT of `workspaces.current_intent` AND `business_context_version` from the same row, inside the already selected signed `WorkspaceContext::run` transaction. Return:

```text
business.version: integer shared revision from that SELECT
business.currentIntent: {
  state: "PRESENT" | "NOT_DECLARED",
  value: complete canonical bundle | null,
  version: same shared revision
}
```

Decode the JSON once and expose it as one value; do not fetch outcome/quantity/horizon separately or fall back to history. `UNKNOWN` describes missing intent knowledge; it is not another persisted lifecycle/status field. Existing type/markets projection remains; the signed selector already holds relevant Workspace protections through the transaction. The same SELECT is a simple additional guarantee for this new bundle and revision, not a claim that the existing read path is defective.

In `BusinessContextController::edit`, compute the projection once, use its revision for `version` and `workspace.business_context_version`, and pass it unchanged to Context. Existing callers, including `WorkspaceController::show`, receive the additive projection; no new feature in Sources is required. Prove this call site remains compatible rather than editing it preemptively.

One new POST in the existing authenticated/current-principal route group:

```text
/workspaces/{workspace}/business/current-intent
name: workspaces.business.current-intent.update
BusinessContextController::updateCurrentIntent(
  Request $request, string $workspace, WorkspaceContext $context,
  BusinessContext $business
): RedirectResponse

payload: { command: "replace" | "clear", value: full object | null,
           confirmations: object, version: nonnegative integer }
```

Create/modify/replace share `command=replace`; clear requires `command=clear,value=null`. Use Inertia's JSON request transport (no files or FormData); require a JSON object body and preserve nested object versus array/null shape before Laravel associative decoding. Reject form-encoded or other ambiguous bodies with a bilingual input error; no second payload dialect or clear sentinel is needed. Reject unknown command/value keys, partial bundles, unsupported scopes, implicit missing optional keys, malformed quantities/dates/confirmations and contradictory clear data. Map validation to specific value fields where known; SQL `22023` to a bilingual declaration error, `42501` to 403, `40001` to a bilingual `version` conflict requiring review. Do not swallow `40P01` or other unexpected failures; they fail proof. Successful redirect goes to existing Business Context edit, whose GET reauthorizes and returns a coherent possibly later committed state.

Apply F3's explicit HTTP field/command limits without truncation; oversize bodies return an actionable bilingual input error (413 for the raw-body limit), and field-limit failures use ordinary validation errors. Direct SQL oversize requests raise `22023` with zero effects. HTTP validation checks deterministic lexical/calendar rules and confirmation shape; it must not impose current selectable-timezone membership on every submitted horizon. PostgreSQL decides exact carryover versus new/changed horizon against serialized current state and remains authoritative for required support/civil-date validation. Use server-side current authority even on no-op and read-only UI. Existing CSRF/session/current-principal guards apply. No fresh grant request route, new permission or owner-only policy.

## 5. Minimal advertiser UX

Add `resources/js/Components/Business/CurrentIntentEditor.vue` inside the existing Context page, using existing Business styles/copy and actual `business.currentIntent` + `business.canManage` props. Define the canonical TypeScript shape locally in this component and mirror the small prop contract in Context; do not add a schema/client-framework file just for this slice. `BusinessContext::currentIntentTimezones(): array` supplies the PHP-candidate/PostgreSQL-predicate intersection defined in F1, sorted without a default selection; `edit` passes this presentation-only list as `currentIntentTimezoneOptions`. Prove every emitted option through the actual writer; do not assume catalog parity. It is neither business timezone authority nor a registry/new endpoint.

- Show current summary first or “not declared”; absence leaves the business usable. Read-only users see meaning, direction, scope, description and optional quantity/deadline with timezone, without mutation controls.
- Authorized declare/edit opens an optional progressive form. No default direction. Present the four human-readable direction choices, optional prose and explicit “applies to the whole business” confirmation. Explain that a service/project/country-only intent cannot be saved here; offer defer/cancel rather than broadening.
- Outcome is a visibly distinct section. Clear booking needs a simple confirmation. Ask a short advertiser-directed question about acceptance-changing qualifiers; reveal clarification only when needed. Preservation asks what valuable result is preserved; validation asks what business evidence/result would count, without building experiment policy. No NLP detection or qualification engine.
- Optional count uses digit-string input, a semantic unit, and no progress display. Outcome changes invalidate outcome confirmation and require target confirmation when retaining/adding quantity; direction-only changes can carry the identical outcome value.
- Enforce F3's exact technical budgets with code-point/digit counters and serialized command byte checks. Explain over-limit input in ar/en as an adjustable input limit, preserve the entire draft and block submission; do not silently trim oversized content, truncate pasted text or describe the quantity digit bound as a business target ceiling.
- Optional deadline uses concrete date and an explicit nondefault timezone choice/confirmation. Changing date/zone invalidates that confirmation; retries and quantity edits retain the concrete pair. A stored UTC or a presented suggestion is not confirmation.
- If the stored confirmed timezone is absent from current selectable options, display the original date/name faithfully as a retained CURRENT horizon, and allow the entire pair to remain exactly unchanged while other fields are edited. Do not add that unsupported name as a newly selectable option, silently substitute it, or force a horizon change because quantity/direction changed. Changing either deadline or timezone requires selecting/confirming a currently supported timezone for the new pair. Removing the horizon is allowed; reintroducing it later uses the new-horizon rule. The UI preserves current confirmed values separately from the current option list; the writer's serialized-state comparison remains authoritative.
- Final summary shows all semantic fields and removals. A declaration confirmation permits submit; any subsequent material edit invalidates it. Clear has an explicit history-preserving confirmation, then the same mutation endpoint.
- Keep canonical baseline bundle + revision separately from draft/confirmation state. Clean incoming props can refresh together. Dirty incoming props only mark context changed and show the new current summary separately; do not replace draft, expected revision or confirmations. Explicit review reloads canonical context, preserves draft, explains differences and requires a separate user decision to adopt the new revision and reconfirm before resubmission. Reload alone is not a silent rebase.
- During a save, snapshot submitted draft/revision. On success adopt the redirected canonical value AND revision only if draft is still the submitted draft; newer edits remain dirty with their prior submission baseline. A redirect may include a later writer; do not combine the submitted bundle with its newer revision.
- Do not send Intent success through existing `marketsSaved` or `savedContextVersion`. Parent prop watchers already update clean name/type/DTM forms and preserve dirty drafts. Intent watches shared props with its own dirty guard. Existing name/DTM success signals must not rebase a dirty Intent draft. Preserve existing DTM behavior; test mixed forms in both directions before proposing any change to its component.
- LanguageSwitcher keeps the existing Context state-preserving contract. Translate all visible copy/errors into ar/en, retain entered semantic prose across locales, support RTL and keyboard/focus/error associations. No Goals navigation/dashboard or history UI.

## 6. Expected file map

All paths are repository-relative below; this is a future map, not authorization to create these files during planning.

| Class | Exact file | Responsibility and placement justification |
|---|---|---|
| NEW | `database/migrations/0001_01_01_000340_create_current_intent.php` | Forward installation of new column/journal/functions/ACLs; old applied migrations cannot hold a new forward change correctly. `down` refuses history erasure. |
| NEW | `database/sql/current_intent.sql` | Static bounded canonical validator, controlled write-time horizon validation/shared timezone predicate, complete declaration writer, local immutable guard and journal DDL. Existing name/type/DTM SQL owners have different value/error contracts; appending this there would couple independent installation/contracts. No SQL framework. |
| MODIFY | `app/Business/BusinessContext.php` | Existing Workspace composition boundary adds one write adapter and one complete projection; no separate domain service/repository required. |
| MODIFY | `app/Http/Controllers/BusinessContextController.php` | Existing advertiser Context controller adds sibling command, validation/errors and coherent version props. |
| MODIFY | `routes/web.php` | Adds only the existing-page authenticated mutation route. |
| NEW | `resources/js/Components/Business/CurrentIntentEditor.vue` | Complete declaration editor with distinct confirmations/baseline; country-selection editor cannot hold this state correctly. Parent page already composes sibling DTM editor; do not merge these form contracts. |
| MODIFY | `resources/js/Pages/Business/Context.vue` | Existing owning page mounts the sibling editor and extends typed props; keeps create-business flow optional. |
| MODIFY | `resources/js/localization/business.ts` | Existing owner of bilingual Business UI copy; no new localization layer. |
| MODIFY | `package.json` | Add `tests/current-intent-interaction.test.mjs` to existing `test` script; no dependency/lockfile changes. |
| NEW | `tests/Feature/CurrentIntentDatabaseTest.php` | Canonical representation, direct SQL writer, equality, atomic history/revision and constraint tests. |
| NEW | `tests/Feature/CurrentIntentTest.php` | HTTP command validation, optional/read-only UX props and coherent projection tests. |
| NEW | `tests/Feature/CurrentIntentSecurityTest.php` | Prepared entry, grant/session/source/lifecycle, RLS and immutable-history negative proofs. |
| NEW | `tests/Feature/CurrentIntentConcurrencyTest.php` | Real separate-connection races and read/write interleavings; focused failure diagnostics. |
| NEW | `tests/CurrentIntentCommandWorker.php` | Focused signed-context process commands for intent/name/DTM/read/authority schedule fixtures; reuse established process pattern without changing DTM worker contract. |
| NEW | `scripts/Test-CurrentIntent.ps1` | Task-owned disposable cluster runner pinned to baseline `2cf51ea...`; old DTM runner deliberately rejects that baseline. Copy only needed lifecycle/guard pattern; no new generic runner abstraction. |
| NEW | `tests/Support/CurrentIntentDurabilityProof.php` | Fresh vs populated authorized-baseline forward migration, full preservation/catalog equality and restart proof; older DTM proof has different baseline evidence. |
| NEW | `tests/Support/CurrentIntentUiFixture.php` | Guarded synthetic sessions/business for Intent browser proof, extending existing fixture technique with current declaration and reader/manager actors. |
| NEW | `tests/Support/current-intent-browser.mjs` | Real Context interactions, locale/mobile/read-only/conflict screenshots/assertions against the task-owned server. |
| NEW | `tests/current-intent-interaction.test.mjs` | Compile/mount actual editor, Context and sibling editor for deterministic draft/confirmation interactions. |
| MODIFY | `tests/target-markets-interaction.test.mjs` | Map the newly imported actual Intent SFC in existing Context harness so landed DTM tests still execute; do not weaken existing assertions or stub away mixed-draft behavior. |
| NO_CHANGE_EXPECTED | `app/Models/Workspace.php` | No new mass-assignment path/cast is needed; projection decodes the selected canonical JSON explicitly. |
| NO_CHANGE_EXPECTED | `app/Foundation/Tenancy/WorkspaceContext.php`, `database/sql/business_context_lock_order.sql`, `database/sql/declared_target_markets.sql` | Existing authority/serialization and DTM contract reused verbatim. |
| NO_CHANGE_EXPECTED | `resources/js/Components/Business/TargetMarketsEditor.vue`, `resources/js/Components/LanguageSwitcher.vue`, `resources/js/Layouts/AdvertiserLayout.vue` | Existing state/locale behavior retained; if new integration fails, first fix the new editor/parent integration, not landed semantics. |
| NO_CHANGE_EXPECTED | `app/Http/Controllers/WorkspaceController.php`, `resources/js/Pages/Business/Sources.vue` | Additive business projection consumer, with no Intent workflow here. |
| NO_CHANGE_EXPECTED | `scripts/builderad.ps1`, `scripts/Test-DeclaredTargetMarkets.ps1`, `tests/TestCase.php`, `tests/Support/DisposableDatabaseReset.php`, `tests/Support/MigrationDurabilityProof.php` | Existing runtime guard, old proof baselines and reset support remain; FK-aware disposable reset is verified before assuming it needs edits. |
| NO_CHANGE_EXPECTED | Existing Business/DTM/Commercial/provider/security suites named in section 9 | Run affected regressions without refactoring their owners. No new docs beyond this artifact in the planning phase. |

No new PHP class, Goal/Outcome model, repository, generic subject, shared lock helper, client schema library or timezone subsystem is proposed. New support files are bounded proofs, not production abstractions.

## 7. Dependency-correct implementation tasks

All checkboxes remain unchecked until a separately authorized implementation phase. “Failing test” means a meaningful unmet contract; never accept failures caused by unsafe infrastructure. No commits are prescribed; source-control authority must be separately established.

### Task 1 — Canonical persistence and disposable proof harness

**Purpose:** Establish the selected storage shape and prove migration/shape/immutability assumptions immediately.

**Files/symbols:** New `scripts/Test-CurrentIntent.ps1`; migration `000340`; `database/sql/current_intent.sql` initially DDL + static `builderad_current_intent_normalize` + local `builderad_current_intent_event_immutable`; `CurrentIntentDatabaseTest`, `CurrentIntentSecurityTest`; `CurrentIntentDurabilityProof.php` initial forward migration checks.

**Dependencies/interfaces:** Authorized baseline migrations and existing RLS/signed scope; local journal guard, not SA01-helper reuse. Produce canonical column/journal + static normalizer/local-guard signatures from section 3. Runner accepts `-Phase Focused|Full|Durability|UI`, `-Filter`, `-Baseline` fixed to full authorized hash and optional `-DurabilityReference`; exports `CURRENT_INTENT_PROOF_RUN`, task cluster identity/reference variables, disposable DB variables using existing role bootstrap. No implicit primary fallback.

- [ ] Add guarded runner with free-port check for 5433, fresh UUID-owned directory/cluster, PostgreSQL 18 bootstrap, disposable assertion before fixtures/tests, fail-fast subprocess exit codes and finally cleanup. For UI reserve 8017 and launch hidden server. Verify positive disposable guard and negative occupied-port/wrong-baseline cases without touching an existing cluster. Record task process IDs and verify resolved cleanup paths remain inside the owned run directory.
- [ ] Write failing static storage tests: absent on historical/new Workspace; exact shape; invalid JSON/object/list/null/keys; four directions; narrow scope reject; empty outcome/optional clarification; exact count, leading-zero normalization, zero/fraction/unit errors; Gregorian leap/calendar/date lexical boundaries; timezone lexical/length only. Assert an unrecognized but structurally valid zone is not rejected by a durable CHECK; writer rejection belongs to Task 2. Review Focus 1–3.
- [ ] Add F3 boundary proofs for every field: exactly the code-point/digit maximum accepted in a valid complete bundle; maximum+1 rejected before normalization with no effects; oversized whitespace/leading-zero padding rejected rather than normalized away. Cover Arabic, combining characters, non-BMP code points, JSON escape/control characters and simultaneous maxima. Assert declaration ≤40,960 bytes, limit+1 invalid, command SQL arguments >81,920 bytes invalid when writer lands. Verify UTF-8 encoding, no clipping and exact retention of accepted boundary values.
- [ ] Run only new storage/security tests through the guarded runner; distinguish missing schema/function failures from infrastructure failures.
- [ ] Implement forward migration/SQL DDL and IMMUTABLE static normalizer, null-safe canonical constraints/bounds, journal FK/revision/distinct checks, RLS/ACL and Current-Intent-local immutable trigger. Inspect function definitions/dependency graph to prove durable CHECKs contain no STABLE catalog/zone function, no timezone conversion and no disguised wrapper for environmental reasoning. Leave writer/route unavailable until Task 2.
- [ ] Prove current/history cannot be rewritten via runtime DML or ordinary owner UPDATE/DELETE; demonstrate Workspace/user deletion cannot silently cascade away history. Prove scoped history isolation. Run initial fresh vs populated-baseline migration/catalog comparison now, including unchanged old rows/columns and new NULL column; verify guarded reset includes new relation through FK cascade in disposable tests only.

**Stop:** Existing baseline/roles/guard unavailable, primary connection required, destructive legacy rewrite, invalid timezone handling cannot preserve accepted meaning, or persistence needs new domain ownership. Return to Owner rather than relax guard/semantics. Success leaves a forward-installable validated nullable field/journal with no advertiser write path yet.

### Task 2 — Atomic authorized writer with immediate concurrency proof

**Purpose:** Make create/replace/modify/clear/no-op atomic with shared revision/history and effect-time authority.

**Files/symbols:** Complete `builderad_current_intent_write`, `builderad_current_intent_timezone_supported`, `builderad_current_intent_validate_horizon` in new SQL; `BusinessContext::replaceCurrentIntent`; new `CurrentIntentCommandWorker.php`, `CurrentIntentConcurrencyTest`; extend new database/security tests.

**Dependencies/interfaces:** Task 1 canonicalizer/storage/runner; unchanged `runBusinessMutation`, prepared proof/source actor/grant checks. Produce writer/adapter signatures from sections 3–4. Worker consumes a guarded synthetic fixture path containing operation, actor/session/workspace, full value/confirmations/expected shared version and barrier IDs; emits JSON `{status, sqlstate, version}` plus controlled synchronization evidence, never authority secrets.

- [ ] Write failing full-value sequence tests and independent confirmation tests. Assert every canonical component before/after, actor, timestamp and revision pair; unchanged carryover; simultaneous intent/outcome/quantity change increments once; A→B→A; unauthorized identical; no-op changes zero rows/events/timestamps; stale material conflict changes nothing.
- [ ] Run focused tests; implement writer in the exact existing lock/authority order and the PHP marshalling adapter. Enforce raw/canonical bounds and static validation always; compare against serialized current state before deciding whether dynamic horizon validation is required. For NEW/CHANGED horizons prove recognized zones/ordinary dates succeed, unknown/abbreviated zones and skipped civil dates fail without effects; unchanged accepted pairs and whole-bundle no-ops skip catalog/tzdata checks. Static constraints/history remain environment-independent. Enforce command byte-bound rejection, including equal/no-op requests. Revoke unintended privileges while allowing only the harmless shared timezone predicate for option queries; no raw Eloquent update.
- [ ] Focused F4 proofs A–D: first accept a complete horizon with a genuinely supported zone through the real writer; then simulate support loss only in guarded disposable fixtures by temporarily making the existing timezone-support predicate deny that zone. Save/restore its original definition in finally; do not edit actual catalogs/tzdata or add a production test hook. A: identical authorized request, including stale version, remains a no-op with zero value/event/revision/time effects. B: fresh quantity-only and direction-only changes each carry the exact pair with one normal transition. C: changing the date while retaining the now-unsupported zone, changing to an unsupported zone, or reintroducing that pair after removal/clear is rejected without effects; changing to a supported confirmed pair succeeds. D: current/historical projection retains exact date/name and never invokes environmental acceptance. Include stale MATERIAL conflict and unauthorized equal-request assertions to prove no authority/version bypass. These are targeted cases within the existing writer/projection tests, not another task or proof phase.
- [ ] Prove journal UPDATE/DELETE rejection uses local function/message and SQLSTATE 23514, without SA01/Commercial payload, authority or error text. Capture complete preexisting SA01/DTM/Foundation helper definitions before/after installation and assert unchanged. Prove order with shared revision pairs and actual serialization, not monotonic `occurred_at` or commit-time assumptions.
- [ ] Immediately run genuine equal/different Intent races before HTTP/UI work. Use process barriers + observed `pg_blocking_pids` and lock snapshots to identify the winner/waiter. Equal stale contender returns current version/no event; different contender returns `40001`; one increment, one complete event, no `40P01`/partial value.
- [ ] Immediately prove Intent×name/type and Intent×DTM with each ordered winner plus synchronized entry. Both requests start from same expected version. Winning material writer alone increments; losing material request conflicts. Test a fresh follow-up request separately; never call it automatic retry.
- [ ] Prove Intent×outcome-meaning contention through two full-bundle requests to this same writer, both schedules; independent semantic changes do not imply an independent Outcome endpoint. Combined same-request change stays one transition.
- [ ] Immediately prove session/grant expiry while genuinely blocked before grant acquisition and after a synthetic audit-insert wait; rollback value/event/revision/timestamp. Test revocation committed before acquisition, and revocation waiting behind a grant already held by an authorized writer (writer commits then revocation linearizes). Repeat equal/no-op wait with expired/revoked authority. Test lifecycle/member/responsible-owner invalidation during prepared-entry wait under existing Foundation rules.

**Proof gate:** `Test-CurrentIntent.ps1 -Phase Focused -Filter 'CurrentIntent(Database|Security|Concurrency)Test|BusinessContextLockOrderTest|DeclaredTargetMarketsConcurrencyTest'`. Record actual outcomes/SQLSTATE and schedule evidence, not elapsed sleeps. All fixture triggers/barriers/processes are removed/stopped in finally.

**Stop:** Requires changing Foundation/DTM locks or semantics, accepts `40P01`, extra revision or lock namespace, lost updates, failed post-wait checks or nonatomic event. Do not progress to projection/HTTP until this gate proves the boundary.

### Task 3 — Coherent current projection

**Purpose:** Guarantee one committed semantic bundle and its shared version before exposing a new read contract.

**Files/symbols:** `BusinessContext::projection`; `CurrentIntentTest.php`; read operation in `CurrentIntentCommandWorker.php`; projection schedules in `CurrentIntentConcurrencyTest.php`.

**Dependencies/interfaces:** Task 2 complete writer and signed Workspace read boundary. Consume canonical column+shared version in one SQL SELECT; produce `business.currentIntent` and `business.version` from section 4. No mixed cached Workspace revision.

- [ ] Write absent/present/clear projection tests asserting every component and equal versions; provider objectives/history cannot fill absence. Add an assertion that the current bundle and revision are selected in one statement, backed by real database behavior rather than source grep alone.
- [ ] Implement one row read/decode and compose existing projection. Check existing `WorkspaceController::show` caller's Sources payload remains compatible.
- [ ] Immediately run reader/writer interleavings: reader holds selected context before writer; writer commits before reader selects; synchronized reader/writer entry. Cover complete replacement, outcome+quantity+horizon changes and clear. Distinct sentinel values on every component make mixed states visible. The normal selector locks can serialize the interleaving; prove the actual blocking/order rather than forcing a new isolation model.
- [ ] For every result assert the entire bundle/presence/revision matches one captured committed state, allowing an old coherent result. Repeat with unrelated name/DTM shared-version changes. No separately fetched outcome/horizon or history fallback. Exercise Review Focus 4 at the projection boundary.

**Proof gate:** Focused projection/concurrency tests plus `BusinessFoundationTest`, `DeclaredTargetMarketsTest`, `Foundation/WorkspaceIsolationTest.php` through guarded runner. Stop if any projection requires a new generic snapshot framework or breaks signed read isolation; do not expose mixed values through controller props.

### Task 4 — HTTP command and composed Context props

**Purpose:** Expose only the existing-authority advertiser mutation and coherent page props.

**Files/symbols:** `BusinessContextController::updateCurrentIntent`, `::edit`; `BusinessContext::currentIntentTimezones(): array`; `routes/web.php`; `CurrentIntentTest`, `CurrentIntentSecurityTest`.

**Dependencies/interfaces:** Task 3 projection, Task 2 adapter; consume full command from section 4; produce redirect/403/bilingual field errors and same-version page props.

- [ ] Write failing authorized create/edit/clear/equal/stale HTTP tests and direct malformed JSON/form tests: object/list ambiguity, omitted/extra fields, booleans/fractions, incomplete optional values, unsupported scope, forged actor/provider/status/bypass flags, contradictory clear. Add each F3 field at maximum/max+1, Unicode versus UTF-16 cases, escaped JSON and body exactly 81,920 bytes versus 81,921; valid exact-size body succeeds, oversized body fails with no effects. Assert SQL-invalid direct callers cannot bypass HTTP validation or input bounds.
- [ ] Prove UI/writer timezone-set coherence exactly as F1 specifies: derive emitted options via shared PostgreSQL support predicate and PHP candidate filter; test differing catalog candidates; submit every exposed option through the actual PostgreSQL writer on the ordinary reference date. Keep date-specific civil-validity tests separate. No new endpoint, cached catalog or timezone subsystem.
- [ ] With the same F4 disposable support-loss fixture, prove HTTP accepts an authorized unchanged-pair no-op and fresh quantity/direction carryover without option-membership rejection; changed unsupported horizons still fail. `edit` exposes the faithful stored pair while its selectable option list excludes that zone. Restore the fixture predicate in finally.
- [ ] Add exact route/method, complete shape validation and prepared context call. Compose `edit` projection once and use its revision consistently. Return actionable conflict errors; do not turn `40001` into an automatic retry or successful response.
- [ ] Prove manager with exact grant may mutate even when non-owner; owner without exact grant may not; reader gets projection and no mutation rights. Verify unauthenticated/revoked session, staff/service/provider paths and cross-workspace contexts fail; read authorization remains unchanged. Ensure CSRF/current-principal route guards apply.
- [ ] Run focused HTTP/security tests and existing Business Foundation/DTM/security authority cases; inspect route registration with repository wrapper in the same guarded environment. No web server is required yet.

**Stop:** Route needs new authority or no-op bypass; additive projection cannot keep page props coherent; HTTP forces partial patches or reopens semantic choices. Return to Owner if existing contracts would need change.

### Task 5 — Minimal bilingual editor with real component interaction proofs

**Purpose:** Deliver the accepted optional declaration UX without rebasing any dirty Intent draft or disturbing existing Context workflows.

**Files/symbols:** New `CurrentIntentEditor.vue`, `tests/current-intent-interaction.test.mjs`; modify Context page, Business localization, `package.json`, existing DTM interaction harness import map. Component props: `workspaceId:string`, `currentIntent:{state,value,version}`, `canManage:boolean`, `timezoneOptions:string[]`; no success event sent into existing markets/name callbacks.

**Dependencies/interfaces:** Task 4 route/props/errors, existing Vue/Inertia form conventions and locale preservation. POST complete replace/clear commands; consume redirect canonical bundle+version together.

- [ ] Compile/mount actual new SFC and Context/sibling DTM editor in the existing Node renderer pattern. First write failing direction/scope/no-default tests; booking simple-confirm vs conditional qualification; unchanged outcome carryover; quantity re-confirmation after changed outcome; absent/past/date-zone summary; unsupported scope cancellation; read-only summary; clear history explanation and confirmation.
- [ ] Implement the progressive summary-first UI in existing styles, with ar/en Business copy, exact digit inputs, concrete date/explicit timezone confirmation and final-summary invalidation. No relative picker, ontology wizard, automatic prose classification or new navigation.
- [ ] Prove Review Focus 2–4: exactly 120 quantity digits retained; 121 blocked without clipping; each prose/unit field at maximum/max+1 with Arabic/non-BMP/control/escaped input; counters match SQL/HTTP code-point semantics; oversized draft and transport errors retain complete input. Retries/quantity-only edits preserve deadline, timezone suggestion not accepted implicitly, user edits during save preserved, late redirect never associates submitted value with newer version. No native UTF-16 maxlength or quantity rounding silently changes input.
- [ ] Focused F4 component proof: remove a saved confirmed zone from the supplied current option list; retain/display its exact current pair during quantity/direction edits and submit it unchanged, with no substitution or forced horizon edit. Do not offer the unsupported zone as a new selection. Date/zone changes require a supported selection and explicit new confirmation; projection remains faithful. Keep this within existing interaction proof scope.
- [ ] Prove dirty Intent survives name/DTM changes, locale changes and conflict reload; old version/confirmations remain until explicit review/adopt/reconfirm. Prove Intent save leaves dirty name/type/DTM drafts untouched; clean forms follow canonical props together. Existing name/DTM success notifications cannot rebase dirty Intent. Keep existing DTM assertions unchanged; do not broadcast new save signals through its baseline mechanism.
- [ ] Update the existing Context harness to load the actual new import; add new test file to `npm test`. Run focused Node tests, existing DTM/localization tests, then `vue-tsc`/build via wrapper. Verify no dependency/lockfile updates and creation flow still works with no Intent section until Workspace exists.

**Proof gate:** `C:\tools\node24\node.exe --test tests/current-intent-interaction.test.mjs tests/target-markets-interaction.test.mjs tests/localization.test.mjs`; `pwsh -NoProfile -File scripts/builderad.ps1 build`.

**Stop:** Requires changing accepted DTM semantics, silent dirty rebasing or scope/timezone inference. Diagnose integration first; return to Owner before expanding the foundation or accepted contract.

### Task 6 — Integrated security, durability and browser proof

**Purpose:** Confirm previously proved boundaries survive migrations/restart and actual browser/server integration.

**Files/symbols:** Complete new durability proof, UI fixture, browser proof and runner UI phase; extend new tests only where integration exposes uncovered risks.

**Dependencies/interfaces:** Tasks 1–5. Durability uses exact baseline migrations from Git and guarded role/bootstrap pattern; UI fixture exports synthetic session cookies/workspace identifiers under `CURRENT_INTENT_PROOF_RUN` for the browser process, never primary/customer data.

- [ ] Complete fresh-empty→implementation and populated-authorized-baseline→implementation proof. Seed synthetic existing name/type/DTM/authority facts; compare every preserved historical column/row and seven catalog groups (tables/columns/constraints/indexes/policies/functions/triggers). New declaration defaults absent without historical rewriting. Verify repeated install/reset compatibility in disposable environment.
- [ ] Declare A, replace B with changed outcome/quantity/deadline, clear, then redeclare. Capture complete current/journal/revision/actor/time facts. Restart task-owned cluster; verify all facts, ACLs, RLS, CHECKs, immutable trigger and function definitions remain. Denied ordinary UPDATE/DELETE and runtime TRUNCATE/direct insert cannot erase history. Verify forward-only down refusal. Add negative missing-reference/guard failure that must exit nonzero, following existing proof exception handling.
- [ ] Verify stored rows/history and restore/catalog comparison never rerun timezone-dependent acceptance as a durable invariant. Test static validator results independent of session timezone/DateStyle; inspect absence of catalog/tzdata CHECK dependencies. Repeat the exhaustive UI-option/writer gate for the supported restored runtime. Verify local immutable error contract and unchanged existing immutability helpers. Retain boundary-size values exactly through replacement/clear/restart; rejected oversized writes leave no journal/revision/time effect. Order transitions by shared revision, never audit timestamps.
- [ ] Run bounded browser proof on built assets at task-owned localhost server: Arabic RTL/English, desktop and 320px, authorized/read-only, clear/redeclare, direction/scope confirmation, simple vs clarified outcome, optional count/deadline+zone, two-tab conflict, dirty mixed forms, locale switch and in-flight save. Assert actual POST payloads/server errors/canonical props, keyboard focus/labels, no overflow and semantic draft retention; save screenshots plus structured assertions/logs.
- [ ] Run existing DTM browser proof in this same safe UI environment against its own unchanged synthetic fixture contract; set its required `DTM_PROOF_RUN` to an owned subdirectory and create its fixture separately. Do not reset the database in the middle of another proof's active sessions. Existing DTM runner remains unchanged. Stop browser contexts/servers/cluster/workers and verify no task-owned listeners/processes remain; preserve evidence paths.
- [ ] Re-run new foundational tests only if integration changed relevant production/SQL code. No broad gate repetition solely to increase evidence volume.

**Stop:** Durability requires primary, changes historical facts, loses event content, permits history rewriting, or browser requires new integration/semantics. Retain logs and return the exact blocker.

### Task 7 — Final affected gate and independent review

**Purpose:** Confirm integration and have an independent reviewer assess the immutable final diff/evidence against the closed design.

**Files/symbols:** No additional production surface. The implementation phase records results in its approved completion report; it does not modify accepted design/discovery to make failures disappear.

**Dependencies:** Tasks 1–6 passed with reproducible evidence, process cleanup complete.

- [ ] Run section 9 final integrated PHP/client/build/durability/browser gates once; inspect exit codes, logs and warnings rather than reporting only test totals. Any new changes return to their affected gates first.
- [ ] Verify diff is confined to section 6, old SQL/authority/locks unchanged, no unauthorized source-control/external actions and no process left running. Review quantity/clarification/timezone confirmation gaps and RLS/function ACLs explicitly.
- [ ] Obtain independent review of the fixed diff and actual proof artifacts, using a separate reviewer in the implementation workflow. Delegate a read-only review only when Owner/approved workflow authorizes it; do not spawn agents in this planning phase. Review contract separation, no-op/effect-time checks, atomic journal, mixed projections/drafts, baseline preservation and A–AA coverage. Findings require targeted repair/proof before completion. If independent review cannot be obtained, report it as an unmet final gate rather than claim it passed.
- [ ] Return the actual implemented scope, tests/evidence/limitations and source-control status to Owner. No deploy/merge/commit authorization is implied by this plan.

**Stop:** Unresolved material finding, missing required proof or scope/authority expansion; return to Owner with exact remaining work.

## 8. A–AA acceptance-to-proof matrix

“PG concurrency” means real independent PostgreSQL connections with witnessed schedules, beyond ordinary PostgreSQL-backed feature tests. “Browser” identifies cases requiring real browser confirmation; deterministic Vue tests additionally cover form behavior.

| Case | Implementation surface | Test level / concrete required assertion | PG concurrency | Browser |
|---|---|---|---|---|
| A | Nullable storage/projection/Context | DB + feature + Vue: existing/new business valid without declaration, NOT_DECLARED/null, no gate/default. | No | Yes, optional flow |
| B | Projection isolated from providers/history | Feature/DB: populated objectives/conversions/spend/history do not fill absent current value. | No | No |
| C | Direction/outcome + optional fields | DB/HTTP/Vue: preserve_value with clear confirmed valuable result persists, quantity/horizon null. | No | Yes |
| D | Exact quantity/count_unit | DB/HTTP/Vue: 100 qualified buyers linked to declared unit and clarification, positive integer only, never observed progress; large count remains exact. | No | Yes |
| E | Confirmed horizon | DB/HTTP/Vue: concrete local deadline+zone, no reporting/campaign/verification field; full-day interpretation. | No | Yes |
| F | Exact schema exclusions | DB/HTTP: resource/budget/spend payload keys rejected; no new spending authority or canonical field. | No | No |
| G | Outcome declaration boundary | Feature/security: provider conversion cannot create outcome value/grant or qualifying evidence; no fallback. | No | No |
| H | Replace writer/journal | DB + durability: acquisition→preservation replaces current, preserves full old/new values/history; existing observations untouched. | No | Yes, summary/edit |
| I | Clear writer/projection | DB + durability + Vue: SQL NULL/current absent, old bundle preserved, no zero/fallback or business invalidation. | No | Yes |
| J | Serialized equality | DB + races: stale identical authorized value returns current revision; zero event/row/time effects. | Yes | No |
| K | Version conflict | DB/HTTP + races: stale material request 40001, entire state/history unchanged, no retry/rebase. | Yes | Yes, two tabs |
| L | Existing prepared serialization | Races: Intent×name/type and Intent×DTM both winners/synchronized entry, one winner increment, material stale loser conflicts, no 40P01/lost update. | Yes | Yes, mixed drafts |
| M | Full outcome carryover/confirmation | DB/HTTP/Vue + races: unchanged meaning carried, changed outcome independently confirmed, combined bundle one increment/event. | Yes | Yes |
| N | Exact existing authority | Security/HTTP: staff/service/provider/owner lacking grant denied; authorized non-owner manager allowed, direct prepared bypass denied. | Yes, wait transitions | No |
| O | Read projection/canManage | Feature/Vue: scoped reader sees complete value, lacks all mutation/outcome controls; direct POST denied. | No | Yes |
| P | Draft baseline/review | Actual Vue interactions: unrelated shared changes preserve draft+old version; explicit review/adopt/reconfirmation required, no automatic rebase. | No | Yes |
| Q | No automatic lifecycle | DB/feature: past deadline remains PRESENT; time passage causes no event/status mutation, replacement only changes current. | No | Yes, past date display |
| R | Exact value whitelist | DB/HTTP: channel/audience/campaign/allocation/opportunity/provider fields rejected, no canonical integration slots. | No | No |
| S | No-op effect-time checks | Wait races: equal request with expiry/revocation denied after actual wait; no row/event/version/time effect; no accepted 40P01. | Yes | No |
| T | Concrete horizon carryover | DB/HTTP/Vue: same date/zone on retry and quantity edit, relative string rejected, changing confirmed deadline is material. | No | Yes |
| U | Outcome/quantity reconfirmation | DB/HTTP/Vue + races: altered meaning with retained or new quantity requires confirmation; stale competing full bundle conflicts. | Yes | Yes |
| V | Conservative equality | DB: outer whitespace presentation normalization only; paraphrase/case/punctuation remain material; A→B→A compares current, new event/revision. | No; J covers stale race | No |
| W | Explicit direction | DB/HTTP/Vue: same outcome but each different direction is material; prose never changes projected direction, no inferred default. | No | Yes |
| X | Scope field and human confirmation | DB/HTTP/Vue: business_wide only after confirmation; explicit narrow scope/subject/market payload rejected/deferred, never broadens. Prose is not auto-classified. | No | Yes |
| Y | One-row projection/version | Read/write races: every component, absence and revision match exactly one committed sentinel state for replace/outcome/clear/unrelated shared changes. | Yes | Yes, late redirect |
| Z | Explicit horizon timezone | DB/HTTP/Vue: no Workspace/default/browser/server/provider/IP/market inheritance; absent confirmation/invalid zone rejects; saved pair retained. | No | Yes |
| AA | Progressive outcome clarification | DB/HTTP/Vue: clear booking accepted with simple confirm; advertiser-marked relevant qualifier requires clarification; preservation/validation have declared result, no forced quantity/ontology/experiment engine. | No | Yes |

Grouped proofs intentionally cover several cases; do not create one test per document sentence. Security and database tests are distinct because runtime SQL callers must not bypass the HTTP human-confirmation/shape boundary. Human-confirmed prose is not machine-certified business truth.

## 9. Exact regression gates and execution commands

Commands below are planned for a separately authorized implementation phase; none was run against a database during planning. All PHP paths run under the new disposable runner. Existing runtime wrapper is retained.

| Increment | Exact affected existing suites | Why |
|---|---|---|
| Writer/locks | `tests/Feature/BusinessContextLockOrderTest.php`, `tests/Feature/DeclaredTargetMarketsConcurrencyTest.php`, `tests/Feature/DeclaredTargetMarketsDatabaseTest.php`, `tests/Feature/DeclaredTargetMarketsSecurityTest.php` | New sibling writer shares Workspace row/revision/grant/serialization; landed semantics must survive. |
| Projection/HTTP | `tests/Feature/BusinessFoundationTest.php`, `tests/Feature/DeclaredTargetMarketsTest.php`, `tests/Feature/Foundation/WorkspaceIsolationTest.php`, `tests/Feature/Security/DatabaseAuthorityBoundaryTest.php`, `tests/Feature/Security/SessionAuthorityTest.php`, `tests/Feature/Security/ServiceAuthorityTest.php`, `tests/Feature/Security/InternalAuthorityTest.php`, `tests/Feature/Security/LifecycleActivationTest.php`, `tests/Feature/Security/OwnerGrantReassessmentTest.php` | Optional Context behavior, tenant isolation and exact authority under additive schema/read/write path. |
| UI | `tests/target-markets-interaction.test.mjs`, `tests/localization.test.mjs` and existing DTM browser proof | Context composition, mixed drafts, LanguageSwitcher, RTL and locale navigation. |
| Integrated shared-context consumers | `tests/Feature/Commercial/CommercialScopeTest.php`, `tests/Feature/Commercial/CommercialMarketPreparationTest.php`, `tests/Feature/Providers/ProviderAccessStoreTest.php`, `tests/Feature/Sources/SourceRegistryTest.php`, `tests/Feature/Security/ProviderCallbackIngressTest.php` | Existing grants/context and provider access/observations remain separate and unchanged. |
| Integrated client consumers | `tests/commercial-operator.test.mjs`, `tests/provider-report-interaction.test.mjs`, `tests/source-capability-ui.test.mjs`, `tests/source-registry-interaction.test.mjs`, `tests/country-reference.test.mjs` via `npm test` | Preserve existing commercial/provider/DTM interaction contracts after Context changes. |

Focused examples (runner filters PHPUnit classes; test setup still invokes the existing disposable guard):

```powershell
pwsh -NoProfile -File scripts/Test-CurrentIntent.ps1 -Phase Focused -Filter 'CurrentIntentDatabaseTest|CurrentIntentSecurityTest'
pwsh -NoProfile -File scripts/Test-CurrentIntent.ps1 -Phase Focused -Filter 'CurrentIntentConcurrencyTest|BusinessContextLockOrderTest|DeclaredTargetMarketsConcurrencyTest'
pwsh -NoProfile -File scripts/Test-CurrentIntent.ps1 -Phase Focused -Filter 'CurrentIntentTest|BusinessFoundationTest|DeclaredTargetMarketsTest|WorkspaceIsolationTest'
C:\tools\node24\node.exe --test tests/current-intent-interaction.test.mjs tests/target-markets-interaction.test.mjs tests/localization.test.mjs
```

Final integrated gate:

```powershell
pwsh -NoProfile -File scripts/Test-CurrentIntent.ps1 -Phase Full
pwsh -NoProfile -File scripts/builderad.ps1 npm test
pwsh -NoProfile -File scripts/builderad.ps1 build
pwsh -NoProfile -File scripts/Test-CurrentIntent.ps1 -Phase Durability
pwsh -NoProfile -File scripts/Test-CurrentIntent.ps1 -Phase UI
```

`Full` covers all existing PHP tests once, including provider observation/unit contracts. `npm test` covers existing client aggregate plus the new interaction suite. Durability logs baseline/cluster identity, row preservation and catalog results; UI executes new and existing bounded browser contracts. Do not use existing DTM's older-baseline durability result as proof for this migration. Do not run default `composer setup`, implicit `.env` migration or unguarded tests. If the expected approved toolchain/build/browser runtime is unavailable, report that concrete limitation rather than install or broaden scope automatically.

## 10. Exclusions and assumption handling

Excluded: Targeting reasoning; Spend reasoning/shared spend-resource model; provider objective ingestion; observed-outcome acceptance/qualification/attribution engines; Decision Synthesis; Action/Execution Contract; ADVISE/PREPARE; Observation/Verification; Learning; Market Evidence/Opportunity; REGA/DataForSEO/Similarweb/Semrush and other integrations; CRM; commerce integrations; Builderad SDK; Product/Service/Project subject systems; multiple goals/hierarchy; generic Goal/Intent/Outcome frameworks; scheduler/timezone subsystem; timeline UI.

No material implementation assumption or blocker was found in the inspected boundaries. Technical choices fixed here are implementation choices under the accepted contract: JSONB bundle + one journal, digit-string quantity, fixed direction allowlist, one SQL writer, one row projection, concrete-date-only minimal UI, and a guarded baseline-specific proof runner. They do not rely on unproven Workspace timezone authority, new entities or an automatic prose interpreter. Task gates verify runtime availability, historical preservation, real lock behavior and supported zone/date handling before dependent work. Failure requiring any semantic/DTM/Foundation/authority/lock/domain/scope change is a stop-and-return-to-Owner condition, not permission to redesign.

## 11. Required final plan ruling

| Ruling | Value |
|---|---|
| PLAN_PHASE | COMPLETE |
| SEMANTIC_DESIGN_REOPENED | NO |
| DISCOVERY_REOPENED | NO |
| EXPECTED_PRODUCTION_SCOPE | Optional Current Intent bundle/history, existing-authority writer/coherent projection, one existing-Context POST/editor and ar/en validation/copy. |
| EXPECTED_NEW_PERSISTENCE | Current declaration + confirmed outcome value → nullable `workspaces.current_intent` JSONB; bounded semantic/audit history → one immutable `business_current_intent_events` table. Same row gives coherent current value/shared revision; full event values preserve replacement/clear history. No separate current/Outcome table. |
| SHARED_REVISION_REUSED | YES |
| EXISTING_SERIALIZATION_REUSED | YES |
| NEW_AUTHORITY_MODEL | NO |
| NEW_LOCK_NAMESPACE | NO |
| GOAL_SPECIFIC_REVISION | NO |
| OUTCOME_SUBSYSTEM | NO |
| BUSINESS_WIDE_ONLY_FIRST_SLICE | YES |
| FUTURE_NARROW_SCOPE_ARCHITECTURE_IMPLEMENTED | NO |
| COHERENT_PROJECTION_PROOF_PLANNED | YES |
| A_AA_ACCEPTANCE_COVERAGE_PLANNED | YES |
| PRIMARY_DATABASE_REQUIRED | NO |
| TARGETING_SPEND_INCLUDED | NO |
| MARKET_INCLUDED | NO |
| NEW_INTEGRATION_INCLUDED | NO |
| MATERIAL_IMPLEMENTATION_ASSUMPTIONS | NONE |
| TIMEZONE_DEPENDENT_CHECK_ASSUMPTION | REMOVED — durable CHECKs use static row-intrinsic validation only; catalog/tzdata facts are controlled writer checks. |
| UI_WRITER_TIMEZONE_SET_COHERENT | YES — shared PostgreSQL support predicate, PHP candidate intersection and exhaustive actual-writer option proof planned for the supported runtime; not yet executed. |
| TECHNICAL_INPUT_BOUNDS_DEFINED | YES — 2,000-code-point prose fields, 120-code-point unit, 120 quantity digits/zone ASCII characters, 40,960-byte declaration and 81,920-byte command budgets, with SQL/HTTP/UI boundary/oversize proofs. |
| CURRENT_INTENT_JOURNAL_IMMUTABILITY_OWNER | Current-Intent-local `builderad_current_intent_event_immutable()` in `database/sql/current_intent.sql`; unconditional UPDATE/DELETE guard, SQLSTATE 23514, Current Intent error contract; SA01/DTM/Foundation unchanged. |
| OCCURRED_AT_ORDERING_AUTHORITY | NO — server audit time only; ordering comes from shared revision pairs and serialized mutation order. |
| UNCHANGED_ACCEPTED_HORIZON_REVALIDATED_AGAINST_CURRENT_CATALOG | NO — static validation and authority checks remain; exact carryover is determined only against serialized current declaration. |
| NEW_OR_CHANGED_HORIZON_REQUIRES_CURRENT_CATALOG_VALIDATION | YES — a newly introduced or materially changed nonnull date/timezone pair requires full current PostgreSQL support/civil-date validation and explicit horizon confirmation. |
| MATERIAL_BLOCKERS | NONE |
| READY_FOR_OWNER_PLAN_REVIEW | YES |
| READY_FOR_IMPLEMENTATION | NO |
| NEXT_RECOMMENDED_STEP | Owner review of implementation plan |

Planning ends here. No production implementation or execution handoff is authorized.
