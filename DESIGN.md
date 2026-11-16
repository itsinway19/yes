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

Preserve the compact application typography (14px body, 22–24px headings), natural document scrolling, bounded sidebar/mobile drawer and 44px primary mobile targets. Use the locally served OFL-licensed Noto Sans Arabic variable font with Segoe UI/Arial fallbacks, logical layout properties, visible focus, keyboard/native semantics and reduced-motion behavior. Preserve readable light/dark contrast and shared scrollbars. Do not add a display font or dependency just to restyle an existing task.

Keep evidence limits, unavailable/denied/unknown states, pending command identity and recovery visible in both locales. Preparation is a summary of declared context, not a performance score. Forms preserve original edit baselines and private drafts only within the existing qualification/lifecycle rules. Returning to a panel is not permission to redisplay retained protected data.

For a changed user job, verify actual placement, audience/Business context, authority, entitlement where applicable, RTL/LTR, relevant viewports, focus and failure/recovery. Backend/API proof alone is not browser acceptance. Do not generate images or screenshots without Owner approval.

Relevant existing checks: [appearance](tests/appearance.test.mjs), [advertiser workspace](tests/advertiser-workspace.test.mjs), [admin UI](tests/admin-ui.test.mjs), [SPA presentation](tests/spa-presentation.test.mjs), and the separate guarded browser selections. Their presence is not a claim that this documentation task reran them.

## Advertiser experience — V3

The approved V3 HTML is the visual reference for the Business dashboard. The overview leads with the decision supported by V18, followed by tiles for the offer, markets and priority. The Business surface supplies the content. The desktop sidebar and mobile navigation follow real tasks and available access. Put declarations and private history in the advanced Business context.

Keep the visual hierarchy of V3 with a 26–36px page title, 23–30px decision title and 14px reading text. Use the shared Arabic font, logical spacing and restrained borders. The native editing dialogs are centered at wider widths and dock at the bottom below 600px. The bottom navigation reserves space in the document and follows the viewport safe area.

The V3 shell owns its neutral palette: navy `#192b40` text, `#5e7189` supporting text, `#f6f8fc` canvas and `#e5ebf3` surface borders, with corresponding dark values. The supporting text is darker than the prototype to preserve contrast. Use 20px card corners, 12px controls, restrained surface shadows and 650-weight page headings up to 34px. Keep input boundaries visibly defined by the existing control-border token. Existing components inherit the shell palette instead of the global black foreground. Offers are individual compact cards on the canvas; do not place the list inside another white card. Target market and declared service area remain distinct facts.

The administration-published primary color owns filled actions and navigation accents. The secondary color tints the dark decision surface and supporting accents. The neutral canvas and semantic status colors have their own roles. Use the calculated primary foreground with the original primary fill and derive readable inks for light and dark surfaces.

The font lives in `resources/fonts/noto-sans-arabic/`, with its OFL licence in `public/fonts/noto-sans-arabic/`. Vite bundles the font for development and production and loads it from the application asset origin. The appearance settings publish two colors. V3 uses the bundled font and the existing appearance settings.

Current Business facts and the dedicated history view are visible without disclosure controls. Precision fields are visible in their selected intent step. Optional reference/technical details and long secondary histories keep native details semantics, with localized Show details / Hide details text instead of browser triangle markers. The qualified purpose entry is a direct action, without a wrapper disclosure. Menus and native select controls retain their standard indicators.

GlobalAppearance also owns the published site logo and browser tab icon. Founder-only publication uses the same revision, fresh authentication and atomic audit as colors. PNG inputs stay local until explicit publication; each is bounded to 512 KB, logos to 2048x2048, and square icons to 512x512. The uploaded logo replaces the existing header mark and wordmark. Defaults remain available. Public content-addressed image responses carry no session or identity metadata, and older published URLs remain valid for open documents.
