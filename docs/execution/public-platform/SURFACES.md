# Capability-to-surface acceptance record

Current scope: public platform experience, maintenance, logo and light/dark display. This inventory classifies actual owners; it does not claim future product capabilities are implemented. Existing routes retain their own authority.

| Capability / user job | Audience and placement | Authority / context | Visible states and next action | Evidence / acceptance |
|---|---|---|---|---|
| Understand BuilderAd and start | public `/` | public information only; no customer payload | available today vs in development, maintenance notice; login/register/recovery | PlatformMaintenanceTest + public-platform browser |
| Business setup/context/goals | advertiser `/dashboard` → `/businesses`, existing workspace context/goals routes | current advertiser and exact Business/Workspace rights | existing empty/data/denied/current states; create/open/configure | existing BusinessFoundation/BusinessSurface/context tests; new dashboard logo browser |
| Sources and provider observations | advertiser Sources routes | source-specific rights and current Business context | available/unknown/failed/rights withdrawn; connect or review only supported provider | existing source/provider tests; no broad UI rewrite in this task |
| Team and permission administration | internal existing administration | staff.view; individual mutation grants and fresh auth | eligible navigation, invite/grant/revoke with established feedback | existing InternalAdministration tests; navigation tests |
| Customers | internal Customers | canonical Founder directory authority, no tenant-content access | existing directory/filter/status, deny unqualified users | existing customer tests; server menu projection |
| Business types | internal Catalog | business.manage | existing edit/validation/revision behavior | existing business type tests |
| Service identities | internal Services | service.manage | existing create/rotate/revoke; no keys in navigation | existing service tests |
| Commercial operations / market preparation | existing internal commercial routes | existing exact commercial grants and preparation authority | existing unresolved eligibility, no bypass via menu | existing commercial tests; navigation remains presentation only |
| Published brand colors | internal Global appearance; applied to public/app | current Founder + grant.manage | draft/published/conflict/unknown/fresh auth | GlobalAppearanceTest / appearance JS; no new settings store |
| Pause/restore advertiser service | internal Platform → status and maintenance | explicit platform.maintenance.manage; current eligible staff/session and fresh auth; current revision and UUID command | available/paused, pending/saved/unknown/conflict/denied/fresh; review status or restore | PlatformMaintenanceTest, independent real-lock worker, browser activation/restoration |
| Temporary service pause | advertiser routes/APIs | server admission state; no identity bypass | localized 503 + Retry-After + no-store; homepage and security recovery | direct HTTP tests + browser |
| Branding and display preference | public, dashboard and internal headers | display only; no right, subscription or data authority | light/dark, system default, local persistence, storage denial | public-platform JS and browser, mobile RTL/LTR |
| D1 market evidence | internal foundation/API, no standalone advertiser table | source/field/purpose rights, separate admission/intake | original/revision/currentness/denied; business reader owns delivery | existing D1 tests; internal by deliberate decision |
| D2 bounded processing | internal foundation/API, not a completed advertiser product | current qualified inputs and trusted completion | validated/abstained/failed/replay; consumers later | merged PR51; internal by deliberate decision |
| D3 derived knowledge / reuse | deferred, not implemented | accepted design and independent rights | no menu entry or claim of availability | separate D3 implementation required |
| Customer discovery / competition / growth capabilities | deferred product journeys | specialized evidence, method and rights required | marketing labels in development; no fake dashboard actions | separate capability scope and end-to-end release evidence required |

For every added material capability, record its user job, real surface or reason to stay internal, authority/context/subscription when applicable, evidence limits, failure/unknown/maintenance states, next action and UI/backend/browser evidence. `BACKEND_VERIFIED`, `UI_INTEGRATED` and `BROWSER_VERIFIED` are separate. The existence of an endpoint does not complete a user journey. Do not generate screens just to fill this record.
