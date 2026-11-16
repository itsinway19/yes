# BuilderAd V3 integration

The reference is `BuilderAd_Advertiser_Responsive_Desktop_Mobile_V3.html`. Its SHA-256 matches the supplied fingerprint `014a70ccedfb5f9831e07fa908bb3fa5c60e1766f16e5d7533a05af4804ba1c6`. The starting branch is `design/advertiser-experience-redesign` at `50d908c`. The index and conflict set were empty. Existing working changes include the appearance and Business layouts, licensed font, and browser isolation and asset checks. The local preview uses built assets and has no `public/hot` pointer. The current tree supplies V18 revision 2 and the actual application contracts.

## Screen and editor mapping

| V3 surface | Vue owner | Read and options | Write and authority | Failure and verification |
| --- | --- | --- | --- | --- |
| Overview and next decision | BusinessWorkspace, BusinessFirstUsefulStep | Admitted Business surface, V18 result and qualified practice read | Existing destination or practice selection. Destination editors apply their own rights | Preserve all V18 outcomes and hide stale or wrong-subject results. Business browser V18 case |
| Goals, success criterion, markets | BusinessFacts and goalSelection | Business surface and catalog goals, result choices, countries and time zones | Existing preferences, intent and markets commands with fact-specific grants and versions | Keep original command for unknown results, handle conflicts and preserve scoped drafts. Advertiser Workspace tests |
| Offers and activity editor/history | BusinessPractices | Practices and per-activity revisions with the admitted read path. Catalog countries and activity kind contract | Existing practice commands with context write grant and expected revision | Distinguish read rejection, unknown write and stale revision. Activity component and Business browser paths |
| Advertising accounts and data use | BusinessSources, BusinessDataUse, BusinessDataLicenses | Provider inventory, qualified accounts, source grants and data license projections | Existing provider and data-use operations require their own grants and source context | Distinguish disconnected, restricted and unresolved states. Business source tests |
| More and inquiry records | BusinessWorkspace, InquiryEvidencePanel | Existing scoped inquiry projection and private record access | Inquiry record/correction/withdrawal use the existing admitted commands and source rights | Distinguish zero, unknown and read denial. Inquiry and Business browser checks |
| More and team | DirectBusinessTeam, BusinessTeamMembers, BusinessAgencyTeam, BusinessMandates, BusinessDelegation | Existing team, recipient and agency reads and typed action choices | Team enrollment, grant, mandate and withdrawal commands with separate authority and expiry | Preserve original uncertain request and conceal data on lost qualification. Team and delegation tests |
| Business information, lifecycle and history | BusinessEditor, BusinessLifecycle, BusinessHistory | Admitted surface, current edit options and separate private-history read | Existing context and lifecycle operations with originating versions and grants | Keep guards, recoverable results and archival confirmation. Advertiser Workspace tests |
| Advanced advertising purpose declarations | BusinessPurposes, PurposeStatementEnrollment, ObjectPurposeEditor | Existing Business purpose projection and qualified history | Existing purpose enrollment and declaration commands with independent write rights | Preserve versions, withdrawal, unknown retry and deep link. Object purpose interaction and SPA tests |

Presentation work retains the Business switcher, account and security access, independent data-use controls, inquiry evidence and qualified private history. The V3 prototype's example records and simulated writes have no application owner and do not enter the implementation.

## What the experience delivers

| Classification | Current boundary |
| --- | --- |
| Working journey with a defined benefit | A qualified advertiser can identify the next step from their admitted activity and goal context, open the applicable editor, save the declaration and see V18 reassess it. The benefit is a grounded next step and a clear indication of missing input. |
| Supporting operations | Business information, offer and goal declarations, account inventory, data permissions, inquiry declarations, team authority, private history and advanced ad purpose declarations support real work through their existing owners. |
| Partial capability | A source connection confirms only its admitted state. An inquiry count remains a declaration. An ad purpose describes the business's declared intention for an object. Each has its own provenance and scope. |
| Future commercial value | Diagnosing actual targeting, verifying customer acquisition or sales lift, and executing or optimizing ads require qualified provider data, additional evidence and independently authorized actions. These remain outside V3. |

The secondary ad purpose entry is under **Business information → Advanced declarations and records**. The original `?section=settings&settings=purposes` entry remains available to the qualified Business surface. V3 omits the prototype's fake actions, keeps existing internal validation and recovery states, and uses a bottom sheet or centered dialog for the fact and practice editors and provider details. Existing disclosure and editor interactions remain available for administrative tasks which require their original control flows. The Owner described V3 as a visual reference and allowed the sidebar to follow the real functions. The implementation uses that discretion for the switcher, account security and permission-dependent items.

