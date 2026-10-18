<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { locale } from '../spa/locale';
const copy = (arabic: string, english: string) => locale.value === 'ar' ? arabic : english;
</script>

<template>
    <main data-spa-private data-internal-operations class="business-main operations">
        <nav aria-label="Internal operations"><RouterLink to="/internal/administration">{{ copy('الفريق والصلاحيات', 'Staff and permissions') }}</RouterLink> · <RouterLink to="/internal/administration/customers">{{ copy('العملاء', 'Customers') }}</RouterLink> · <RouterLink to="/internal/business-types">{{ copy('أنواع الأعمال', 'Business types') }}</RouterLink> · <RouterLink to="/internal/services">{{ copy('هويات الخدمات', 'Service identities') }}</RouterLink> · <RouterLink to="/internal/security/devices">{{ copy('الأمان', 'Security') }}</RouterLink> · <RouterLink to="/internal/commercial">{{ copy('التشغيل التجاري', 'Commercial operations') }}</RouterLink> · <RouterLink to="/internal/administration/commercial-markets">{{ copy('تهيئة الأسواق التجارية', 'Commercial market preparation') }}</RouterLink></nav>
        <header><slot name="header" /></header>
        <slot />
    </main>
</template>

<style scoped>
.operations{--admin-line:#e1e5ea;--admin-muted:#586473;--admin-ink:#202b38;--admin-accent:#334b73;--admin-disclosure:#f3f5f7;--admin-group:#f7f8fa;--admin-danger:#a13c38;--admin-warning:#856022;max-width:1180px;overflow-wrap:anywhere}.operations nav{display:flex;gap:12px;flex-wrap:wrap;line-height:1.8}.operations nav a{color:#155f53;text-decoration:underline}.operations header{margin:24px 0}.operations :deep(fieldset){min-width:0}.operations :deep([role=alert]){color:#a52b28}.operations :deep(.operation-outcome){margin:16px 0;padding:16px;border:1px solid #9db7aa}.operations :deep(.operation-outcome button){text-decoration:underline;padding:8px}.operations :focus-visible{outline:2px solid #155f53;outline-offset:3px}
</style>
