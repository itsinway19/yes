<script setup lang="ts">
import { computed, nextTick, ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { RouterLink, onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router';
import { ArrowLeft, House, Link2, Settings2, Target, Users } from '@lucide/vue';
import AdvertiserWorkspaceLayout from './AdvertiserWorkspaceLayout.vue';
import BusinessSources from './BusinessSources.vue';
import BusinessTaskRow from './BusinessTaskRow.vue';
import { locale } from '../spa/locale';
import { status } from '../spa/presentation';
import { readPractices, type Practice } from './activity';
import { internalTeamDrafts, agencyTeamDrafts, teamMembersDrafts, businessMandatesDrafts, delegationDrafts, key, lifecycleDrafts, purposeEnrollmentDrafts } from './drafts';
import { hasPendingDataLicense } from './dataLicenseJourney';
import type { Surface } from './api';
import type { BusinessChoice } from './businessChoices';
import { workspacePresentation, workspacePresentationKey, workspaceLocation, type WorkspaceSection, type WorkspaceSettingsView } from './workspacePresentation';
import { pending as accountPending } from '../sources/commands';
import BusinessEditor from './BusinessEditor.vue';
import BusinessPractices from './BusinessPractices.vue';
import { hasPendingActivity } from './activityPending';
import BusinessLifecycle from './BusinessLifecycle.vue';
import BusinessFacts from './BusinessFacts.vue';
import BusinessDelegation from './BusinessDelegation.vue';
import DirectBusinessTeam from './DirectBusinessTeam.vue';
import BusinessAgencyTeam from './BusinessAgencyTeam.vue';
import BusinessTeamMembers from './BusinessTeamMembers.vue';
import BusinessMandates from './BusinessMandates.vue';
import BusinessDataUse from './BusinessDataUse.vue';
import BusinessPurposes from './BusinessPurposes.vue';
import BusinessHistory from './BusinessHistory.vue';
import InquiryEvidencePanel from '../inquiry-evidence/InquiryEvidencePanel.vue';
import './workspace.css';
const props = withDefaults(defineProps<{ surface: Surface; refreshing?: boolean }>(), { refreshing: false });
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const resource = key(props.surface.business.business_public_id, props.surface.authority_selection);
const hasPendingEmployeeAssignment = () => { const pointer = teamMembersDrafts.get(resource)?.assignment; return !!pointer && (!!agencyTeamDrafts.get(pointer.resource)?.pending || !!delegationDrafts.get(`${pointer.resource}:delegations:1`)?.pending); };
const hasPendingOperation = () => !!internalTeamDrafts.get(`${resource}:business-file-custody:2`)?.pending || hasPendingActivity(props.surface.business.business_public_id) || hasPendingDataLicense(props.surface.business.business_public_id) || !!agencyTeamDrafts.get(resource)?.pending || !!teamMembersDrafts.get(resource)?.pending || !!businessMandatesDrafts.get(`${resource}:mandates:1`)?.pending || hasPendingEmployeeAssignment() || !!purposeEnrollmentDrafts.get(`${resource}:purpose-enrollment:1`)?.pending || !!lifecycleDrafts.get(`${resource}:lifecycle:1`)?.pending;
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
const allowedSettings = ['details', 'data', 'purposes', 'access', 'lifecycle', 'history'] as const;
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
const teamActive = computed(() => active.value === 'settings' && settingsView.value === 'access');
const agencyOpen = ref(!!agencyTeamDrafts.get(resource)?.pending), agencyVisited = ref(agencyOpen.value);
const mandatesOpen = ref(!!businessMandatesDrafts.get(`${resource}:mandates:1`)?.pending), mandatesVisited = ref(mandatesOpen.value);
const mandatesAvailable = computed(() => props.surface.agency_task?.can_mandate === true || !!businessMandatesDrafts.get(`${resource}:mandates:1`)?.pending);
const mandatesTask = ref<{ focus: () => void }>(), mandatesHeading = ref<HTMLElement>();
async function openMandates() { mandatesVisited.value = true; mandatesOpen.value = true; await nextTick(); mandatesHeading.value?.focus(); }
async function closeMandates() { mandatesOpen.value = false; await nextTick(); mandatesTask.value?.focus(); }
const agencyTaskAvailable = computed(() => props.surface.agency_task?.can_mandate === true || props.surface.agency_task?.can_assign === true || !!agencyTeamDrafts.get(resource)?.pending);
watch(agencyTaskAvailable, available => { if (!available) agencyOpen.value = false; });
const agencyTask = ref<{ focus: () => void }>(), agencyHeading = ref<HTMLElement>();
async function openAgency() { agencyVisited.value = true; agencyOpen.value = true; await nextTick(); agencyHeading.value?.focus(); }
async function closeAgency() { agencyOpen.value = false; await nextTick(); agencyTask.value?.focus(); }
const navigationSection = computed(() => teamActive.value ? 'access' : active.value);
const visited = ref(new Set<WorkspaceSection>(['overview', initial.section, ...(initial.section === 'settings' && initial.settingsView === 'data' ? ['inquiries' as const] : [])]));
const visitedSettings = ref(new Set<WorkspaceSettingsView>(initial.section === 'settings' ? [initial.settingsView] : []));
const heading = ref<HTMLElement>();
const sourceTask = ref<'accounts' | 'data-use'>('accounts');
const sourceToolsVisited = ref(true);
const dataUsePending = ref(false);
async function reviewSourceSharing() { await navigate('sources'); sourceTask.value = 'data-use'; }
const reviewEditable = computed(() => ['markets', 'intent', 'preferences'].some(kind => props.surface.fact_write_options[kind as 'markets' | 'intent' | 'preferences'].length > 0));
const practices = ref<Practice[]>();
const practicesUnavailable = ref(false);
const marketLabels = computed(() => props.surface.business.declared_markets.map(code => {
    const country = props.surface.catalog.countries.find(item => item.code === code);
    return country ? t(country.name_ar, country.name_en) : code;
}));
const goalLabels = computed(() => {
    const preferences = props.surface.business.preferences;
    const goals = preferences?.priority ? [preferences.priority] : preferences?.goals ?? [];
    return goals.map(id => {
        const goal = props.surface.catalog.goals?.find(item => item.id === id);
        return goal ? t(goal.name_ar, goal.name_en) : id;
    });
});
const declaredChannels = computed(() => [...new Set(practices.value?.flatMap(item => item.value.channels) ?? [])]);
const sectorLabel = computed(() => {
    const type = props.surface.catalog.business_types.find(item => item.id === props.surface.business.business_type_id);
    return type ? t(type.name_ar, type.name_en) : t('لم يُحدد المجال', 'Sector not specified');
});
const preparation = computed(() => [
    { label: t('المجال', 'Sector'), ready: !!props.surface.business.business_type_id, section: 'settings' as const },
    { label: t('ما تقدمه للعملاء', 'What you offer'), ready: !!practices.value?.length, unavailable: practicesUnavailable.value, unknown: practices.value === undefined, section: 'settings' as const },
    { label: t('السوق المستهدف', 'Target market'), ready: !!marketLabels.value.length, section: 'direction' as const },
    { label: t('الأولوية التجارية', 'Business priority'), ready: !!goalLabels.value.length, section: 'direction' as const },
]);
const nextTask = computed(() => {
    if (!marketLabels.value.length) return { section: 'direction' as const, title: t('حدد أين تريد الوصول إلى عملائك', 'Choose where to reach your customers'), description: t('أضف الأسواق التي تخدمها ليصبح نطاق عملك واضحًا في اللوحة.', 'Add the markets you serve so your dashboard reflects your scope of work.'), action: t('تحديد الأسواق', 'Set target markets') };
    if (!goalLabels.value.length) return { section: 'direction' as const, title: t('اختر أولوية العمل الحالية', 'Choose your current business priority'), description: t('حدد النتيجة التي تهمك، ثم صف كيف ستعرف أنك حققتها.', 'Choose the outcome that matters, then describe how you will recognize success.'), action: t('تحديد الهدف', 'Set your goal') };
    if (practices.value && !practices.value.length) return { section: 'settings' as const, title: t('وضح ما يقدمه نشاطك', 'Describe what your business offers'), description: t('أضف منتجًا أو خدمة أو ممارسة لتمييز أعمال نشاطك.', 'Add a product, service or practice to describe your work.'), action: t('إضافة ممارسة', 'Add a practice') };
    return { section: 'direction' as const, title: t('حوّل هدفك إلى نتيجة واضحة', 'Give your goal a clear success definition'), description: t('راجع اتجاهك الحالي وحدد النتيجة والمدة المناسبة لك. يمكنك إنجاز ذلك الآن من معلومات نشاطك.', 'Review your direction and define a useful outcome and time horizon using your business details.'), action: t('مراجعة الهدف والنتيجة', 'Review goal and outcome') };
});
function openSourceData() { sourceToolsVisited.value = true; sourceTask.value = 'data-use'; }
watch(() => [props.surface, props.refreshing, status.value, active.value] as const, async ([surface, refreshing, readiness, section], _previous, cleanup) => {
    practices.value = undefined;
    practicesUnavailable.value = false;
    if (readiness !== 'ready' || refreshing || section !== 'overview') return;
    const grant = surface.authority_selection.requirements.find(item => item.requirement_kind === 'business')?.leaf_grant_id;
    if (!Number.isSafeInteger(grant) || grant === undefined) { practicesUnavailable.value = true; return; }
    const controller = new AbortController();
    cleanup(() => controller.abort());
    const business = surface.business.business_public_id;
    try {
        const result = await readPractices(business, grant, undefined, controller.signal);
        if (controller.signal.aborted) return;
        if (result.business_public_id !== business) throw Error('Practice list subject mismatch');
        practices.value = result.practices.filter(item => item.state === 'active');
    } catch {
        if (!controller.signal.aborted) practicesUnavailable.value = true;
    }
}, { immediate: true });
const selectedBusiness = computed<BusinessChoice | undefined>(() => {
    const read = props.surface.authority_selection.requirements.find(item => item.requirement_kind === 'business');
    if (!read) return;
    return { business_public_id: props.surface.business.business_public_id, name: props.surface.business.name,
        state: props.surface.business.state, read_grant_id: String(read.leaf_grant_id),
        realm_kind: props.surface.authority_selection.realm_kind,
        agency_business_public_id: props.surface.agency_business_public_id,
        realm_workspace_public_id: props.surface.authority_selection.realm_workspace_public_id };
});
const inquiryActive = computed(() => active.value === 'settings' && settingsView.value === 'data');
const destinations = computed(() => [
    { id: 'overview' as const, label: t('الرئيسية', 'Overview'), description: t('نظرة على معلومات نشاطك وأعماله المتاحة.', 'Your business details and available tasks.'), icon: House },
    { id: 'direction' as const, label: t('الأسواق والأهداف', 'Markets and goals'), description: t('الأسواق والأهداف المعلنة', 'Declared markets and goals'), icon: Target },
    { id: 'sources' as const, label: t('المصادر', 'Sources'), description: t('حسابات الإعلان والمصادر المتاحة وحدود استعمال بياناتها', 'Ad accounts, available sources and their data-use boundaries'), icon: Link2 },
    { id: 'access' as const, label: t('الفريق والصلاحيات', 'Team and permissions'), icon: Users },
    { id: 'settings' as const, label: t('إدارة النشاط', 'Business management'), icon: Settings2 },
]);
const localSettings = computed(() => [
    { id: 'details' as const, label: t('معلومات النشاط', 'Business details') },
    { id: 'data' as const, label: t('البيانات والإفادات', 'Data and declarations') },
    { id: 'purposes' as const, label: t('الأغراض الإعلانية', 'Advertising purposes') },
    ...(props.surface.private_history_available ? [{ id: 'history' as const, label: t('السجلات والتاريخ', 'Records and history') }] : []),
    ...(lifecycleAvailable.value ? [{ id: 'lifecycle' as const, label: t('حالة المسودة', 'Draft status') }] : []),
]);
async function applyLocation() {
    const next = routeLocation();
    if (active.value === next.section && (next.section !== 'settings' || settingsView.value === next.settingsView)) return;
    retained.positions[navigationSection.value] = window.scrollY;
    retained.section = next.section; retained.settingsView = next.settingsView;
    active.value = next.section; settingsView.value = next.settingsView;
    if (next.section === 'settings') visitedSettings.value = new Set([...visitedSettings.value, next.settingsView]);
    visited.value = new Set([...visited.value, next.section, ...(inquiryActive.value ? ['inquiries' as const] : [])]);
    await nextTick();
    heading.value?.focus({ preventScroll: true });
    const appbar = typeof document.querySelector === 'function' ? document.querySelector<HTMLElement>('.advertiser-appbar, .application-appbar') : null;
    const appbarHeight = appbar?.getBoundingClientRect().height ?? 65;
    const destinationTop = (heading.value?.getBoundingClientRect().top ?? 0) + window.scrollY - appbarHeight - 16;
    window.scrollTo({ top: retained.positions[navigationSection.value] ?? Math.max(0, destinationTop), behavior: 'instant' });
}
watch(() => [route.query.section, route.query.settings, lifecycleAvailable.value], () => { void applyLocation(); });
async function navigate(section: WorkspaceSection, subview?: WorkspaceSettingsView) {
    const next = workspaceLocation(section, subview ?? (section === 'settings' && !teamActive.value ? settingsView.value : 'details'));
    if (next.section === active.value && (next.section !== 'settings' || next.settingsView === settingsView.value)) return;
    await router.push({ path: route.path, query: { ...route.query, section: next.section,
        settings: next.section === 'settings' ? next.settingsView : undefined } });
}
</script>
<template>
<AdvertiserWorkspaceLayout :context="surface.business.name" :selection="selectedBusiness" business-selected>
    <template #navigation>
        <nav class="advertiser-navigation" :aria-label="t('أقسام النشاط', 'Business sections')">
            <button v-for="destination in destinations" :key="destination.id" type="button" :data-workspace-section="destination.id" :aria-current="navigationSection === destination.id ? 'page' : undefined" @click="navigate(destination.id)"><component :is="destination.icon" aria-hidden="true" />{{ destination.label }}</button>
        </nav>
    </template>
    <section class="business-workspace" data-business-workspace :data-business-public="surface.business.business_public_id" :aria-busy="refreshing">
        <div class="workspace-content">
            <p v-if="active === 'overview'" class="workspace-overview-label">{{ t('لوحة النشاط', 'Business dashboard') }}</p>
            <h1 ref="heading" class="workspace-section-title" tabindex="-1" data-workspace-heading>{{ active === 'overview' ? surface.business.name : destinations.find(d => d.id === navigationSection)?.label }}<span v-if="navigationSection === 'settings'" class="workspace-location"> / {{ localSettings.find(d => d.id === settingsView)?.label }}</span></h1>
            <section v-show="active === 'overview'" data-workspace-panel="overview">
                <p class="workspace-intro">{{ sectorLabel }} <span aria-hidden="true">·</span> {{ t('معلومات أضفتها عن نشاطك', 'Details you provided about your business') }}</p>
                <section class="workspace-next-action" data-current-work>
                    <div><p class="workspace-overview-label">{{ t('خطوتك التالية', 'Your next step') }}</p><h2>{{ nextTask.title }}</h2><p>{{ nextTask.description }}</p></div>
                    <button type="button" class="bf-primary" data-business-review-task @click="navigate(nextTask.section)">{{ reviewEditable ? nextTask.action : t('مراجعة المعلومات', 'Review details') }}<ArrowLeft aria-hidden="true" /></button>
                </section>
                <div class="workspace-overview-grid">
                <section class="workspace-business-brief">
                <header class="workspace-block-heading"><h2>{{ t('نشاطك في لمحة', 'Your business at a glance') }}</h2><button type="button" class="bf-link" @click="navigate('settings')">{{ t('إدارة المعلومات', 'Manage details') }}</button></header>
                <dl class="workspace-declared-summary" data-business-declared-summary>
                    <div><dt>{{ t('ما يقدّمه النشاط', 'What the business offers') }}</dt><dd data-overview-practices>
                        <template v-if="practices?.length">{{ practices.map(item => item.value.name).join(t('، ', ', ')) }}</template>
                        <template v-else-if="practicesUnavailable">{{ t('تعذّرت قراءة الممارسات', 'Practices unavailable') }}</template>
                        <template v-else-if="practices">{{ t('لم تُسجّل ممارسات نشطة', 'No active practices declared') }}</template>
                        <template v-else>—</template>
                    </dd></div>
                    <div><dt>{{ t('قنوات العمل المعلنة', 'Declared business channels') }}</dt><dd>{{ declaredChannels.join(t('، ', ', ')) || (practices ? t('لم تُحدد بعد', 'Not yet specified') : '—') }}</dd></div>
                    <div><dt>{{ t('الأسواق المستهدفة', 'Target markets') }}</dt><dd data-overview-markets>{{ marketLabels.join(t('، ', ', ')) || t('لم تُحدَّد بعد', 'Not yet declared') }}</dd></div>
                    <div><dt>{{ t('الأولوية التجارية', 'Business priority') }}</dt><dd data-overview-goal>{{ goalLabels.join(t('، ', ', ')) || t('لم تُحدَّد بعد', 'Not yet declared') }}</dd></div>
                </dl>
                </section>
                <section class="workspace-preparation"><header class="workspace-block-heading"><h2>{{ t('إعداد النشاط', 'Business preparation') }}</h2><span v-if="!reviewEditable" class="bf-badge" data-current-work-readonly>{{ t('قراءة فقط', 'Read only') }}</span></header>
                    <ul><li v-for="item in preparation" :key="item.label"><span>{{ item.label }}</span><button type="button" @click="navigate(item.section)">{{ item.unavailable ? t('تعذّرت القراءة', 'Read unavailable') : item.unknown ? t('بانتظار القراءة', 'Awaiting read') : item.ready ? t('مضاف', 'Added') : t('استكمال', 'Complete') }}</button></li></ul>
                    <p>{{ t('يعكس هذا الإعداد المعلومات المضافة؛ حالة الحسابات والبيانات تُراجع من المصادر.', 'Preparation reflects the details provided. Check account and data states under Sources.') }}</p>
                </section>
                </div>
                <section class="workspace-task-list" :aria-label="t('أعمال متاحة', 'Available work')">
                    <BusinessTaskRow data-overview-sources :title="t('المصادر والحسابات الإعلانية', 'Sources and advertising accounts')" :value="t('حالة الاتصال لم تُتحقق في هذه الصفحة', 'Connection status is not verified on this page')" :description="t('افتح المصادر للتحقق من الحالة الفعلية لكل منصة والإجراء المتاح لحسابها.', 'Open Sources to check each platform’s actual state and available account action.')" :action="t('مراجعة المصادر', 'Review sources')" @select="navigate('sources')" />
                    <BusinessTaskRow :title="t('أغراض الإعلان الحالية', 'Current advertising purposes')" :description="t('راجع ما تريد تحقيقه من الإعلان والحدود التي أعلنتها له.', 'Review the purpose and boundaries you have declared for advertising.')" :action="t('فتح الأغراض', 'Open purposes')" @select="navigate('settings', 'purposes')" />
                </section>
            </section>
            <section v-if="visited.has('direction')" v-show="active === 'direction'" data-workspace-panel="direction">
                <p class="workspace-intro">{{ t('دوّن الأسواق واتجاه العمل الذي تريد التركيز عليه. يمكنك تعديل اختيارك لاحقًا.', 'Record your markets and the direction you want to focus on. You can revise your choices later.') }}</p>
                <BusinessFacts :surface="surface" :refreshing="refreshing" summary-first @refresh="emit('refresh')" />
            </section>
            <section v-if="visited.has('sources')" v-show="active === 'sources'" data-workspace-panel="sources">
                <nav class="workspace-local-navigation" :aria-label="t('مهام المصادر', 'Source tasks')"><button type="button" data-source-task="accounts" :aria-current="sourceTask === 'accounts' ? 'page' : undefined" @click="sourceTask = 'accounts'">{{ t('الحسابات والمصادر', 'Accounts and sources') }}</button><button type="button" data-source-task="data-use" :aria-current="sourceTask === 'data-use' ? 'page' : undefined" @click="openSourceData">{{ t('استعمال البيانات', 'Data use') }}</button></nav>
                <BusinessSources v-show="sourceTask === 'accounts'" :surface="surface" :active="active === 'sources' && sourceTask === 'accounts'" @open-access="reviewSourceSharing" @refresh="emit('refresh')" />
                <p v-if="dataUsePending" role="status" data-business-source-pending>{{ t('يوجد أمر استعمال بيانات غير محسوم.', 'A data-use command has an unresolved outcome.') }} <button type="button" class="workspace-button" @click="openSourceData">{{ t('التحقق من الطلب', 'Check the request') }}</button></p>
                <section v-if="sourceToolsVisited" v-show="sourceTask === 'data-use'" class="workspace-task-editor" data-business-source-advanced>
                    <h2 class="workspace-subheading">{{ t('استعمال البيانات في هذا النشاط', 'Data use in this business') }}</h2>
                    <p class="workspace-intro">{{ t('بعد ربط الحساب، راجع البيانات التي يُسمح لهذا النشاط باستعمالها وما يمكنك فعله بها.', 'After connecting an account, review which data this business may use and what it may do with it.') }}</p>
                    <BusinessDataUse :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" @pending-change="dataUsePending = $event" />
                <nav class="workspace-source-rights" :aria-label="t('صلاحيات على مستوى حسابك', 'Account-level permissions')">
                    <RouterLink to="/source-rights" class="workspace-button">{{ t('حقوق المصادر في حسابك', 'Source rights in your account') }}</RouterLink>
                    <RouterLink to="/data-rights" class="workspace-button">{{ t('نطاقات بيانات العملاء في حسابك', 'Client data boundaries in your account') }}</RouterLink>
                </nav>
                </section>
            </section>
            <section v-if="visited.has('settings')" v-show="active === 'settings'" :data-workspace-panel="teamActive ? undefined : 'settings'">
                <nav v-if="!teamActive" class="workspace-local-navigation" :aria-label="t('إعدادات النشاط', 'Business settings')">
                    <button v-for="item in localSettings" :key="item.id" type="button" :data-workspace-settings="item.id" :data-workspace-section="item.id === 'data' ? 'inquiries' : undefined" :aria-current="settingsView === item.id ? 'page' : undefined" @click="navigate('settings', item.id)">{{ item.label }}</button>
                </nav>
                <section v-if="visitedSettings.has('details')" v-show="settingsView === 'details'" data-workspace-settings-panel="details">
                    <BusinessEditor :surface="surface" :refreshing="refreshing" embedded summary-first @refresh="emit('refresh')" />
                    <div class="workspace-task-list"><BusinessTaskRow :title="t('الأسواق والأهداف', 'Markets and goals')" :value="[marketLabels.join(t('، ', ', ')), goalLabels.join(t('، ', ', '))].filter(Boolean).join(' · ') || t('لم تُحدد بعد', 'Not yet specified')" :action="t('مراجعة وتعديل', 'Review and edit')" @select="navigate('direction')" /></div>
                    <BusinessPractices :surface="surface" :refreshing="refreshing" :active="active === 'settings' && settingsView === 'details'" @refresh="emit('refresh')" />
                </section>
                <section v-if="visitedSettings.has('history')" v-show="settingsView === 'history'" data-workspace-settings-panel="history">
                    <h2 class="workspace-subheading">{{ t('السجلات والتاريخ', 'Records and history') }}</h2>
                    <p class="workspace-intro">{{ t('راجع المعلومات السابقة وتواريخ تعديلها وفق الصلاحية المتاحة لك.', 'Review earlier details and their changes under your current access.') }}</p>
                    <details class="workspace-disclosure" data-business-commercial><summary>{{ t('إتاحة الخدمات والاشتراك', 'Service availability and subscription') }}</summary><p>{{ t('لم تُحدد لهذا النشاط تغطية اشتراك أو حصة خدمة بعد. إدارة المصدر أو الدفع لا يفتحان بيانات العملاء.', 'Subscription coverage or a service allowance has not been established for this business. Source management or payment grants no client-data access.') }}</p><p>{{ t('الخدمات المشروطة تحتاج عقد إتاحة وحصة مناسبين. الحصة غير المعروفة لا تعني صفرًا أو استعمالًا غير محدود.', 'Conditioned services need appropriate entitlement and capacity. Unknown capacity means neither zero nor unlimited usage.') }}</p></details>
                    <BusinessHistory v-if="surface.private_history_available" :surface="surface" :refreshing="refreshing" />
                </section>
                <section v-if="visitedSettings.has('lifecycle') && lifecycleAvailable" v-show="settingsView === 'lifecycle'" data-workspace-settings-panel="lifecycle">
                    <BusinessLifecycle :surface="surface" :refreshing="refreshing" :active="active === 'settings' && settingsView === 'lifecycle'" @refresh="emit('refresh')" />
                </section>
                <section v-if="visitedSettings.has('purposes')" v-show="settingsView === 'purposes'" data-workspace-settings-panel="purposes">
                    <BusinessPurposes :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
                </section>
                <section v-if="visited.has('inquiries')" v-show="inquiryActive" data-workspace-panel="inquiries" data-workspace-settings-panel="data">
                    <InquiryEvidencePanel v-if="surface.private_history_available" embedded :active="inquiryActive" :business="surface.business.business_public_id" :basis-access="String(surface.authority_selection.requirements[0].leaf_grant_id)" />
                    <p v-else>{{ t('تحتاج الاستفسارات والسجلات الخاصة إلى صلاحية مستقلة.', 'Inquiries and private records require independent authority.') }}</p>
                </section>
                <section v-if="visitedSettings.has('access')" v-show="settingsView === 'access'" data-workspace-panel="access" data-workspace-settings-panel="access">
                    <p class="workspace-intro">{{ t('حدّد من يعمل على هذا النشاط وما الذي يستطيع فعله. يمكنك إدارة نشاطك منفردًا، أو توزيع العمل على فريقك عند الحاجة.', 'Decide who can work on this business and what they can do. You can manage it yourself or share work with your team when needed.') }}</p>
                    <DirectBusinessTeam :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
                    <BusinessTeamMembers :surface="surface" :active="teamActive" :refreshing="refreshing" @refresh="emit('refresh')" />
                    <div v-if="mandatesAvailable && !mandatesOpen" class="workspace-task-list">
                        <BusinessTaskRow ref="mandatesTask" task="business-mandates" :title="t('تفويضات الأنشطة التي منحتها', 'Business mandates you granted')"
                            :description="t('راجع تفويضات تصريحات نشاطك واسحب المجموعة المحددة مع إبقاء الحقوق المستقلة.', 'Review Business declaration mandates and withdraw the selected group while preserving independent rights.')"
                            :action="businessMandatesDrafts.get(`${resource}:mandates:1`)?.pending ? t('التحقق من الطلب', 'Check the request') : t('مراجعة التفويضات', 'Review mandates')" @select="openMandates" />
                    </div>
                    <section v-if="mandatesVisited && mandatesAvailable" v-show="mandatesOpen" class="workspace-task-editor" data-business-mandates-editor>
                        <header class="business-task-editor-heading"><h2 ref="mandatesHeading" tabindex="-1">{{ t('تفويضات الأنشطة التي منحتها', 'Business mandates you granted') }}</h2>
                            <button type="button" class="workspace-button" data-business-mandates-back @click="closeMandates">{{ t('العودة إلى الفريق والصلاحيات', 'Back to team and permissions') }}</button></header>
                        <BusinessMandates :surface="surface" :active="teamActive && mandatesOpen" :refreshing="refreshing" @refresh="emit('refresh')" />
                    </section>
                    <p v-if="surface.direct_team_enrollment_available && !agencyTaskAvailable" role="status" data-agency-team-prerequisite>{{ t('لتفويض نشاط آخر بمعلومات نشاطك، فعّل إدارة فريق النشاط أعلاه أولًا. لا تحتاج إلى إنشاء جهة عمل.', 'To delegate your business declarations to another business, first enable Business team administration above. No organization creation is needed.') }}</p>
                    <details v-if="surface.agency_task?.can_mandate === true" class="workspace-disclosure" data-business-other-grants :open="!!delegationDrafts.get(`${resource}:delegations:1`)?.pending">
                        <summary>{{ t('تفويضات أخرى وسجل السحب', 'Other grants and withdrawal history') }}<span v-if="delegationDrafts.get(`${resource}:delegations:1`)?.pending"> · {{ t('طلب غير محسوم', 'Unresolved request') }}</span></summary>
                        <BusinessDelegation :surface="surface" :show-issuance="false" :refreshing="refreshing" @refresh="emit('refresh')" />
                    </details>
                    <BusinessDelegation v-else :surface="surface" :show-issuance="!agencyOpen" :refreshing="refreshing" @refresh="emit('refresh')" />
                    <div v-if="agencyTaskAvailable && !agencyOpen" class="workspace-task-list">
                        <BusinessTaskRow ref="agencyTask" task="business-agency" :title="t('التفويض بين الأنشطة', 'Business delegation')"
                            :description="t('فوّض نشاطًا آخر بعمل محدد، أو كلّف موظفًا ضمن تفويض مستلم.', 'Delegate a specific task to another business, or assign an employee within a received mandate.')"
                            :action="agencyTeamDrafts.get(resource)?.pending ? t('التحقق من الطلب', 'Check the request') : t('إدارة التفويض', 'Manage delegation')" @select="openAgency" />
                    </div>
                    <section v-if="agencyVisited && agencyTaskAvailable" v-show="agencyOpen" class="workspace-task-editor" data-business-agency-editor>
                        <header class="business-task-editor-heading"><h2 ref="agencyHeading" tabindex="-1">{{ t('التفويض بين الأنشطة', 'Business delegation') }}</h2>
                            <button type="button" class="workspace-button" data-business-agency-back @click="closeAgency">{{ t('العودة إلى الفريق', 'Back to team') }}</button></header>
                        <BusinessAgencyTeam :surface="surface" :active="teamActive && agencyOpen" :refreshing="refreshing" @refresh="emit('refresh')" />
                    </section>
                </section>
            </section>
            <p v-if="refreshing" role="status" data-business-rechecking>{{ t('جارٍ تحديث معلومات النشاط…', 'Updating business details…') }}</p>
        </div>
    </section>
</AdvertiserWorkspaceLayout>
</template>