Published primary and secondary colors come from GlobalAppearance through the SPA document. The primary colors the actions and navigation accent. The secondary tints the large decision surface and supporting accents. In the actual Codex preview the Business page showed the existing published palette, all five mobile destinations and the native goal editor. The browser tool did not admit the `file://` reference tab. Reference comparison used the verified HTML source and the rendered app's DOM and styles. The reference archives which could be identified supplied V18 revision 1 or earlier code. The verified branch snapshot and current revision 2 served as the functional reference.

## Local verification

| Check | Result |
| --- | --- |
| Typecheck and production build | PASS. Final build manifest SHA-256 `328b01b6621d8edbaa6c81826675e63c4898a9e8c25410fa017fcff1b4e127ec`. |
| Focused Node checks | PASS, 146 tests. Advertiser workspace, activity, purpose interactions and SPA, and appearance. |
| Business layout and navigation | PASS cases, 148 observations from six widths (360, 390, 768, 1280, 1600 and 1920), Arabic/English and light/dark, with no horizontal overflow. Lowest measured contrast was 5.87:1. The matrix also checked meaningful mobile touch targets, font loading, draft recovery and keyboard access to More. That run stopped later at a stale V18 test navigation to the old activity location. |
| V18 and advanced purposes after correcting test navigation | PASS, four browser cases including login, V18, purpose enrollment/correction/withdrawal and retained independent read after revocation with stale response isolation. |
| Admin color publication | PASS in both attempts. An already-open advertiser document kept its prior published values until reload. Reloaded and new documents received both submitted colors. The Business layout also reacted to each published CSS value in an isolated probe. |
| Full appearance browser run | Exit 1 on both attempts. First attempt passed 12 cases then reported two unexpected 502 asset responses from the loopback proof proxy. The retry passed nine cases, including publication, then timed out waiting for the appearance editor while the proxy again recorded asset timeouts. The complete gate remains open. |

Evidence directories under `storage/framework/testing/` are `goals-spa-7191fc52-b1de-4fe0-b257-35b96c1e7569` for the matrix, `goals-spa-cdc0fc9b-9849-4a20-99eb-57832cb17271` for V18 and purposes, and `goals-spa-27ad532c-dbfe-468a-8923-c5282a14fd6d` and `goals-spa-c0d131a8-70dd-49cc-9c88-8cccbfcf04c9` for appearance. Each recorded `source-unchanged=True`. The matrix and final business and appearance manifests differ only in the Business browser test navigation. Executable sources and the build manifest match. Test services and disposable clusters were cleaned up. Capture was disabled.

## Worktree and exit

The worktree remains on `design/advertiser-experience-redesign` at `50d908c`. The index is empty. This round changed `AGENTS.md`, `DESIGN.md`, this report, the advertiser shell, workspace, fact and practice presentations, shared task row and new BusinessSheet, their CSS and workspace presentation types, and the focused browser and Node tests. Earlier local font, appearance and test isolation changes remain in the tree. The original preview still serves the working tree build.

## Final acceptance follow-up

The historical 502 responses were generated by the Node proof proxy after its upstream requests reported `ETIMEDOUT`. A controlled static-asset probe used the same PHP test router and identical request paths directly and through a loopback proxy. Both paths returned matching hashes through bursts of 48 requests. The probe did not reproduce the connection timeout in isolation. The full appearance suite has no requirement for a proxy between the browser and the application. It now uses the same guarded direct PHP origin as the Business suite. The runner selects that origin only for the appearance selection. Per-request fault injection, asset byte checks, authority checks and the unexpected 5xx assertion remain in the suite.

The full direct run passed all 12 cases and verified 54 served asset hashes. It recorded no page error or unexpected 5xx response, `source-unchanged=True`, and exit 0. The disposable PostgreSQL cluster and owned processes were cleaned up. Evidence is under `storage/framework/testing/goals-spa-c64f9b18-188c-49cf-b469-806d45a99f23/`. The production manifest is still `328b01b6621d8edbaa6c81826675e63c4898a9e8c25410fa017fcff1b4e127ec`. The preceding Business layout, V18, purpose and focused Node evidence remains applicable to this executable candidate. No application source or built asset changed in the follow-up. The two changed proof files are `scripts/Test-GoalsSpa.ps1` and `tests/Support/appearance-browser.mjs`.

## Final information, disclosure and brand publication closure — 2026-11-16

