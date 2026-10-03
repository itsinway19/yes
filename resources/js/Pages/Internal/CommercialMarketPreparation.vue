<script setup lang="ts">

import { computed, reactive, ref, watch } from 'vue';

import { Head, Link, router, usePage } from '@inertiajs/vue3';

import axios from 'axios';

import InternalLayout from '@/Layouts/InternalLayout.vue';

type Approval = { id: string; target_id: string; label: string; reason: string; expires_at: string; approved_at: string; administrator_name: string; administrator_public_id: string; founder_id: number; consumed: boolean; active: boolean; canAuthorize: boolean };

const props = defineProps<{ canPrepare: boolean; intentId: string | null; people: { public_id: string; name: string; kind: string }[]; approvals: Approval[]; freshAuthentication: boolean; countryOptions?: Record<string, { ar: string; en: string; currency: string }>; currencyOptions?: string[]; currencyNames?: Record<string, { ar: string; en: string }>; directRecorded?: boolean; recordedMarkets?: { id: string; label: string }[] }>();

const page = usePage();

const c = (ar: string, en: string) => page.props.locale === 'en' ? en : ar;

function currencyName(code: string) { const name = props.currencyNames?.[code]; return name ? `${c(name.ar, name.en)} · ${code}` : code; }
const title = computed(() => c('إضافة سوق تجاري', 'Add a commercial market'));

const preparationMode = ref<'direct' | 'delegated'>('direct');

const preparation = reactive({ administrator: '', label: '', country_code: '', denomination_code: '', reason: '', expires_at: '' });
watch(() => preparation.country_code, code => { preparation.denomination_code = props.countryOptions?.[code]?.currency ?? ''; }, { flush: 'sync' });
const draftKey = `commercial-market-draft:${page.props.auth.user.id}`;
try {
    const draft = JSON.parse(sessionStorage.getItem(draftKey) ?? 'null');
    if (draft && typeof draft.label === 'string' && typeof draft.reason === 'string') {
        preparation.label = draft.label; preparation.reason = draft.reason;
        if (typeof draft.country_code === 'string' && props.countryOptions?.[draft.country_code]) preparation.country_code = draft.country_code;
        if (typeof draft.denomination_code === 'string' && props.currencyOptions?.includes(draft.denomination_code)) preparation.denomination_code = draft.denomination_code;
    }
    sessionStorage.removeItem(draftKey);
} catch { /* A draft contains human text only, never identity or authority. */ }

const delegation = reactive({ market_id: '', operator: '', permission: 'read', reason: '', expires_at: '' });
const operator = reactive({ approval: '', operator: '', reason: '' });

const busy = ref(false); const error = ref(''); const message = ref('');

const errors = ref<Record<string, string[]>>({});

type Attempt = { kind: 'prepare' | 'operator' | 'delegate'; url: string; payload: Record<string, string>; uncertain?: boolean; approvalId?: string };

const storageKey = `commercial-market-approval:${page.props.auth.sessionContextId}`;

const pending = ref<Attempt | null>(null);

try { const saved = sessionStorage.getItem(storageKey); if (saved) pending.value = JSON.parse(saved); } catch { /* malformed tab evidence never grants authority */ }

const approvedIntent = computed(() => props.approvals.find(a => a.id === props.intentId));

const retiredPending = computed(() => pending.value?.kind === 'operator' && props.approvals.some(a => a.id === pending.value?.approvalId && a.consumed));

watch(() => props.intentId, () => { if (!pending.value) Object.assign(preparation, { administrator: '', label: '', country_code: '', denomination_code: '', reason: '', expires_at: '' }); });

const confirmedPending = computed(() => pending.value?.kind === 'prepare' && approvedIntent.value &&

    pending.value.payload.intent_id === approvedIntent.value.id && pending.value.payload.label === approvedIntent.value.label &&

    pending.value.payload.reason === approvedIntent.value.reason && pending.value.payload.administrator === approvedIntent.value.administrator_public_id &&

    new Date(pending.value.payload.expires_at).getTime() === new Date(approvedIntent.value.expires_at).getTime());

const eligibleApprovals = computed(() => props.approvals.filter(a => a.canAuthorize && a.active && !a.consumed));

const selectedApproval = computed(() => eligibleApprovals.value.find(a => a.id === operator.approval));

const operatorPeople = computed(() => props.people.filter(p => p.kind !== 'founder'));

