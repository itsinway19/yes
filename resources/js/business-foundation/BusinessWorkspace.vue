<script setup lang="ts">
import { computed, nextTick, ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { RouterLink, onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router';
import { ArrowLeft, FileText, House, Link2, Settings2, Target } from '@lucide/vue';
import AdvertiserWorkspaceLayout from './AdvertiserWorkspaceLayout.vue';
import BusinessSources from './BusinessSources.vue';
import { locale } from '../spa/locale';
import { key, lifecycleDrafts, purposeEnrollmentDrafts } from './drafts';
import type { Surface } from './api';
import type { BusinessChoice } from './businessChoices';
import { workspacePresentation, workspacePresentationKey, workspaceLocation, type WorkspaceSection, type WorkspaceSettingsView } from './workspacePresentation';
import { pending as accountPending } from '../sources/commands';
import BusinessEditor from './BusinessEditor.vue';
import BusinessLifecycle from './BusinessLifecycle.vue';
import BusinessFacts from './BusinessFacts.vue';
import BusinessCollaboration from './BusinessCollaboration.vue';
import BusinessDelegation from './BusinessDelegation.vue';
import InternalClientFileTeam from './InternalClientFileTeam.vue';
import DirectBusinessTeam from './DirectBusinessTeam.vue';
import OrganizationTeam from '../workspaces/OrganizationTeam.vue';
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
const hasPendingOperation = () => !!purposeEnrollmentDrafts.get(`${resource}:purpose-enrollment:1`)?.pending || !!lifecycleDrafts.get(`${resource}:lifecycle:1`)?.pending;
const canDepartPurposeEnrollment = () => (!hasPendingOperation() && !accountPending.size) || confirm(t('توجد عملية غير محسومة. هل تريد المغادرة مع الاحتفاظ بالطلب الأصلي؟', 'An operation has an unknown outcome. Leave while retaining its original request?'));
onBeforeRouteLeave(canDepartPurposeEnrollment);
onBeforeRouteUpdate((to, from) => {
    const resourceQuery = (query: typeof to.query) => JSON.stringify(Object.entries(query)
        .filter(([key]) => key !== 'section' && key !== 'settings').sort(([a], [b]) => a.localeCompare(b)));
    return to.path === from.path && resourceQuery(to.query) === resourceQuery(from.query) || canDepartPurposeEnrollment();
});
const guardPurposeUnload = (event: BeforeUnloadEvent) => { if (hasPendingOperation() || accountPending.size) { event.preventDefault(); event.returnValue = ''; } };
onMounted(() => window.addEventListener?.('beforeunload', guardPurposeUnload));
onBeforeUnmount(() => window.removeEventListener?.('beforeunload', guardPurposeUnload));
const presentationKey = workspacePresentationKey(resource);
const retained = workspacePresentation.get(presentationKey) ?? { section: 'overview' as WorkspaceSection, settingsView: 'details' as WorkspaceSettingsView, positions: {} as Partial<Record<WorkspaceSection, number>> };
const route = useRoute(), router = useRouter();
const allowedSections = ['overview', 'direction', 'sources', 'settings', 'inquiries', 'access'] as const;
const allowedSettings = ['details', 'data', 'purposes', 'access', 'lifecycle'] as const;
const lifecycleAvailable = computed(() => props.surface.lifecycle.archive_options.length > 0 || !!lifecycleDrafts.get(`${resource}:lifecycle:1`)?.pending);
function routeLocation() {
    const section = allowedSections.find(value => value === route.query.section) ?? 'overview';
    const requested = allowedSettings.find(value => value === route.query.settings) ?? 'details';
    const view = requested === 'lifecycle' && !lifecycleAvailable.value ? 'details' : requested;
    return workspaceLocation(section, view);
}
const initial = routeLocation();
retained.section = initial.section; retained.settingsView = initial.settingsView;
workspacePresentation.set(presentationKey, retained);
const active = ref(initial.section), settingsView = ref<WorkspaceSettingsView>(initial.settingsView);
const visited = ref(new Set<WorkspaceSection>(['overview', initial.section, ...(initial.section === 'settings' && initial.settingsView === 'data' ? ['inquiries' as const] : [])]));
const visitedSettings = ref(new Set<WorkspaceSettingsView>(initial.section === 'settings' ? [initial.settingsView] : []));
const heading = ref<HTMLElement>();
const advancedSources = ref<HTMLDetailsElement>();
const dataUsePending = ref(false);
const reviewEditable = computed(() => ['markets', 'intent', 'preferences'].some(kind => props.surface.fact_write_options[kind as 'markets' | 'intent' | 'preferences'].length > 0));
const selectedBusiness = computed<BusinessChoice | undefined>(() => {
    const read = props.surface.authority_selection.requirements.find(item => item.requirement_kind === 'business');
    if (!read) return;
    return { business_public_id: props.surface.business.business_public_id, name: props.surface.business.name,
        state: props.surface.business.state, read_grant_id: String(read.leaf_grant_id),
        realm_kind: props.surface.authority_selection.realm_kind,
        realm_workspace_public_id: props.surface.authority_selection.realm_workspace_public_id };
});
const inquiryActive = computed(() => active.value === 'settings' && settingsView.value === 'data');
const destinations = computed(() => [
    { id: 'overview' as const, label: t('الرئيسية', 'Overview'), description: t('نظرة على معلومات نشاطك وأعماله المتاحة.', 'Your business details and available tasks.'), icon: House },
    { id: 'direction' as const, label: t('الأسواق والأهداف', 'Markets and goals'), description: t('الأسواق والأهداف المعلنة', 'Declared markets and goals'), icon: Target },
    { id: 'sources' as const, label: t('المصادر', 'Sources'), description: t('حسابات الإعلان والمصادر المتاحة وحدود استعمال بياناتها', 'Ad accounts, available sources and their data-use boundaries'), icon: Link2 },
    { id: 'settings' as const, label: t('إدارة النشاط', 'Business management'), description: t('المعلومات والبيانات والوصول', 'Details, data and access'), icon: Settings2 },
]);
const localSettings = computed(() => [
    { id: 'details' as const, label: t('معلومات النشاط', 'Business details') },
    { id: 'data' as const, label: t('البيانات والإفادات', 'Data and declarations') },
    { id: 'purposes' as const, label: t('الأغراض الإعلانية', 'Advertising purposes') },
    { id: 'access' as const, label: t('الفريق والوصول', 'Team and access') },
    ...(lifecycleAvailable.value ? [{ id: 'lifecycle' as const, label: t('حالة المسودة', 'Draft status') }] : []),
]);
async function applyLocation() {
    const next = routeLocation();
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
watch(() => [route.query.section, route.query.settings, lifecycleAvailable.value], () => { void applyLocation(); });
async function navigate(section: WorkspaceSection, subview?: WorkspaceSettingsView) {
    const next = workspaceLocation(section, subview ?? (section === 'settings' ? settingsView.value : 'details'));
    if (next.section === active.value && (next.section !== 'settings' || next.settingsView === settingsView.value)) return;
    await router.push({ path: route.path, query: { ...route.query, section: next.section,
        settings: next.section === 'settings' ? next.settingsView : undefined } });
}
</script>
<template>
<AdvertiserWorkspaceLayout :context="surface.business.name" :selection="selectedBusiness" business-selected>
    <template #navigation>
        <nav class="advertiser-navigation" :aria-label="t('أقسام النشاط', 'Business sections')">
            <p class="advertiser-nav-label">{{ t('النشاط التجاري', 'Business') }}</p>
            <button v-for="destination in destinations.filter(d => d.id !== 'settings')" :key="destination.id" type="button" :data-workspace-section="destination.id" :aria-current="active === destination.id ? 'page' : undefined" @click="navigate(destination.id)"><component :is="destination.icon" aria-hidden="true" />{{ destination.label }}</button>
            <p class="advertiser-nav-label">{{ t('إدارة النشاط', 'Business management') }}</p>
            <button type="button" data-workspace-section="settings" :aria-current="active === 'settings' ? 'page' : undefined" @click="navigate('settings')"><Settings2 aria-hidden="true" />{{ t('إدارة النشاط', 'Business management') }}</button>
        </nav>
    </template>
    <section class="business-workspace" data-business-workspace :data-business-public="surface.business.business_public_id" :aria-busy="refreshing">
        <div class="workspace-content">
            <h1 ref="heading" class="workspace-section-title" tabindex="-1" data-workspace-heading>{{ active === 'overview' ? t('العمل والمتابعة', 'Work and review') : destinations.find(d => d.id === active)?.label }}<span v-if="active === 'settings'" class="workspace-location"> / {{ localSettings.find(d => d.id === settingsView)?.label }}</span></h1>
            <section v-show="active === 'overview'" data-workspace-panel="overview">
                <p class="workspace-intro">{{ t('اختر خطوة متاحة، وراجع معناها قبل المتابعة.', 'Choose an available step and review its meaning before continuing.') }}</p>
                <section class="workspace-review" data-current-work>
                    <header><h2>{{ t('العمل الحالي', 'Current work') }}</h2><span v-if="!reviewEditable" class="bf-badge" data-current-work-readonly>{{ t('قراءة فقط', 'Read only') }}</span></header>
                    <div class="workspace-review-body">
                        <h2>{{ !surface.business.declared_markets.length ? t('راجع الأسواق التي يخدمها نشاطك', 'Review the markets served by your business') : t('هل تعكس تصريحاتك عملك الحالي؟', 'Do your declarations reflect your current work?') }}</h2>
                        <p>{{ t('راجع ما أعلنته عن عملك، وعدّل التصريح المناسب عند توفر صلاحية التعديل. هذه مراجعة للسياق، وليست نتيجة أداء مقاسة.', 'Review your declarations and update the relevant one when editing is available. This is a context review, not a measured performance result.') }}</p>
                        <button type="button" class="bf-primary" data-business-review-task @click="navigate('direction')">{{ reviewEditable ? t('مراجعة التصريح', 'Review declaration') : t('قراءة التصريح الحالي', 'Read the current declaration') }}</button>
                    </div>
                    <div class="workspace-review-followup"><h3>{{ t('متاح أيضًا للمراجعة', 'Also available to review') }}</h3><button type="button" @click="navigate('settings', 'purposes')"><FileText aria-hidden="true" /><span><strong>{{ t('أغراض الإعلان الحالية', 'Current advertising purposes') }}</strong><span>{{ t('اقرأ التصريحات المتاحة دون استنتاج نتائج أو حقوق أخرى.', 'Read available declarations without inferring outcomes or other rights.') }}</span></span><ArrowLeft aria-hidden="true" /></button></div>
                </section>
            </section>
            <section v-if="visited.has('direction')" v-show="active === 'direction'" data-workspace-panel="direction">
                <p class="workspace-intro">{{ t('دوّن الأسواق واتجاه العمل الذي تريد التركيز عليه. يمكنك تعديل اختيارك لاحقًا.', 'Record your markets and the direction you want to focus on. You can revise your choices later.') }}</p>
                <BusinessFacts :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
            </section>
            <section v-if="visited.has('sources')" v-show="active === 'sources'" data-workspace-panel="sources">
                <BusinessSources :surface="surface" :active="active === 'sources'" @open-access="navigate('settings', 'access')" />
                <p v-if="dataUsePending" role="status" data-business-source-pending>{{ t('يوجد أمر استعمال بيانات غير محسوم.', 'A data-use command has an unresolved outcome.') }} <button type="button" class="workspace-button" @click="advancedSources && (advancedSources.open = true)">{{ t('فتح الإعدادات للتحقق من الأمر', 'Open settings to reconcile the command') }}</button></p>
                <details ref="advancedSources" class="workspace-disclosure" data-business-source-advanced>
                    <summary>{{ t('استعمال البيانات في هذا النشاط', 'Data use in this business') }}</summary>
                    <p class="workspace-intro">{{ t('بعد ربط الحساب، راجع البيانات التي يُسمح لهذا النشاط باستعمالها وما يمكنك فعله بها.', 'After connecting an account, review which data this business may use and what it may do with it.') }}</p>
                    <BusinessDataUse :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" @pending-change="dataUsePending = $event" />
                </details>
                <nav class="workspace-source-rights" :aria-label="t('إدارة صلاحيات البيانات', 'Data permissions')">
                    <RouterLink to="/source-rights" class="workspace-button">{{ t('إدارة حقوق المصادر', 'Manage source rights') }}</RouterLink>
                    <RouterLink to="/data-rights" class="workspace-button">{{ t('نطاقات بيانات العملاء', 'Client data boundaries') }}</RouterLink>
                </nav>
            </section>
            <section v-if="visited.has('settings')" v-show="active === 'settings'" data-workspace-panel="settings">
                <nav class="workspace-local-navigation" :aria-label="t('إعدادات النشاط', 'Business settings')">
                    <button v-for="item in localSettings" :key="item.id" type="button" :data-workspace-settings="item.id" :data-workspace-section="item.id === 'data' ? 'inquiries' : item.id === 'access' ? 'access' : undefined" :aria-current="settingsView === item.id ? 'page' : undefined" @click="navigate('settings', item.id)">{{ item.label }}</button>
                </nav>
                <section v-if="visitedSettings.has('details')" v-show="settingsView === 'details'" data-workspace-settings-panel="details">
                    <BusinessEditor :surface="surface" :refreshing="refreshing" embedded @refresh="emit('refresh')" />
                    <details class="workspace-disclosure" data-business-commercial><summary>{{ t('إتاحة الخدمات والاشتراك', 'Service availability and subscription') }}</summary><p>{{ t('لم تُحدد لهذا النشاط تغطية اشتراك أو حصة خدمة بعد. لا يُنقل اشتراك مساحة التعاون إليه تلقائيًا، وإدارة المصدر أو الدفع لا يفتحان بيانات العملاء.', 'Subscription coverage or a service allowance has not been established for this business. Workspace subscriptions do not transfer automatically, and source management or payment grants no client-data access.') }}</p><p>{{ t('الخدمات المشروطة تحتاج عقد إتاحة وحصة مناسبين. الحصة غير المعروفة لا تعني صفرًا أو استعمالًا غير محدود.', 'Conditioned services need appropriate entitlement and capacity. Unknown capacity means neither zero nor unlimited usage.') }}</p></details>
                    <BusinessHistory v-if="surface.private_history_available" :surface="surface" :refreshing="refreshing" />
                    <BusinessLegacyHistory v-if="surface.private_history_available" :surface="surface" :refreshing="refreshing" />
                </section>
                <section v-if="visitedSettings.has('lifecycle') && lifecycleAvailable" v-show="settingsView === 'lifecycle'" data-workspace-settings-panel="lifecycle">
                    <BusinessLifecycle :surface="surface" :refreshing="refreshing" :active="active === 'settings' && settingsView === 'lifecycle'" @refresh="emit('refresh')" />
                </section>
                <section v-if="visitedSettings.has('purposes')" v-show="settingsView === 'purposes'" data-workspace-settings-panel="purposes">
                    <BusinessPurposes :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
                </section>
                <section v-if="visited.has('inquiries')" v-show="inquiryActive" data-workspace-panel="inquiries" data-workspace-settings-panel="data">
                    <InquiryEvidencePanel v-if="surface.private_history_available" embedded :active="inquiryActive" :business="surface.business.business_public_id" :basis-access="String(surface.authority_selection.requirements[0].leaf_grant_id)" />
                    <p v-else>{{ t('إدارة الملف الداخلي لا تتيح الاستفسارات أو السجلات الخاصة. تحتاج هذه البيانات إلى صلاحية مستقلة.', 'Internal file administration does not provide inquiries or private records. These require independent authority.') }}</p>
                </section>
                <section v-if="visitedSettings.has('access')" v-show="settingsView === 'access'" data-workspace-panel="access" data-workspace-settings-panel="access">
                    <p class="workspace-intro">{{ t('حدّد من يعمل على هذا النشاط وما الذي يستطيع فعله. يمكنك إدارة نشاطك منفردًا، أو توزيع العمل على فريقك عند الحاجة.', 'Decide who can work on this business and what they can do. You can manage it yourself or share work with your team when needed.') }}</p>
                    <OrganizationTeam v-if="surface.authority_selection.realm_kind === 'internal_team' && surface.authority_selection.realm_workspace_public_id" :workspace="surface.authority_selection.realm_workspace_public_id" />
                    <DirectBusinessTeam :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
                    <InternalClientFileTeam :surface="surface" :active="active === 'settings' && settingsView === 'access'" @refresh="emit('refresh')" />
                    <BusinessDelegation :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
                    <BusinessCollaboration :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
                </section>
            </section>
            <p v-if="refreshing" role="status" data-business-rechecking>{{ t('جارٍ تحديث معلومات النشاط…', 'Updating business details…') }}</p>
        </div>
    </section>
</AdvertiserWorkspaceLayout>
</template>