Status: `LOCAL_VERIFIED_UNMERGED`. The preceding entries describe earlier candidates and runs; their failures remain historical failures. This closure covers the Owner's subsequent information-tab, V3 presentation, disclosure, logo/favicon and preview-save requests. It does not establish hosted Foundation, merge or production readiness.

Business information now separates readable activity data, inquiry counts and qualified history. Required saved facts, activity direction and offer revisions are visible in their context. Remaining optional native disclosures use explicit localized show/hide text instead of unexplained triangles. The advanced purpose entry remains directly reachable. Offer editing separates the offer from its coverage, preserves the draft between steps and writes only on the existing explicit save. V3 proportions, responsive cards, typography and neutral surfaces reuse the published administrative primary and secondary colors. No commercial result, provider integration or new business engine is implied by this presentation work.

The existing Founder-only GlobalAppearance publication now accepts an optional logo and favicon. Selection and local preview do not publish. Both assets publish atomically with the colors, revision and audit event through the existing fresh-authentication boundary. Omitting image fields preserves published assets; explicit null restores the default. Immutable content-addressed PNG bytes remain available to older documents. The public image route emits no session cookie or protected document identity. PNG is the admitted format, at most 512 KiB per file; logo dimensions are at most 2048 × 2048 and favicon dimensions are square, at most 512 × 512. Invalid and undecodable local selections do not enable publication.

### Why fresh login still produced an unknown outcome

The actual preview on `http://localhost:8038`, database `builderad_dev` on port 5432, had only migration `000000` installed. It lacked both the saved-practice qualification migration `000001` and the new brand-image migration `000002`. Its old `global_appearance` row had no `logo_sha256` property. The publish path raised `Undefined property: stdClass::$logo_sha256`; the resulting server error correctly entered the client's uncertain-outcome protection. Fresh login cannot repair missing schema. The client still preserves a genuinely uncertain request and never silently replays it.

The compatibility correction preserves color-only publication against the older schema and returns a known 422 rejection before any write if an image is submitted before image installation. The forward image migration requires a sealed predecessor. Historical migration bytes were not changed. The new reader and SQL input are registered under the existing independent migration-input guard.

### Owner-authorized preview reconciliation and data preservation

The Owner explicitly authorized repairing the preview with its data preserved, after being told that this included schema and internal-function privilege reconciliation. The original catalog was not sealed. Independent comparison used the protected baseline catalog captured by the passing PostgreSQL 18.6 test, rather than accepting the preview's own digest as its reference.

The raw comparison contained 249 record differences: one schema ACL, three policies, 241 functions and four constraints. Of the function definitions, 154 differed only by CRLF; two contained older custody-enrollment behavior (`builderad_private.source_grant_issue` and `public.builderad_business_source_authority`). There were 213 default PUBLIC-execute function ACLs in the preview. The local reconciliation restored the canonical definitions, owners and ACLs, with no application data changes. It did not create a production repair endpoint.

The first repair transaction rolled back before committing because seven deparsed policy/constraint expressions differed in the placement of casts around constant varchar arrays. Six distinct constant-array pairs account for these seven records. PostgreSQL independently confirmed equal values **and equal types** for each pair. Only these six explicit pairs, ACL order/default representation and CRLF were normalized in the local independent comparison. All other catalog content had to match exactly. Product catalog-digest logic and migration assertions were unchanged. The local helper then requalified the matching predecessor and applied migrations `000001` and `000002` in the same transaction.

Before mutation, a repeatable-read exported snapshot and exclusive table locks supported a complete custom-format backup. Applicable RLS policies were checked for an unrestricted owner read. SHA-256 fingerprints of sorted row JSON, using all original columns, matched before reconciliation and after both migrations for all 112 original tables other than the migration ledger. Added nullable image columns were excluded from the original-column projection. Accounts, Businesses and existing rows were preserved; no user grant was changed for testing. The final migration ledger contains all three migrations, and the final catalog seal is true.

Local retained evidence under `storage/framework/testing/`:

