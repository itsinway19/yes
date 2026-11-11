<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import {
    ArrowRight, ArrowLeft, Building2, BriefcaseBusiness, Check, CircleDollarSign, ClipboardList, Clock3, Factory, Gauge,
    Globe2, Hammer, Handshake, KeyRound, Layers3, MapPin, MessageCircle, Package, Phone, ShoppingBag, Stethoscope,
    Store, Target, TrendingUp, Users, Utensils, Wrench,
} from '@lucide/vue';
import { locale } from '../spa/locale';
import { suggestedGoals, suggestedPractices, wizardAnswersValid, wizardChannels, wizardSteps, type WizardAnswers, type WizardCatalog, type WizardPractice } from './businessWizard';

const props = withDefaults(defineProps<{ catalog: WizardCatalog; answers: WizardAnswers; actionLabel: string; initialStep?: number; guest?: boolean }>(), { initialStep: 0 });
const emit = defineEmits<{ 'update:answers': [WizardAnswers]; finish: []; step: [number] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const step = ref(Math.max(0, Math.min(wizardSteps.length - 1, props.initialStep)));
const heading = ref<HTMLElement>();
const marketSearch = ref<HTMLInputElement>();
const search = ref('');
const attempted = ref(false);
const sector = computed(() => props.catalog.business_types.find(row => row.active && row.id === props.answers.type));
const sectorDetails = computed(() => ({
    real_estate: { icon: Building2, description: t('بيع وتأجير ووساطة عقارية', 'Property sales, rentals and brokerage') },
    ecommerce: { icon: ShoppingBag, description: t('عرض المنتجات واستقبال الطلبات عبر الإنترنت', 'Products and orders online') },
    healthcare: { icon: Stethoscope, description: t('عيادات ومراكز وخدمات رعاية صحية', 'Clinics, centres and care services') },
    hospitality: { icon: Utensils, description: t('مطاعم ومقاهٍ وخدمات ضيافة', 'Restaurants, cafés and hospitality') },
    professional_services: { icon: BriefcaseBusiness, description: t('استشارات وخدمات تخصصية', 'Consulting and specialist services') },
    other: { icon: Layers3, description: t('عملك أقرب إلى مجال آخر', 'A different field of work') },
}) as Record<string, { icon: typeof Building2; description: string } | undefined>);
const practiceIcons = {
    sale: Package, service: BriefcaseBusiness, rental: KeyRound, brokerage: Handshake,
    production: Factory, installation: Hammer, maintenance: Wrench, other: Layers3,
} satisfies Record<WizardPractice['kind'], typeof Building2>;
const channelIcons = { whatsapp: MessageCircle, phone: Phone, social: Users, website: Globe2, in_person: Store } as const;
const goalIcons: Record<string, typeof Target | undefined> = { sales: CircleDollarSign, customers: Users, growth: TrendingUp, efficiency: Gauge };
const sectorOrder = ['real_estate', 'hospitality', 'ecommerce', 'professional_services', 'healthcare'];
const sectorOptions = computed(() => {
    const activeTypes = props.catalog.business_types.filter(row => row.active);
    if (!props.guest) return activeTypes;
    const rank = (key: string) => {
        const index = sectorOrder.indexOf(key);
        return index < 0 ? sectorOrder.length : index;
    };
    return activeTypes.sort((a, b) => rank(a.key) - rank(b.key));
});
const practices = computed(() => suggestedPractices(sector.value?.key));
const goals = computed(() => suggestedGoals(props.catalog, sector.value?.key));
const label = (value: { name_ar: string; name_en: string }) => t(value.name_ar, value.name_en);
const countryName = (code: string) => {
    const country = props.catalog.countries.find(row => row.code === code);
    return country ? label(country) : code;
};
const countries = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    const exactCode = props.catalog.countries.find(country => country.code.toLowerCase() === query);
    return exactCode ? [exactCode] : props.catalog.countries.filter(country =>
        [country.code, country.name_ar, country.name_en].some(value => value.toLocaleLowerCase().includes(query)));
});
const steps = computed(() => [
    { icon: Building2, label: t('المجال', 'Sector'), title: t('ما مجال نشاطك التجاري؟', 'What type of business do you run?'), detail: t('اختر المجال الأقرب لطبيعة عملك.', 'Choose the closest business category.') },
    { icon: Layers3, label: t('الممارسات', 'Practices'), title: t('ماذا تقدم لعملائك؟', 'What do you offer customers?'), detail: t('اختر ما تقدمه بالفعل. يمكنك تعديل التفاصيل من لوحة نشاطك لاحقًا.', 'Choose what you already offer. You can edit the details from your dashboard later.') },
    { icon: Globe2, label: t('السوق', 'Markets'), title: t('أين تريد الوصول إلى عملائك؟', 'Where do you want to reach customers?'), detail: t('اختر الدول التي تخدمها أو تستهدفها. يمكنك تأجيل هذا الاختيار.', 'Choose the countries you serve or target. You can set this later.') },
    { icon: MessageCircle, label: t('القنوات', 'Channels'), title: t('كيف يتواصل عملاؤك معك؟', 'How do customers reach you?'), detail: t('اختر القنوات المشتركة بين الممارسات التي أضفتها. يمكنك تخصيصها لكل ممارسة لاحقًا.', 'Choose the channels shared by the practices you added. You can refine them per practice later.') },
    { icon: Target, label: t('الهدف', 'Goal'), title: t('ما النتيجة التي تهمك الآن؟', 'Which outcome matters now?'), detail: t('حدد أولوية عمل واحدة كبداية. يمكنك تعديلها من لوحة نشاطك.', 'Choose one business priority to start. You can revise it from your dashboard.') },
    { icon: ClipboardList, label: t('المراجعة', 'Review'), title: t('سمِّ نشاطك وراجع اختياراتك', 'Name your business and review your choices'), detail: t('ستظهر هذه المعلومات كتفاصيل صرّحت بها عن نشاطك.', 'These details will appear as declarations you made about your business.') },
]);
const active = computed(() => steps.value[step.value]);
const stepIndexes = computed(() => wizardSteps.map((_, index) => index).filter(index => index !== 3 || !!props.answers.practices.length));
const stepNumber = computed(() => stepIndexes.value.indexOf(step.value) + 1);
const validName = computed(() => props.answers.name.trim().length >= 2 && props.answers.name.length <= 120);
const goal = computed(() => props.catalog.goals.find(row => row.id === props.answers.goal));
const unspecified = computed(() => t('لم تحدد بعد', 'Not specified'));

