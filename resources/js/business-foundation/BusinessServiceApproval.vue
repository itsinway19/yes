<script setup lang="ts">
import { computed, ref, onBeforeUnmount } from 'vue';
import { locale } from '../spa/locale';
import { stamp, owned } from '../spa/transport';
import { commandsFor, submit } from '../sources/commands';
import PendingCommands from '../sources/PendingCommands.vue';
import type { ServiceApprovalCommand } from '../sources/client';
import type { Surface } from './api';
const props = defineProps<{surface:Surface}>();
const emit = defineEmits<{refresh:[]}>();
let disposed=false;onBeforeUnmount(()=>{disposed=true;});
const t=(ar:string,en:string)=>locale.value==='ar'?ar:en;
const authorityReference=ref(''),expires=ref(''),confirmed=ref(false);
const service=ref(''), purpose=ref(''), busy=ref(false), error=ref('');
const business=computed(()=>props.surface.business.business_public_id);
const approval=computed(()=>props.surface.service_approval);
const owner=computed(()=>({kind:'business' as const,publicId:business.value}));
const locked=computed(()=>busy.value || commandsFor(owner.value).length>0);
async function enroll() {
    const eligibility=approval.value.enrollment;
    if(locked.value || !eligibility.available || !eligibility.mandate_grant_id || !confirmed.value || authorityReference.value.trim().length<8 || !expires.value)return;
    const expiry=new Date(expires.value);if(!Number.isFinite(expiry.getTime()) || expiry.getTime()<=Date.now()){error.value=t('اختر موعد انتهاء قادمًا وصحيحًا.','Choose a valid future expiry.');return;}
    const identity=stamp(),target=business.value;busy.value=true;error.value='';
    try {
        await submit({business:business.value,effect:'service-enrollment',body:{command_id:crypto.randomUUID(),confirmed:true,authority_reference:authorityReference.value.trim(),expires_at:expiry.toISOString(),mandate_grant_id:eligibility.mandate_grant_id,authority_selection:JSON.parse(JSON.stringify(props.surface.authority_selection))}});
        if(disposed || business.value!==target || !owned(identity))return;authorityReference.value='';expires.value='';confirmed.value=false;emit('refresh');
    }catch{if(!disposed && business.value===target && owned(identity))error.value=t('لم تتأكد أهلية الموافقة. راجع الطلب الأصلي المحفوظ؛ لا تمنح هذه النتيجة وصولًا للخدمة.','Approval qualification is unconfirmed. Review the retained original request; this outcome grants no service access.');}
    finally{busy.value=false;}
}
async function send(effect:'service'|'revoke-service',scope?:{public_id:string;purpose:string;revision:number}) {
    if(locked.value || !approval.value.available || !approval.value.selection)return;
    const body:ServiceApprovalCommand={command_id:crypto.randomUUID(),service:scope?.public_id??service.value,purpose:scope?.purpose??purpose.value,
        expected_revision:scope?.revision??0,business_revision:approval.value.business_revision,authority_selection:structuredClone(approval.value.selection)};
    const identity=stamp(),target=business.value;busy.value=true;error.value='';
    try {
        await submit(effect==='service'?{business:business.value,effect,body}:{business:business.value,effect,source:body.service,body});
        if(disposed || business.value!==target || !owned(identity))return;
        service.value='';purpose.value='';emit('refresh');
    } catch {if(!disposed && business.value===target && owned(identity))error.value=t('تعذر تأكيد الأمر. راجع الأمر الأصلي المحفوظ؛ لا تُنشئ موافقة بديلة.','The command could not be confirmed. Review the retained original command before creating another approval.');}
    finally{busy.value=false;}
}
</script>
<template>
<section class="workspace-card" data-business-service-approval :aria-busy="busy">
<h2>{{ t('موافقة النشاط على الخدمات','Business service approvals') }}</h2>
<p>{{ t('تقتصر الموافقة على معرّف النشاط واسمه وحالته. تحتاج الخدمة أيضًا منحة إدارة مستقلة؛ لا تمنحها الموافقة وصولًا للمصادر أو بيانات العملاء.','Approval covers the business identifier, display name and lifecycle only. A separate administration grant is required; approval grants no source or client-data access.') }}</p>
<form v-if="approval.enrollment.available && approval.enrollment.mandate_grant_id" class="biz-form" data-service-approval-enrollment @submit.prevent="enroll">
<h3>{{ t('تأهيل مسؤول موافقة الخدمات','Qualify a service approver') }}</h3>
<p>{{ t('يستند التأهيل إلى تفويض مسؤولية نشاط ساري ومحدد. إدارة النشاط أو قراءته لا تمنح هذه الصلاحية تلقائيًا.','Qualification requires a separately declared current Business responsibility mandate. Business management or reading alone grants no approval authority.') }}</p>
<label>{{ t('مرجع تفويض المسؤولية','Responsibility mandate reference') }}<input v-model="authorityReference" required minlength="8" maxlength="512" :disabled="locked" /></label>
<label>{{ t('انتهاء صلاحية التأهيل بتوقيت جهازك','Qualification expiry in your device timezone') }}<input v-model="expires" type="datetime-local" required :disabled="locked" /></label>
<label><input v-model="confirmed" type="checkbox" required :disabled="locked" />{{ t('أؤكد تفويض المسؤولية المحدد والمدة. لا يمنح هذا التأهيل مصادر أو بيانات عملاء.','I confirm the specific responsibility mandate and expiry. This qualification grants no sources or client data.') }}</label>
<button type="submit" class="workspace-button" :disabled="locked || !confirmed">{{ t('طلب تأهيل الموافقة','Request approval qualification') }}</button>
</form>
<p v-if="!approval.available || !approval.selection">{{ t('لا تتوفر صلاحية موافقة مؤهلة في السياق الحالي.','No qualified approval authority is available in this context.') }}</p>
<form v-else class="biz-form" @submit.prevent="send('service')">
<label>{{ t('معرّف الخدمة','Service identifier') }}<input v-model="service" required dir="ltr" autocomplete="off" :disabled="locked" /></label>
<label>{{ t('الغرض المحدد','Specific purpose') }}<input v-model="purpose" required minlength="10" maxlength="128" :disabled="locked" /></label>
<button type="submit" class="workspace-button" :disabled="locked">{{ t('الموافقة على بيانات التعريف','Approve metadata access') }}</button>
</form>
<p v-if="error" role="alert">{{ error }}</p>
<PendingCommands :business-id="business" @settled="$emit('refresh')" />
<ul><li v-for="scope in approval.scopes" :key="scope.public_id"><strong>{{ scope.name }}</strong> — {{ scope.purpose }} <button v-if="approval.available && approval.selection" type="button" class="workspace-button" :disabled="locked" @click="send('revoke-service',scope)">{{ t('سحب الموافقة','Revoke approval') }}</button></li></ul>
</section>
</template>
