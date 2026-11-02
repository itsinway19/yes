# Independent BF03 / Business Workspace UX and integration subreview

Review: `BF03-I01-FINAL-INDEPENDENT-REVIEW-001`, 2026-11-01. HEAD observed by parent: `c747f6d8a8f2011fa5dcc368da2aa4e8449e0565`, dirty detached worktree. This subreview did not implement the application. Same-model delegated review; no distinct-provider or unfamiliar-user study is claimed.

## Disposition

**B — BLOCKED** by UX-001: returning to a retained Inquiry panel redisplays protected contents after its independent read right was revoked. A direct backend read already rejects the same grant. This is a frontend disclosure lifecycle defect, not evidence of a backend authorization bypass or cross-actor access.

UX-002 is a separate nonblocking usability defect. Current rendered evidence is useful but does not establish complete preservation of all legacy Business journeys. The independent official browser attempt is **FAILED / INCOMPLETE**, with eleven observed passing intended scenarios and one accidentally selected, prerequisite-dependent legacy scenario failure. It must not be called a complete passing browser gate.

## Scope and inspected sources

Read applicable AGENTS guidance and the review assignment; consulted UX-CONTRACT.md, DESIGN.md changes, BUSINESS_WORKSPACE_UX.md for artifact locations, NEXT_IMPLEMENTATION_PACKAGE.md §§4/authority extension, CASE_A_CONTRACT.md relevant display/meaning requirements, and relevant executable architecture references. Prior verdicts were not adopted.

Inspected:

- `resources/js/business-foundation/BusinessWorkspace.vue`
- `resources/js/business-foundation/BusinessSurface.vue`
- `resources/js/business-foundation/BusinessEditor.vue` and its tracked diff
- `resources/js/business-foundation/workspacePresentation.ts`
- `resources/js/business-foundation/workspace.css`
- `resources/js/business-foundation/state.ts`, `drafts.ts`, `BusinessCreate.vue`
- Relevant lifecycle/authority consumers in `BusinessFacts.vue`, `BusinessPurposes.vue`, `BusinessHistory.vue`, `BusinessLegacyHistory.vue`, `BusinessCollaboration.vue`, `BusinessDelegation.vue`, `BusinessDataUse.vue`
- `resources/js/inquiry-evidence/InquiryEvidencePanel.vue`, `DeclarationDetails.vue`, `presentationDrafts.ts`, `editor.ts`, `api.ts`
- `resources/js/spa/transport.ts`, `presentation.ts`, `browserLifecycle.ts`, relevant `main.ts` rendering
- `tests/inquiry-evidence.test.mjs`, `tests/Support/business-surface-browser.mjs`, relevant `GoalsSpaFixture.php`, `BusinessSurfaceFixture.php`, `goals-spa-server.php`
- `scripts/Test-GoalsSpa.ps1`, relevant canonical verification guidance, package scripts.

No application, SQL, migration, test, or accepted-governance file was changed by this subreview. The supplemental script is a review artifact, not a new application test or changed test gate.

## UX-001 — MATERIAL — Retained Inquiry detail is redisplayed after read revocation

**Affected source:** `resources/js/business-foundation/BusinessWorkspace.vue:35-42` and `:67-68`; `resources/js/inquiry-evidence/InquiryEvidencePanel.vue:59-66`, `:90`, `:182-196`.

**Preconditions:** Same authenticated actor/document and Business retain ordinary Business context reading. The actor explicitly enrolled in BF03 and viewed an authorized Inquiry detail. Its Inquiry read right is then revoked outside the mounted panel while the panel is hidden behind Overview.

**Independent reproduction:**

1. In the official guarded synthetic browser environment, log in as the separate `goals-a@example.test` fixture principal and create a new private Business through the real API (201).
2. Open the actual workspace and explicitly enroll through its UI.
3. Record a permitted aggregate with inquiry count 37; refresh and open its details.
4. Navigate to Overview, which hides but retains the mounted Inquiry component.
5. Revoke `business.inquiry_evidence.read` using the actual protected HTTP endpoint and exact grant. The response is 200.
6. Independently GET the collection using that revoked read grant: **403**.
7. Return to Inquiry evidence through local task navigation.

**Observed:** The protected detail is visible and shows count **37**. Returning performs **zero** Inquiry API requests. An explicit subsequent “Check current state” clears details, collection, and editor.

**Required:** A retained protected result must not be re-exposed on destination entry as if its earlier qualification remained current after revocation. Preserve legitimate unsent work separately, but qualify current display/read permission before presenting a retained result again. The explicit assignment §7 requires protection for retained panels after revoked read, and the package preserves independently enforceable read/display authority. This finding concerns re-entry into a hidden result, not a requirement for instantaneous push removal of all pixels from an already-open document.

**Cause:** `navigate()` changes only presentation state; `v-show` keeps the child alive. The Inquiry watcher observes only Business/basis-access changes, so re-entry under the same Business/basis does not invoke `refresh()`. Its local collection/selected detail remains the rendering source.

