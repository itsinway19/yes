@php
$ar = $locale === 'ar';
$c = fn ($a, $e) => $ar ? $a : $e;
$title = $c('BuilderAd — صورة أوضح، وخطوة تسويقية أفضل', 'BuilderAd — A clearer picture. A better marketing step.');
$description = $c('نظّم نشاطك وأهدافك ومصادرك مع BuilderAd. اكتشف المتاح اليوم ورؤيتنا لفهم الفرص وتحسين الوصول والنتائج.', 'Organize your business, goals and sources with BuilderAd. Explore what is available today and our vision for better opportunities, reach and results.');
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <script src="/brand/theme.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="website">
    @include('public.styles')
    @include('public.content-styles')
    <style id="published-appearance">:root {@foreach($appearanceTokens as $token => $value){{ $token }}:{{ $value }};@endforeach}</style>
    <link rel="stylesheet" href="/brand/theme.css">
    <style>
        :root{scroll-padding-top:90px}
        body{background:var(--public-paper);color:var(--public-ink);font-family:Tahoma,"Segoe UI",Arial,sans-serif;line-height:1.8}
        .public-wrap{width:min(1340px,calc(100% - 64px))}
        .public-nav{width:min(1340px,calc(100% - 64px));min-height:76px;border-color:#1b2230;gap:24px}
        .public-brand,.public-brand span{color:#f1f4fb}.public-brand{font-size:23px;letter-spacing:-1px}
        .public-links{gap:24px;color:#a4b2c8;font-size:14px}.public-links a:hover{color:#fff}
        .public-language button,.public-menu summary{color:#dbe3f1;background:#101522;border-color:#242d3d;border-radius:12px}
        .public-nav-actions{display:flex;align-items:center;gap:10px}.public-nav-actions .public-theme-toggle{margin:0}
        .public-eyebrow{display:inline-flex;align-items:center;gap:10px;width:max-content;max-width:100%;border:1px solid #104c41;border-radius:999px;background:#0b1b1c;padding:8px 16px;color:#27d5ad;font-size:14px;margin:0 0 22px}
        .public-eyebrow:before{content:"";width:8px;height:8px;border-radius:50%;background:#27d5ad;box-shadow:0 0 0 5px #27d5ad22}
        .ba-hero{display:grid;grid-template-columns:.95fr 1.05fr;align-items:center;gap:clamp(48px,8vw,112px);padding-block:38px 58px;min-height:610px}
        .ba-hero-copy{text-align:end}.ba-hero h1{font-size:clamp(42px,5.3vw,76px);font-weight:800;line-height:1.28;letter-spacing:-1.6px;margin:0 0 22px}
        .ba-hero h1 .gradient-text{background:linear-gradient(100deg,#24d0a9,#23b7ed 53%,#8961f5);color:transparent;background-clip:text;-webkit-background-clip:text}
        .ba-hero .public-lead{font-size:19px;max-width:670px;margin-inline-start:auto;color:#91a7c4}
        .public-actions{justify-content:flex-start;margin-block:28px 14px}.ba-hero-copy .public-actions{justify-content:flex-end}
        .public-button{border-radius:15px;padding:13px 25px;min-height:54px;background:#111824;border-color:#2a3444;color:#e9eef8;transition:transform .18s,box-shadow .18s}
        .public-button.primary{background:linear-gradient(105deg,#25d2a5,#18bde6 58%,#8261f4);color:#07121b;border:0;box-shadow:0 12px 34px #21c9a533}
        .public-note{color:#7f94b1}
        .ba-dashboard{position:relative;border:1px solid #28313e;border-radius:30px;padding:28px;background:linear-gradient(145deg,#151a24,#0d111a 80%);box-shadow:0 30px 90px #0005;min-height:430px}
        .ba-dashboard-badge{position:absolute;inset-block-start:-18px;inset-inline-start:26px;border:1px solid #2a3444;border-radius:15px;background:#101622;padding:10px 16px;font-weight:700;color:#dbe5f4}
        .ba-dashboard-top{display:flex;justify-content:space-between;align-items:center;color:#98aecb;font-size:14px;margin-block-end:22px}.ba-live{color:#27d5ad;background:#10322f;border:1px solid #16564a;border-radius:999px;padding:3px 12px;font-size:12px}
        .ba-dashboard-main{display:grid;grid-template-columns:1fr .72fr;gap:18px;align-items:center}.ba-stat-stack{display:grid;gap:12px}.ba-stat{border:1px solid #252d39;background:#171c26;border-radius:16px;padding:17px 20px;min-height:84px}.ba-stat strong{display:block;font-size:24px;color:#25d1a7;line-height:1.25}.ba-stat span{font-size:13px;color:#91a7c4}
        .ba-ring-wrap{display:grid;place-items:center}.ba-ring{width:156px;aspect-ratio:1;border-radius:50%;display:grid;place-items:center;background:radial-gradient(closest-side,#10141d 83%,transparent 84% 99%),conic-gradient(#25d1ad 0 82%,#26333b 82%)}.ba-ring strong{font-size:30px;color:#f0f4fb}.ba-ring small{display:block;text-align:center;color:#91a7c4;font-size:12px}
        .ba-chart{margin-block-start:18px;border:1px solid #252d39;background:#151a24;border-radius:16px;padding:14px 18px}.ba-chart-head{display:flex;justify-content:space-between;color:#91a7c4;font-size:13px}.ba-chart svg{display:block;width:100%;height:112px;margin-block-start:8px}
        .ba-proof{background:#0d1422;border-block:1px solid #1b2636}.ba-proof-list{display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap;padding-block:18px;color:#91a7c4;font-size:13px}.ba-proof-list span:before{content:"●";color:#27d5ad;font-size:12px;margin-inline-end:10px}
        .public-band{background:#0c1220}.public-section{padding-block:88px;border-color:#1c2635}.public-heading{text-align:center;max-width:760px;margin:0 auto 38px}.public-heading h2,.ba-centered-title{font-size:clamp(30px,3.4vw,45px);line-height:1.35;margin:0 0 12px}.public-heading p{font-size:16px}
        .public-grid{gap:14px}.public-feature,.ba-card{border:1px solid #202a39;background:#141a27;border-radius:22px;padding:25px;text-align:start}.public-feature{border-inline-start:1px solid #202a39}.public-feature h3{font-size:20px}.public-feature p{font-size:14px}.public-state{background:#10332f;color:#36d4ae;border-radius:999px;padding:4px 12px}.public-state.planned{background:#201d36;color:#b5a5ff}
        .ba-card-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.ba-card-icon{display:grid;place-items:center;width:50px;height:50px;border-radius:15px;background:#102d31;font-size:23px;margin-block-end:16px}.ba-card h3{font-size:20px;margin-block-end:8px}.ba-card ul{list-style:none;margin:14px 0 0;padding:0;color:#91a7c4;font-size:14px}.ba-card li{padding-block:5px}.ba-card li:before{content:"✓";color:#25d1a7;margin-inline-end:8px}.ba-card-result{margin-block-start:16px;padding-block-start:14px;border-block-start:1px dashed #30394a;color:#eef2fa;font-weight:700;font-size:14px}
        .ba-split{display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:56px}.ba-bars{border:1px solid #202a39;border-radius:24px;background:#151b29;padding:26px}.ba-bars svg{display:block;width:100%;height:auto}.ba-split-copy h2{font-size:clamp(30px,3.5vw,46px);line-height:1.35}.ba-platforms{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.ba-platform{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #202a39;background:#121824;padding:20px;border-radius:18px}.ba-platform small{display:block;color:#91a7c4}.ba-platform .public-state{margin:0}.ba-step-card{min-height:172px}.ba-step-number{display:grid;place-items:center;width:42px;height:42px;border-radius:14px;background:linear-gradient(120deg,#25d2a5,#20b7e6,#8261f4);color:#07121b;font-weight:800;margin-block-end:14px}
        .public-faq{max-width:850px}.public-faq h2{text-align:center;font-size:clamp(30px,3.5vw,44px);margin-block-end:32px}.public-faq details{padding:17px 22px;margin-block:10px;border:1px solid #202a39;background:#111722;border-radius:15px}.public-faq summary{font-weight:700;cursor:pointer;list-style:none}.public-faq summary::-webkit-details-marker{display:none}.public-faq summary:before{content:"+";color:#25d1a7;font-size:21px;margin-inline-end:14px}.public-faq details[open] summary:before{content:"−"}.public-faq p{margin-block:12px 0}
        .ba-final-cta{margin-block:76px 88px;border:1px solid #263442;border-radius:28px;padding:52px 28px;text-align:center;background:linear-gradient(110deg,#0e2929,#141d30 52%,#1e1938)}.ba-final-cta h2{font-size:clamp(29px,4vw,47px);margin-block-end:8px}.ba-final-cta p{color:#91a7c4}.ba-final-cta .public-actions{justify-content:center}
        .public-footer{border-color:#1b2432;color:#91a7c4}.public-footer a{color:#91a7c4}.public-notice{background:#2b2414;border-color:#594a28;color:#f3d99e}
        .public-nav{width:min(1340px,calc(100% - 64px));min-height:76px}.public-nav .ba-logo{font-size:25px}.public-nav-actions{display:flex;align-items:center;gap:10px}.public-nav-actions .public-language button,.public-nav-actions .ba-theme-toggle{width:44px;height:44px;min-height:44px;padding:0;display:inline-flex;align-items:center;justify-content:center;gap:4px;border:1px solid #293242;border-radius:13px;background:#10141e;color:#dce5f3}.public-nav-actions svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.public-nav-actions .public-language button span{font-size:12px;font-weight:700}.theme-icon{fill:currentColor!important;stroke:none!important}.public-links{margin-inline:auto}
        @media(max-width:900px){.ba-hero{grid-template-columns:1fr;gap:46px;padding-block:48px}.ba-hero-copy{text-align:center}.ba-hero .public-lead{margin-inline:auto}.ba-hero-copy .public-actions{justify-content:center}.ba-dashboard{max-width:660px;width:100%;margin-inline:auto}.ba-split{grid-template-columns:1fr;gap:30px}.ba-bars{order:2}.ba-platforms{grid-template-columns:1fr}}
        @media(max-width:760px){.public-wrap,.public-nav{width:calc(100% - 36px)}.public-nav{align-items:center;flex-direction:row;flex-wrap:wrap;gap:12px;padding-block:14px}.public-nav .public-links{order:3;width:100%;overflow-x:auto;flex-wrap:nowrap;padding-block:4px}.public-nav-actions{margin-inline-start:auto}.public-hero{min-height:0;padding-block:42px}.ba-hero h1{font-size:clamp(38px,10vw,58px);letter-spacing:-.8px}.ba-hero .public-lead{font-size:16px}.ba-dashboard{padding:21px;border-radius:23px;min-height:0}.ba-dashboard-main{grid-template-columns:1fr .65fr;gap:9px}.ba-ring{width:min(120px,26vw)}.ba-stat{padding:13px;min-height:75px}.ba-stat strong{font-size:20px}.ba-proof-list{justify-content:center}.public-section{padding-block:60px}.public-grid,.ba-card-grid{grid-template-columns:1fr}.public-feature{padding:22px}.ba-final-cta{margin-block:48px;padding:38px 20px}.public-footer{padding-block:28px}}
        @media(max-width:420px){.ba-dashboard-main{grid-template-columns:1fr}.ba-ring-wrap{padding-block:8px}.ba-dashboard-top{font-size:12px}.public-links{gap:17px}}
        @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}.public-button{transition:none}}
        @media(forced-colors:active){.public-button,.public-state,.ba-card,.ba-platform,.ba-dashboard{border:1px solid ButtonText}}
        .landing-header {
            border-block-end: 1px solid var(--public-line);
        }
        .landing-header .public-nav {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
            border: 0;
        }
        .landing-header .public-links {
            justify-content: center;
            color: var(--public-muted);
        }
        .landing-header .public-links a:hover { color: var(--public-ink); }
        .landing-header .public-menu summary { background: transparent; color: inherit; }
        .landing-header .public-nav-actions { margin: 0; }
        .landing-header .public-sign-in { white-space: nowrap; text-decoration: none; }
        .landing-header .public-button { min-height: 48px; padding: 8px 18px; white-space: nowrap; }
        .landing-header .theme-icon { color: #ffc66d; }
        .landing-header .theme-sun,
        html[data-theme="light"] .landing-header .theme-icon { display: none; }
        html[data-theme="light"] .landing-header .theme-sun { display: inline; font-size: 22px; }

        .ba-hero {
            grid-template-columns: minmax(0, 1.12fr) minmax(0, 1fr);
            column-gap: clamp(26px, 4vw, 64px);
            row-gap: 36px;
            padding-block: 20px 68px;
        }
        .ba-hero > .public-eyebrow { grid-column: 1 / -1; justify-self: center; margin: 0; }
        .ba-hero-copy { text-align: start; }
        .ba-hero-copy .public-actions { justify-content: flex-start; }
        .ba-hero h1 { font-size: clamp(42px, 5vw, 78px); line-height: 1.18; }
        .ba-hero .public-lead { margin-inline: 0; color: var(--public-muted); }
        #how .public-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        #how .public-feature { text-align: center; }
        #how .ba-card-icon { margin-inline: auto; }
        .public-faq > .public-eyebrow { display: flex; margin-inline: auto; }
        .public-faq summary { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .public-faq summary::before { order: 1; margin: 0; }
        .public-nav,.public-footer,.public-section,.ba-proof{border-color:var(--public-line)}
        .public-brand{color:var(--ba-primary-ink)}.public-brand span{color:var(--ba-secondary-ink)}
        .public-links,.ba-proof-list,.ba-card ul,.ba-platform small,.ba-final-cta p,.public-footer,.public-footer a,.ba-dashboard-top,.ba-stat span,.ba-ring small,.ba-chart-head{color:var(--public-muted)}
        .public-links a:hover{color:var(--public-ink)}
        .public-language button,.public-menu summary,.public-nav-actions .public-language button,.public-nav-actions .ba-theme-toggle{background:var(--public-canvas);color:var(--public-ink);border-color:var(--public-line)}
        .public-eyebrow,.ba-live,.public-state{color:var(--ba-primary-ink);background:color-mix(in srgb,var(--ba-brand-primary) 12%,var(--public-paper));border-color:var(--public-line)}
        .public-eyebrow:before{background:var(--ba-brand-primary);box-shadow:0 0 0 5px color-mix(in srgb,var(--ba-brand-primary) 20%,transparent)}
        .ba-hero .public-lead,.public-note{color:var(--public-muted)}
        .public-button{background:var(--public-canvas);border-color:var(--public-line);color:var(--public-ink)}
        .public-button.primary{background:var(--ba-brand-primary);color:var(--ba-primary-text);box-shadow:none}
        .ba-dashboard,.ba-bars,.ba-platform,.ba-card,.public-feature,.ba-chart,.public-faq details,.ba-dashboard-badge,.ba-stat{background:var(--public-canvas);border-color:var(--public-line);color:var(--public-ink)}
        .ba-ring{background:radial-gradient(closest-side,var(--public-canvas) 83%,transparent 84% 99%),conic-gradient(var(--ba-brand-primary) 0 82%,var(--public-line) 82%)}
        .ba-ring strong,.ba-dashboard-badge,.ba-card-result{color:var(--public-ink)}
        .ba-stat strong,.ba-card li:before,.public-faq summary:before,.ba-proof-list span:before{color:var(--ba-primary-ink)}
        :root.dark .ba-stat strong,:root.dark .ba-live,:root.dark .public-state{color:var(--ba-logo-primary)}
        .ba-proof,.public-band{background:var(--public-canvas)}
        .public-state.planned{background:color-mix(in srgb,var(--ba-brand-secondary) 12%,var(--public-paper));color:var(--ba-secondary-ink)}
        .ba-card-icon{background:color-mix(in srgb,var(--ba-brand-primary) 12%,var(--public-paper))}
        .ba-final-cta{background:color-mix(in srgb,var(--ba-brand-primary) 9%,var(--public-canvas));border-color:var(--public-line)}
        .public-notice{background:var(--public-canvas);color:var(--public-ink);border-color:var(--public-line)}
        @media (max-width: 1050px) {
            .landing-header .public-nav { grid-template-columns: minmax(0, 1fr) auto; padding-block: 12px; gap: 12px; }
            .landing-header .public-links { grid-row: 2; grid-column: 1 / -1; width: 100%; flex-wrap: wrap; overflow: visible; }
        }
        @media (max-width: 900px) {
            .ba-hero { grid-template-columns: minmax(0, 1fr); }
            .ba-hero-copy { text-align: center; }
            .ba-hero-copy .public-actions { justify-content: center; }
            .ba-hero .public-lead { margin-inline: auto; }
            #how .public-grid { grid-template-columns: repeat(auto-fit, minmax(min(100%, 145px), 1fr)); }
        }
        @media (max-width: 480px) {
            .landing-header .public-nav { grid-template-columns: 1fr; }
            .landing-header .public-links { grid-row: 3; justify-content: flex-start; }
            .landing-header .public-nav-actions { flex-wrap: wrap; }
            .landing-header .public-menu-panel { position: static; }
        }
        /* Landing surfaces inherit the published appearance tokens. */
        html[data-theme="light"] {
            --landing-surface: var(--public-paper);
            --landing-accent: var(--ba-primary-ink);
            --landing-wash: color-mix(in srgb, var(--ba-brand-primary) 12%, var(--public-paper));
        }
        html[data-theme="light"] body {
            background: radial-gradient(ellipse 900px 440px at 90% 0, #ede7fc, transparent),
                radial-gradient(ellipse 700px 420px at 0 12%, #e1f4ed, transparent),
                var(--public-paper);
            color: var(--public-ink);
        }
        html[data-theme="light"] .landing-header { background: color-mix(in srgb, var(--public-paper) 86%, transparent); }
        html[data-theme="light"] :is(.ba-proof, .public-band) {
            background: var(--public-canvas);
            border-color: var(--public-line);
        }
        html[data-theme="light"] :is(.ba-stat, .ba-chart, .public-feature, .ba-card, .ba-platform,
            .ba-bars, .public-faq details, .public-menu-panel, .public-button:not(.primary)) {
            background: var(--landing-surface);
            border-color: var(--public-line);
            color: var(--public-ink);
        }
        html[data-theme="light"] .ba-dashboard {
            background: linear-gradient(180deg, var(--public-paper), var(--public-canvas));
            border-color: var(--public-line);
            box-shadow: 0 30px 70px -40px #0f172a59;
        }
        html[data-theme="light"] :is(.ba-dashboard-badge, .landing-header .public-language button, .landing-header .ba-theme-toggle) {
            background: var(--landing-surface);
            border-color: var(--public-line);
            color: var(--public-ink);
        }
        html[data-theme="light"] :is(.public-eyebrow, .ba-live, .public-state, .ba-card-icon) {
            background: var(--landing-wash);
            border-color: var(--public-line);
            color: var(--landing-accent);
        }
        html[data-theme="light"] .public-state.planned { background: color-mix(in srgb, var(--ba-brand-secondary) 12%, var(--public-paper)); color: var(--ba-secondary-ink); }
        html[data-theme="light"] .ba-ring {
            background: radial-gradient(closest-side, var(--public-paper) 83%, transparent 84%),
                conic-gradient(var(--ba-brand-primary) 0 82%, var(--public-line) 82%);
        }
        html[data-theme="light"] :is(.ba-ring strong, .ba-card-result) { color: var(--public-ink); }
        html[data-theme="light"] :is(.ba-card-result, .public-section, .public-footer-band, .public-footer-bottom) {
            border-color: var(--public-line);
        }
        html[data-theme="light"] :is(.ba-dashboard-top, .ba-stat span, .ba-ring small, .ba-chart-head,
            .ba-proof-list, .ba-card ul, .ba-platform small, .ba-final-cta p, .public-note, .public-footer a) {
            color: var(--public-muted);
        }
        html[data-theme="light"] .ba-stat strong,
        html[data-theme="light"] .ba-card li::before,
        html[data-theme="light"] .public-faq summary::before {
            color: var(--landing-accent);
        }
        html[data-theme="light"] .ba-bars svg text { fill: var(--public-muted); }
        html[data-theme="light"] .ba-bars svg g[stroke] { stroke: var(--public-line); }
        html[data-theme="light"] .ba-hero h1 .gradient-text {
            background-image: linear-gradient(100deg, var(--ba-primary-ink), var(--ba-brand-primary) 53%, var(--ba-secondary-ink));
        }
        html[data-theme="light"] .ba-final-cta {
            background: linear-gradient(110deg, color-mix(in srgb, var(--ba-brand-primary) 12%, var(--public-paper)),
                var(--public-canvas) 52%, color-mix(in srgb, var(--ba-brand-secondary) 12%, var(--public-paper)));
            border-color: var(--public-line);
        }
        html[data-theme="light"] .public-footer-band { background: var(--public-canvas); }
    </style>
@include('public.appearance-assets')
</head>
<body>
<a class="public-skip" href="#main">{{ $c('انتقل إلى المحتوى','Skip to content') }}</a>
@if($maintenance['active'] ?? false)<aside class="public-notice" role="status">{{ $maintenance['message_'.$locale] }}</aside>@endif
<div class="landing-header">
    @include('public.header', ['homeLinks' => true])
</div>
<main id="main">
    <section class="public-wrap ba-hero">
        <span class="public-eyebrow">{{ $c('الجيل الجديد لحماية وإدارة الإنفاق الإعلاني','A clearer way to understand your marketing') }}</span>
        <div class="ba-hero-copy"><h1>{{ $c('إعلاناتك تنفق دون عائد؟','Are your ads spending without returns?') }}<br><span class="gradient-text">{{ $c('اكتشف أين يضيع المال','Find where your budget goes') }}</span>{{ $c('، ولماذا، وماذا تفعل حيال ذلك.',' — and what to do next.') }}</h1><p class="public-lead">{{ $c('رتّب نشاطك وأهدافك ومصادرك في مكان واحد. اكتشف ما تعرفه وما لا تعرفه، ثم اختر خطوة واضحة تناسب نشاطك.','Bring your business goals and sources together. Understand what is known and what remains unclear, then choose a practical next step.') }}</p><div class="public-actions"><a class="public-button primary" href="/register">{{ $c('ابدأ مجانًا','Get started') }} <span aria-hidden="true">←</span></a><a class="public-button" href="/login">{{ $c('تسجيل الدخول','Sign in') }}</a></div><p class="public-note">{{ $c('نطور المنصة تدريجيًا. راجع المتاح اليوم قبل البدء.','The platform is developing in stages. Check what is available before you start.') }}</p></div>
        <div class="ba-dashboard" aria-label="{{ $c('رسم توضيحي للوحة قياس الأداء','Illustrative performance dashboard') }}">
            <div class="ba-dashboard-badge">{{ $c('حماية الإعلان ◈','Ad protection ◈') }}</div>
            <div class="ba-dashboard-top"><span>{{ $c('درع حماية الإنفاق','Spend protection') }}</span><span class="ba-live">{{ $c('نشط الآن','Illustration') }}</span></div>
            <div class="ba-dashboard-main"><div class="ba-stat-stack"><div class="ba-stat"><strong>42%</strong><span>{{ $c('وفر من ميزانيتك — مثال توضيحي','Illustrative metric') }}</span></div><div class="ba-stat"><strong>×2.4</strong><span>{{ $c('أداء أفضل — مثال توضيحي','Illustrative metric') }}</span></div></div><div class="ba-ring-wrap"><div class="ba-ring"><div><strong>98.7%</strong><small>{{ $c('مثال بصري','Visual example') }}</small></div></div></div></div>
            <div class="ba-chart"><div class="ba-chart-head"><span>{{ $c('الأداء بعد التحسين — رسم توضيحي','Illustrative performance trend') }}</span><span>{{ $c('تجريبي','Sample') }}</span></div><svg viewBox="0 0 520 110" role="img" aria-label="{{ $c('منحنى توضيحي','Illustrative trend chart') }}"><defs><linearGradient id="area" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#28d0a8" stop-opacity=".26"/><stop offset="1" stop-color="#28d0a8" stop-opacity="0"/></linearGradient></defs><path d="M5 92 C90 81 110 73 180 68 S290 50 350 40 S450 24 515 4 L515 108 L5 108Z" fill="url(#area)"/><path d="M5 92 C90 81 110 73 180 68 S290 50 350 40 S450 24 515 4" fill="none" stroke="#28d0a8" stroke-width="4" stroke-linecap="round"/></svg></div>
        </div>
    </section>
    <div class="ba-proof"><div class="public-wrap ba-proof-list"><span>{{ $c('تحكم واضح في بياناتك','Clear control of your data') }}</span><span>{{ $c('صلاحيات المصادر تبقى بيدك','You control source permissions') }}</span><span>{{ $c('تمييز المعروف من غير المؤكد','Known facts stay distinct from uncertainty') }}</span><span>{{ $c('خطواتك وقراراتك بيدك','Your decisions remain yours') }}</span></div></div>
    <section id="how" class="public-band"><div class="public-wrap public-section"><div class="public-heading"><span class="public-eyebrow">{{ $c('ماذا يفعل الموقع','How BuilderAd works') }}</span><h2>{{ $c('كيف نعمل من أجلك؟','How do we work for you?') }}</h2><p>{{ $c('نساعدك على تنظيم السياق، ومراجعة الدليل وحدوده، ثم اختيار خطوة واضحة.','We help organize your context, understand the evidence and its limits, and choose a clear next step.') }}</p></div><div class="public-grid">
        @foreach([[$c('راقب','Organize'),$c('رتّب بيانات نشاطك وأهدافك ومصادرك.','Organize your business, goals and sources.'),'◉'],[$c('اكتشف','Explore'),$c('افهم الفرص وما يحتاج إلى معرفة إضافية.','Explore opportunities and what still needs evidence.'),'🔎'],[$c('اشرح','Understand'),$c('راجع المعلومات وسياقها وحدودها.','Review information, context and limitations.'),'◌'],[$c('قرر','Decide'),$c('اختر خطوتك التالية بما يناسب نشاطك.','Choose a next step that fits your business.'),'🧭'],[$c('تابع','Track'),$c('تابع ما يتغير مع توفر البيانات المناسبة.','Track what changes when relevant data is available.'),'📈']] as $step)<article class="public-feature"><span class="ba-card-icon" aria-hidden="true">{{ $step[2] }}</span><h3>{{ $step[0] }}</h3><p>{{ $step[1] }}</p></article>@endforeach
    </div></div></section>
    <section id="benefits" class="public-wrap public-section"><div class="public-heading"><span class="public-eyebrow">{{ $c('ما الذي تستفيد','What you can gain') }}</span><h2>{{ $c('ثلاث فوائد رئيسية تصنع فارقًا','Three benefits that make a difference') }}</h2></div><div class="ba-card-grid">
        @foreach([[$c('توفير المال','Protect your budget'),'💰',[$c('راجع ما تنفقه ضمن سياق نشاطك','Understand spend in your business context'),$c('لاحظ الفجوات عندما تتوفر البيانات','Spot gaps when relevant data is available'),$c('تجنب القرار المبني على افتراض غير مؤكد','Avoid decisions based on unverified assumptions')],$c('النتيجة: صورة أوضح قبل تخصيص الميزانية','Result: a clearer picture before allocating budget')],[$c('تحسين النتائج','Improve results'),'📈',[$c('اربط أهدافك بمصادرك وبياناتك','Connect goals to sources and data'),$c('تعرف على حدود القياس والتغطية','Understand measurement and coverage limits'),$c('تابع التقدم وفق الأدلة المتاحة','Track progress using available evidence')],$c('النتيجة: قرارات تستند إلى سياق مفهوم','Result: decisions grounded in clear context')],[$c('قرارات أفضل','Make better decisions'),'🎯',[$c('افهم ما نعرفه وما لا نعرفه','See what is known and unknown'),$c('راجع البدائل قبل اختيار خطوة','Review alternatives before acting'),$c('تبقى الموافقة والتنفيذ بيدك','You keep approval and execution authority')],$c('النتيجة: خطوة أوضح تناسب نشاطك','Result: a clearer step for your business')]] as $benefit)<article class="ba-card"><span class="ba-card-icon" aria-hidden="true">{{ $benefit[1] }}</span><h3>{{ $benefit[0] }}</h3><ul>@foreach($benefit[2] as $item)<li>{{ $item }}</li>@endforeach</ul><div class="ba-card-result">{{ $benefit[3] }}</div></article>@endforeach
    </div></section>
    <section id="available" class="public-band"><div class="public-wrap public-section ba-split"><div class="ba-bars"><h3>{{ $c('المنصة تعرض ما يتوفر من بيانات — مثال توضيحي','Illustrative data comparison') }}</h3><p class="public-note">{{ $c('لا تمثل الأرقام أداءً فعليًا أو وعدًا بنتيجة.','Values are illustrative, not actual performance or a promise.') }}</p><svg viewBox="0 0 540 300" role="img" aria-label="{{ $c('مخطط أعمدة توضيحي','Illustrative bar chart') }}"><g stroke="#293447" stroke-width="1"><path d="M50 35H520M50 115H520M50 195H520M50 275H520"/></g><g fill="#9a7aff"><rect x="108" y="55" width="54" height="220" rx="11"/><rect x="315" y="96" width="54" height="179" rx="11"/></g><g fill="#27cfa4"><rect x="177" y="150" width="54" height="125" rx="11"/><rect x="384" y="171" width="54" height="104" rx="11"/></g><g fill="#91a7c4" font-size="15" font-family="Arial"><text x="135" y="296">Meta</text><text x="340" y="296">Google</text></g></svg></div><div class="ba-split-copy"><span class="public-eyebrow">{{ $c('الأرقام الحقيقية','Clearer measurement') }}</span><h2>{{ $c('افهم الفرق بين ما تعرضه المنصة وسياق نشاطك','Understand platform data in your business context') }}</h2><p>{{ $c('نظّم معلومات نشاطك ومصادرك. تعتمد أي رؤية على البيانات المتاحة وصلاحيات الوصول، ونوضح حدود ما يمكن استنتاجه.','Organize your business information and sources. Any insight depends on available data and access permissions, with clear limits on what can be concluded.') }}</p><a class="public-button primary" href="#available-details">{{ $c('اكتشف المتاح','Explore what is available') }}</a></div></div></section>
    <section id="platforms" class="public-wrap public-section"><div class="public-heading"><span class="public-eyebrow">{{ $c('المنصات','Platforms') }}</span><h2>{{ $c('مصادرك، تحت سيطرتك','Your sources, under your control') }}</h2><p>{{ $c('تختلف الإتاحة حسب إعداد المنصة وأهلية الحساب والصلاحيات.','Availability depends on platform setup, account eligibility and permissions.') }}</p></div><div class="ba-platforms"><div class="ba-platform"><span><strong>Meta Ads</strong><small>Facebook &amp; Instagram</small></span><span class="public-state">{{ $c('حسب الإتاحة','Availability varies') }}</span></div><div class="ba-platform"><span><strong>Google Ads</strong><small>Search &amp; Display</small></span><span class="public-state">{{ $c('حسب الإتاحة','Availability varies') }}</span></div><div class="ba-platform"><span><strong>{{ $c('مصادر أخرى','More sources') }}</strong><small>{{ $c('حسب الدعم','As supported') }}</small></span><span class="public-state planned">{{ $c('قريبًا','Coming later') }}</span></div></div></section>
    <section class="public-band"><div class="public-wrap public-section"><div class="public-heading"><span class="public-eyebrow">{{ $c('البداية','Getting started') }}</span><h2>{{ $c('ابدأ في ثلاث خطوات','Start in three steps') }}</h2></div><div class="ba-card-grid">@foreach([[$c('اربط حسابك','Set up your account'),$c('أنشئ حسابك وعرّف نشاطك وسياقه.','Create your account and describe your business context.')],[$c('ضع السياق','Add your context'),$c('أضف أهدافك ومصادرك حسب المتاح.','Add goals and supported sources as available.')],[$c('راجع الخطوة التالية','Review next steps'),$c('افهم المعلومات المتاحة وحدودها واختر ما يناسبك.','Understand the available information and choose what fits.')]] as $i => $step)<article class="ba-card ba-step-card"><span class="ba-step-number">{{ $i + 1 }}</span><h3>{{ $step[0] }}</h3><p>{{ $step[1] }}</p></article>@endforeach</div></div></section>
    <section id="questions" class="public-wrap public-section public-faq"><span class="public-eyebrow">{{ $c('الأسئلة الشائعة','Frequently asked questions') }}</span><h2>{{ $c('كل ما تريد معرفته قبل البدء','What to know before you begin') }}</h2>
        <details><summary>{{ $c('كم يستغرق الإعداد؟','How long does setup take?') }}</summary><p>{{ $c('يمكنك إنشاء حسابك وتعريف نشاطك والبدء بإضافة السياق المتاح. يعتمد ربط المصادر على أهلية الحساب وصلاحياته.','You can create an account, describe your business and add available context. Source connections depend on account eligibility and permissions.') }}</p></details>
        <details><summary>{{ $c('هل سأوفر مالًا فعلًا؟','Will I actually save money?') }}</summary><p>{{ $c('لا نضمن توفيرًا أو نتيجة تجارية. نساعدك على تنظيم المعلومات وفهم ما يمكن استنتاجه منها.','We do not guarantee savings or a commercial outcome. We help organize information and understand what can be inferred from it.') }}</p></details>
        <details><summary>{{ $c('هل بياناتي آمنة؟','Is my data safe?') }}</summary><p>{{ $c('تدار المصادر وفق الصلاحيات التي تمنحها. يمكنك مراجعة سياسة الخصوصية والتفاصيل المتعلقة ببياناتك.','Sources are handled according to the permissions you grant. Review the privacy policy for details about your data.') }}</p></details>
        <details><summary>{{ $c('ما المنصات المدعومة حاليًا؟','Which platforms are currently supported?') }}</summary><p>{{ $c('تظهر المصادر المدعومة وأهلية ربطها ضمن حسابك. قد تختلف الإتاحة حسب المنصة والحساب.','Supported sources and connection eligibility appear in your account and may vary by platform and account.') }}</p></details>
        <details><summary>{{ $c('هل توجد فترة تجريبية؟','Is there a trial period?') }}</summary><p>{{ $c('راجع تفاصيل الخطة والإتاحة الحالية عند التسجيل.','Check the current plan and availability details when registering.') }}</p></details>
        <details><summary>{{ $c('هل كل القدرات المذكورة متاحة الآن؟','Are all the capabilities described available now?') }}</summary><p><strong>{{ $c('قيد التطوير','In development') }}:</strong> {{ $c('بعض القدرات لا تزال ضمن رؤية المنتج. راجع المتاح اليوم قبل البدء.','Some capabilities are still part of the product vision. Check what is available today before you start.') }}</p></details>
        <details><summary>{{ $c('كيف أعود إلى حسابي؟','How do I get back into my account?') }}</summary><p><a href="/login">{{ $c('تسجيل الدخول','Sign in') }}</a> · <a href="/forgot-password">{{ $c('استعادة الحساب','Recover your account') }}</a></p></details>
    </section>
    <section id="available-details" class="public-wrap ba-final-cta"><h2>{{ $c('ابدأ بصورة أوضح عن نشاطك','Start with a clearer picture of your business') }}</h2><p>{{ $c('أنشئ حسابك، ثم استكشف السياق والمصادر المتاحة لك.','Create an account, then explore the context and sources available to you.') }}</p><div class="public-actions"><a class="public-button primary" href="/register">{{ $c('ابدأ مجانًا','Get started') }} ←</a><a class="public-button" href="/login">{{ $c('تسجيل الدخول','Sign in') }}</a></div></section>
</main>
@include('public.footer')
</body>
</html>