function update(patch: Partial<WizardAnswers>) { emit('update:answers', { ...props.answers, ...patch }); }
function changePractice(kind: WizardPractice['kind'], name: string | null) {
    const values = [...props.answers.practices];
    const index = values.findIndex(item => item.kind === kind);
    if (index < 0 && name !== null) values.push({ kind, name });
    else if (index >= 0 && name === null) values.splice(index, 1);
    update({ practices: values, channels: values.length ? props.answers.channels : [] });
}
function changeChoice(field: 'codes' | 'channels', value: string, event: Event) {
    const checked = (event.target as HTMLInputElement).checked;
    const values = props.answers[field].filter(item => item !== value);
    update({ [field]: checked ? [...values, value] : values });
}
function clearMarketSearch() { search.value = ''; marketSearch.value?.focus(); }
async function go(index: number) {
    step.value = index; attempted.value = false; emit('step', index);
    await nextTick(); heading.value?.focus();
}
function next() {
    if (step.value === 5) {
        attempted.value = true;
        if (wizardAnswersValid(props.answers, props.catalog)) emit('finish');
        return;
    }
    void go(stepIndexes.value[stepIndexes.value.indexOf(step.value) + 1]);
}
</script>

<template>
    <section class="setup-studio" :class="{ 'setup-studio--guest': guest }" data-business-wizard :data-wizard-step="wizardSteps[step]">
        <aside class="setup-rail">
            <p class="setup-eyebrow"><span aria-hidden="true">✦</span> {{ guest ? t('بداية أفضل لنشاطك', 'A better business start') : t('مساحة المعلن', 'Advertiser workspace') }}</p>
            <h2>{{ t('خطوات صغيرة.', 'Small steps.') }}<em>{{ t('صورة أوضح لعملك.', 'A clearer picture of your business.') }}</em></h2>
            <p>{{ t('أضف ما تعرفه عن نشاطك. سنستخدمه لترتيب المعلومات التي تراها في لوحتك.', 'Tell us what you know about your business. We’ll use it to organize the information on your dashboard.') }}</p>
            <ul v-if="guest" class="setup-benefits">
                <li><i aria-hidden="true"><Check :size="17" /></i>{{ t('اختيارات واضحة لكل خطوة', 'Clear choices at each step') }}</li>
                <li><i aria-hidden="true"><Layers3 :size="17" /></i>{{ t('يمكنك استكمال التفاصيل لاحقًا', 'Add more details later') }}</li>
                <li><i aria-hidden="true"><component :is="locale === 'ar' ? ArrowLeft : ArrowRight" :size="17" /></i>{{ t('انتقال مباشر إلى مساحة عملك', 'Go straight to your workspace') }}</li>
            </ul>
            <ol v-else class="setup-steps" :aria-label="t('خطوات تعريف النشاط', 'Business setup steps')">
                <li v-for="(index, position) in stepIndexes" :key="index" :aria-current="index === step ? 'step' : undefined">
                    <span class="setup-step-index" aria-hidden="true"><Check v-if="index < step" :size="14" /><template v-else>{{ position + 1 }}</template></span>
                    <span>{{ steps[index].label }}</span>
                </li>
            </ol>
            <p v-if="guest" class="setup-rail-note">{{ t('يمكنك تعديل معلومات نشاطك من لوحتك بعد إنشائه.', 'You can update your business details from your dashboard after setup.') }}</p>
        </aside>
        <form class="setup-panel" novalidate @submit.prevent="next">
            <div class="setup-progress-label">
                <p class="setup-count" :aria-current="guest ? 'step' : undefined">{{ t('الخطوة', 'Step') }} <bdi dir="ltr">{{ stepNumber }} / {{ stepIndexes.length }}</bdi></p>
                <p>{{ guest ? active.label : t('تعريف النشاط', 'Business setup') }}</p>
            </div>
            <progress class="setup-meter" :aria-label="t('تقدم تعريف النشاط', 'Business setup progress')" :max="stepIndexes.length" :value="stepNumber" />
            <div class="setup-panel-heading">
                <i class="setup-icon" aria-hidden="true"><component :is="active.icon" :size="23" /></i>
                <div>
                    <h1 ref="heading" tabindex="-1">{{ active.title }}</h1>
                    <p>{{ active.detail }}</p>
                </div>
            </div>
            <fieldset v-if="step === 0" class="setup-choices" id="bf-create-type">
                <legend class="setup-visually-hidden">{{ active.title }}</legend>
                <label v-for="type in sectorOptions" :key="type.id" class="setup-option setup-sector-option">
                    <input type="radio" name="business-type" :value="type.id" :checked="answers.type === type.id"
                        :aria-labelledby="`setup-sector-${type.id}`" :aria-describedby="sectorDetails[type.key] ? `setup-sector-description-${type.id}` : undefined" @change="update({ type: type.id })" />
                    <i class="setup-choice-icon" aria-hidden="true"><component :is="sectorDetails[type.key]?.icon ?? Building2" :size="23" /></i>
                    <span><strong :id="`setup-sector-${type.id}`">{{ label(type) }}</strong><small v-if="sectorDetails[type.key]" :id="`setup-sector-description-${type.id}`">{{ sectorDetails[type.key]?.description }}</small></span>
                    <i class="setup-option-check" aria-hidden="true"><Check :size="13" /></i>
                </label>
                <label class="setup-option setup-sector-option">
                    <input type="radio" name="business-type" :checked="answers.type === null" @change="update({ type: null })" />
                    <i class="setup-choice-icon" aria-hidden="true"><Clock3 :size="23" /></i>
                    <span>{{ t('أحدد المجال لاحقًا', 'Set the field later') }}</span>
                    <i class="setup-option-check" aria-hidden="true"><Check :size="13" /></i>
                </label>
            </fieldset>
            <fieldset v-else-if="step === 1" class="setup-choices">
                <legend class="setup-visually-hidden">{{ active.title }}</legend>
                <div v-for="item in practices" :key="item.kind" class="setup-practice-option">
                    <label class="setup-option">
                        <input type="checkbox" :checked="answers.practices.some(row => row.kind === item.kind)" @change="changePractice(item.kind, ($event.target as HTMLInputElement).checked ? t(...item.label) : null)" />
                        <i class="setup-choice-icon" aria-hidden="true"><component :is="practiceIcons[item.kind]" :size="23" /></i>
                        <span>{{ answers.practices.find(row => row.kind === item.kind)?.name || t(...item.label) }}</span>
                        <i class="setup-option-check" aria-hidden="true"><Check :size="13" /></i>
                    </label>
                </div>
                <p class="setup-note">{{ t('يمكنك فصل البيع عن التأجير أو الصيانة عن التركيب لتسهيل مراجعة كل عمل لاحقًا.', 'Keep sales and rentals or maintenance and installation separate so you can review each practice later.') }}</p>
            </fieldset>
            <fieldset v-else-if="step === 2">
                <legend class="setup-visually-hidden">{{ active.title }}</legend>
                <div class="setup-search">
                    <label class="setup-field" for="bf-create-market-search"><span>{{ t('ابحث عن دولة', 'Search countries') }}</span><input id="bf-create-market-search" ref="marketSearch" v-model="search" type="search" @keydown.enter.prevent /></label>
                    <button v-if="search" class="setup-quiet" type="button" data-create-market-search-clear @click="clearMarketSearch">{{ t('مسح البحث', 'Clear search') }}</button>
                </div>
                <ul v-if="answers.codes.length" class="setup-selected"><li v-for="code in answers.codes" :key="code"><span>{{ countryName(code) }}</span><button type="button" :aria-label="t('إزالة', 'Remove') + ' ' + countryName(code)" @click="update({ codes: answers.codes.filter(item => item !== code) })">×</button></li></ul>
                <p v-if="search.trim() && !countries.length" role="status" data-create-market-search-empty>{{ t('لا توجد دول مطابقة للبحث.', 'No countries match your search.') }}</p>
                <p v-if="countries.length > 4" id="setup-country-scroll" class="setup-country-scroll">{{ t('مرّر داخل القائمة لعرض المزيد من الدول.', 'Scroll within the list to see more countries.') }}</p>
                <div v-if="countries.length" class="setup-countries" role="group" :aria-label="t('الدول المتاحة', 'Available countries')" :aria-describedby="countries.length > 4 ? 'setup-country-scroll' : undefined">
                    <label v-for="country in countries" :key="country.code" class="setup-option">
                        <input type="checkbox" :value="country.code" :checked="answers.codes.includes(country.code)" :disabled="answers.codes.length >= 50 && !answers.codes.includes(country.code)" @change="changeChoice('codes', country.code, $event)" />
                        <i class="setup-choice-icon" aria-hidden="true"><MapPin :size="18" /></i>
                        <span>{{ label(country) }}</span><bdi aria-hidden="true">{{ country.code }}</bdi>
                        <i class="setup-option-check" aria-hidden="true"><Check :size="13" /></i>
                    </label>
                </div>
                <p v-if="answers.codes.length >= 50">{{ t('اخترت الحد الأقصى وهو 50 سوقًا.', 'You’ve reached the limit of 50 markets.') }}</p>
            </fieldset>
            <fieldset v-else-if="step === 3" class="setup-choices">
                <legend class="setup-visually-hidden">{{ active.title }}</legend>
                <label v-for="[id, ar, en] in wizardChannels" :key="id" class="setup-option">
                    <input type="checkbox" :checked="answers.channels.includes(id)" @change="changeChoice('channels', id, $event)" />
                    <i class="setup-choice-icon" aria-hidden="true"><component :is="channelIcons[id]" :size="23" /></i>
                    <span>{{ t(ar, en) }}</span>
                    <i class="setup-option-check" aria-hidden="true"><Check :size="13" /></i>
                </label>
                <p class="setup-note">{{ t('يكفي تحديد القنوات التي تستخدمها. ربط الحسابات خطوة منفصلة.', 'Choose the channels you use today. Connecting accounts is a separate step.') }}</p>
            </fieldset>
            <fieldset v-else-if="step === 4" class="setup-choices setup-goals">
                <legend class="setup-visually-hidden">{{ active.title }}</legend>
                <label v-for="choice in goals" :key="choice.id" class="setup-option">
                    <input type="radio" name="business-goal" :checked="answers.goal === choice.id" @change="update({ goal: choice.id })" />
                    <i class="setup-choice-icon" aria-hidden="true"><component :is="goalIcons[choice.id] ?? Target" :size="23" /></i>
                    <span><strong>{{ label(choice) }}</strong><small>{{ t(choice.description_ar, choice.description_en) }}</small></span>
                    <i class="setup-option-check" aria-hidden="true"><Check :size="13" /></i>
                </label>
                <label class="setup-option">
                    <input type="radio" name="business-goal" :checked="answers.goal === null" @change="update({ goal: null })" />
                    <i class="setup-choice-icon" aria-hidden="true"><Clock3 :size="23" /></i>
                    <span>{{ t('أحدد الهدف لاحقًا', 'Set the goal later') }}</span>
                    <i class="setup-option-check" aria-hidden="true"><Check :size="13" /></i>
                </label>
                <p class="setup-note">{{ t('يظهر هذا الاختيار في لوحتك كأولوية أعلنتها لنشاطك.', 'Your dashboard will show this choice as your stated priority.') }}</p>
            </fieldset>
            <template v-else>
                <label for="bf-create-name" class="setup-field"><span>{{ t('اسم النشاط', 'Business name') }}</span>
                    <input id="bf-create-name" :value="answers.name" autocomplete="organization" maxlength="120" required :aria-invalid="attempted && !validName" :aria-describedby="attempted && !validName ? 'setup-name-error' : undefined" @input="update({ name: ($event.target as HTMLInputElement).value })" />
                </label>
                <p v-if="attempted && !validName" id="setup-name-error" class="setup-error" role="alert">{{ t('اكتب اسمًا يتكون من حرفين على الأقل.', 'Enter a name with at least two characters.') }}</p>
                <dl class="setup-review">
                    <div><dt>{{ steps[0].label }}</dt><dd>{{ sector ? label(sector) : unspecified }}</dd><button type="button" class="setup-quiet" @click="go(0)">{{ t('تعديل المجال', 'Edit sector') }}</button></div>
                    <div><dt>{{ steps[1].label }}</dt><dd>{{ answers.practices.map(row => row.name).join(t('، ', ', ')) || unspecified }}</dd><button type="button" class="setup-quiet" @click="go(1)">{{ t('تعديل الممارسات', 'Edit practices') }}</button></div>
                    <div><dt>{{ steps[2].label }}</dt><dd>{{ answers.codes.map(countryName).join(t('، ', ', ')) || unspecified }}</dd><button type="button" class="setup-quiet" @click="go(2)">{{ t('تعديل الأسواق', 'Edit markets') }}</button></div>
                    <div v-if="answers.practices.length"><dt>{{ steps[3].label }}</dt><dd>{{ wizardChannels.filter(row => answers.channels.includes(row[0])).map(([, ar, en]) => t(ar, en)).join(t('، ', ', ')) || unspecified }}</dd><button type="button" class="setup-quiet" @click="go(3)">{{ t('تعديل القنوات', 'Edit channels') }}</button></div>
                    <div><dt>{{ steps[4].label }}</dt><dd>{{ goal ? label(goal) : unspecified }}</dd><button type="button" class="setup-quiet" @click="go(4)">{{ t('تعديل الهدف', 'Edit goal') }}</button></div>
                </dl>
                <p v-if="attempted && validName && !wizardAnswersValid(answers, catalog)" class="setup-error" role="alert">{{ t('تغيرت بعض الخيارات. راجع اختياراتك قبل المتابعة.', 'Some options have changed. Review your choices before continuing.') }}</p>
            </template>
            <div class="setup-actions">
                <button v-if="step > 0 || guest" type="button" class="setup-quiet" data-wizard-back :disabled="step === 0" @click="go(stepIndexes[stepIndexes.indexOf(step) - 1])"><component :is="locale === 'ar' ? ArrowRight : ArrowLeft" aria-hidden="true" :size="16" />{{ t('السابق', 'Back') }}</button>
                <button type="submit" class="setup-primary" data-wizard-next>{{ step === 5 ? actionLabel : t('متابعة', 'Continue') }}<component :is="locale === 'ar' ? ArrowLeft : ArrowRight" aria-hidden="true" :size="16" /></button>
            </div>
        </form>
    </section>
