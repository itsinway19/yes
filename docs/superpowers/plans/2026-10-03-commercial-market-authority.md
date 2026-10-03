# Commercial market authority preparation

**Goal:** Implement the Owner-approved Founder → exact administrator → operator → recorded market journey, under existing Administration.
**Architecture:** Immutable authority approval evidence; server-retained intent; atomic exact-target administrator grant through GrantAuthority. Independent operational Configure and Read grants, unchanged Commercial writer/RLS.
**Constraints:** No commit, push, merge, Target Market, manual target IDs, generic grant console, or operational self-grant.

## Task 1 — Authority and HTTP boundary
Write focused feature tests first for Founder qualification, denial, exact UUID propagation, replay/conflict, failed issuance rollback, revoked/expired administration, and separate operational/read grants. Run SA01Proof filtered to the new test: expect RED before implementation. Implement one forward migration, narrow GrantAuthority methods and independently gated Administration endpoints. Re-run: expect GREEN.

## Task 2 — Bounded presentation
Add a bilingual Administration subpage with real eligible-person choices, market display name, reason, expiry and approval result; system IDs only read-only evidence. Preserve retry intent until confirmed. Add discoverable authorized links and consumed-target filtering in Commercial. Run operator/localization tests and typecheck/build: expect passing.

## Task 3 — Closure
Run focused new and existing Commercial/authority tests, two-session consumption proof, rendered Arabic/English desktop/mobile checks, git diff --check. Record exact evidence and remaining gaps; stop all task processes. No commit. Review the final patch for authority lifetime, retries and unauthorized input classes.

## Review focus
Generation changes, role revocation during waits, request-forged system IDs, partial grants, replay with changed payload, registry visibility independent of Configure, concurrent target consumption, and historical evidence retention.

## Ledger
Preflight: Owner decision explicit; existing SQL recording/idempotency boundaries unchanged. No commit requested; user instruction overrides skill commit steps. Worktree reused to preserve existing uncommitted Phase A changes.

Task 1: complete — initial missing endpoint RED; corrected Founder fixture requires two factors and independence evidence, never bypassed authentication. Focused actual DB tests 4/4, 81 assertions; wider authority/Commercial cone 45/45, 745 assertions.
Task 2: complete — human fields, independent authority presentation and real links; final native Vue/localization tests 35/35, build/typecheck pass. Owner reported disabled fields; now only missing administrator selection and final effect button are gated, human text/date fields remain editable.
Task 3: complete — real Founder approval → distinct administrator → operator grant → market recording → consumed choice removal → readable registry → immutable historical approval proven in disposable browser; Arabic/English desktop/mobile checked, no overflow, processes stopped.
Task 3: Ruling: Two sessions for the same user serialize at existing SessionAuthority's principal advisory lock. Use two distinct eligible, exact-authorized operators to exercise market uniqueness itself; observed real DB waiter, one success/one409, one market/receipt. Cost if wrong: concurrency coverage narrower than assumed; existing authority serialization retained.
Task 3: Ruling: Only exact000280 forward migration applied to existing local preview builderad_test:5432 after its missing-table500 was traced. Existing users/workspaces/grants/markets counts preserved. Disposable test runners still never connect to5432. Cost if wrong: preview schema changes; additive table/function only, no existing authority/data mutation.
Final review: independent reviewer found two important recovery issues before its session hit a usage limit. Both reproduced RED and fixed GREEN: first definitive403/409 no longer traps editable requests; stable session-bound storage survives changed server intent; uncertain operator follow-up can retire only against refreshed consumed evidence, preserving original request without claiming grant success. Final consumed-selector runtime finding also reproduced RED and fixed GREEN, including newMarketTargets in partial reload and excluding readable recorded targets immediately.
Final review: author completed bounded follow-up review; independent full verdict unavailable due reviewer usage limit. No merge/release acceptance claim. Known broad-suite/N1 durability/real WebAuthn gaps remain in the closure report.
