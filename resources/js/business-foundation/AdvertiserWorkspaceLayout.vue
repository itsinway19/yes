<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { Building2, ChevronLeft, Link2, Target } from '@lucide/vue';
import { locale } from '../spa/locale';
import { advertiserNavigationCurrent } from './advertiserNavigation';
import './advertiser.css';
defineProps<{ context?: string; businessSelected?: boolean; workspaceContext?: boolean; workspaceId?: string }>();
const navigation = ref<HTMLDetailsElement>();
const route = useRoute();
const legacyRecord = computed(() => /^\/workspaces\/[^/]+\/(?:commercial-history|purpose-history(?:\/[^/]+)?)\/?$/.test(route.path));
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
let viewport: MediaQueryList | undefined;
const syncNavigation = () => { if (navigation.value && viewport) navigation.value.open = viewport.matches; };
onMounted(() => { viewport = window.matchMedia('(min-width: 761px)'); syncNavigation(); viewport.addEventListener('change', syncNavigation); });
onBeforeUnmount(() => viewport?.removeEventListener('change', syncNavigation));
function closeNavigation(event: MouseEvent) {
    if (!(event.target as Element | null)?.closest('a[href], button')) return;
    if (navigation.value && viewport && !viewport.matches) navigation.value.open = false;
}
</script>
<template>
    <div class="advertiser-layout" data-advertiser-shell>
        <a class="advertiser-skip" href="#advertiser-content">{{ t('انتقل إلى المحتوى', 'Skip to content') }}</a>
        <details ref="navigation" class="advertiser-sidebar" open>
            <summary>{{ t('القائمة', 'Navigation') }}</summary>
            <div class="advertiser-sidebar-body" @click="closeNavigation">
                <div class="advertiser-context"><span class="advertiser-context-icon"><Building2 aria-hidden="true" /></span><div><span>{{ workspaceContext ? t('جهة العمل', 'Organization') : businessSelected ? t('النشاط التجاري', 'Business') : t('حساب المعلن', 'Advertiser account') }}</span><strong>{{ context || (workspaceContext ? t('اختر جهة عمل', 'Choose an organization') : t('اختر نشاطك التجاري', 'Choose your business')) }}</strong></div></div>
                <slot name="navigation">
                    <nav class="advertiser-navigation" :aria-label="t('مساحة المعلن', 'Advertiser workspace')">
                        <p class="advertiser-nav-label">{{ t('أنشطتك وعملك', 'Your businesses and work') }}</p>
                        <RouterLink to="/businesses" :class="{ active: advertiserNavigationCurrent($route.path, '/businesses') }" :aria-current="advertiserNavigationCurrent($route.path, '/businesses') ? 'page' : undefined"><Building2 aria-hidden="true" />{{ t('أنشطتي التجارية', 'My businesses') }}</RouterLink>
                        <template v-if="workspaceId">
                            <p class="advertiser-nav-label">{{ t('داخل مساحة العمل', 'In this workspace') }}</p>
                            <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}`" :aria-current="$route.path === `/workspaces/${encodeURIComponent(workspaceId)}` ? 'page' : undefined"><Link2 aria-hidden="true" />{{ t('المنصات المتصلة', 'Connected platforms') }}</RouterLink>
                            <details class="advertiser-legacy-navigation" :open="legacyRecord" data-workspace-previous-navigation>
                                <summary @click.stop>{{ t('السجلات السابقة للمساحة', 'Previous workspace records') }}</summary>
                                <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}/commercial-history`" :aria-current="advertiserNavigationCurrent($route.path, `/workspaces/${encodeURIComponent(workspaceId)}/commercial-history`) ? 'page' : undefined"><Target aria-hidden="true" />{{ t('التاريخ التجاري المحفوظ', 'Preserved commercial history') }}</RouterLink>
                                <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}/purpose-history`" :aria-current="advertiserNavigationCurrent($route.path, `/workspaces/${encodeURIComponent(workspaceId)}/purpose-history`) ? 'page' : undefined"><Target aria-hidden="true" />{{ t('الأغراض الإعلانية السابقة', 'Previous advertising purposes') }}</RouterLink>
                            </details>
                        </template>
                    </nav>
                </slot>
                <details class="advertiser-organization-tools" :open="workspaceContext">
                    <summary>{{ t('الفريق والموارد المشتركة', 'Team and shared resources') }}</summary>
                    <RouterLink to="/workspaces">{{ t('جهات العمل والفريق', 'Organizations and team') }}</RouterLink>
                    <p>{{ t('للعمل الجماعي وإدارة الحسابات المشتركة.', 'For teamwork and shared account administration.') }}</p>
                </details>
                <RouterLink v-if="businessSelected" class="advertiser-switch" to="/businesses"><ChevronLeft aria-hidden="true" />{{ t('تبديل النشاط', 'Switch business') }}</RouterLink>
                <p class="advertiser-sidebar-note">{{ t('ابدأ من نشاطك، ثم اختر العمل الذي تحتاجه.', 'Start with your business, then choose the task you need.') }}</p>
            </div>
        </details>
        <main id="advertiser-content" class="bf-main advertiser-content" tabindex="-1">
            <aside v-if="workspaceId && legacyRecord" class="advertiser-legacy-notice" data-workspace-previous-notice>
                <strong>{{ t('أنت في السجل التجاري السابق لهذه المساحة', 'You are viewing this workspace’s previous business records') }}</strong>
                <p>{{ t('حقائق النشاط الحالية تدار في النشاط المستقل. التاريخ محفوظ بحقوقه الأصلية، ولا نفترض ارتباطه بنشاط معين.', 'Current commercial facts belong to the independent business. History retains its original rights; no business association is inferred.') }}</p>
                <RouterLink to="/businesses">{{ t('اختيار النشاط التجاري', 'Choose a business') }}</RouterLink>
            </aside>
            <slot />
        </main>
    </div>
</template>
