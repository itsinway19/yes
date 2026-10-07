# Builderad — Owner-controlled Real Pilot 001: dependency activation review

Prepared 2026-10-07, Europe/Istanbul. **Owner-review candidate; unaccepted and unlanded.** This document records a real-case dependency activation, not canonical declaration storage, an implementation design or an implementation plan.

## 1. Authoritative state and authorized boundary

Before review work, `git fetch origin` succeeded after a sandbox retry permitted Git metadata access. `git branch --show-current` returned `codex/real-targeting-pilot-001`; `git rev-parse HEAD origin/main` returned `b4c1f4134c22c12088006d4148b3f5fbc5e11692` twice. `git status --porcelain=v1 --untracked-files=all` returned no entries. Worktree: `C:/dev/builderad-worktrees/real-targeting-pilot-001`.

Authority is the Owner's current assignment, `C:/Users/stoma/.codex/attachments/8f3ce6e4-0d5a-4da7-a6cd-27d00871ce35/Pasted text.txt`. It authorizes bounded read-only Builderad/Meta inspection, necessary evidence retention, necessary source inspection and exactly this review document. It prohibits implementation, solution design, database/schema mutation, Meta object mutation, OAuth, Builderad Meta API calls, N06/N07/N08 reasoning and commit/push/PR/merge.

**CURRENT_STATE_FOR_DEPENDENCY_ACTIVATION = ESTABLISHED. FRESH_BUILDERAD_RUNTIME_CONTINUITY = NOT_ESTABLISHED.** Current Meta campaign/ad-set fields were inspected; the local Builderad reread was blocked. This limitation does not erase the already-established canonical E1 proof consumed below or the new Owner-confirmed real-case declaration. No server/database service was started to bypass the read-only boundary.

## 2. Exact real case identity

These are **PILOT_SPECIFIC_FACTS**, not general platform constraints.

| Identity | Value and basis |
| --- | --- |
| Pilot | OWNER_CONTROLLED_REAL_PILOT_001 |
| Business / Workspace name | `builderad` |
| Workspace public_id | `01a10220-cc4c-7f16-aabd-ed4d58676c8d` |
| Business type | `other` / نشاط آخر / ID 6 |
| Business Context revision | 5, consumed from prior canonical read-back and reaffirmed in current Owner assignment |
| Provider / Ad Account | META / `1907769679864990`, current Ads Manager account and URL |
| Campaign | `23861326338620792`; `Builderad | Pre-launch Awareness | Pilot 001` |
| Ad Set | `23861326338630792`; `Builderad | Pilot 001 | Audience 01` |
| Ad | `23861326338640792`; Meta-created empty draft per historical creation evidence and Owner confirmation |

Current Ads Manager tree contains these exact three IDs in `ads_campaign_structure_item_<id>` rows, under the selected account; the Ad Set breadcrumb points to the named Campaign and shows one Ad. Native IDs and visible native relations corroborate the Owner's explicit business-to-case attribution. This is no canonical Builderad provider-object linkage admission. Names alone are not the identity proof. Ad editor entry failed in this pass; current creative emptiness and Ad-specific settings were not independently revalidated.

## 3. E1 proof and consumed revision

**E1 = PASS_ON_CONSUMED_CANONICAL_PROOF_REVISION_5. E1_FRESH_CONTINUITY_REVALIDATION = NOT_ESTABLISHED.** In the earlier E1 read-back in this same Owner conversation, a fresh normal `GET /workspaces/01a10220-cc4c-7f16-aabd-ed4d58676c8d/business` returned component `Business/Context`. Its DOM-embedded Inertia application projection returned name `builderad`, type `other` ID 6, Workspace and projection version 5, `targetMarketsDeclared=true`, exactly `AE, BH, JO, KW, OM, QA, SA`, count 7, no missing or unexpected codes, and `currentIntent={state:NOT_DECLARED,value:null,version:5}`. The current Owner assignment explicitly reaffirms that proof and set.

Consumed DTM: **SA, AE, KW, QA, BH, OM, JO**. Count: **7**. Unexpected markets: **NONE**. Current Intent: **NOT_DECLARED at consumed revision 5**, Owner-reaffirmed; not rewritten or used as Object Purpose.

