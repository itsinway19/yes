<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import InternalLayout from '@/Layouts/InternalLayout.vue';
type Market = { id: string; label: string; configuration_id: string | null; revision: number };
type Configuration = { id: string; market_id: string; version: number; catalog_reference_id: string | null; denomination_code: string | null };
type Catalog = { id: string; market_id: string; catalog_identity: string; catalog_version: string; approval_reference: string; source_sha256: string };
type Target = { action: string; target_id: string; version_id: string | null; workspace_public_id: string | null };
const props = defineProps<{ markets: Market[]; configurations: Configuration[]; catalogReferences: Catalog[]; targets: Target[]; obligations: { id: string; command_id: string; state: string; attempts: number }[]; newConfigurationId: string; result: Record<string, unknown> | null }>();
const operations = {
    market: { label: 'تسجيل هوية سوق', fields: ['market_id', 'label'] },
    catalog: { label: 'توثيق مرجع Catalog المعتمد', fields: ['market_id', 'catalog_reference_id', 'catalog_identity', 'catalog_version', 'approval_reference', 'source_sha256'] },
    configuration: { label: 'إنشاء إصدار إعدادات ثابت', fields: ['market_id', 'configuration_id', 'catalog_reference_id', 'denomination_code'] },
    assignability: { label: 'تحديد الإتاحة للإسنادات الجديدة', fields: ['market_id', 'configuration_id', 'expected_revision'] },
    register: { label: 'تسجيل نطاق تجاري', fields: ['scope_id', 'workspace_public_id', 'market_id', 'configuration_id', 'catalog_reference_id', 'denomination_code'] },
};
type Operation = keyof typeof operations;
const operation = ref<Operation>('register');
const labels: Record<string, string> = { market_id: 'هوية السوق', label: 'اسم العرض', scope_id: 'هوية النطاق المشمولة بالمنحة', workspace_public_id: 'هوية مساحة العمل المستفيدة', configuration_id: 'هوية إصدار الإعدادات المحدد', catalog_reference_id: 'هوية مرجع Catalog', catalog_identity: 'هوية Catalog', catalog_version: 'إصدار Catalog', approval_reference: 'مرجع الموافقة المعتمد', source_sha256: 'SHA-256 للمصدر المعتمد', denomination_code: 'سياق العملة الصريح (3 أحرف)', expected_revision: 'المراجعة الحالية للإتاحة' };
const fields = reactive<Record<string, string>>({ market_id: '', label: '', scope_id: '', workspace_public_id: '', configuration_id: props.newConfigurationId, catalog_reference_id: '', catalog_identity: '', catalog_version: '', approval_reference: '', source_sha256: '', denomination_code: '', expected_revision: '' });
const reason = ref('commercial_configuration'); const command = ref(crypto.randomUUID());
const busy = ref(false); const error = ref(''); const result = ref<Record<string, unknown> | null>(props.result);
const fieldErrors = ref<Record<string, string[]>>({});
const help: Record<string, string> = { label: 'اسم مفهوم للسوق، حتى 120 حرفًا.', catalog_version: 'إصدار المرجع المعتمد، حتى 80 حرفًا.', approval_reference: 'مرجع معتمد حتى 120 حرفًا؛ أحرف وأرقام و . _ : / - فقط.', source_sha256: '64 خانة hexadecimal صغيرة من المصدر المعتمد.', denomination_code: 'ثلاثة أحرف كبيرة؛ سياق صريح مستقل عن السوق.', expected_revision: 'حدّث سجل الأسواق واستخدم المراجعة الحالية عند تعارض الإتاحة.' };
function catalogLabel(c: Catalog | undefined) { return c ? `${c.approval_reference} · Catalog إصدار ${c.catalog_version}` : 'Catalog غير مثبت'; }
function configurationLabel(c: Configuration | undefined) { return c ? `إعدادات الإصدار ${c.version} · ${catalogLabel(props.catalogReferences.find(r => r.id === c.catalog_reference_id))} · ${c.denomination_code ?? 'عملة غير مثبتة'}` : 'لا يوجد إصدار متاح للإسناد'; }
function availableActions(m: Market) { return Object.entries(operations).filter(([key]) => props.targets.some(t => t.target_id === m.id && t.action === ({ market: 'ConfigureCommercialMarket', catalog: 'RecordOrVerifyApprovedMarketReference', configuration: 'ConfigureCommercialMarket', assignability: m.configuration_id ? 'DisableCommercialMarket' : 'EnableCommercialMarket' } as Record<string, string>)[key])).map(([, op]) => op.label).join('، ') || 'لا توجد منحة إدارة معروضة لهذا السوق'; }
function handleFailure(failure: unknown) {
    fieldErrors.value = {};
    const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;
    if (status === 422 && axios.isAxiosError(failure)) {
        const serverErrors = failure.response?.data?.errors;
        if (serverErrors && typeof serverErrors === 'object') for (const [field, messages] of Object.entries(serverErrors)) {
            if ((field in labels || ['reason_code', 'commercial', 'command_id'].includes(field)) && Array.isArray(messages)) fieldErrors.value[field] = messages.filter((m): m is string => typeof m === 'string');
        }
        error.value = 'حقول أو مراجع غير صالحة. صحّح الحقول المبينة أدناه؛ لا تنشئ أمرًا جديدًا قبل تصحيحها.';
    } else if (status === 409) error.value = 'تعارض مع أمر أو تسجيل أو مراجعة موجودة. افحص إيصال الأمر، وحدّث سجل الأسواق؛ لا تكرر طلبًا مختلفًا بالمعرّف نفسه.';
    else if (status === 403) error.value = 'رفضت الصلاحية الحالية هذه العملية. تحقّق من المنحة الدقيقة والهدف، أو أعد تسجيل الدخول الحديث؛ تغيير معرّف الأمر لا يمنح صلاحية.';
    else error.value = 'تعذّر تأكيد النتيجة. احتفظ بمعرّف الأمر وافحص الإيصال قبل إعادة المحاولة.';
}
const workspace = ref<{ name: string; public_id: string } | null>(null);
const scopeTargets = computed(() => props.targets.filter(t => t.action === 'RegisterCommercialScope'));
const marketOptions = computed(() => operation.value === 'register'
    ? props.markets.filter(m => m.configuration_id !== null).map(m => ({ id: m.id, label: m.label }))
    : [...props.markets.map(m => ({ id: m.id, label: m.label })), ...props.targets.filter(t => t.action === 'ConfigureCommercialMarket' && !props.markets.some(m => m.id === t.target_id)).map(t => ({ id: t.target_id, label: t.target_id }))]);