</template>

<style scoped>
.setup-studio {
    --setup-line: var(--ad-line, #dbe6e0);
    --setup-paper: var(--ad-paper, #fff);
    --setup-ink: var(--ad-ink, #182f39);
    --setup-muted: var(--ad-muted, #506762);
    --setup-accent: var(--ad-accent, #216457);
    --setup-soft: var(--ad-soft, #edf4ef);
    --setup-gold: #ad7b26;
    --setup-checked: #755313;
    --setup-option-bg: color-mix(in srgb, var(--setup-paper) 70%, var(--setup-soft));
    --setup-selected-bg: color-mix(in srgb, var(--setup-paper) 92%, var(--setup-gold));
    display: grid;
    grid-template-columns: minmax(215px, .78fr) minmax(0, 1.22fr);
    gap: clamp(18px, 3vw, 40px);
    width: 100%;
    max-width: 1280px;
    margin-inline: auto;
    align-items: stretch;
    color: var(--setup-ink);
    text-align: start;
}
.setup-rail {
    position: relative;
    overflow: hidden;
    padding: 28px;
    background: linear-gradient(145deg, #153f36, #0d3029);
    color: #f0f8f4;
    border-radius: 24px;
}
.setup-rail::after {
    content: '';
    position: absolute;
    pointer-events: none;
    width: 340px;
    aspect-ratio: 1;
    inset-inline-end: -185px;
    inset-block-end: -146px;
    border: 1px solid #ffffff26;
    border-radius: 50%;
    box-shadow: 0 0 0 34px #ffffff0b, 0 0 0 68px #ffffff06;
}
.setup-rail > * { position: relative; z-index: 1; }
.setup-rail h2 {
    font-size: clamp(27px, 3vw, 36px);
    line-height: 1.3;
    letter-spacing: -.022em;
    margin: 11px 0 15px;
    color: inherit;
    font-weight: 780;
}
.setup-rail h2 em { display: block; color: #f2cc80; font-style: normal; }
.setup-rail p { font-size: .875rem; line-height: 1.85; margin: 0; color: #c9e1d7; }
.setup-rail .setup-eyebrow { color: #efcd8f; font-size: .76rem; font-weight: 750; }
.setup-steps {
    display: grid;
    gap: 10px;
    list-style: none;
    margin: 29px 0 0;
    padding: 0;
    color: #d8e8e2;
    font-size: .84rem;
}
.setup-steps li { display: flex; align-items: center; gap: 10px; }
.setup-step-index {
    flex: none;
    border: 1px solid #ffffff40;
    border-radius: 9px;
    width: 28px;
    height: 28px;
    display: grid;
    place-items: center;
    background: #ffffff0e;
}
.setup-steps [aria-current=step] { font-weight: 750; color: #ffe3ab; }
.setup-steps [aria-current=step] .setup-step-index { background: #f4d59a; color: #153c34; border-color: transparent; }
.setup-panel {
    display: flex;
    flex-direction: column;
    margin: 0;
    min-height: 480px;
    padding: 28px;
    border: 1px solid var(--setup-line);
    border-radius: 24px;
    background: var(--setup-paper);
    color: var(--setup-ink);
    min-width: 0;
    box-shadow: 0 20px 52px #102e3519;
}
.setup-progress-label { display: flex; justify-content: space-between; gap: 10px; align-items: center; }
.setup-panel .setup-progress-label p { font-size: .75rem; font-weight: 650; }
.setup-panel .setup-progress-label .setup-count { color: var(--setup-accent); font-weight: 750; }
.setup-meter {
    appearance: none;
    display: block;
    width: 100%;
    height: 7px;
    border: 0;
    margin: 10px 0 24px;
    border-radius: 6px;
    overflow: hidden;
    background: var(--setup-soft);
}
.setup-meter::-webkit-progress-bar { background: var(--setup-soft); }
.setup-meter::-webkit-progress-value { background: linear-gradient(115deg, #278974, #a8be71); border-radius: 6px; }
.setup-meter::-moz-progress-bar { background: linear-gradient(115deg, #278974, #a8be71); border-radius: 6px; }
.setup-panel-heading { display: flex; gap: 14px; align-items: flex-start; margin-block-end: 22px; }
.setup-icon {
    flex: none;
    width: 44px;
    height: 44px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: var(--setup-soft);
    color: var(--setup-accent);
}
.setup-panel h1 {
    font-size: clamp(23px, 2.8vw, 34px);
    font-weight: 760;
    line-height: 1.35;
    letter-spacing: -.02em;
    margin: 0 0 7px;
    color: var(--setup-ink);
}
.setup-panel p { font-size: .875rem; margin: 0; color: var(--setup-muted); line-height: 1.65; }
.setup-panel fieldset { margin: 0; border: 0; padding: 0; min-width: 0; }
.setup-visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
.setup-choices { display: grid; gap: 9px; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; }
.setup-option {
    position: relative;
    display: flex;
    align-items: center;
    min-height: 64px;
    gap: 9px;
    border: 1px solid var(--setup-line);
    border-radius: 17px;
    padding: 10px;
    cursor: pointer;
    background: var(--setup-option-bg);
    font-size: .875rem;
    line-height: 1.45;
    margin: 0;
    transition: background-color .16s, border-color .16s;
}
.setup-sector-option { min-height: 64px; align-self: stretch; }
.setup-option:hover { border-color: var(--setup-accent); }
.setup-option:has(input:checked) {
    border-color: var(--setup-gold);
    background: var(--setup-selected-bg);
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--setup-gold) 23%, transparent);
}
.setup-option input {
    position: absolute;
    inset: 0;
    z-index: 1;
    width: 100%;
    height: 100%;
    margin: 0;
    opacity: 0;
    cursor: inherit;
}
.setup-option:has(input:focus-visible) { outline: 2px solid var(--setup-accent); outline-offset: 2px; }
.setup-option:has(input:disabled) { opacity: .6; cursor: default; }
.setup-choice-icon {
    flex: none;
    width: 32px;
    height: 32px;
    display: grid;
    place-items: center;
    border: 1px solid var(--setup-line);
    border-radius: 11px;
    background: var(--setup-paper);
    color: var(--setup-accent);
}
.setup-option-check {
    display: grid;
    place-items: center;
    flex: none;
    width: 18px;
    height: 18px;
    border: 1px solid color-mix(in srgb, var(--setup-muted) 55%, var(--setup-line));
    border-radius: 50%;
    color: transparent;
}
.setup-option:has(input:checked) .setup-option-check {
    border-color: var(--setup-gold);
    background: #e9bd65;
    color: var(--setup-checked);
}
.setup-option span { flex: 1; min-width: 0; overflow-wrap: anywhere; font-weight: 650; }
.setup-option bdi { color: var(--setup-muted); font-size: .72rem; }
.setup-option small { display: block; color: var(--setup-muted); margin-top: 3px; line-height: 1.5; font-weight: 400; }
.setup-panel .setup-note {
    grid-column: 1 / -1;
    background: var(--setup-soft);
    border-inline-start: 3px solid var(--setup-accent);
    border-radius: 6px;
    padding: 10px 12px;
    margin-block-start: 4px;
    font-size: .8rem;
}
.setup-field { display: grid; gap: 6px; color: var(--setup-ink); font-size: .82rem; font-weight: 650; min-width: 0; }
.setup-field input { margin: 0; width: 100%; min-height: 44px; border: 1px solid var(--setup-line); border-radius: 9px; background: var(--setup-paper); color: var(--setup-ink); padding: 8px 11px; font: inherit; font-weight: 400; }
.setup-search { display: flex; align-items: end; gap: 10px; margin-block-end: 10px; }
.setup-search label { flex: 1; }
.setup-panel .setup-country-scroll { margin-block-end: 6px; font-size: .78rem; }
.setup-countries { max-height: 250px; overflow: auto; scrollbar-gutter: stable; scroll-padding-block: 4px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; padding: 4px; }
.setup-countries .setup-option { min-height: 50px; padding: 6px 8px; }
.setup-countries .setup-choice-icon { width: 32px; height: 32px; border-radius: 8px; }
.setup-selected { display: flex; gap: 7px; flex-wrap: wrap; list-style: none; margin: 0 0 10px; padding: 0; }
.setup-selected li { display: flex; align-items: center; gap: 6px; border: 1px solid var(--setup-line); padding-inline-start: 8px; border-radius: 6px; font-size: .78rem; }
.setup-selected button { border: 0; background: none; color: inherit; width: 44px; min-height: 44px; cursor: pointer; font-size: 1.15rem; }
.setup-review { margin: 18px 0 0; border: 1px solid var(--setup-line); border-radius: 12px; overflow: hidden; }
.setup-review > div { display: grid; grid-template-columns: minmax(65px, .6fr) minmax(0, 1.4fr) auto; gap: 10px; align-items: center; padding: 3px 12px; font-size: .83rem; }
.setup-review > div + div { border-block-start: 1px solid var(--setup-line); }
.setup-review dt { color: var(--setup-muted); }
.setup-review dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.setup-panel .setup-error { grid-column: 1 / -1; color: var(--ad-danger, #b32626); margin-top: 7px; }
.setup-actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-block-start: auto; padding-block-start: 24px; }
.setup-primary, .setup-quiet { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 44px; padding: 8px 12px; border-radius: 8px; font: inherit; font-size: .85rem; font-weight: 650; cursor: pointer; }
.setup-primary { border: 1px solid transparent; background: #216457; color: #fff; margin-inline-start: auto; }
.setup-primary:hover { background: #194d43; }
.setup-quiet { border: 0; background: none; color: var(--setup-accent); text-align: start; }
.setup-quiet:hover { text-decoration: underline; }
.setup-studio :focus-visible { outline: 2px solid var(--setup-accent); outline-offset: 3px; }
@media (max-width: 970px) {
    .setup-studio { grid-template-columns: 1fr; }
    .setup-rail { padding: 17px 20px; }
    .setup-rail h2 { font-size: 25px; margin: 4px 0 6px; }
    .setup-rail h2 em { display: inline; margin-inline-start: .22em; }
    .setup-steps { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-top: 14px; }
    .setup-step-index { width: 23px; height: 23px; border-radius: 7px; }
}
@media (max-width: 510px) {
    .setup-choices, .setup-countries { grid-template-columns: 1fr; }
    .setup-countries { scroll-snap-type: y proximity; }
    .setup-countries .setup-option { scroll-snap-align: start; }
    .setup-panel-heading { gap: 10px; }
    .setup-panel, .setup-rail { border-radius: 15px; }
    .setup-panel { min-height: 0; }
    .setup-rail h2 { font-size: 22px; }
    .setup-rail > p:not(.setup-eyebrow) { display: none; }
    .setup-review > div { grid-template-columns: 1fr auto; gap: 0 8px; padding-block: 6px; }
    .setup-review dd { grid-row: 2; }
    .setup-review button { grid-column: 2; grid-row: 1 / span 2; }
    .setup-sector-option { min-height: 56px; }
    .setup-steps { gap: 6px 10px; }
}
.setup-studio--guest {
    font-family: "Noto Sans Arabic", "Segoe UI", Tahoma, Arial, sans-serif;
    max-width: 1280px;
    min-height: calc(100dvh - 76px);
    grid-template-columns: minmax(240px, .85fr) minmax(440px, 1.15fr);
    align-items: center;
    gap: 32px;
    padding: 32px;
}
.setup-studio--guest .setup-rail { padding: 0; overflow: visible; background: none; border-radius: 0; color: #fff; }
.setup-studio--guest .setup-rail::after {
    width: 460px;
    inset-inline-end: -100px;
    inset-block-end: -150px;
    border-color: #ffffff10;
    box-shadow: 0 0 0 52px #ffffff05, 0 0 0 110px #ffffff04;
}
.setup-studio--guest .setup-rail .setup-eyebrow { color: #f6d28e; text-transform: uppercase; margin-block-end: 12px; }
.setup-studio--guest .setup-rail h2 {
    font-size: 36px;
    font-weight: 950;
    line-height: 1.36;
    margin: 3px 0 16px;
}
.setup-studio--guest .setup-rail h2 em { display: block; color: #f0c978; margin: 0; }
.setup-studio--guest .setup-rail > p:not(.setup-eyebrow) { display: block; max-width: 450px; color: #bfd5cf; line-height: 2; }
.setup-studio--guest .setup-rail .setup-rail-note { margin-block-start: 24px; color: #b2ccc4; font-size: .75rem; }
.setup-benefits { display: grid; gap: 11px; list-style: none; padding: 0; margin: 24px 0 0; color: #d8e6e2; font-size: .8rem; }
.setup-benefits li { display: flex; align-items: center; gap: 12px; }
.setup-benefits i { display: grid; place-items: center; width: 31px; height: 31px; border-radius: 10px; background: #ffffff17; color: #f1c96f; }
.setup-studio--guest .setup-panel {
    position: relative;
    z-index: 2;
    border: 0;
    border-radius: 29px;
    padding: 28px;
    min-height: 480px;
    box-shadow: 0 35px 80px #041a1a55;
}
.setup-studio--guest .setup-meter { height: 6px; margin: 19px 0 25px; }
.setup-studio--guest .setup-panel-heading { position: relative; display: block; }
.setup-studio--guest .setup-panel h1 { padding-inline-start: 30px; font-size: clamp(23px, 2.5vw, 31px); font-weight: 900; line-height: 1.5; }
.setup-studio--guest .setup-icon { position: absolute; inset-inline-start: 0; inset-block-start: .6rem; width: 20px; height: 20px; background: none; }
.setup-studio--guest .setup-icon svg { width: 19px; height: 19px; }
.setup-studio--guest .setup-option { padding: 10px; gap: 9px; }
.setup-studio--guest .setup-sector-option { min-height: 64px; }
.setup-studio--guest .setup-choice-icon { width: 32px; height: 32px; border-radius: 10px; }
.setup-studio--guest .setup-option-check { width: 20px; height: 20px; }
.setup-studio--guest .setup-countries .setup-option { min-height: 58px; padding: 7px 9px; gap: 7px; }
.setup-studio--guest .setup-countries .setup-choice-icon { width: 32px; height: 32px; border-radius: 8px; }
.setup-studio--guest .setup-panel > :is(fieldset, .setup-review) { margin-block-end: 20px; }
.setup-studio--guest .setup-actions { border-block-start: 1px solid var(--setup-line); padding-block-start: 16px; }
.setup-studio--guest .setup-primary, .setup-studio--guest [data-wizard-back] { padding: 10px 18px; border-radius: 12px; font-weight: 750; }
.setup-studio--guest .setup-primary { background: #145f52; }
.setup-studio--guest .setup-primary:hover { background: #0b4a40; }
.setup-studio--guest [data-wizard-back] { border: 1px solid var(--setup-line); background: var(--setup-paper); }
.setup-studio--guest [data-wizard-back]:disabled { cursor: default; text-decoration: none; }
@media (max-width: 1050px) {
    .setup-studio--guest { grid-template-columns: minmax(240px, .7fr) minmax(440px, 1.3fr); gap: 23px; padding: 25px; }
}
@media (max-width: 780px) {
    .setup-studio--guest { display: flex; flex-direction: column; align-items: stretch; gap: 0; padding: 0; }
    .setup-studio--guest .setup-rail { padding: 20px 22px 38px; }
    .setup-studio--guest .setup-rail .setup-eyebrow { margin-block-end: 5px; }
    .setup-studio--guest .setup-rail h2 { font-size: 25px; margin: 5px 0; }
    .setup-studio--guest .setup-rail > p:not(.setup-eyebrow) { font-size: .75rem; }
    .setup-studio--guest .setup-rail .setup-rail-note { display: none; }
    .setup-studio--guest .setup-benefits { display: none; }
    .setup-studio--guest .setup-panel {
        border-radius: 27px 27px 0 0;
        box-shadow: none;
        margin-block-start: -22px;
        min-height: 480px;
        padding: 24px 19px;
    }
    .setup-studio--guest .setup-sector-option, .setup-studio--guest .setup-option { min-height: 56px; }
}
@media (prefers-reduced-motion: reduce) {
    .setup-option { transition: none; }
}
@media (forced-colors: active) {
    .setup-option:has(input:checked) { border-color: Highlight; }
    .setup-option-check svg { visibility: hidden; }
    .setup-option:has(input:checked) .setup-option-check svg { visibility: visible; }
    .setup-meter { appearance: auto; }
}
</style>