Fresh-local limitation: this review's browser GET failed with connection refused. A scoped Laravel-bootstrapped SQL corroboration attempted only the local `pgsql_migrations` connection after checking configured host `127.0.0.1` and database `builderad_test`, with `SET TRANSACTION READ ONLY` before case queries. It failed with PDOException before returning case values. Listener inspection found neither 8017 nor 5432 listening. No fresh SQL result, DTM event timestamp or new revision is claimed. The review consumes the earlier canonical application proof rather than pretending the unavailable reread passed; no later canonical change is reported by the Owner, but independent continuity through this capture is not proven.

The landed normal read boundary is [BusinessContextController::edit](../../../app/Http/Controllers/BusinessContextController.php), using WorkspaceContext and [BusinessContext::projection](../../../app/Business/BusinessContext.php). The projection reads the canonical Workspace revision, type, scoped target-market rows and Current Intent. [Declared-target-market SQL](../../../database/sql/declared_target_markets.sql) supplies the shared version and immutable before/after event semantics. Exact consumed revision is **business_context_version 5**, not a Meta revision.

## 4. Exact Object Purpose declaration

Owner-confirmed statement:

> The purpose of this Campaign and Ad Set is to introduce advertisers and business owners to Builderad before launch in Builderad's declared target markets.

Purpose phase: **PRE_LAUNCH**. Standing declared by Owner: effective for this bounded pre-launch use until launch or until explicitly replaced/withdrawn by the Owner. The declaration refers to Builderad's declared markets; this review associates the supplied case with consumed E1 revision 5 without creating a canonical dependency record. No absolute business-effective start timestamp, canonical purpose identity, acceptance revision or canonical transition history has been created.

**E2_RAW_OWNER_DECLARATION = ESTABLISHED.** This is the attributable current Owner declaration, preserved here as documentary evidence only. Meta Awareness is a provider-native objective and does not substitute for this meaning.

## 5. Exact finite applicability

**E2_EXACT_FINITE_OBJECT_APPLICABILITY = ESTABLISHED.** The declaration applies directly and only to Campaign `23861326338620792` and Ad Set `23861326338630792`, account `1907769679864990`, for this Pilot. It does not automatically apply to Ad `23861326338640792`, future Campaigns, future Ad Sets or other objects. No parent inheritance or broader scope is inferred.

**E2_CANONICAL_ACCEPTANCE = NOT_ESTABLISHED.** The assignment and this document cannot themselves become canonical Object Purpose storage or establish an accepted instance/version.

## 6. Owner authority boundary

The current Owner assignment expressly grants inspection, use and retention of evidence necessary for these exact Pilot objects:

- OWNER_INSPECT_AUTHORITY = ESTABLISHED_FOR_SELECTED_CASE.
- OWNER_USE_AUTHORITY = ESTABLISHED_FOR_SELECTED_CASE.
- OWNER_RETENTION_AUTHORITY = ESTABLISHED_FOR_SELECTED_CASE.

That bounded grant does not authorize unrelated account/customer evidence, publication, spending, configuration changes, permission changes or implementation. Evidence was retained locally in the existing Git-ignored `storage/framework/testing/owner-preview` boundary of this worktree. No secrets, payment details or audience-member identities were collected. No evidence was uploaded elsewhere. Owner authority is distinct from canonical declaration acceptance and full E5 qualification.

## 7. Current Meta object and evidence state

Read-only browser inspection of the already-authenticated original Meta Ads Manager session occurred **2026-10-07 16:26:59–16:27:59 +03:00**, i.e. **13:26:59–13:27:59 UTC**. Later attempted Ad-editor reads produced no additional configuration evidence. Campaign and Ad Set were explicitly labeled **في المسودة / DRAFT**. Their switches displayed on, but this is not a delivery/publishing assertion. No switch, field, publish action or save-audience action was used. Opening editors, expanding display sections and scrolling served read-only observation.

The following are **PROVIDER_NATIVE_FACTS as shown by the current UI**, limited to these objects and this capture. Their selected values are case-specific configuration, not Builderad intent or universal provider semantics.