function retain(attempt: Attempt | null) {

    pending.value = attempt;

    if (attempt) sessionStorage.setItem(storageKey, JSON.stringify(attempt)); else sessionStorage.removeItem(storageKey);

}

async function send(attempt: Attempt) {

    if (busy.value) return;

    retain(attempt); busy.value = true; error.value = ''; errors.value = {};

    try {

        await axios.post(attempt.url, attempt.payload);
        if (attempt.payload.mode === 'direct') sessionStorage.removeItem(draftKey);

        retain(null);

        message.value = attempt.kind === 'delegate' ? c('حُفظ تفويض الموظف للسوق المحدد. صلاحية المؤسس المباشرة مستقلة عنه.', 'Employee authorization saved for the selected market. Founder direct authority remains independent.') : attempt.payload.mode === 'direct' ? c('حُفظت الدولة والعملة. أكمل المرجع المعتمد والإعداد في الإعداد التجاري قبل تفعيل الاستخدام الجديد.', 'Country and currency saved. Complete the approved reference and configuration in Commercial before activating new usage.') : attempt.kind === 'prepare' ? c('اعتُمدت الهوية وفُوِّض المسؤول. تسجيل السوق خطوة منفصلة لدى المشغّل المخوّل.', 'Identity approved and administrator authorized. Recording the market is a separate operator action.') : c('أصبح المشغّل مخوّلًا لتسجيل هذا السوق وقراءة سجله.', 'The operator may now record this market and read its registry.');

        router.reload();

    } catch (failure) {

        const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;

        if (status === 422 && axios.isAxiosError(failure)) errors.value = failure.response?.data?.errors ?? {};

        if ([403, 409, 422].includes(status ?? 0) && !attempt.uncertain) retain(null);

        else retain({ ...attempt, uncertain: true });

        error.value = status === 422 ? c('صحّح الحقول الموضحة.', 'Correct the indicated fields.') : status === 403 ? c('الصلاحية غير متاحة أو انتهت. راجع المسؤول أو جدّد تسجيل الدخول؛ لم تُمنح صلاحية جديدة.', 'Authority is unavailable or expired. Contact the administrator or sign in again; no new authority was granted.') : status === 409 ? c('الطلب يتعارض مع اعتماد أو سوق موجود. حدّث الحالة وافحص الاعتماد قبل محاولة جديدة.', 'The request conflicts with an existing approval or market. Refresh and review the approval before another attempt.') : c('تعذّر تأكيد النتيجة. أعد المحاولة بنفس الطلب المحفوظ.', 'The result is unconfirmed. Retry the saved request.');

    } finally { busy.value = false; }

}

function prepare() {

    if (!props.intentId || pending.value || approvedIntent.value || props.directRecorded || (preparationMode.value === 'direct' && (!props.countryOptions?.[preparation.country_code] || !props.currencyOptions?.includes(preparation.denomination_code)))) return;

    send({ kind: 'prepare', url: route('internal.commercial-market-preparation.store'), payload: preparationMode.value === 'direct' ? { intent_id: props.intentId, mode: 'direct', country_code: preparation.country_code, denomination_code: preparation.denomination_code, reason: preparation.reason } : { intent_id: props.intentId, administrator: preparation.administrator, label: preparation.label, reason: preparation.reason, expires_at: new Date(preparation.expires_at).toISOString() } });

}

function authorize() {

    if (!selectedApproval.value || pending.value) return;

    send({ kind: 'operator', approvalId: selectedApproval.value.id, url: route('internal.commercial-market-preparation.operator', selectedApproval.value.id), payload: { operator: operator.operator, reason: operator.reason } });

}

function delegate() {
    if (!props.canPrepare || pending.value || !props.recordedMarkets?.some(m => m.id === delegation.market_id)) return;
    send({ kind: 'delegate', url: route('internal.commercial-market-preparation.delegate'), payload: { ...delegation, expires_at: new Date(delegation.expires_at).toISOString() } });
}

function renewLogin() {
    if (busy.value || pending.value) return;
    sessionStorage.setItem(draftKey, JSON.stringify({ label: preparation.label, reason: preparation.reason, country_code: preparation.country_code, denomination_code: preparation.denomination_code }));
    router.post(route('internal.logout'));
}
function reviewRetiredTarget() {

    if (!retiredPending.value || !pending.value) return;

    sessionStorage.setItem(`${storageKey}:reviewed:${pending.value.approvalId}`, JSON.stringify(pending.value));

    retain(null);

    message.value = c('راجعت السوق المسجّل. لم تعد هذه الهوية متاحة كسوق جديد؛ يحتفظ سجل المراجعة بالطلب السابق دون افتراض نجاح التفويض.', 'Recorded market reviewed. This identity is no longer available as a new market; the previous request remains in review evidence without assuming authorization succeeded.');

}

