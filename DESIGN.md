# BuilderAd visual context

## Register and audience
Hybrid: `/` is a public Arabic-first marketing entry; authenticated advertiser and internal administration remain task-oriented applications. Arabic RTL and complete English LTR are peers. The public page makes availability explicit; domain internals are not product copy.

## Identity and token ownership
`App/Appearance/GlobalAppearance.php` owns published primary `#155f53` and secondary `#334b73`, their contrast-tested foregrounds and inks. Existing color administration stays authoritative; no second color settings store. The new shared mark is `public/brand/builderad-mark.svg`, paired with the BuilderAd wordmark in `BrandLogo.vue` and `public/brand.blade.php`. Never use the framework's ApplicationLogo as BuilderAd branding.

`public/brand/theme.css` adapts existing tokens for light/dark display: daytime paper `#ffffff`, pale canvas `#f4f8f9`, ink `#182f39`; night canvas `#101d26`, surface `#192832`, text `#e7eef2`, muted `#b5c6cf`. Published button colors retain their contrast-calculated foregrounds. Night wordmark uses `#b5e4d3` and `#b4c9ed`; the same mark becomes light without changing its geometry. `theme.js` is the sole preference owner, initialized before paint. System preference applies until explicit choice; storage denial does not break rendering. Preference contains no identity or protected state.

## Composition
Public hero uses a real advertiser reasoning sequence, not fabricated product charts: understand the business, qualify evidence, choose a step. Generous whitespace and a quiet decision-path panel; upcoming capabilities explicitly say in development. No generated imagery, unverified logos/testimonials/numbers or provider promises. Existing administration layout, navigation and form components are retained.

## Typography and accessibility
Public display: bold Segoe UI/Tahoma; body: Tahoma/Segoe UI/Arial with full Arabic coverage. Wordmark uses Segoe UI; no external font request. Logical layout properties, 390px mobile baseline, no horizontal overflow, visible focus, native semantic controls, reduced-motion support. Shared styles own scrollbars and theme controls. Preserve errors, unknown outcomes and authority denials in both locales.

## Canonical owners
Runtime appearance: GlobalAppearance → published CSS variables → public styles / app styles / theme adapter. Display preference: public/brand/theme.js. Brand: BrandLogo and public brand partial, same static asset. Application behavior: UX-CONTRACT.md and the existing SPA identity/presentation protocol. Visual changes do not grant authority or advertise future capabilities as available.
