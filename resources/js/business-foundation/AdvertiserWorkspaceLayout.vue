<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { Building2, ChevronLeft, Layers3, Link2, ShieldCheck, Target, Settings2 } from '@lucide/vue';
import { locale } from '../spa/locale';
import { advertiserNavigationCurrent, deferredAdvertiserDestinations } from './advertiserNavigation';
import './advertiser.css';
defineProps<{ context?: string; businessSelected?: boolean; workspaceContext?: boolean; workspaceId?: string }>();
const navigation = ref<HTMLDetailsElement>();
const route = useRoute();
const legacyRecord = computed(() => /^\/workspaces\/[^/]+\/(?:business|goals(?:\/review)?|object-purposes(?:\/[^/]+)?)\/?$/.test(route.path));
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
let viewport: MediaQueryList | undefined;
const syncNavigation = () => { if (navigation.value && viewport) navigation.value.open = viewport.matches; };
onMounted(() => { viewport = window.matchMedia('(min-width: 761px)'); syncNavigation(); viewport.addEventListener('change', syncNavigation); });
onBeforeUnmount(() => viewport?.removeEventListener('change', syncNavigation));
function closeNavigation() { if (navigation.value && viewport && !viewport.matches) navigation.value.open = false; }
</script>
<template>
    <div class="advertiser-layout" data-advertiser-shell>
        <a class="advertiser-skip" href="#advertiser-content">{{ t('انتقل إلى المحتوى', 'Skip to content') }}</a>
        <details ref="navigation" class="advertiser-sidebar" open>
            <summary>{{ t('تنقل مساحة العمل', 'Workspace navigation') }}</summary>
            <div class="advertiser-sidebar-body" @click="closeNavigation">
                <div class="advertiser-context"><span class="advertiser-context-icon"><Building2 aria-hidden="true" /></span><div><span>{{ workspaceContext ? t('مساحة العمل', 'Workspace') : businessSelected ? t('النشاط التجاري', 'Business') : t('حساب المعلن', 'Advertiser account') }}</span><strong>{{ context || (workspaceContext ? t('اختر مساحة عمل', 'Choose a workspace') : t('اختر نشاطك التجاري', 'Choose your business')) }}</strong></div></div>
                <slot name="navigation">
                    <nav class="advertiser-navigation" :aria-label="t('مساحة المعلن', 'Advertiser workspace')">
                        <p class="advertiser-nav-label">{{ t('أنشطتك وعملك', 'Your businesses and work') }}</p>
                        <RouterLink to="/businesses" :class="{ active: advertiserNavigationCurrent($route.path, '/businesses') }" :aria-current="advertiserNavigationCurrent($route.path, '/businesses') ? 'page' : undefined"><Building2 aria-hidden="true" />{{ t('أنشطتي التجارية', 'My businesses') }}</RouterLink>
                        <RouterLink to="/workspaces" :class="{ active: advertiserNavigationCurrent($route.path, '/workspaces') }" :aria-current="$route.path === '/workspaces' ? 'page' : undefined"><Layers3 aria-hidden="true" />{{ t('مساحات العمل', 'Workspaces') }}</RouterLink>
                        <template v-if="workspaceId">
                            <p class="advertiser-nav-label">{{ t('داخل مساحة العمل', 'In this workspace') }}</p>
                            <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}`" :aria-current="$route.path === `/workspaces/${encodeURIComponent(workspaceId)}` ? 'page' : undefined"><Link2 aria-hidden="true" />{{ t('المنصات المتصلة', 'Connected platforms') }}</RouterLink>
                            <details class="advertiser-legacy-navigation" :open="legacyRecord" data-workspace-previous-navigation>
                                <summary @click.stop>{{ t('السجلات السابقة للمساحة', 'Previous workspace records') }}</summary>
                                <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}/goals`" :aria-current="advertiserNavigationCurrent($route.path, `/workspaces/${encodeURIComponent(workspaceId)}/goals`) ? 'page' : undefined"><Target aria-hidden="true" />{{ t('الأهداف السابقة', 'Previous goals') }}</RouterLink>
                                <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}/object-purposes`" :aria-current="advertiserNavigationCurrent($route.path, `/workspaces/${encodeURIComponent(workspaceId)}/object-purposes`) ? 'page' : undefined"><Target aria-hidden="true" />{{ t('الأغراض الإعلانية السابقة', 'Previous advertising purposes') }}</RouterLink>
                                <RouterLink :to="`/workspaces/${encodeURIComponent(workspaceId)}/business`" :aria-current="$route.path === `/workspaces/${encodeURIComponent(workspaceId)}/business` ? 'page' : undefined"><Settings2 aria-hidden="true" />{{ t('الملف التجاري السابق', 'Previous business profile') }}</RouterLink>
                            </details>
                        </template>
                        <p class="advertiser-nav-label">{{ t('المصادر والتكاملات', 'Sources and integrations') }}</p>
                        <RouterLink to="/source-rights" :class="{ active: advertiserNavigationCurrent($route.path, '/source-rights') }" :aria-current="advertiserNavigationCurrent($route.path, '/source-rights') ? 'page' : undefined"><Link2 aria-hidden="true" />{{ t('حقوق المصادر', 'Source rights') }}</RouterLink>
                        <RouterLink to="/data-rights" :class="{ active: advertiserNavigationCurrent($route.path, '/data-rights') }" :aria-current="advertiserNavigationCurrent($route.path, '/data-rights') ? 'page' : undefined"><ShieldCheck aria-hidden="true" />{{ t('نطاقات بيانات العملاء', 'Client data boundaries') }}</RouterLink>
                    </nav>
                </slot>
                <details class="advertiser-future" @click.stop>
                    <summary>{{ t('ضمن تصور المنصة', 'In the product vision') }}</summary>
                    <p>{{ t('وجهات لم تتوفر بعد. يمكنك استعمال وظائف النشاط القائمة دون انتظارها.', 'These destinations are not yet available. Existing business tasks work independently.') }}</p>
                    <ul><li v-for="item in deferredAdvertiserDestinations" :key="item.id"><span>{{ locale === 'ar' ? item.ar : item.en }}</span><small>{{ t('غير متاح بعد', 'Not yet available') }}</small></li></ul>
                </details>
                <RouterLink v-if="businessSelected" class="advertiser-switch" to="/businesses"><ChevronLeft aria-hidden="true" />{{ t('تبديل النشاط', 'Switch business') }}</RouterLink>
                <p class="advertiser-sidebar-note">{{ t('ابدأ من نشاطك، ثم اختر العمل الذي تحتاجه.', 'Start with your business, then choose the task you need.') }}</p>
            </div>
        </details>
        <main id="advertiser-content" class="bf-main advertiser-content" tabindex="-1">
            <aside v-if="workspaceId && legacyRecord" class="advertiser-legacy-notice" data-workspace-previous-notice>
                <strong>{{ t('أنت في السجل التجاري السابق لهذه المساحة', 'You are viewing this workspace’s previous business records') }}</strong>
                <p>{{ t('هذه البيانات محفوظة ضمن المسار السابق. تعديلها لا يحدّث النشاط المستقل تلقائيًا. لإدارة معلومات النشاط وأسواقه وأولوياته، افتحه من أنشطتي التجارية.', 'These records belong to the previous workflow. Editing them does not automatically update an independent business. Open My businesses to manage its details, markets and priorities.') }}</p>
                <RouterLink to="/businesses">{{ t('اختيار النشاط التجاري', 'Choose a business') }}</RouterLink>
            </aside>
            <slot />
        </main>
    </div>
</template>