const configurations = computed(() => props.configurations.filter(c => c.market_id === fields.market_id));
const catalogs = computed(() => props.catalogReferences.filter(c => c.market_id === fields.market_id));
function selectMarket() {
    const market = props.markets.find(m => m.id === fields.market_id); fields.expected_revision = String(market?.revision ?? '');
    if (operation.value === 'register') { const config = props.configurations.find(c => c.id === market?.configuration_id); fields.configuration_id = config?.id ?? ''; fields.catalog_reference_id = config?.catalog_reference_id ?? ''; fields.denomination_code = config?.denomination_code ?? ''; }
}
async function selectScope(event: Event) {
    const selection = (event.target as HTMLSelectElement).value;
    const target = selection === '' ? undefined : scopeTargets.value[Number(selection)]; workspace.value = null; fields.scope_id = ''; fields.workspace_public_id = ''; if (!target) return;
    fields.scope_id = target.target_id; fields.workspace_public_id = target.workspace_public_id ?? '';
    try { workspace.value = (await axios.get(route('internal.commercial.workspace'), { params: { scope_id: fields.scope_id, workspace_public_id: fields.workspace_public_id } })).data; }
    catch { error.value = 'تعذّر التحقق من المستفيد ضمن صلاحيتك الحالية.'; }
}
async function execute() {
    error.value = ''; fieldErrors.value = {}; busy.value = true;
    const input: Record<string, unknown> = { command_id: command.value, reason_code: reason.value };
    for (const field of operations[operation.value].fields) input[field] = fields[field] || null;
    if (operation.value === 'assignability') input.expected_revision = Number(fields.expected_revision);
    try { result.value = (await axios.post(route(`internal.commercial.${operation.value}`), input)).data; router.reload({ only: ['markets', 'configurations', 'catalogReferences', 'obligations', 'newConfigurationId'] }); }
    catch (failure) { handleFailure(failure); }
    finally { busy.value = false; }
}
const receipt = ref('');
const contextScope = ref('');
async function lookupContext() { busy.value = true; error.value = ''; try { result.value = (await axios.get(route('internal.commercial.context', contextScope.value))).data; } catch { error.value = 'السياق الداخلي غير متاح ضمن صلاحيتك الحالية لهذا الهدف.'; } finally { busy.value = false; } }
function newCommand() { command.value = crypto.randomUUID(); if (operation.value === 'configuration') fields.configuration_id = props.newConfigurationId; selectMarket(); }
watch(operation, () => { fieldErrors.value = {}; error.value = ''; if (operation.value === 'configuration') fields.configuration_id = props.newConfigurationId; selectMarket(); });
async function lookup() { busy.value = true; error.value = ''; try { result.value = (await axios.get(route('internal.commercial.receipt', receipt.value))).data; } catch { error.value = 'الإيصال غير متاح ضمن صلاحيتك الحالية؛ لا تفترض فشل التسجيل.'; } finally { busy.value = false; } }
async function recover(id: string) { busy.value = true; error.value = ''; try { result.value = (await axios.post(route('internal.commercial.recover', id))).data; router.reload({ only: ['obligations'] }); } catch { error.value = 'تعذّرت الاستعادة ضمن صلاحيتك الحالية.'; } finally { busy.value = false; } }
</script>
<template>
    <Head title="السياق التجاري والأسواق" />
    <InternalLayout>
        <template #header><h1 class="text-xl font-semibold">السياق التجاري والأسواق</h1></template>
        <main class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
            <p class="text-sm leading-7">الإتاحة للإسناد تضبط الإسنادات التجارية الجديدة فقط، ولا تعني موافقة قانونية أو إطلاقًا إنتاجيًا. التسجيل يحفظ السياق؛ الاتفاقية والمبلغ غير مثبتين.</p>
            <div v-if="error" role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-red-800"><p>{{ error }}</p><ul><li v-for="(messages, field) in fieldErrors" :key="field">{{ labels[field] ?? (field === 'reason_code' ? 'رمز السبب' : 'المرجع التجاري / الأمر') }}: {{ messages.join(' ') }}</li></ul><button v-if="error.includes('تعارض')" type="button" class="mt-2 underline" @click="router.reload({ only: ['markets', 'configurations', 'catalogReferences'] })">تحديث سجل الأسواق والمراجعات</button></div>
            <section v-if="result" aria-labelledby="result-heading" class="rounded-lg border bg-white p-5"><h2 id="result-heading" class="font-semibold">النتيجة / الإيصال</h2><pre dir="ltr" class="mt-3 overflow-auto whitespace-pre-wrap break-all text-xs">{{ JSON.stringify(result, null, 2) }}</pre></section>
            <section aria-labelledby="markets-heading" class="rounded-lg border bg-white p-5">
                <h2 id="markets-heading" class="font-semibold">سجل الأسواق وإصدارات الإعدادات</h2><p v-if="!markets.length" class="mt-3 text-sm">لا توجد أسواق متاحة للعرض ضمن صلاحيتك الحالية.</p>
                <ul class="divide-y"><li v-for="m in markets" :key="m.id" class="space-y-2 py-4"><h3 class="font-medium">{{ m.label }} — {{ m.configuration_id ? 'متاح للإسناد التجاري' : 'غير متاح للإسناد التجاري' }}</h3><p>الإعدادات الفعالة للإسنادات الجديدة: {{ configurationLabel(props.configurations.find(c => c.id === m.configuration_id)) }}</p><p>المراجعة: {{ m.revision }}. عمليات الإدارة ضمن المنح المعروضة: {{ availableActions(m) }}.</p><ul class="space-y-1 text-sm"><li v-for="c in props.configurations.filter(c => c.market_id === m.id)" :key="c.id">{{ c.id === m.configuration_id ? 'الإصدار المتاح للإسناد — ' : '' }}{{ configurationLabel(c) }}<details class="text-xs"><summary>التفاصيل التقنية للإصدار {{ c.version }}</summary><p dir="ltr" class="break-all">Market {{ m.id }} / Configuration {{ c.id }} / Reference {{ c.catalog_reference_id ?? '—' }}</p><p v-if="c.catalog_reference_id" dir="ltr" class="break-all">Catalog {{ props.catalogReferences.find(r => r.id === c.catalog_reference_id)?.catalog_identity }} / SHA-256 {{ props.catalogReferences.find(r => r.id === c.catalog_reference_id)?.source_sha256 }}</p></details></li></ul></li></ul>
            </section>
            <section aria-labelledby="operation-heading" class="rounded-lg border bg-white p-5">
                <h2 id="operation-heading" class="font-semibold">عملية تجارية محكومة</h2><p class="mt-2 text-sm">كل تغيير في Catalog أو العملة يتطلب إصدار إعدادات جديدًا. الإعداد غير المكتمل لا يمكن إتاحته. لمنع الإسنادات الجديدة اترك هوية الإصدار فارغة في عملية الإتاحة.</p>
                <form novalidate class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="execute">
                    <div class="sm:col-span-2"><label for="commercial-operation">نوع العملية</label><select id="commercial-operation" v-model="operation" class="mt-1 w-full rounded border-slate-300"><option v-for="(op, key) in operations" :key="key" :value="key">{{ op.label }}</option></select></div>
                    <div v-if="operation === 'register'" class="sm:col-span-2"><label for="scope-select">اختر النطاق والمستفيد المشمولين بالمنحة</label><select id="scope-select" required class="mt-1 w-full rounded border-slate-300" @change="selectScope"><option value="">اختر الهدف</option><option v-for="(t, i) in scopeTargets" :key="t.target_id" :value="i">{{ t.target_id }} / {{ t.workspace_public_id }}</option></select><p v-if="workspace" class="mt-2">تأكيد المستفيد: {{ workspace.name }} — {{ workspace.public_id }}</p><p v-if="!scopeTargets.length" class="mt-2">لا توجد أهداف تسجيل متاحة ضمن صلاحيتك.</p></div>
                    <div v-for="field in operations[operation].fields" :key="field"><label :for="`commercial-${field}`">{{ labels[field] }}</label>
                        <select v-if="field === 'market_id'" :id="`commercial-${field}`" v-model="fields[field]" required :aria-describedby="`help-${field} error-${field}`" :aria-invalid="!!fieldErrors[field]" class="mt-1 w-full rounded border-slate-300" @change="selectMarket"><option value="">اختر السوق الصريح</option><option v-for="m in marketOptions" :key="m.id" :value="m.id">{{ m.label }}</option></select>
                        <select v-else-if="field === 'configuration_id' && operation === 'assignability'" :id="`commercial-${field}`" v-model="fields[field]" :aria-describedby="`help-${field} error-${field}`" :aria-invalid="!!fieldErrors[field]" class="mt-1 w-full rounded border-slate-300"><option value="">غير متاح للإسناد</option><option v-for="c in configurations" :key="c.id" :value="c.id">{{ configurationLabel(c) }}</option></select>
                        <select v-else-if="field === 'catalog_reference_id' && operation === 'configuration'" :id="`commercial-${field}`" v-model="fields[field]" :aria-describedby="`help-${field} error-${field}`" :aria-invalid="!!fieldErrors[field]" class="mt-1 w-full rounded border-slate-300"><option value="">غير مثبت</option><option v-for="c in catalogs" :key="c.id" :value="c.id">{{ catalogLabel(c) }}</option></select>
                        <input v-else :id="`commercial-${field}`" v-model="fields[field]" :required="!(operation === 'configuration' && field === 'denomination_code')" :readonly="operation === 'register'" :aria-describedby="`help-${field} error-${field}`" :aria-invalid="!!fieldErrors[field]" :dir="field === 'label' ? undefined : 'ltr'" class="mt-1 w-full rounded border-slate-300" />
                        <p :id="`help-${field}`" class="mt-1 text-xs text-slate-600">{{ help[field] ?? 'الهوية الدقيقة ضمن المرجع والمنحة المعتمدين؛ تفاصيل تقنية وليست اسم السوق.' }}</p><p v-if="fieldErrors[field]" :id="`error-${field}`" class="mt-1 text-sm text-red-800">{{ fieldErrors[field].join(' ') }}</p>
                    </div>
                    <div><label for="commercial-reason">رمز السبب — دون بيانات شخصية</label><input id="commercial-reason" v-model="reason" required pattern="[a-z][a-z0-9_.-]{2,63}" :aria-invalid="!!fieldErrors.reason_code" aria-describedby="reason-help reason-error" dir="ltr" class="mt-1 w-full rounded border-slate-300" /><p id="reason-help" class="text-xs text-slate-600">3–64 حرفًا؛ يبدأ بحرف صغير، ثم أحرف صغيرة وأرقام و . _ - فقط.</p><p v-if="fieldErrors.reason_code" id="reason-error" class="text-sm text-red-800">{{ fieldErrors.reason_code.join(' ') }}</p></div><button :disabled="busy || (operation === 'register' && !workspace)" class="self-end rounded bg-indigo-700 px-4 py-2 text-white disabled:opacity-50">{{ busy ? 'جارٍ التحقق…' : 'تأكيد العملية المحددة' }}</button>
                    <p dir="ltr" class="break-all text-xs sm:col-span-2">Command {{ command }}</p><button type="button" :disabled="busy" class="text-start text-sm text-indigo-700 underline" @click="newCommand">بدء أمر جديد بعد مراجعة النتيجة</button>
                </form>
            </section>
            <section aria-labelledby="receipt-heading" class="rounded-lg border bg-white p-5"><h2 id="receipt-heading" class="font-semibold">استعلام السياق والإيصال وتسليم التدقيق</h2><form class="mt-4 flex flex-wrap gap-3" @submit.prevent="lookupContext"><label for="context-scope">هوية النطاق للعرض الداخلي</label><input id="context-scope" v-model="contextScope" required dir="ltr" class="max-w-full rounded border-slate-300" /><button :disabled="busy" class="text-indigo-700 underline">عرض السياق الداخلي</button></form><form class="mt-4 flex flex-wrap gap-3" @submit.prevent="lookup"><label for="receipt-command">معرّف الأمر</label><input id="receipt-command" v-model="receipt" required dir="ltr" class="max-w-full rounded border-slate-300" /><button :disabled="busy" class="text-indigo-700 underline">عرض الإيصال</button></form><ul class="mt-4 divide-y"><li v-for="o in obligations" :key="o.id" class="space-y-2 py-3"><p dir="ltr" class="break-all text-xs">{{ o.command_id }} · {{ o.state }} · {{ o.attempts }}</p><button :disabled="busy || o.state === 'acknowledged'" class="text-indigo-700 underline disabled:opacity-50" @click="recover(o.id)">إعادة محاولة التسليم</button></li></ul><p v-if="!obligations.length" class="mt-3 text-sm">لا توجد التزامات تدقيق متاحة ضمن صلاحية العرض أو الاستعادة.</p></section>
        </main>
    </InternalLayout>
</template>
