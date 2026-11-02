# BuilderAd affected-surface interaction contract

This records current owners for the public-platform change; it does not replace canonical governance or require unrelated screen rewrites.

| Capability | Canonical owner | Source of truth | Allowed variants | Verification |
|---|---|---|---|---|
| Form | existing internal form styles / MaintenanceView | typed OpenAPI, server validation | maintenance activation/restoration | PlatformMaintenanceTest, browser flow |
| Dialog | Components/Modal.vue | native dialog, accessible names, cancellation and focus restoration | bounded operational confirmation | browser keyboard flow |
| Navigation | AdministrationLayout / AdministrationNavigation | server AdministrationNavigation projection, canonical destination guards | desktop/sidebar, mobile drawer | admin-ui tests + browser |
| Display preference | public/brand/theme.js | local light/dark choice or system preference | public, advertiser, internal | theme unit and browser |
| Scrollbar | public/brand/theme.css | shared visible scrollbar baseline | browser and forced-colors defaults | computed styles |
| Async effects | SPA transport/presentation and maintenance state | server command receipt + state revision | pending, acknowledged, unknown, conflict, denied, fresh auth | focused state tests + HTTP |
| Status | inline role=status/alert | acknowledged result vs qualified current read | success, failure, unknown, conflict | HTTP/state/browser |

No new table selection, combobox or date input is introduced. Numeric input accepts a retry delay, not an appointment or guaranteed completion time. Error text remains next to the action; no transient-only result. Inputs keep drafts during pending/unknown; no automatic retry or replacement command. Unknown result requires a current read and explicit draft replacement. Leaving never silently executes an unresolved command. Explicit draft discard does not undo a committed effect.

Maintenance has one initial scope: new advertiser requests are denied at server admission while active; public entry, protected internal recovery and the finite identity/security allowlist remain available. Already-admitted HTTP work and already-queued, independently authorized jobs may drain. This is not database quiescence, scheduler control, or a deployment lock. Infrastructure maintenance remains a separate offline operation.

Every future material capability must update the surface record in docs/execution/public-platform/SURFACES.md (or its accepted successor): user job, audience/context/authority, placement or deliberate internal/deferred status, readable evidence limits, state transitions, next action, and actual verification. A schema or green backend suite alone does not complete a user-facing capability.


## Business workspace variant — 2026-11-01

Existing BusinessSurface admission owns the route; BusinessWorkspace owns local task navigation only. Navigation must not imply a grant or trigger duplicate fact ownership. Visited panels retain mounted drafts; exact document/Business/authority-scoped return context stores section/scroll only. Unsent Inquiry form drafts may survive remount in document memory after current qualification, preserving the original edit baseline; denial/success/cancel/identity closure clear the applicable draft. No localStorage evidence cache.

Inquiry collection/detail/create/correction use existing typed API/SQL authority. Explicit current record selection, back/focus, append-only history, separate read permission, no hidden closed form payload, no automatic replay or replacement of an uncertain command. Definitive initial validation rejection preserves editable input; generic conflict/transport loss remains uncertain. Locale changes translate retained critical status without clearing it. Source assertions never become verified leads/sales/profit through presentation. Exact user-job/browser evidence and limits: docs/execution/structural-foundation/BUSINESS_WORKSPACE_UX.md. Existing brand theme controls and native semantic field/select/date ownership remain in use; no new date parser or pagination meaning.


## BF03 bounded correction — retained display and criterion snapshots

Returning to a retained Inquiry panel requires fresh display qualification. Inactive panels clear protected collection/history/read-derived editor state synchronously; current identity/Business/basis/activation tickets govern delayed replies. Unsent drafts are presentation only and restore after their independently required current rights. Read failure or denial conceals prior data; a known missing record/correct right closes the affected editor with localized feedback, while independent reading remains available. Pending command identities remain immutable.

Operator-defined acceptance now carries a bounded abstract plain-text definition in each immutable source-asserted revision, separately from its criterion version. New custom acceptance requires the definition; historical missing meaning remains explicitly unknown. Render through escaped interpolation. Contact-pattern/code/reference rejection and source no-personal-data obligations are bounded safeguards, not universal PII detection or independent verification. No external definition resolver or criteria registry.