| Field | Current read-back |
| --- | --- |
| Campaign objective / buying type | Awareness / Auction |
| Campaign frequency control / A/B | OFF / OFF |
| Facebook Page | `builderad` (display name; Page ID not exposed in the inspected summary) |
| Budget and grain | 1 USD/day, in this Ad Set's budget editor; not a spend observation or a lifetime budget |
| Provider budget notice | Approximately 1 USD/day, maximum 1.75 USD/day and 7 USD/week; native UI notice only |
| Start / end | 7 October 2026, 15:55 GMT+3; end-date checkbox unchecked. Draft configuration, not scheduled live delivery |
| Performance goal / bid | Maximize reach / Highest volume (أكبر عدد) |
| Ad Set frequency control | Cap of 2 impressions per 7 days |
| Dynamic creative / live-video option | OFF / OFF |
| Included location | Jordan (JO), only listed location; expanded location list showed Jordan |
| Location exclusions | None visible in expanded list; no authoritative export/full exclusion enumeration obtained |
| Audience Advantage+ | ON |
| Minimum-age control | 18; unknown age on WhatsApp included |
| Suggested age / gender | 18–65+ / All genders |
| Languages | All languages |
| Custom audience inclusions / exclusions | None / None |
| Detailed targeting | Summary: all demographics, interests and behaviours; no individually selected criteria exposed in this summary |
| Expansion notice | Meta says it can reach beyond custom-audience, age, gender and detailed-targeting suggestions when likely to improve performance; control notice says it will not reach outside controls |
| Placements Advantage+ | ON; automatic placements; account placement exclusions: None |
| WhatsApp | Status included; UI warns unknown-age WhatsApp people are included |
| Default CTA notice | View details; notice, not a configured creative proof |
| Recommendations | Current campaign/ad-set UI says no additional recommendations; not acceptance of any recommendation by this review |
| Ad relation | One Ad shown under the selected Ad Set; exact tree ID corroborated; current Ad editor/creative not independently inspected |

Fresh screenshots below do not overwrite earlier screenshots. Each is an original browser capture of the provider-rendered UI, with captions/observations in this document being derived records.

| Local original capture, relative to worktree | Evidence |
| --- | --- |
| `storage/framework/testing/owner-preview/dependency-review-campaign.jpg` | Current campaign name, objective and draft editor |
| `storage/framework/testing/owner-preview/dependency-review-adset.jpg` | Current Ad Set, Page and performance/frequency settings |
| `storage/framework/testing/owner-preview/dependency-review-budget.jpg` | Current budget/schedule and audience boundary |
| `storage/framework/testing/owner-preview/dependency-review-audience.jpg` | Audience control/suggestion summary and WhatsApp warning |
| `storage/framework/testing/owner-preview/dependency-review-locations.jpg` | Expanded Jordan location list |
| `storage/framework/testing/owner-preview/dependency-review-placements.jpg` | Advantage+ placements and WhatsApp Status notice |

Historical change ledger: the earlier creation pass showed Page `باب الرزق للعقارات`, 5 USD/day, Jordan, and draft objects. The current assignment reports Owner changes to Page Builderad and budget 1 USD/day, without intentional country changes. This pass independently observed `builderad`, 1 USD/day and Jordan. The historical 5 USD/Page values are superseded for current configuration, not deleted or silently treated as current. Their exact change timestamps and native edit history were not obtained.

## 8. E3 provenance and currentness assessment

| Dimension | Assessment |
| --- | --- |
| ORIGINAL_OR_DERIVED | Screenshots: original UI captures; this table/prose: derived observations |
| ARTIFACT_ORIGIN | Owner-authenticated Meta Ads Manager, selected exact account/draft editors; no API, mock or reconstruction |
| OBJECT_LINK | Account/URL IDs and current native tree/breadcrumb establish the selected Campaign/Ad Set/Ad relation; Owner attributes the finite case to the Builderad business |
| OBJECT_STATE | Campaign and Ad Set DRAFT, explicitly observed; Ad draft/empty standing supplied by Owner and historical creation, not fresh creative revalidation |
| CAPTURE_TIME | 2026-10-07 16:26:59–16:27:59 +03:00; no provider-native revision number exposed |
| CONFIGURATION_CURRENTNESS | Current displayed configuration as of this capture; no claim of continued currentness after capture or published/effective settings |
| KNOWN_LATER_CHANGE | Owner Page/budget changes after historical initial capture, confirmed here; no later change known after this capture, but absence of all later edits is not certified |
| DEFAULTS_ACCOUNTED_FOR | Visible defaults and materially relevant native notices recorded; original automatic defaults distinguished from Owner Page/budget changes |
| EXPANSION_ACCOUNTED_FOR | Visible audience/placement Advantage+ and WhatsApp behaviour recorded; complete official meaning/eligibility qualification not performed |
| INHERITANCE_ACCOUNTED_FOR | Visible native hierarchy recorded; no purpose inheritance inferred; complete account/Ad-level override coverage remains unverified |
| Completeness for proposition | Sufficient for identity/current displayed fields and dependency activation; not certified sufficient for a targeting implication |