</script>



<template>

    <Head :title="title" />

    <InternalLayout>

        <template #header><div><p class="admin-eyebrow">{{ c('الإدارة · الأسواق التجارية', 'Administration · Commercial markets') }}</p><h1 class="admin-title">{{ title }}</h1><p class="admin-description">{{ c('يمكن للمؤسس إضافة السوق وإدارة إعداداته مباشرة. تفويض الموظفين اختياري ومنفصل. هذه إعدادات تجارية لـ Builderad وليست استهدافًا جغرافيًا للحملات.', 'The Founder can add markets and manage their configuration directly. Employee delegation is optional and separate. These are Builderad commercial settings, not campaign geographic targeting.') }}</p></div></template>

        <div class="admin-administration preparation-workflow">

            <section v-if="!freshAuthentication" class="admin-section preparation-section"><p role="status" class="admin-description">{{ c('زر التنفيذ متوقف لأن التحقق الحديث بمفتاح المرور انتهت صلاحيته. هذا لا يغيّر صلاحيتك كمؤسس. يمكنك تعبئة البيانات ثم تجديد الدخول.', 'Execution is paused because recent passkey authentication has expired. Your Founder authority is unchanged. You may fill in the details and renew sign-in.') }}</p><button v-if="!pending" id="renew-market-login" type="button" class="admin-action" :disabled="busy" @click="renewLogin">{{ c('تجديد الدخول مع حفظ بيانات السوق', 'Renew sign-in and retain market details') }}</button></section>

            <p v-if="error" role="alert" class="admin-error">{{ error }}</p><p v-if="message" role="status" class="admin-success">{{ message }}</p>

            <section v-if="pending" class="admin-section preparation-section"><h2>{{ c('طلب بانتظار تأكيد النتيجة', 'Request awaiting confirmation') }}</h2><p class="admin-description">{{ c('حُفظ الطلب الأصلي. إعادة المحاولة تحافظ على الهوية والاختيارات نفسها.', 'The original request is retained. Retrying preserves the same identity and choices.') }}</p><dl><template v-for="(value, key) in pending.payload" :key="key"><template v-if="['label','reason','expires_at'].includes(key)"><dt>{{ key === 'label' ? c('اسم السوق', 'Market name') : key === 'reason' ? c('سبب الاعتماد أو التفويض', 'Approval or authorization reason') : c('نهاية التفويض', 'Authorization expiry') }}</dt><dd>{{ value }}</dd></template></template></dl><button class="admin-action" :disabled="busy || !freshAuthentication" @click="send(pending)">{{ c('إعادة المحاولة بنفس الطلب', 'Retry the same request') }}</button><button v-if="retiredPending" class="admin-action" :disabled="busy" @click="reviewRetiredTarget">{{ c('راجعت السوق المسجّل؛ إنهاء متابعة التسجيل الجديد', 'Reviewed recorded market; close new-market follow-up') }}</button><button v-if="confirmedPending" class="admin-action" :disabled="busy" @click="retain(null)">{{ c('راجعت الاعتماد المطابق للطلب', 'I reviewed the matching approval') }}</button><button class="admin-action" :disabled="busy" @click="router.reload()">{{ c('تحديث الحالة', 'Refresh state') }}</button></section>

            <section v-if="canPrepare" class="admin-section preparation-section"><h2>{{ c('إضافة السوق', 'Add market') }}</h2><p class="admin-description">{{ c('لا تحتاج إلى تفويض نفسك أو اختيار موظف آخر لإضافة السوق. يولّد النظام هوية السوق ويحفظ سجل العملية.', 'No self-grant or other employee is needed to add a market. The system generates the market identity and records the operation.') }}</p>

                <p v-if="approvedIntent" class="admin-success">{{ c('تم اعتماد', 'Approved:') }} {{ approvedIntent.label }} · {{ approvedIntent.administrator_name }}</p>

                <p v-if="preparationMode === 'delegated' && !people.length && !approvedIntent" class="admin-description">{{ c('يلزم مسؤول آخر نشط ومستوفٍ لشروط الدخول قبل التفويض. أكمل إضافة الموظف وتفعيل أهليته من الإدارة أولًا.', 'Another active, eligible administrator is required. Complete staff invitation and activation in Administration first.') }}</p><p v-if="directRecorded" class="admin-success">{{ c('سُجّل السوق. افتح الإعداد التجاري لإدارة إعداداته، أو أضف سوقًا آخر.', 'Market recorded. Open Commercial to manage its configuration, or add another market.') }}</p><form v-if="!approvedIntent && !directRecorded" @submit.prevent="prepare"><fieldset :disabled="busy || !!pending"><div class="admin-field"><label for="preparation-mode">{{ c('طريقة العمل', 'Workflow') }}</label><select id="preparation-mode" v-model="preparationMode"><option value="direct">{{ c('إضافة السوق مباشرة بصفتي المؤسس', 'Add market directly as Founder') }}</option><option value="delegated">{{ c('تفويض موظفين لإعداد سوق جديد', 'Delegate new-market preparation to employees') }}</option></select></div><div class="preparation-fields"><template v-if="preparationMode === 'direct'"><div class="admin-field"><label for="market-country">{{ c('الدولة', 'Country') }}</label><select id="market-country" v-model="preparation.country_code" required><option value="">{{ c('اختر الدولة', 'Select country') }}</option><option v-for="(country, code) in countryOptions" :key="code" :value="code">{{ c(country.ar, country.en) }}</option></select><p v-if="errors.country_code" class="admin-error">{{ errors.country_code.join(' ') }}</p></div><div class="admin-field"><label for="market-currency">{{ c('عملة السوق', 'Market currency') }}</label><select id="market-currency" v-model="preparation.denomination_code" required><option value="">{{ c('اختر العملة', 'Select currency') }}</option><option v-for="currency in currencyOptions" :key="currency" :value="currency">{{ currencyName(currency) }}</option></select><p class="admin-description">{{ c('تقترح الدولة العملة؛ يمكنك اختيار عملة أخرى، بما فيها الدولار الأمريكي USD.', 'The country suggests a currency; you may choose another, including US dollars (USD).') }}</p><p v-if="errors.denomination_code" class="admin-error">{{ errors.denomination_code.join(' ') }}</p></div><p class="admin-description">{{ c('نحفظ الدولة والعملة في إعداد أولي. يبقى السوق غير نشط حتى توثيق مرجع معتمد وإكمال الإعداد وتفعيله.', 'We save the country and currency in an initial configuration. The market stays inactive until an approved reference is recorded and setup is completed and activated.') }}</p></template><div v-if="preparationMode === 'delegated'" class="admin-field"><label for="market-name">{{ c('اسم السوق التجاري', 'Commercial market name') }}</label><input id="market-name" v-model="preparation.label" required maxlength="120" /><p v-if="errors.label" class="admin-error">{{ errors.label.join(' ') }}</p></div><div v-if="preparationMode === 'delegated'" class="admin-field"><label for="market-administrator">{{ c('مسؤول تفويض السوق', 'Market authorization administrator') }}</label><select id="market-administrator" v-model="preparation.administrator" :disabled="!people.length" required><option value="">{{ c('اختر مسؤولًا آخر', 'Select another administrator') }}</option><option v-for="person in people" :key="person.public_id" :value="person.public_id">{{ person.name }}</option></select><p class="admin-description">{{ c('يفوّض مشغّلًا لهذا السوق فقط؛ لا يحصل على صلاحية التسجيل تلقائيًا.', 'May authorize an operator for this market only; does not automatically receive recording authority.') }}</p></div><div class="admin-field"><label for="approval-reason">{{ preparationMode === 'direct' ? c('ملاحظة اختيارية', 'Optional note') : c('سبب الاعتماد', 'Approval reason') }}</label><input id="approval-reason" v-model="preparation.reason" :required="preparationMode === 'delegated'" maxlength="120" /></div><div v-if="preparationMode === 'delegated'" class="admin-field"><label for="approval-expiry">{{ c('نهاية صلاحية التفويض', 'Authorization expiry') }}</label><input id="approval-expiry" v-model="preparation.expires_at" type="datetime-local" required /><p v-if="errors.expires_at" class="admin-error">{{ errors.expires_at.join(' ') }}</p></div></div><button class="admin-action admin-action-primary" type="submit" :disabled="!freshAuthentication || (preparationMode === 'delegated' ? !people.length : !preparation.country_code || !preparation.denomination_code)">{{ preparationMode === 'direct' ? c('إضافة السوق', 'Add market') : c('اعتماد الهوية وتفويض المسؤول', 'Approve identity and authorize administrator') }}</button></fieldset></form>

                <Link v-if="(approvedIntent || directRecorded) && !pending" :href="route('internal.commercial-market-preparation.new-intent')" method="post" as="button" class="admin-action" :disabled="!freshAuthentication">{{ c('إضافة سوق آخر', 'Add another market') }}</Link>

            </section>

            <section v-if="canPrepare && recordedMarkets?.length" class="admin-section preparation-section">
                <h2>{{ c('تفويض موظف لسوق قائم · اختياري', 'Authorize an employee for an existing market · Optional') }}</h2>
                <p class="admin-description">{{ c('عملك كمؤسس لا يحتاج إلى هذا التفويض. اختر موظفًا والسوق وحدود عمله فقط عند الحاجة.', 'Your work as Founder does not require delegation. Choose an employee, market and permission only when needed.') }}</p>
                <p v-if="!operatorPeople.length" class="admin-description">{{ c('لا يوجد موظف آخر مؤهل حاليًا؛ يمكنك مواصلة العمل بنفسك.', 'No other eligible employee is available; you can continue working directly.') }}</p>
                <form id="market-delegation" @submit.prevent="delegate"><fieldset :disabled="busy || !!pending"><div class="preparation-fields">
                    <div class="admin-field"><label for="delegate-market">{{ c('السوق', 'Market') }}</label><select id="delegate-market" v-model="delegation.market_id" required><option value="">{{ c('اختر السوق', 'Select market') }}</option><option v-for="market in recordedMarkets" :key="market.id" :value="market.id">{{ market.label }}</option></select></div>
                    <div class="admin-field"><label for="delegate-employee">{{ c('الموظف', 'Employee') }}</label><select id="delegate-employee" v-model="delegation.operator" :disabled="!operatorPeople.length" required><option value="">{{ c('اختر الموظف', 'Select employee') }}</option><option v-for="person in operatorPeople" :key="person.public_id" :value="person.public_id">{{ person.name }}</option></select></div>
                    <div class="admin-field"><label for="delegate-permission">{{ c('حدود التفويض', 'Permission') }}</label><select id="delegate-permission" v-model="delegation.permission"><option value="read">{{ c('عرض السوق وإعداداته فقط', 'View market and configuration only') }}</option><option value="manage">{{ c('إدارة إعدادات السوق وإتاحته', 'Manage market configuration and availability') }}</option></select><p class="admin-description">{{ c('لا يشمل تفويضًا لأسواق أخرى أو منح صلاحيات للموظفين أو توثيق مراجع تجارية جديدة.', 'Excludes other markets, employee permission management and recording new commercial references.') }}</p></div>
                    <div class="admin-field"><label for="delegate-reason">{{ c('سبب التفويض', 'Authorization reason') }}</label><input id="delegate-reason" v-model="delegation.reason" required maxlength="120" /></div>
                    <div class="admin-field"><label for="delegate-expiry">{{ c('نهاية صلاحية تفويض الموظف', 'Employee authorization expiry') }}</label><input id="delegate-expiry" v-model="delegation.expires_at" type="datetime-local" required /></div>
                </div><p v-if="Object.keys(errors).length" class="admin-error">{{ Object.values(errors).flat().join(' ') }}</p><button type="submit" class="admin-action admin-action-primary" :disabled="!freshAuthentication || !operatorPeople.length">{{ c('تفويض الموظف', 'Authorize employee') }}</button></fieldset></form>
            </section>
            <section v-if="eligibleApprovals.length" class="admin-section preparation-section"><h2>{{ c('٢ · تفويض المشغّل', '2 · Authorize operator') }}</h2><p class="admin-description">{{ c('يُمنح مشغّل آخر صلاحيتين مستقلتين: تسجيل السوق وقراءة سجله، للسوق المحدد فقط.', 'Another operator receives two independent permissions: market configuration and registry reading, for the selected market only.') }}</p><p v-if="!operatorPeople.length" class="admin-description">{{ c('يلزم مشغّل آخر نشط ومستوفٍ لشروط الدخول؛ راجع المؤسس لاستكمال أهلية الموظف.', 'Another active, eligible operator is required; ask the Founder to complete staff eligibility.') }}</p><form @submit.prevent="authorize"><fieldset :disabled="busy || !!pending"><div class="preparation-fields"><div class="admin-field"><label for="approved-market">{{ c('السوق المعتمد', 'Approved market') }}</label><select id="approved-market" v-model="operator.approval" required><option value="">{{ c('اختر السوق', 'Select market') }}</option><option v-for="a in eligibleApprovals" :key="a.id" :value="a.id">{{ a.label }}</option></select></div><div class="admin-field"><label for="market-operator">{{ c('المشغّل المسؤول عن التسجيل', 'Operator responsible for recording') }}</label><select id="market-operator" v-model="operator.operator" required><option value="">{{ c('اختر مشغّلًا آخر', 'Select another operator') }}</option><option v-for="person in operatorPeople" :key="person.public_id" :value="person.public_id">{{ person.name }}</option></select></div><div class="admin-field"><label for="operator-reason">{{ c('سبب التفويض', 'Authorization reason') }}</label><input id="operator-reason" v-model="operator.reason" required maxlength="120" /></div></div><button class="admin-action admin-action-primary" type="submit" :disabled="!freshAuthentication || !operatorPeople.length">{{ c('تفويض التسجيل والقراءة', 'Authorize configuration and reading') }}</button></fieldset></form></section>

            <section class="admin-section preparation-section"><h2>{{ c('الاعتمادات المحفوظة', 'Saved approvals') }}</h2><p v-if="!approvals.length" class="admin-description">{{ c('لم يُعتمد سوق في هذا المسار بعد.', 'No market has been approved through this path yet.') }}</p><article v-for="a in approvals" :key="a.id" class="approval-record"><h3>{{ a.label }}</h3><p>{{ c('مسؤول التفويض:', 'Administrator:') }} {{ a.administrator_name }}</p><p class="admin-description">{{ a.consumed ? c('سُجّل السوق؛ يبقى الاعتماد محفوظًا للمراجعة ولا يُعاد استخدامه كسوق جديد.', 'Market recorded; approval remains as review evidence and cannot be reused as a new market.') : !a.active ? c('انتهى التفويض أو أُلغي؛ عرض للمراجعة فقط.', 'Authorization expired or revoked; read only for review.') : a.canAuthorize ? c('بانتظار تفويض المشغّل والتسجيل.', 'Awaiting operator authorization and recording.') : c('معتمد؛ التفويض لدى المسؤول المحدد. عرض فقط.', 'Approved; authorization belongs to the named administrator. Read only.') }}</p><details><summary>{{ c('تفاصيل الاعتماد للمراجعة', 'Approval details for review') }}</summary><dl><dt>{{ c('سبب الاعتماد', 'Approval reason') }}</dt><dd>{{ a.reason }}</dd><dt>{{ c('نهاية التفويض', 'Authorization expiry') }}</dt><dd>{{ a.expires_at }}</dd><dt>{{ c('رقم الاعتماد', 'Approval number') }}</dt><dd dir="ltr">{{ a.id }}</dd><dt>{{ c('رقم هوية السوق', 'Market identity number') }}</dt><dd dir="ltr">{{ a.target_id }}</dd></dl></details></article></section>

            <Link :href="route('internal.commercial.index')" class="admin-text-link preparation-navigation">{{ c('فتح الأسواق التجارية', 'Open Commercial markets') }}</Link>

        </div>

    </InternalLayout>

</template>



<style scoped>

.preparation-workflow{display:grid;gap:20px}.preparation-section{padding-block:20px;margin:0}.preparation-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:22px;margin:24px 0}.preparation-section>h2{margin-bottom:10px}.preparation-section>p+p{margin-top:10px}.preparation-navigation{justify-self:start}.preparation-workflow :is(button,input,select){max-inline-size:100%;min-inline-size:0;overflow-wrap:anywhere}.preparation-workflow button{white-space:normal}.preparation-workflow input[type=datetime-local]{direction:ltr}fieldset{border:0;padding:0;margin:0;min-width:0}.approval-record{padding:16px 0;border-bottom:1px solid var(--admin-line)}dl{display:grid;grid-template-columns:minmax(120px,1fr) 2fr;gap:8px}dd{margin:0;overflow-wrap:anywhere}h2{font-size:1.15rem}h3{font-size:1rem}details{margin-top:12px}summary{cursor:pointer}@media(max-width:650px){.preparation-fields{grid-template-columns:1fr}dl{grid-template-columns:1fr}}

</style>
