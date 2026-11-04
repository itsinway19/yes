<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { ArrowLeft, Building2, FileText, House, Link2, Settings2, Target, Users } from '@lucide/vue';
import AdvertiserWorkspaceLayout from './AdvertiserWorkspaceLayout.vue';
import BusinessSources from './BusinessSources.vue';
import { locale } from '../spa/locale';
import { key } from './drafts';
import type { Surface } from './api';
import { workspacePresentation, workspacePresentationKey, workspaceLocation, type WorkspaceSection, type WorkspaceSettingsView } from './workspacePresentation';
import BusinessEditor from './BusinessEditor.vue';
import BusinessFacts from './BusinessFacts.vue';
import BusinessCollaboration from './BusinessCollaboration.vue';
import BusinessDelegation from './BusinessDelegation.vue';
import InternalClientFileTeam from './InternalClientFileTeam.vue';
import BusinessDataUse from './BusinessDataUse.vue';
import BusinessPurposes from './BusinessPurposes.vue';
import BusinessHistory from './BusinessHistory.vue';
import BusinessLegacyHistory from './BusinessLegacyHistory.vue';
import InquiryEvidencePanel from '../inquiry-evidence/InquiryEvidencePanel.vue';
import './workspace.css';
const props = withDefaults(defineProps<{ surface: Surface; refreshing?: boolean }>(), { refreshing: false });
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const resource = key(props.surface.business.business_public_id, props.surface.authority_selection);
const presentationKey = workspacePresentationKey(resource);
const retained = workspacePresentation.get(presentationKey) ?? { section: 'overview' as WorkspaceSection, settingsView: 'details' as WorkspaceSettingsView, positions: {} as Partial<Record<WorkspaceSection, number>> };
const initial = workspaceLocation(retained.section, retained.settingsView);
retained.section = initial.section; retained.settingsView = initial.settingsView;
workspacePresentation.set(presentationKey, retained);
const active = ref(initial.section), settingsView = ref<WorkspaceSettingsView>(initial.settingsView);
const visited = ref(new Set<WorkspaceSection>(['overview', initial.section, ...(initial.section === 'settings' && initial.settingsView === 'data' ? ['inquiries' as const] : [])]));
const visitedSettings = ref(new Set<WorkspaceSettingsView>(initial.section === 'settings' ? [initial.settingsView] : []));
const heading = ref<HTMLElement>();
const advancedSources = ref<HTMLDetailsElement>();
const dataUsePending = ref(false);
const businessType = computed(() => props.surface.catalog.business_types.find(type => type.id === props.surface.business.business_type_id));
const inquiryActive = computed(() => active.value === 'settings' && settingsView.value === 'data');
const destinations = computed(() => [
    { id: 'overview' as const, label: t('الرئيسية', 'Overview'), description: t('نظرة على معلومات نشاطك وأعماله المتاحة.', 'Your business details and available tasks.'), icon: House },
    { id: 'direction' as const, label: t('العروض والخطة', 'Offers and plan'), description: t('الأسواق والأهداف المعلنة', 'Declared markets and goals'), icon: Target },
    { id: 'sources' as const, label: t('المصادر والحسابات الإعلانية', 'Sources and ad accounts'), description: t('مصادر مساحات العمل المرتبطة التي يمكنك إدارتها', 'Sources you can manage in linked workspaces'), icon: Link2 },
    { id: 'settings' as const, label: t('النشاط والفريق والإعدادات', 'Business, team and settings'), description: t('المعلومات والبيانات والوصول', 'Details, data and access'), icon: Settings2 },
]);
const localSettings = computed(() => [
    { id: 'details' as const, label: t('معلومات النشاط', 'Business details') },
    { id: 'data' as const, label: t('البيانات والإفادات', 'Data and declarations') },
    { id: 'purposes' as const, label: t('الأغراض الإعلانية', 'Advertising purposes') },
    { id: 'access' as const, label: t('الفريق والوصول', 'Team and access') },
]);
async function navigate(section: WorkspaceSection, subview?: WorkspaceSettingsView) {
    const next = workspaceLocation(section, subview ?? (section === 'settings' ? settingsView.value : 'details'));
    if (active.value === next.section && (next.section !== 'settings' || settingsView.value === next.settingsView)) return;
    retained.positions[active.value] = window.scrollY;
    retained.section = next.section; retained.settingsView = next.settingsView;
    active.value = next.section; settingsView.value = next.settingsView;
    if (next.section === 'settings') visitedSettings.value = new Set([...visitedSettings.value, next.settingsView]);
    visited.value = new Set([...visited.value, next.section, ...(inquiryActive.value ? ['inquiries' as const] : [])]);
    await nextTick();
    heading.value?.focus({ preventScroll: true });
    window.scrollTo({ top: retained.positions[next.section] ?? ((heading.value?.getBoundingClientRect().top ?? 0) + window.scrollY - 24), behavior: 'instant' });
}
</script>
<template>
<AdvertiserWorkspaceLayout :context="surface.business.name" business-selected>
    <template #navigation>
        <nav class="advertiser-navigation" :aria-label="t('أقسام النشاط', 'Business sections')">
            <p class="advertiser-nav-label">{{ t('النشاط التجاري', 'Business') }}</p>
            <button v-for="destination in destinations.filter(d => d.id !== 'settings')" :key="destination.id" type="button" :data-workspace-section="destination.id" :aria-current="active === destination.id ? 'page' : undefined" @click="navigate(destination.id)"><component :is="destination.icon" aria-hidden="true" />{{ destination.label }}</button>
            <p class="advertiser-nav-label">{{ t('إدارة النشاط', 'Business management') }}</p>
            <button type="button" data-workspace-section="settings" :aria-current="active === 'settings' ? 'page' : undefined" @click="navigate('settings')"><Settings2 aria-hidden="true" />{{ t('النشاط والفريق والإعدادات', 'Business, team and settings') }}</button>
        </nav>
    </template>
    <section class="business-workspace" data-business-workspace :data-business-public="surface.business.business_public_id">
        <header class="workspace-identity">
            <div><p class="workspace-eyebrow">{{ t('النشاط التجاري', 'Business') }}</p><h1 data-business-name>{{ surface.business.name }}</h1><p class="workspace-type">{{ businessType ? (locale === 'ar' ? businessType.name_ar : businessType.name_en) : t('يمكنك تحديد مجال النشاط من الإعدادات', 'Choose a business field in settings') }}</p></div>
            <button v-if="active === 'overview'" type="button" class="workspace-button" @click="navigate('settings', 'details')"><Building2 aria-hidden="true" />{{ t('معلومات النشاط', 'Business details') }}</button>
        </header>
        <div class="workspace-content">
            <h2 ref="heading" class="workspace-section-title" tabindex="-1" data-workspace-heading>{{ destinations.find(d => d.id === active)?.label }}<span v-if="active === 'settings'" class="workspace-location"> / {{ localSettings.find(d => d.id === settingsView)?.label }}</span></h2>
            <section v-show="active === 'overview'" data-workspace-panel="overview">
                <p class="workspace-intro">{{ t('ماذا تريد أن تنجز؟ اختر مهمة، ويمكنك العودة إلى غيرها في أي وقت.', 'What would you like to do? Choose a task and return to the others whenever you need.') }}</p>
                <ul class="workspace-task-list">
                    <li><button type="button" @click="navigate('direction')"><Target aria-hidden="true" /><span><strong>{{ t('حدّد اتجاه نشاطك', 'Set your business direction') }}</strong><span>{{ t('راجع الأسواق والأهداف والأولويات التي أعلنتها.', 'Review your declared markets, goals and priorities.') }}</span></span><ArrowLeft aria-hidden="true" class="workspace-task-arrow" /></button></li>
                    <li><button type="button" @click="navigate('sources')" data-business-source-task><Link2 aria-hidden="true" /><span><strong>{{ t('راجع مصادرك وحساباتك الإعلانية', 'Review your sources and ad accounts') }}</strong><span>{{ t('اعرض المصادر المتاحة في مساحات العمل المرتبطة بهذا النشاط.', 'View available sources in workspaces linked to this business.') }}</span></span><ArrowLeft aria-hidden="true" class="workspace-task-arrow" /></button></li>
                    <li><button type="button" @click="navigate('settings', 'details')"><Building2 aria-hidden="true" /><span><strong>{{ t('أكمل معلومات النشاط', 'Complete business details') }}</strong><span>{{ t('عدّل الاسم والمجال وراجع سجل التغييرات.', 'Edit the name and field, and review changes.') }}</span></span><ArrowLeft aria-hidden="true" class="workspace-task-arrow" /></button></li>
                    <li><button type="button" @click="navigate('settings', 'access')"><Users aria-hidden="true" /><span><strong>{{ t('أدر الفريق والوصول', 'Manage team and access') }}</strong><span>{{ t('راجع علاقات التعاون والتفويضات لهذا النشاط.', 'Review collaboration and delegations for this business.') }}</span></span><ArrowLeft aria-hidden="true" class="workspace-task-arrow" /></button></li>
                </ul>
                <p class="workspace-context-note">{{ t('ابدأ بما تحتاجه الآن. لا يلزم حساب إعلاني أو هدف معلن لاستعمال هذه الوظائف.', 'Start with what you need now. These tasks require neither an ad account nor a declared goal.') }}</p>
            </section>
            <section v-if="visited.has('direction')" v-show="active === 'direction'" data-workspace-panel="direction">
                <p class="workspace-intro">{{ t('دوّن الأسواق واتجاه العمل الذي تريد التركيز عليه. يمكنك تعديل اختيارك لاحقًا.', 'Record your markets and the direction you want to focus on. You can revise your choices later.') }}</p>
                <BusinessFacts :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
            </section>
            <section v-if="visited.has('sources')" v-show="active === 'sources'" data-workspace-panel="sources">
                <BusinessSources :surface="surface" :active="active === 'sources'" @open-access="navigate('settings', 'access')" />
                <p v-if="dataUsePending" role="status" data-business-source-pending>{{ t('يوجد أمر استعمال بيانات غير محسوم.', 'A data-use command has an unresolved outcome.') }} <button type="button" class="workspace-button" @click="advancedSources && (advancedSources.open = true)">{{ t('فتح الإعدادات للتحقق من الأمر', 'Open settings to reconcile the command') }}</button></p>
                <details ref="advancedSources" class="workspace-disclosure" data-business-source-advanced>
                    <summary>{{ t('إعدادات متقدمة: استعمال البيانات وحقوق المصادر', 'Advanced: data use and source rights') }}</summary>
                    <p class="workspace-intro">{{ t('نطاقات استعمال البيانات تخص هذا النشاط، وتختلف عن إدارة اتصال الحساب الإعلاني. لا تعني إدارة المصدر إتاحة جميع بياناته للنشاط.', 'Data-use scopes belong to this business and are distinct from managing an ad account connection. Managing a source does not make all of its data available to the business.') }}</p>
                    <BusinessDataUse :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" @pending-change="dataUsePending = $event" />
                    <RouterLink to="/source-rights">{{ t('إدارة حقوق المصادر', 'Manage source rights') }}</RouterLink>
                </details>
            </section>
            <section v-if="visited.has('settings')" v-show="active === 'settings'" data-workspace-panel="settings">
                <nav class="workspace-local-navigation" :aria-label="t('إعدادات النشاط', 'Business settings')">
                    <button v-for="item in localSettings" :key="item.id" type="button" :data-workspace-settings="item.id" :data-workspace-section="item.id === 'data' ? 'inquiries' : item.id === 'access' ? 'access' : undefined" :aria-current="settingsView === item.id ? 'page' : undefined" @click="navigate('settings', item.id)">{{ item.label }}</button>
                </nav>
                <section v-if="visitedSettings.has('details')" v-show="settingsView === 'details'" data-workspace-settings-panel="details">
                    <BusinessEditor :surface="surface" :refreshing="refreshing" embedded @refresh="emit('refresh')" />
                    <details class="workspace-disclosure" data-business-commercial><summary>{{ t('إتاحة الخدمات والاشتراك', 'Service availability and subscription') }}</summary><p>{{ t('لم تُحدد لهذا النشاط تغطية اشتراك أو حصة خدمة بعد. لا يُنقل اشتراك مساحة التعاون إليه تلقائيًا، وإدارة المصدر أو الدفع لا يفتحان بيانات العملاء.', 'Subscription coverage or a service allowance has not been established for this business. Workspace subscriptions do not transfer automatically, and source management or payment grants no client-data access.') }}</p><p>{{ t('الخدمات المشروطة تحتاج عقد إتاحة وحصة مناسبين. الحصة غير المعروفة لا تعني صفرًا أو استعمالًا غير محدود.', 'Conditioned services need appropriate entitlement and capacity. Unknown capacity means neither zero nor unlimited usage.') }}</p></details>
                    <BusinessHistory v-if="surface.authority_selection.realm_kind !== 'internal_team'" :surface="surface" :refreshing="refreshing" />
                    <BusinessLegacyHistory v-if="surface.authority_selection.realm_kind !== 'internal_team'" :surface="surface" :refreshing="refreshing" />
                </section>
                <section v-if="visitedSettings.has('purposes')" v-show="settingsView === 'purposes'" data-workspace-settings-panel="purposes">
                    <BusinessPurposes :surface="surface" :refreshing="refreshing" />
                </section>
                <section v-if="visited.has('inquiries')" v-show="inquiryActive" data-workspace-panel="inquiries" data-workspace-settings-panel="data">
                    <InquiryEvidencePanel v-if="surface.authority_selection.realm_kind !== 'internal_team'" embedded :active="inquiryActive" :business="surface.business.business_public_id" :basis-access="String(surface.authority_selection.requirements[0].leaf_grant_id)" />
                    <p v-else>{{ t('إدارة الملف الداخلي لا تتيح الاستفسارات أو السجلات الخاصة. تحتاج هذه البيانات إلى صلاحية مستقلة.', 'Internal file administration does not provide inquiries or private records. These require independent authority.') }}</p>
                </section>
                <section v-if="visitedSettings.has('access')" v-show="settingsView === 'access'" data-workspace-panel="access" data-workspace-settings-panel="access">
                    <InternalClientFileTeam :surface="surface" :active="active === 'settings' && settingsView === 'access'" @refresh="emit('refresh')" />
                    <BusinessCollaboration :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
                    <BusinessDelegation :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
                </section>
            </section>
            <p v-if="refreshing" role="status" data-business-rechecking>{{ t('جارٍ تحديث معلومات النشاط…', 'Updating business details…') }}</p>
        </div>
    </section>
</AdvertiserWorkspaceLayout>
</template>