**E3_ARTIFACT_AVAILABILITY = ESTABLISHED. E3_QUALIFICATION = PARTIAL / NOT_ESTABLISHED_FOR_DEPENDENT_TARGETING_PROPOSITION.** No E3 PASS is forced. Draft UI is not applied delivery evidence; no official export, full account/Ad controls, native change revision, complete configuration qualification or E4 business bridge has been established. The E2 stop prevents dependent qualification/reasoning claims.

## 9. E5 component assessment

**E5_OWNER_INSPECT_USE_RETAIN_AUTHORITY = ESTABLISHED_FOR_SELECTED_CASE.** Case scope and lawful Owner retention boundary are explicit. Source origin and capture interval are documented. Known changes and limitations are retained.

**E5_OVERALL = NOT_ESTABLISHED.** Canonical purpose acceptance/currentness is missing; fresh Builderad continuity is unavailable; full proposition-relative completeness, Ad/account overrides and conflict/transition coverage are not certified. Owner authority alone does not pass provenance/currentness/completeness gates. No denial or global data unavailability is inferred.

## 10. Source-backed known Purpose-admission absence

The landed [Object-specific Advertising Purpose Semantic Design §§22–24](../specs/2026-10-07-object-specific-advertising-purpose-semantic-design.md) records Owner acceptance of **Object-specific Advertising Purpose Responsibility**, its defining bounded contract and scope/revision/authority obligations. That semantic contract remains accepted; it is not reopened.

The [landed Pilot preparation §5](../pilots/2026-10-07-owner-controlled-real-pilot-001.md) separately establishes canonical admission-path absence and no actual real-case implementation mandate at its historical preparation stage. Fresh scoped searches for `object.?purpose|advertising.?purpose|purpose.?admission|purpose.?declaration` in `app`, `routes`, `database`, `resources`, `tests` found no implementation matches. A broader bounded `purpose` check showed operational source-resource purposes, not advertiser-confirmed object-use admission. Context controller/projection inspection showed only Context, DTM and business-wide Intent boundaries. `git diff --name-only 8c575ec83045dbb9e961e9b6ee7795f05fefd732 HEAD` contained only the real-case review and Pilot preparation documents: no product implementation since Purpose landing.

**KNOWN_OBJECT_PURPOSE_ADMISSION_ABSENCE = ESTABLISHED in this authoritative landed source boundary.** This is not a global assertion about unknown external stores. No legitimate already-accepted instance was supplied; no inspection of unrelated stores was warranted. The accepted semantics do not imply implementation or an admitted declaration instance.

## 11. Real-case activation proof

| Required premise | Evidence / limit |
| --- | --- |
| A. Real Business exists | Prior canonical Workspace read-back and current explicit Owner attribution |
| B. E1 canonical and PASS | Exact seven-country set and consumed revision 5; current assignment reaffirms it; fresh reread unavailable |
| C. Real provider/account/Campaign/Ad Set identities | Current original Ads Manager identity/tree/draft observations |
| D. Actual Owner Purpose declaration | Exact confirmed statement in current assignment |
| E. Exact finite applicability | Direct Campaign + Ad Set enumeration, no future-object propagation |
| F. New real declaration requires acceptance | Current bounded declaration has no supplied canonical accepted identity/version |
| G. Raw declaration cannot satisfy canonical E2 | Accepted Purpose contract and explicit Owner instruction preserve acceptance boundary |
| H. Landed canonical admission absent | Source inspection and document-only history above |

**ACTIVATION_PROOF = PASS on these evidence-backed premises and consumed E1 proof.** It does not claim fresh local runtime continuity or a qualified targeting witness.

REAL_CASE_REQUIRES_CANONICAL_PURPOSE_ADMISSION = **YES**.

PILOT_ACTIVATED_REQUIRED_IMPLEMENTATION_GAP = **OBJECT_PURPOSE_CANONICAL_ADMISSION**.

WHY_REQUIRED_BY_REAL_PILOT: E2 requires an accepted canonical Object Purpose instance for the exact real Campaign/Ad Set premise, while the landed system has no canonical admission implementation for that accepted semantic responsibility. Historical preparation established the absence; the now-real finite Owner declaration establishes its necessity for this dependent Pilot path.