- `preview-catalog-before-brand.json`, `preview-catalog-summary.json`, `preview-catalog-difference.json`: original state and classified differences.
- `goals-spa-73ceeb54-cb45-4bf6-9210-5af136e469e1/canonical-baseline-catalog.json`: independent baseline reference.
- `preview-brand-reconciliation.sql`: reviewed local DDL; SHA-256 `524722cd2687137853cd45bf6aa59aa797ea60d3df03c9f48695318f667b7c4b`.
- `preview-cast-equivalence-queries.json`, `preview-cast-equivalence-proof.json`: six typed-array proofs; proof SHA-256 `bb64421b093fd87b75b228d9ab02a451e77c6f8c30026fa0b1c75cc66bac11e2`.
- `preview-brand-reconciliation-result.json`: transaction result, data-preservation checks and final seal.
- `preview-backup-archive-list.txt`: readable PostgreSQL 18.6 custom archive, 1,799 TOC entries. A full restore was not performed.
- `preview-brand-final-readback.json`: final migration ledger, actual palette and image privileges. Application role has SELECT only; authority role has SELECT/INSERT, with neither UPDATE nor DELETE. Actual preview revision is 2, colors remain `#2e5ff5` / `#4083f7`, and custom logo/favicon are null. No synthetic image was published into the Owner's data.
- `preview-brand-served-assets.json`: homepage 200 with favicon link; actual main JS/CSS and Appearance JS/CSS bytes match the local production files. Stable-preview status also confirmed the exact checkout, application/authority/owner roles and Redis store over HTTP.

The successful pre-repair backup is private local data at `storage/app/retirement-preview/private/brand-before-reconciliation-20261116-204042.dump`, SHA-256 `fa90ca021ae70746745070e89d3c2b4c21429b9a003e456271155aededf29488`. The earlier backup and rolled-back attempt were retained. Neither backup nor private credentials are publication artifacts.

### Verification for the final candidate

| Check | Result and scope |
| --- | --- |
| Business information, offers, V18 and purposes | 7 browser cases PASS, including 148 layout observations across six widths, Arabic/English and both themes; lowest measured contrast 4.7035:1. Run `goals-spa-cb5888c8-f47f-4442-8c0c-0b3c75b0c0c0`, source unchanged. This precedes the additive branding work. |
| Focused Business Node checks | 126 PASS before branding, `v3-closure-targeted-node.log`. |
| Branding and advertiser Node checks | 112 PASS, `v3-brand-node.log`; the later 10-case appearance subset also passed. Counts overlap and are not summed. |
| PostgreSQL behavior | 19 tests / 258 assertions PASS in `goals-spa-73ceeb54-cb45-4bf6-9210-5af136e469e1`. Includes bytes, authority, atomic rollback, populated upgrade, old-schema compatibility and rejection of unsealed predecessors. That combined run subsequently failed its browser fixture's non-HTML guest request; it is not labelled an overall passing run. |
| Final appearance browser gate | All 13 cases PASS, no page errors or unexpected responses, run `goals-spa-b691ef4f-95f1-4c44-a9f4-2584227aee47`, exit 0, source unchanged. Covers valid upload, invalid selection, publication, reload, fresh guest image bytes, immutable old URLs, default restoration, unknown response, stale revision, fresh authentication and actual authority/session loss. Isolated accounts and disposable PostgreSQL were used. |
| Migration integrity | 47 tests / 81 assertions PASS in the retained focused integrity run. After the final new-SQL prologue change, the actual-history/readers comparison was rerun: 1 test / 1 assertion PASS. Protected historical inputs remain unchanged. |
| Contract, build and color checks | API/type generation check PASS; typecheck and production build PASS; Color unit tests 2 / 36 PASS. Final production manifest SHA-256 `08fea446407d2660780e45b5f06315ac69d19545d55c73573611cc1ba3d85a63`. |
| Evidence consistency | Current sources exactly match the final browser source manifest before this documentation append. Against the 19-test PHP run, the only difference is `tests/Support/appearance-browser.mjs`, correcting the guest navigation fixture. Production PHP, SQL, contracts and build match. |
| Actual preview | Backup, catalog reference comparison, 112-table preservation, two forward migrations, final seal, image privileges, HTTP runtime and four served asset hashes PASS. |

No full local Foundation was run. Temporary image capture was not written to disk; automated browser runs had image capture disabled. Owned test clusters and processes were stopped and cleaned. The Owner's original preview and Redis remain running as requested. The original failed browser runs and the rolled-back reconciliation remain visible in their logs.

The final branding surface changes are `app/Appearance/BrandImage.php`, `GlobalAppearance.php`, `GlobalAppearanceController.php`, the new `000002` migration and SQL input, the migration-input manifest, routes, shared brand/head templates, `BrandLogo.vue`, appearance state/view, OpenAPI generator/contract/types and focused fixtures/tests. Business presentation changes are in the existing workspace, editor, facts, history, practices, knowledge, styles and focused tests; `DESIGN.md` and this report record their scope. Existing local work was preserved. Final Git state remains branch `design/advertiser-experience-redesign`, HEAD `50d908c027452650eb1a092d81fb5eaa4926aad4`, empty index, with tracked modifications and untracked additions. No commit, push, PR, merge or deployment was performed.
