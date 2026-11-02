<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { locale } from '../spa/locale';
import { key } from './drafts';
import type { Surface } from './api';
import { workspacePresentation, workspacePresentationKey, type WorkspaceSection } from './workspacePresentation';
import BusinessEditor from './BusinessEditor.vue';
import BusinessFacts from './BusinessFacts.vue';
import BusinessCollaboration from './BusinessCollaboration.vue';
import BusinessDelegation from './BusinessDelegation.vue';
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
const retained: { section: WorkspaceSection; positions: Partial<Record<WorkspaceSection, number>> } = workspacePresentation.get(presentationKey) ?? { section: 'overview' as WorkspaceSection, positions: {} };
workspacePresentation.set(presentationKey, retained);
const active = ref<WorkspaceSection>(retained.section);
const visited = ref(new Set<WorkspaceSection>(['overview', retained.section]));
const heading = ref<HTMLElement>();
const businessType = computed(() => props.surface.catalog.business_types.find(type => type.id === props.surface.business.business_type_id));
const destinations = computed(() => [
    { id: 'overview' as const, label: t('نظرة عامة', 'Overview'), description: t('تعرّف على مساحة نشاطك وما يمكنك إنجازه.', 'Your activity and the tasks available here.') },
    { id: 'inquiries' as const, label: t('بيانات الاستفسارات', 'Inquiry evidence'), description: t('سجّل بياناتك، راجع تفاصيلها وصحّحها مع حفظ تاريخها.', 'Record your data, review details and correct it while retaining history.') },
    { id: 'direction' as const, label: t('الأسواق والأهداف', 'Markets & goals'), description: t('حدّد الأسواق المستهدفة والنية التجارية وأولوياتك.', 'Set target markets, current business intent and preferences.') },
    { id: 'settings' as const, label: t('إعدادات النشاط', 'Activity settings'), description: t('معلومات النشاط، إتاحة الخدمات وسجل التغييرات.', 'Activity details, service availability and change history.') },
    { id: 'access' as const, label: t('الوصول والصلاحيات', 'Access & permissions'), description: t('التعاون والتفويضات واستعمال بيانات العملاء وفق صلاحياتك.', 'Collaboration, delegations and permitted customer-data use.') },
]);
async function navigate(section: WorkspaceSection) {
    if (active.value === section) return;
    retained.positions[active.value] = window.scrollY;
    retained.section = section; active.value = section;
    visited.value = new Set([...visited.value, section]);
    await nextTick();
    heading.value?.focus({ preventScroll: true });
    window.scrollTo({ top: retained.positions[section] ?? ((heading.value?.getBoundingClientRect().top ?? 0) + window.scrollY - 24), behavior: 'instant' });
}
</script>
<template>
<section class="business-workspace" data-business-workspace :data-business-public="surface.business.business_public_id">
    <header class="workspace-identity">
        <div><p class="workspace-eyebrow">{{ t('مساحة النشاط', 'Activity workspace') }}</p><h1 data-business-name>{{ surface.business.name }}</h1>
            <p class="workspace-type">{{ businessType ? (locale === 'ar' ? businessType.name_ar : businessType.name_en) : t('مجال النشاط غير محدد', 'Business field not specified') }}</p>
        </div>
        <button v-if="active === 'overview'" type="button" class="workspace-button" @click="navigate('settings')">{{ t('معلومات النشاط وتعديلها', 'View and edit activity details') }}</button>
    </header>
    <p v-if="active === 'overview'" class="workspace-purpose">{{ t('راجع معلومات نشاطك وبيانات الاستفسارات، وحدّد اتجاهه وأدر الوصول إليه من مكان واحد.', 'Review your activity and inquiry evidence, set its direction and manage access in one place.') }}</p>
    <div class="workspace-layout">
        <nav class="workspace-navigation" :aria-label="t('أقسام النشاط', 'Activity sections')">
            <button v-for="destination in destinations" :key="destination.id" type="button" :data-workspace-section="destination.id" :aria-current="active === destination.id ? 'page' : undefined" @click="navigate(destination.id)">{{ destination.label }}</button>
        </nav>
        <div class="workspace-content">
            <h2 ref="heading" class="workspace-section-title" tabindex="-1" data-workspace-heading>{{ destinations.find(d => d.id === active)?.label }}</h2>
            <section v-show="active === 'overview'" data-workspace-panel="overview">
                <p class="workspace-intro">{{ t('اختر ما تريد العمل عليه. يمكنك العودة إلى أي قسم دون المرور بالنماذج الأخرى.', 'Choose a task. Return to any section without going through unrelated forms.') }}</p>
                <ul class="workspace-task-list">
                    <li v-for="destination in destinations.filter(d => d.id !== 'overview')" :key="destination.id"><button type="button" @click="navigate(destination.id)"><strong>{{ destination.label }}</strong><span>{{ destination.description }}</span><span class="workspace-task-action">{{ t('فتح القسم', 'Open section') }} <span aria-hidden="true">{{ locale === 'ar' ? '←' : '→' }}</span></span></button></li>
                </ul>
                <p class="workspace-context-note">{{ t('لا تحتاج إلى حساب إعلاني متصل أو CRM أو هدف معلن لاستعمال الأقسام المتاحة لك. كل إجراء يخضع لصلاحياته.', 'No connected ad account, CRM or declared goal is required to use your available sections. Each operation has its own permissions.') }}</p>
            </section>
            <section v-if="visited.has('inquiries')" v-show="active === 'inquiries'" data-workspace-panel="inquiries">
                <InquiryEvidencePanel embedded :active="active === 'inquiries'" :business="surface.business.business_public_id" :basis-access="String(surface.authority_selection.requirements[0].leaf_grant_id)" />
            </section>
            <section v-if="visited.has('direction')" v-show="active === 'direction'" data-workspace-panel="direction">
                <BusinessFacts :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
            </section>
            <section v-if="visited.has('settings')" v-show="active === 'settings'" data-workspace-panel="settings">
                <h3 class="workspace-subheading">{{ t('معلومات النشاط', 'Activity details') }}</h3>
                <BusinessEditor :surface="surface" :refreshing="refreshing" embedded @refresh="emit('refresh')" />
                <details class="workspace-disclosure" data-business-commercial><summary>{{ t('الاشتراك وإتاحة الخدمات', 'Subscription and service availability') }}</summary>
                    <p>{{ t('يمكنك إدارة تصريحات النشاط وفق صلاحياتك. لم تُثبت بعد تغطية اشتراك أو حصة خدمة لهذا النشاط.', 'You can manage business declarations under your rights. Subscription coverage or a service allowance has not yet been established for this Business.') }}</p>
                    <p>{{ t('لا ننقل اشتراك مساحة التعاون إلى النشاط تلقائيًا. إدارة المصدر أو الدفع لا يمنحان حق استعمال بيانات العملاء.', 'A collaboration workspace subscription is not automatically transferred to this Business. Source management or payment grants no customer-data access.') }}</p>
                    <p>{{ t('الخدمات المشروطة غير مفعّلة دون عقد إتاحة وحصة مؤهل؛ عدم معرفة الحصة لا يعني صفرًا أو استعمالًا غير محدود.', 'Conditioned services remain unavailable without qualified entitlement and capacity. Unknown capacity means neither zero nor unlimited usage.') }}</p>
                </details>
                <BusinessPurposes :surface="surface" :refreshing="refreshing" />
                <BusinessHistory :surface="surface" :refreshing="refreshing" />
                <BusinessLegacyHistory :surface="surface" :refreshing="refreshing" />
            </section>
            <section v-if="visited.has('access')" v-show="active === 'access'" data-workspace-panel="access">
                <BusinessCollaboration :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
                <BusinessDelegation :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
                <BusinessDataUse :surface="surface" :refreshing="refreshing" @refresh="emit('refresh')" />
            </section>
            <p v-if="refreshing" role="status" data-business-rechecking>{{ t('جارٍ التحقق من معلومات النشاط الحالية…', 'Checking current business information…') }}</p>
        </div>
    </div>
</section>
</template>