**Impact:** A revoked read right is enforced by the server but its previously retained protected aggregate can be displayed again through the new workspace navigation. No cross-actor leak, new network data read, SQL bypass, or unqualified mutation was demonstrated.

**Smallest correction boundary:** Workspace-to-Inquiry activation/display lifecycle plus existing qualification/concealment ownership. Requalify before exposing retained protected collection/history/edit-derived contents on re-entry and clear or conceal them on denial/unknown qualification. Keep unsent, non-read-derived drafts and immutable pending commands within their existing authority/document scope; do not solve this by broad loss of all form work or weakened backend permissions.

**Regression evidence required:** Real browser test: authorized detail/history/correction baseline → leave Inquiry → revoke only Inquiry read while Business context remains valid → return without full route remount → no stale protected contents before/during qualification and no disclosure after denial. Include a still-authorized return preserving permissible unsent work, unknown qualification, and a late response that cannot republish concealed content.

**Artifacts:** `ux-supplemental-results-attempt2.json`, probe “hidden-panel return following out-of-panel read revocation”; `retained-panel-after-read-revocation.png`; source script `ux-supplemental.mjs`.

## UX-002 — IMPROVEMENT — Revoked correction leaves an enabled silent Save action

**Affected source:** `resources/js/inquiry-evidence/InquiryEvidencePanel.vue:61-64`, `:84`, `:113-114`, `:165`.

**Reproduction:** Open a correction while authorized. Revoke only the correction right outside the panel. Click the panel's actual “Check current state” and wait for completion. Verify eligibility no longer contains `business.inquiry_evidence.correct`. Click “Save correction”.

**Observed:** The correction form and enabled Save correction button remain visible, but submit returns at `if(!right)return`; **zero** revision POST requests occur. The panel continues showing the earlier unrelated successful-save notice. The current read right remains valid, so this is not a protected-data read violation or unauthorized write.

**Impact:** A user given an apparently available save action receives no explanation or visible transition when the application already knows that operation is unavailable.

**Correction boundary:** Derive the editor's available action and explanation from current operation grants; after known loss, remove/disable the save operation and explain the unavailable authority without misrepresenting unsent values as saved. Preserve legitimate drafts according to the accepted contract.

**Regression check:** Revoke correct while read remains valid, refresh while editor is open, verify disabled/unavailable correction with an understandable bilingual notice and no POST; verify ordinary authorized correction remains usable.

**Artifacts:** `ux-supplemental-results-attempt2.json`, probe “known correction revocation after explicit refresh”; `correction-right-revoked-refresh.png`.

## Rendered comprehension and accessibility observations

Opened original screenshots independently from:

`C:/Users/stoma/.codex/visualizations/2026/10/30/01a18cdf-2b2d-7682-ac51-b50f944e5fcc/workspace-ux-20261101T171558Z/`

Inspected `workspace-ar-light-390-collection.png`, `workspace-ar-light-1366-collection.png`, `workspace-en-dark-1366-collection.png`, `workspace-ar-light-390-form.png`, and `workspace-en-light-390-detail.png`. Also independently viewed all three supplemental screenshots produced in this review. Current official-run screenshots are archived under `official-browser-screenshots/` (22 PNGs).

The current independent overview screenshot shows a coherent real activity identity, five task destinations, descriptive task links, and explicit statement that connected ads/CRM/goals are not universal prerequisites. The current actual rendered `direction`, `settings`, and `access` sections were opened and their visible text recorded. Markets/intent/preferences, context editing, purposes/history, collaboration/delegation/data-use owners are present in their intended destinations. This establishes discoverability/rendered placement, not complete mutation/concurrency/revocation proof for each legacy workflow.

Original Arabic mobile/desktop and English dark desktop collections have clear compact rows, declared/unverified assurance, separate unknown and zero values, explicit detail actions and recognizable navigation. Long mixed-language identity wraps without hiding the destinations in the inspected images. Detail pages group source meaning, observation time, costs/counts, acceptance/criterion and limits; technical identifiers are disclosed progressively. The mobile detail is long, but no essential authorized content was found inaccessible in inspected states.

The input form is grouped and labels are associated by wrapping. Dates remain raw timezone-bearing string inputs, which may impose expertise on nontechnical users; this is a usability follow-up, not an independently demonstrated inability to complete the required task. No formal unfamiliar-user research or exhaustive assistive-technology audit was performed. Existing browser scenarios check returned focus, labels, touch target height, RTL, and overflow; these are bounded evidence, not comprehensive WCAG certification.

Source supports document-memory-only draft stores, onClose clearing, Business/basis/document scoped keys, current grant checks before restoring saved correction drafts, preserved original correction baselines, immutable pending commands, and server-protected API operations. Main document closure removes the active RouterView; Business state is cleared onClose. No localStorage evidence cache or cross-Business draft restoration was found. These positives do not negate UX-001's same-document re-entry gap.

## Current execution evidence and preserved failures