## 12. Exact blocking dependency and stop

**E2 = BLOCKED_BY_MISSING_CANONICAL_ADMISSION.** Stop the dependent Pilot path here. N06_WITNESS = NOT_ESTABLISHED; N06_REASONING = NOT_STARTED; N07_REASONING = NOT_STARTED; N08_SYNTHESIS = NOT_STARTED.

Jordan versus the DTM set has not been evaluated as a Builderad recommendation. No recommendation to change/preserve countries, budget, Page, expansion, placements or creative is made. Provider-native facts remain retained evidence. No Object Purpose is inferred from Awareness, naming, geography or performance.

## 13. Next governed work and implementation boundary

NEXT_GOVERNED_STAGE = **BOUNDED IMPLEMENTATION PLAN**.

PLAN_SCOPE = **OBJECT-PURPOSE CANONICAL ADMISSION ONLY**.

This separate governed stage follows Owner acceptance and landing of this review. Readiness identifies the dependency; it grants no implementation authority.

IMPLEMENTATION_PLAN_AUTHORIZED = NO. IMPLEMENTATION_AUTHORIZED = NO. NEW_WORKTREE_AUTHORIZED = NO. IMPLEMENTATION_PLAN_CREATED = NO.

DATABASE TABLE, STORAGE, API, ROUTE, UI, WRITE CONTRACT TECHNICAL SHAPE and READ MODEL = **UNSELECTED**. No technical solution or new semantic design is proposed. Restoring local read availability and validating continuity remain evidence follow-up limitations, not a product-change authorization.

## 14. Nonclaims and verification

No product code, tracked existing file, canonical data, schema, Meta Campaign/Ad Set/Ad configuration, budget, countries, Page, permissions, billing or OAuth changed in this review. No migrations, publication, activation, scheduling, deletion, creative setup, commit, push, PR or merge occurred. No secrets were printed. **SPEND_OCCURRED = NOT_VERIFIED**: draft state and no spending action in this review are established, but no actual spend/account ledger was inspected. Other account campaigns are outside this case.

This document does not close a Slice/Node, complete E2/E3/E4/E5/N05/SR9, admit Object Purpose, establish a targeting witness, authorize implementation, declare a source-reader implementation necessary or promote Pilot settings into platform semantics. The landed preparation document is unchanged historical evidence. Only this new Markdown document is the repository delta; screenshots are local ignored evidence. Documentary verification is Git whitespace/delta/path verification; application tests do not prove these documentary claims and were not run.

## 15. Evidence matrix

| Claim | Exact evidence | Status / limitation |
| --- | --- | --- |
| Authoritative base and clean initial state | Successful fetch, branch/HEAD/origin-main/status outputs | ESTABLISHED at b4c1f4134c22c12088006d4148b3f5fbc5e11692 |
| Real Builderad / E1 | Prior canonical Business/Context read-back, revision 5; current Owner reaffirmation | PASS on consumed proof; fresh runtime reread BLOCKED |
| Finite declared Purpose | Current assignment §§4, 8, exact two IDs and PRE_LAUNCH standing | Raw declaration and applicability ESTABLISHED; acceptance NOT_ESTABLISHED |
| Inspect/use/retain authority | Current assignment §§2, 4, 7 | ESTABLISHED_FOR_SELECTED_CASE, not full E5 |
| Native identity/state/configuration | Current browser account, URL, tree, breadcrumbs and six original captures | Campaign/Ad Set current draft fields ESTABLISHED; complete Ad/account settings not established |
| Change history | Previous capture findings + Owner change report + current Page/budget read-back | Historical/current values separated; exact edit timestamps unavailable |
| Missing canonical admission | Accepted Purpose §§22–24; preparation §5; focused searches; document-only post-Purpose history | Known source absence ESTABLISHED |
| Activated required gap | A–H proof in §11 | OBJECT_PURPOSE_CANONICAL_ADMISSION; E2 dependent path BLOCKED |
| E3 / E5 overall | §§8–9 provenance, capture/currentness/completeness boundaries | No full PASS claimed |
| Review-only delta | Final Git status and diff checks | Exactly this new review document; no source-control landing authorized |

**READY_FOR_OWNER_REVIEW = YES. STOP = RETURN TO OWNER.**
