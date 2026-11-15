# BuilderAd — runtime appearance context

**RETAIN_TECHNICAL_CONTRACT.** This file records existing presentation owners for UI work. It is not product governance, a screen backlog or a continuation of historical navigation plans. [Governing architecture](docs/architecture/BUILDERAD_GOVERNING_ARCHITECTURE.md) owns design; [current implementation](docs/architecture/CURRENT_IMPLEMENTATION.md) owns source/evidence claims; [engineering standards](docs/engineering/ENGINEERING_STANDARDS.md#user-experience-and-lifecycle) owns lifecycle and verification guidance.

## Audience and structure

The public entry is Arabic-first marketing; advertiser and internal administration are task-oriented applications. Arabic RTL and English LTR share behavior. Public copy distinguishes available capabilities from development; no fabricated metrics, testimonials, provider readiness or commercial claims.

Business is the advertiser's selected operating context. Internal administration has its own navigation. A public Workspace or previous-record navigation area must not be recreated from an old prototype. A visible task, logo, job title, agency declaration or Source connection grants no data access. Current routes and server projections determine what can be shown.

## Existing owners

| Concern | Runtime owner |
|---|---|
| Published appearance | [GlobalAppearance](app/Appearance/GlobalAppearance.php), existing color administration, published CSS variables. Do not create another color/settings store. |
| Brand | [BuilderAd mark](public/brand/builderad-mark.svg), [BrandLogo](resources/js/Components/BrandLogo.vue) and [public brand partial](resources/views/public/brand.blade.php). |
| Light/dark preference | [theme.js](public/brand/theme.js) and [theme.css](public/brand/theme.css). Preference is appearance only, not identity or protected data. System preference applies until explicit choice; storage denial must not break rendering. |
| Advertiser layout/tasks | Existing Business views, shared controls and [workspace.css](resources/js/business-foundation/workspace.css). Reuse tokens/layout instead of a new design system or historical section count. |
| Identity/navigation/private-state lifetime | Existing SPA transport/router/presentation/browser lifecycle and server authority, as mapped in Engineering standards. |

## Appearance and interaction constraints

Preserve the compact application typography (14px body, 22–24px headings), natural document scrolling, bounded sidebar/mobile drawer and 44px primary mobile targets. Use the existing Arabic-capable system fonts, logical layout properties, visible focus, keyboard/native semantics and reduced-motion behavior. Preserve readable light/dark contrast and shared scrollbars. Do not add a display font or dependency just to restyle an existing task.

Keep evidence limits, unavailable/denied/unknown states, pending command identity and recovery visible in both locales. Preparation is a summary of declared context, not a performance score. Forms preserve original edit baselines and private drafts only within the existing qualification/lifecycle rules. Returning to a panel is not permission to redisplay retained protected data.

For a changed user job, verify actual placement, audience/Business context, authority, entitlement where applicable, RTL/LTR, relevant viewports, focus and failure/recovery. Backend/API proof alone is not browser acceptance. Do not generate images or screenshots without Owner approval.

Relevant existing checks: [appearance](tests/appearance.test.mjs), [advertiser workspace](tests/advertiser-workspace.test.mjs), [admin UI](tests/admin-ui.test.mjs), [SPA presentation](tests/spa-presentation.test.mjs), and the separate guarded browser selections. Their presence is not a claim that this documentation task reran them.
