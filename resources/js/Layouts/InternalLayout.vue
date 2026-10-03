<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { supportedLocale } from '@/localization/messages';
const { t } = useI18n({ useScope: 'global' });
const page = usePage();
const locale = computed(() => supportedLocale(page.props.locale));
const menuOpen = ref(false);
const menuButton = ref<HTMLButtonElement | null>(null);
const navigation = computed(() => [
    { key: 'administration', route: 'internal.administration', visible: page.props.auth.canInternalAdministration },
    { key: 'services', route: 'internal.services.index', visible: page.props.auth.canManageServices },
    { key: 'businessTypes', route: 'internal.business-types.index', visible: page.props.auth.canManageBusinessTypes },
    { key: 'commercial', route: 'internal.commercial.index', visible: true },
    { key: 'security', route: 'internal.security.devices', visible: true },
].filter(item => item.visible));
const context = computed(() => navigation.value.find(item => route().current(item.route))?.key ?? 'workspace');
const removeNavigationListener = router.on('navigate', () => { menuOpen.value = false; });
onBeforeUnmount(removeNavigationListener);
function closeMenu() {
    if (!menuOpen.value) return;
    menuOpen.value = false;
    menuButton.value?.focus();
}
</script>
<template>
    <div class="admin-shell" :dir="locale === 'ar' ? 'rtl' : 'ltr'" :lang="locale" @keydown.esc="closeMenu">
        <a href="#internal-content" class="admin-skip">{{ t('admin.skip') }}</a>
        <header class="admin-header">
            <div class="admin-header-context"><span class="admin-eyebrow">{{ t('admin.internal') }}</span><span class="admin-context-divider" aria-hidden="true">/</span><span>{{ t(`admin.${context}`) }}</span></div>
            <div class="admin-header-actions"><LanguageSwitcher route-name="internal.locale.update" /><button ref="menuButton" type="button" class="admin-action admin-menu-button" :aria-expanded="menuOpen" aria-controls="internal-menu" @click="menuOpen = !menuOpen">{{ t(menuOpen ? 'admin.closeMenu' : 'admin.menu') }}</button></div>
        </header>
        <aside class="admin-sidebar" :class="{ 'admin-sidebar-open': menuOpen }">
            <Link :href="route($page.props.auth.canInternalAdministration ? 'internal.administration' : 'internal.security.devices')" class="admin-brand"><ApplicationLogo class="admin-brand-logo" aria-hidden="true" /><span class="admin-brand-copy"><span dir="ltr" class="admin-brand-name">Builderad</span><span class="admin-eyebrow">{{ t('admin.internal') }}</span></span></Link>
            <nav id="internal-menu" :aria-label="t('admin.navigation')" class="admin-navigation">
                <p class="admin-nav-heading">{{ t('admin.workspace') }}</p>
                <Link v-for="item in navigation" :key="item.key" :href="route(item.route)" :aria-current="route().current(item.route) ? 'page' : undefined" class="admin-nav-link" @click="menuOpen = false"><span>{{ t(`admin.${item.key}`) }}</span></Link>
            </nav>
            <div class="admin-sidebar-footer"><span class="admin-eyebrow">{{ t('admin.internalAccess') }}</span><Link :href="route('internal.logout')" method="post" as="button" class="admin-logout">{{ t('admin.logout') }}</Link></div>
        </aside>
        <div class="admin-workspace">
            <div v-if="$slots.header" class="admin-page-heading"><slot name="header" /></div>
            <main id="internal-content" tabindex="-1"><slot /></main>
        </div>
    </div>
</template>