1. `agent-browser skills get core` could not execute because the CLI is not installed. No installation or new paid service was attempted. Used the repository's existing official Playwright browser route as fallback.
2. First guarded runner attempt failed **before service startup** because the sandbox could not access Windows CIM for its existing port-occupancy guard. Run ID `f43822f9-ae41-45d0-8058-44c046602d6f`; reported STOPPED / EXIT=1. The guard was not bypassed.
3. The same official bounded command was allowed outside the sandbox:

   `pwsh -NoProfile -File scripts/Test-GoalsSpa.ps1 -Phase Browser -BrowserSelection businesses -TestFilter 'BF03|workspace'`

   Run ID `30a67e58-6f5b-458d-a486-0444c12894fe`. Disposable PostgreSQL and roles were checked; fresh migrations through 540 completed; real guarded fixture receipt identified `builderad_test_disposable`, owner role, port5433. Redis and PHP servers were task-owned. No real customer/provider data was used.
4. All eleven intended login/BF03/workspace cases passed independently, including explicit opt-in/422 correction, stale revision rejection, historical denial clearing, correction/withdrawal history, own unsent draft restoration, revoked-read draft nonrestoration, known zero/unknown, 28 rendering combinations, lost ACK exact retry, and later-denial uncertain outcome handling. Browser page errors: zero.
5. **Overall run failed.** The reviewer supplied an insufficiently anchored regex. It also selected the later legacy case “qualified agency relationship is managed through the same resource without granting access to workspace members”. That case depends on an excluded earlier workspace setup and threw `TypeError ... public_id` at `tests/Support/business-surface-browser.mjs:548`. Recorded result: **12 cases, 11 PASS / 1 FAIL**, overall FAIL. This is a review orchestration/dependent-test selection failure, not a substantiated product regression. A future correct focused filter is `^(BF03|workspace)`; it was **not rerun** and is not claimed PASS.
6. Supplemental attempt1 incorrectly expected Business creation HTTP200; the actual contract returned201 and the probe stopped. Its original script/result are preserved as `ux-supplemental-attempt1.mjs` and `ux-supplemental-results.json`. It created a separate synthetic Business under goals-b; the official exact-count first case had already passed and no subsequent official failure was attributable to that creation. No product defect is assigned to this probe assertion.
7. Supplemental attempt2 corrected that expectation and used separate fixture principal goals-a and a newly created private Business. Source inspection verified separate users (`GoalsSpaFixture.php:30`) and that selected official BF03/workspace checks use goals-b; no shared grants or Business facts were mutated. Its actual endpoints/UI reproduced UX-001/002, zero page errors. Results and screenshots are retained, with browser context closed in finally.
8. Official `source-unchanged.txt` is False because authorized review files were added/changed during the runner. Do not relabel it True. Parent review's initial/final application manifest comparison is required to distinguish those evidence writes from implementation drift. Source manifests and fetched asset hashes are preserved.

Archived originals: `official-browser-browser-results.json`, `official-browser-browser.log`, `official-browser-source-manifest.json`, `official-browser-source-unchanged.txt`, `official-browser-processes.json`, PostgreSQL/PHP server logs and 22 screenshots. These retain the failure and do not fabricate a complete passing gate.

## Legacy regression limits

Existing full Business browser selection was not executed. Source inspection shows important legacy checks directly expect editor controls after opening a Business (`business-surface-browser.mjs:291-300`) and later expect BusinessFacts without first selecting the new task destination (`:386` onward). The new lazy workspace can make these assumptions invalid unless a prior retained section happens to mount them. This is a material limit on existing regression evidence and a bounded test-navigation integration follow-up; it is not proof that the underlying Business product operations are broken. Parent can consolidate this as VER-001.

Complete legacy market/intent/preferences mutation, collaboration/delegation/data-use, purpose/history mutation, authority changes, and their combined navigation/concurrency selections remain unverified in this current subreview. Reused source owners and visible placement do not substitute for those journeys. No Foundation run or full JavaScript suite was executed here.

## Cleanup and effects

Official finally completed: Redis24448, PHP22924/19932 and PostgreSQL8088 stopped; PostgreSQL log explicitly records database shutdown. A separate CIM check found **no test-port listeners (5433/6397/8136/8137/8138) and no remaining exact task PIDs**. `ux-process-cleanup.json` records the check. Both supplemental browsers closed in finally.

After archiving evidence, resolved absolute paths for the two task-created run directories were verified inside this worktree's `storage/framework/testing`, checked against reparse points, and removed with native PowerShell. No preexisting run directory was deleted. `ux-file-cleanup.json` records those paths and 22 archived screenshots. The runner-created temporary `.env` is absent after cleanup. Its official package-discovery command may refresh ignored bootstrap package/service cache files; preexisting generated caches were not deleted or restored speculatively. No persistent source change outside authorized review artifacts was made by the subreview.

The global Foundation gate remains a separate failed/incomplete acceptance condition. This report neither authorizes corrections nor recommends publication, merge, migration, or deployment.
