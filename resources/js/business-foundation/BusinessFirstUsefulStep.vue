<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ArrowLeft } from '@lucide/vue';
import { locale } from '../spa/locale';
import type { Surface } from './api';

type Step = Surface['first_useful_step'];
const props = defineProps<{
    step?: Step;
    pending: boolean;
    goalLabel?: string;
    countries: Surface['catalog']['countries'];
    canEditGoals: boolean;
    canEditPractice: boolean;
}>();
const emit = defineEmits<{ navigate: [section: 'direction' | 'settings']; select: [practice: string]; refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const heading = ref<HTMLElement>();
defineExpose({ focus: () => heading.value?.focus() });

const copy = computed(() => ({
    choose_goal: [
        t('ما النتيجة التي تريد أن تبدأ بها؟', 'Which result do you want to work on first?'),
        t('توضح أولويتك ما ينبغي حسمه أولًا، مثل من يشتري العرض أو أين يمكنك اختباره أو كيف ستعرف أن البيع اكتمل.', 'Your priority determines what to clarify first, such as who buys, where to test the offer, or how to recognize a completed sale.'),
    ],
    choose_practice: [
        t('على أي ممارسة تريد العمل أولًا؟', 'Which activity do you want to work on first?'),
        t('لكل ممارسة عرض ونطاق مختلفان. اختيار واحدة يجعل الخطوة التالية مرتبطة بها.', 'Each activity has its own offer and coverage. Choosing one gives the next step a specific scope.'),
    ],
    add_practice: [
        t('ما المنتج أو الخدمة التي تريد البدء بها؟', 'Which product or service will you start with?'),
        t('أضف ممارسة واحدة لتحديد العرض الذي ستعمل عليه ضمن أولويتك الحالية.', 'Add one activity to identify the offer you will work on for your current priority.'),
    ],
    describe_offering: [
        t('ما الذي سيحصل عليه العميل؟', 'What will the customer receive?'),
        t('وصف العرض في هذه الممارسة يحدد ما يمكنك اختباره مع العميل أو تسجيل بيعه.', 'Describing this offer defines what you can test with a customer or record as sold.'),
    ],
    declare_target_market: [
        t('في أي منطقة تريد اختبار هذا العرض؟', 'Where do you want to test this offer?'),
        t('في الممارسة المعنية، حدّد في حقل «الأسواق المقصودة» أين يوجد المشترون الذين تريد اختبار العرض معهم.', 'In this activity, use Intended markets to identify where the buyers you want to test the offer with are based.'),
    ],
    declare_fulfillment: [
        t('أين يُقدَّم هذا العرض أو يُسلَّم؟', 'Where is this offer provided or delivered?'),
        t('راجع «نطاق الوفاء المعلن» في الممارسة المعنية. تحديد مكان تقديم العرض يساعد على معرفة طريقة إتمام التعامل مع المشتري.', 'Review Declared fulfillment in this activity. Where the offer is provided helps clarify how the buyer can complete the transaction.'),
    ],
    check_market_coverage: [
        t('كيف يرتبط السوق المقصود بنطاق الوفاء؟', 'How does the intended market relate to fulfillment?'),
        t('راجع «الأسواق المقصودة» و«نطاق الوفاء المعلن» في هذه الممارسة. المراجع الحالية لا تكفي لحسم تداخل المنطقتين.', 'Review Intended markets and Declared fulfillment for this activity. The current references do not establish whether the areas overlap.'),
    ],
    clarify_cross_market_handling: [
        t('كيف سيُتمّ المشتري التعامل من سوقه؟', 'How will the buyer complete the transaction from their market?'),
        t('تذكر القائمتان المعلنتان بلدانًا مختلفة. حدّد كيف يمكن إتمام الشراء أو تقديم العرض، وما إذا كان يلزم حضور أو تسليم في مكان محدد.', 'The declarations list different countries. Identify how a buyer could complete the purchase or receive the offer, including whether they must attend or take delivery in a specific place.'),
    ],
    test_offer_in_declared_market: [
        t('اختبر العرض في محادثة واحدة داخل بلد مشترك', 'Test the offer in one conversation in a shared country'),
        t('إذا كان التواصل مناسبًا، اختر عميلًا محتملًا في بلد مشترك معلن. اسأل عن حاجة قائمة وسجّل لماذا يناسبه العرض أو أين يقصر.', 'If outreach is appropriate, choose a prospective customer in a declared shared country. Ask about a current need and record why the offer fits or falls short.'),
    ],
    record_buyer_roles: [
        t('حدّد أدوار الشراء في الاستفسار التالي', 'Identify the buying roles in your next inquiry'),
        t('اسأل هل الشراء شخصي أم لجهة. في الشراء الشخصي، حدّد حاجة المشتري ومن سيدفع. وفي الشراء لجهة، حدّد المستخدم وصاحب قرار الموافقة.', 'Ask whether the purchase is personal or for an organization. For a personal purchase, clarify the buyer’s need and who will pay. For an organization, identify the user and who approves the purchase.'),
    ],
    record_brokerage_qualifier: [
        t('تحقّق من جاهزية الاستفسار التالي للوساطة', 'Check whether your next brokerage inquiry is ready to proceed'),
        t('اسأل عن المعاملة المطلوبة والموعد وصاحب صلاحية الموافقة. استخدم الإجابات لتحديد خطوة المتابعة المناسبة.', 'Ask about the transaction, timing, and who can authorize it. Use the answers to decide how to follow up.'),
    ],
    record_sale_evidence: [
        t('حدّد ما الذي ستعدّه بيعًا مكتملًا', 'Define what will count as a completed sale'),
        t('اختر واقعة تستطيع التحقق منها في سجلاتك، مثل إيصال دفع أو عقد موقّع، وسجّل عند تحققها في البيع التالي.', 'Choose an event you can verify in your own records, such as a paid receipt or signed contract, and record when it happens on the next sale.'),
    ],
    spend_evidence_required: [
        t('تقييم العائد يحتاج بيانات الإنفاق والنتائج', 'Assessing return requires spend and outcome records'),
        t('اجمع سجل الإنفاق والنتائج الفعلية لنفس النشاط والفترة حتى يمكن مقارنتهما.', 'Gather spend records and actual outcomes for the same business and period so they can be compared.'),
    ],
    goal_not_supported: [
        t('هذا الهدف خارج نطاق هذا التقييم', 'This goal is outside the scope of this assessment'),
        t('لا تتوفر لهذا الهدف قاعدة لتحديد الخطوة التالية في هذه البطاقة.', 'This card has no rule for choosing a next step for this goal.'),
    ],
    practice_unavailable: [
        t('الممارسة المحددة غير متاحة', 'The selected activity is unavailable'),
        t('راجع ممارسات النشاط النشطة لتحديد العرض الذي تريد العمل عليه.', 'Review this business’s active activities to choose the offer you want to work on.'),
    ],
    business_unavailable: [
        t('الخطوة التالية غير متاحة لهذا النشاط', 'A next step is unavailable for this business'),
        t('حالة النشاط الحالية لا تسمح بتقييم خطوة عملية.', 'The current business state does not support an assessment.'),
    ],
} satisfies Record<Step['reason'], [string, string]>));
const message = computed(() => props.step && copy.value[props.step.reason]);
const label = computed(() => {
    if (props.step?.status === 'NEEDS_INPUT') return t('المعلومة التالية', 'Next question');
    if (props.step?.status === 'ACTIONABLE') return t('خطوتك التجارية التالية', 'Your next business step');
    return t('تقييم الخطوة التالية', 'Next-step assessment');
});
const destination = computed(() => {
    switch (props.step?.reason) {
        case 'choose_goal':
            return { section: 'direction' as const, label: props.canEditGoals ? t('فتح الأولويات', 'Open priorities') : t('مراجعة الأولويات', 'Review priorities') };
        case 'add_practice':
        case 'describe_offering':
        case 'declare_target_market':
        case 'declare_fulfillment':
        case 'check_market_coverage':
            return { section: 'settings' as const, label: props.canEditPractice ? t('فتح إعدادات الممارسات', 'Open activity settings') : t('مراجعة الممارسات', 'Review activities') };
        default:
            if (props.step?.reason === 'practice_unavailable' && !props.step.practice_choices.length) {
                return { section: 'settings' as const, label: t('مراجعة الممارسات', 'Review activities') };
            }
    }
});
const choices = computed(() => props.step?.practice_choices ?? []);
const practice = computed(() => props.step?.basis.practice);
const market = computed(() => props.step?.basis.market_check);
const countries = (codes: string[]) => codes.map(code => {
    const country = props.countries.find(item => item.code === code);
    return country ? t(country.name_ar, country.name_en) : code;
}).join(t('، ', ', '));
const choice = ref('');
watch(practice, value => { choice.value = value?.practice_id ?? ''; }, { immediate: true });
const showChoices = computed(() =>
    !!props.step && (props.step.reason === 'choose_practice' || props.step.reason === 'practice_unavailable')
        ? choices.value.length > 0
        : !!practice.value && choices.value.length > 1
);
const canSelect = computed(() => choice.value !== practice.value?.practice_id && choices.value.some(item => item.practice_id === choice.value));
function select() {
    if (canSelect.value) emit('select', choice.value);
}
</script>

<template>
    <section class="workspace-next-action" data-current-work data-first-useful-step :data-step-status="!pending && message ? step?.status : undefined"
        :data-step-reason="!pending && message ? step?.reason : undefined" :aria-busy="pending">
        <div class="workspace-step-copy">
            <template v-if="!pending && step && message">
                <p class="workspace-overview-label">{{ label }}</p>
                <h2 ref="heading" tabindex="-1">{{ message[0] }}</h2>
                <p><strong>{{ t('السبب', 'Why this step') }}</strong> {{ message[1] }}</p>
                <dl v-if="goalLabel || practice || market" class="workspace-step-basis">
                    <div v-if="goalLabel"><dt>{{ t('الغاية', 'Goal') }}</dt><dd>{{ goalLabel }}</dd></div>
                    <div v-if="practice"><dt>{{ t('الممارسة', 'Activity') }}</dt><dd><bdi>{{ practice.name }}</bdi></dd></div>
                    <div v-if="practice?.offering"><dt>{{ t('العرض', 'Offer') }}</dt><dd><bdi>{{ practice.offering }}</bdi></dd></div>
                    <template v-if="market">
                        <div v-if="market.target_countries.length"><dt>{{ t('البلدان المستهدفة المعلنة', 'Declared target countries') }}</dt><dd><bdi>{{ countries(market.target_countries) }}</bdi></dd></div>
                        <div v-if="market.fulfillment_countries.length"><dt>{{ t('بلدان الوفاء المعلنة', 'Declared fulfillment countries') }}</dt><dd><bdi>{{ countries(market.fulfillment_countries) }}</bdi></dd></div>
                        <div v-if="market.shared_countries.length"><dt>{{ t('البلدان المشتركة', 'Shared countries') }}</dt><dd><bdi>{{ countries(market.shared_countries) }}</bdi></dd></div>
                    </template>
                </dl>
                <p class="workspace-step-limit">{{ t('تعتمد الخطوة على معلومات أعلنها النشاط. لم يتحقق التقييم من الطلب أو القدرة الفعلية على الوفاء أو المتطلبات القانونية أو المبيعات أو الربحية.', 'Based on business declarations. This assessment does not verify demand, operating capacity, legal requirements, sales, or profitability.') }}</p>
            </template>
            <h2 v-else-if="pending" role="status">{{ t('جارٍ التحقق من الخطوة التالية…', 'Checking your next step…') }}</h2>
            <template v-else>
                <h2 ref="heading" tabindex="-1">{{ t('تعذّر عرض الخطوة التالية', 'Your next step could not be shown') }}</h2>
                <p>{{ t('حدّث معلومات النشاط للتحقق من السياق وإعادة المحاولة.', 'Refresh the business details to verify the current context and try again.') }}</p>
            </template>
        </div>
        <div v-if="!pending && (showChoices || destination || !message)" class="workspace-step-controls">
            <form v-if="showChoices" class="workspace-step-selector" @submit.prevent="select">
                <label for="first-useful-step-practice">{{ t('الممارسة التي تريد تقييمها', 'Activity to assess') }}</label>
                <select id="first-useful-step-practice" v-model="choice" data-step-practice>
                    <option value="" disabled>{{ t('اختر ممارسة', 'Select an activity') }}</option>
                    <option v-for="item in choices" :key="item.practice_id" :value="item.practice_id">{{ item.name }}</option>
                </select>
                <button type="submit" class="workspace-button" data-first-step-select :disabled="!canSelect">{{ t('اعرض الخطوة لهذه الممارسة', 'Show the step for this activity') }}</button>
            </form>
            <button v-if="destination" type="button" class="bf-primary" data-business-review-task @click="emit('navigate', destination.section)">{{ destination.label }}<ArrowLeft aria-hidden="true" /></button>
            <button v-else-if="!message" type="button" class="bf-primary" data-first-step-refresh @click="emit('refresh')">{{ t('إعادة المحاولة', 'Try again') }}</button>
        </div>
    </section>
</template>
