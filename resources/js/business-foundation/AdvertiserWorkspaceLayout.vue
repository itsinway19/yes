<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, Teleport, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { Building2, Link2, Menu, Target, X } from '@lucide/vue';
import BrandLogo from '../Components/BrandLogo.vue';
import BusinessSwitcher from './BusinessSwitcher.vue';
import { businessChoiceDestination, createBusinessChoices, type BusinessChoice } from './businessChoices';
import { documentStatus } from '../spa/transport';
import { locale } from '../spa/locale';
import { advertiserNavigationCurrent } from './advertiserNavigation';
import './advertiser.css';

const props = defineProps<{ context?: string; businessSelected?: boolean; selection?: BusinessChoice; workspaceContext?: boolean; workspaceId?: string }>();
const route = useRoute(), router = useRouter();
const drawer = ref<HTMLDialogElement>(), menu = ref<HTMLButtonElement>(), content = ref<HTMLElement>();
const open = ref(false), headerTarget = ref(false), desktop = ref(true);
const choices = createBusinessChoices();
const legacyRecord = computed(() => /^\/workspaces\/[^/]+\/(?:commercial-history|purpose-history(?:\/[^/]+)?)\/?$/.test(route.path));
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
let viewport: MediaQueryList | undefined;
let resizing = false;
function closeNavigation() { drawer.value?.close(); open.value = false; }
function openNavigation() { drawer.value?.showModal(); open.value = !!drawer.value?.open; }
function closed() { open.value = false; if (!desktop.value && !resizing) menu.value?.focus({ preventScroll: true }); }
function breakpoint() {
    desktop.value = viewport?.matches ?? true;
    if (!viewport?.matches || !drawer.value?.open) return;
    resizing = true; closeNavigation();
    void nextTick(() => {
        (document.getElementById('businessSelect') ?? content.value)?.focus({ preventScroll: true });
        resizing = false;
    });
}
function outside(event: MouseEvent) {
    const element = drawer.value;
    if (!element || event.target !== element) return;
    const box = element.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) closeNavigation();
}
function closeForLink(event: MouseEvent) { if ((event.target as Element | null)?.closest('a[href],button[data-workspace-section]')) closeNavigation(); }
async function selectBusiness(choice: BusinessChoice) {
    // The drawer closes before existing departure/admission guards run.
    closeNavigation(); await router.push(businessChoiceDestination(choice));
}
watch(() => route.fullPath, closeNavigation);
watch(documentStatus, value => { if (value === 'closed') { closeNavigation(); choices.clear(); } }, { flush: 'sync' });
onMounted(() => {
    headerTarget.value = !!document.getElementById('advertiser-header-context');
    viewport = window.matchMedia('(min-width: 851px)'); desktop.value = viewport.matches; viewport.addEventListener('change', breakpoint);
    void choices.load();
});
onBeforeUnmount(() => { choices.dispose(); viewport?.removeEventListener('change', breakpoint); drawer.value?.close(); });
</script>
<template>
    <div class="advertiser-layout" data-advertiser-shell>
        <a class="advertiser-skip" href="#advertiser-content">{{ t('انتقل إلى المحتوى', 'Skip to content') }}</a>
        <Teleport v-if="headerTarget" to="#advertiser-header-context">
            <div class="advertiser-header-selection" data-spa-private>
            <button ref="menu" type="button" class="advertiser-menu-toggle" data-advertiser-menu-toggle :aria-label="t('فتح القائمة', 'Open navigation')" :aria-expanded="open" aria-controls="advertiser-drawer" @click="openNavigation"><Menu aria-hidden="true" /></button>
            <strong v-if="businessSelected && selection" class="advertiser-mobile-identity" data-mobile-business-identity data-business-name>{{ selection.name }}</strong>
            <span class="advertiser-header-label">{{ workspaceContext ? t('الفريق والموارد المشتركة', 'Team and shared resources') : t('النشاط التجاري', 'Business') }}</span>
            </div>
        </Teleport>
        <aside class="advertiser-sidebar" data-advertiser-sidebar :aria-label="t('التنقل الرئيسي', 'Main navigation')">
            <div v-if="desktop" class="advertiser-sidebar-body">
                <BrandLogo to="/businesses" />
                <BusinessSwitcher id="businessSelect" :items="choices.items.value" :selected="selection" :phase="choices.phase.value" :more="!!choices.nextCursor.value" @select="selectBusiness" @reload="choices.load()" @more="choices.load(true)" />
                <div v-if="workspaceContext" class="advertiser-organization-context"><span>{{ t('جهة العمل', 'Organization') }}</span><strong>{{ context || t('اختر جهة عمل', 'Choose an organization') }}</strong></div>
                <slot name="navigation">
                    <nav class="advertiser-navigation" :aria-label="t('مساحة المعلن', 'Advertiser workspace')">
                        <RouterLink to="/businesses" :class="{ active: advertiserNavigationCurrent($route.path, '/businesses') }" :aria-current="advertiserNavigationCurrent($route.path, '/businesses') ? 'page' : undefined"><Building2 aria-hidden="true" />{{ t('أنشطتي التجارية', 'My businesses') }}</RouterLink>
                        <template v-if="workspaceId">
                            <p class="advertiser-nav-label">{{ t('داخل الجهة', 'Within the organization') }}</p>
                            <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}`" :aria-current="$route.path === `/workspaces/${encodeURIComponent(workspaceId)}` ? 'page' : undefined"><Link2 aria-hidden="true" />{{ t('إدارة الجهة', 'Organization management') }}</RouterLink>
                            <details class="advertiser-legacy-navigation" :open="legacyRecord" data-workspace-previous-navigation>
                                <summary>{{ t('السجلات السابقة للجهة', 'Previous organization records') }}</summary>
                                <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}/commercial-history`" :aria-current="advertiserNavigationCurrent($route.path, `/workspaces/${encodeURIComponent(workspaceId)}/commercial-history`) ? 'page' : undefined"><Target aria-hidden="true" />{{ t('التاريخ التجاري المحفوظ', 'Preserved commercial history') }}</RouterLink>
                                <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}/purpose-history`" :aria-current="advertiserNavigationCurrent($route.path, `/workspaces/${encodeURIComponent(workspaceId)}/purpose-history`) ? 'page' : undefined"><Target aria-hidden="true" />{{ t('الأغراض الإعلانية السابقة', 'Previous advertising purposes') }}</RouterLink>
                            </details>
                        </template>
                    </nav>
                </slot>
                <details class="advertiser-organization-tools" :open="workspaceContext">
                    <summary>{{ t('الفريق والموارد المشتركة', 'Team and shared resources') }}</summary>
                    <RouterLink to="/workspaces" :aria-current="route.path === '/workspaces' ? 'page' : undefined">{{ t('جهات العمل والفريق', 'Organizations and team') }}</RouterLink>
                    <p>{{ t('للعمل الجماعي وإدارة الحسابات المشتركة.', 'For teamwork and shared account administration.') }}</p>
                </details>
                <RouterLink class="advertiser-account-link" to="/profile">{{ t('الحساب والأمان', 'Account and security') }}</RouterLink>
            </div>
        </aside>
        <dialog id="advertiser-drawer" ref="drawer" class="advertiser-drawer" data-advertiser-drawer :dir="locale === 'ar' ? 'rtl' : 'ltr'" :aria-label="t('قائمة المعلن', 'Advertiser navigation')" @close="closed" @cancel="open = false" @click="outside">
            <div v-if="!desktop" class="advertiser-drawer-body" @click="closeForLink">
                <button type="button" class="advertiser-drawer-close" data-advertiser-drawer-close :aria-label="t('إغلاق القائمة', 'Close navigation')" @click="closeNavigation"><X aria-hidden="true" /></button>
                <BrandLogo to="/businesses" />
                <BusinessSwitcher id="drawerBusinessSelect" :items="choices.items.value" :selected="selection" :phase="choices.phase.value" :more="!!choices.nextCursor.value" @select="selectBusiness" @reload="choices.load()" @more="choices.load(true)" />
                <div v-if="workspaceContext" class="advertiser-organization-context"><span>{{ t('جهة العمل', 'Organization') }}</span><strong>{{ context || t('اختر جهة عمل', 'Choose an organization') }}</strong></div>
                <div @click="closeForLink">
                    <slot name="navigation">
                        <nav class="advertiser-navigation" :aria-label="t('مساحة المعلن', 'Advertiser workspace')">
                            <RouterLink to="/businesses" :aria-current="advertiserNavigationCurrent($route.path, '/businesses') ? 'page' : undefined"><Building2 aria-hidden="true" />{{ t('أنشطتي التجارية', 'My businesses') }}</RouterLink>
                            <template v-if="workspaceId">
                                <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}`" :aria-current="$route.path === `/workspaces/${encodeURIComponent(workspaceId)}` ? 'page' : undefined"><Link2 aria-hidden="true" />{{ t('إدارة الجهة', 'Organization management') }}</RouterLink>
                                <details class="advertiser-legacy-navigation" :open="legacyRecord" data-workspace-previous-navigation>
                                    <summary>{{ t('السجلات السابقة للجهة', 'Previous organization records') }}</summary>
                                    <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}/commercial-history`" :aria-current="advertiserNavigationCurrent($route.path, `/workspaces/${encodeURIComponent(workspaceId)}/commercial-history`) ? 'page' : undefined"><Target aria-hidden="true" />{{ t('التاريخ التجاري المحفوظ', 'Preserved commercial history') }}</RouterLink>
                                    <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}/purpose-history`" :aria-current="advertiserNavigationCurrent($route.path, `/workspaces/${encodeURIComponent(workspaceId)}/purpose-history`) ? 'page' : undefined"><Target aria-hidden="true" />{{ t('الأغراض الإعلانية السابقة', 'Previous advertising purposes') }}</RouterLink>
                                </details>
                            </template>
                        </nav>
                    </slot>
                </div>
                <details class="advertiser-organization-tools" :open="workspaceContext">
                    <summary>{{ t('الفريق والموارد المشتركة', 'Team and shared resources') }}</summary>
                    <RouterLink to="/workspaces" :aria-current="route.path === '/workspaces' ? 'page' : undefined">{{ t('جهات العمل والفريق', 'Organizations and team') }}</RouterLink>
                    <p>{{ t('للعمل الجماعي وإدارة الحسابات المشتركة.', 'For teamwork and shared account administration.') }}</p>
                </details>
                <RouterLink class="advertiser-account-link" to="/profile">{{ t('الحساب والأمان', 'Account and security') }}</RouterLink>
            </div>
        </dialog>
        <main id="advertiser-content" ref="content" class="bf-main advertiser-content" tabindex="-1">
            <aside v-if="workspaceId && legacyRecord" class="advertiser-legacy-notice" data-workspace-previous-notice>
                <strong>{{ t('أنت في السجل التجاري السابق لهذه الجهة', 'You are viewing this organization’s previous business records') }}</strong>
                <p>{{ t('حقائق النشاط الحالية تدار في النشاط المستقل. التاريخ محفوظ بحقوقه الأصلية، ولا نفترض ارتباطه بنشاط معين.', 'Current commercial facts belong to the independent business. History retains its original rights; no business association is inferred.') }}</p>
                <RouterLink to="/businesses">{{ t('اختيار النشاط التجاري', 'Choose a business') }}</RouterLink>
            </aside>
            <slot />
        </main>
    </div>
</template>
