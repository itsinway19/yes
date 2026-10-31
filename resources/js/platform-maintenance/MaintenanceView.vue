<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch, watchEffect } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import { locale } from '../spa/locale';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';
import { confirmDeparture } from '../spa/presentation';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import Modal from '../Components/Modal.vue';
import { platform, readPlatform, sendPlatform, type Command } from './state';
const c=(a:string,e:string)=>locale.value==='ar'?a:e;
watchEffect(()=>{document.title=c('حالة المنصة والصيانة — BuilderAd','Platform status and maintenance — BuilderAd');});
let baseline=platform.value!.maintenance;
const initial=baseline;
const messageAr=ref(initial.message_ar),messageEn=ref(initial.message_en),reason=ref(''),revision=ref(initial.revision),target=ref(!initial.active),retry=ref(initial.retry_after);
const outcome=ref<'idle'|'pending'|'saved'|'saved-unverified'|'saved-conflict'|'unknown'|'conflict'|'rejected'|'fresh'>('idle');
const discarding=ref(false);
const error=ref(''),reviewed=ref(false),busy=ref(false),confirmation=ref(false),reasonField=ref<HTMLTextAreaElement>();
const controller=new AbortController();
const clear=()=>{controller.abort();reason.value='';messageAr.value='';messageEn.value='';confirmation.value=false;};
const closures=onClose(clear);
const valid=computed(()=>reason.value.trim().length>0&&reason.value.length<=500&&messageAr.value.trim().length>0&&messageAr.value.length<=500&&messageEn.value.trim().length>0&&messageEn.value.length<=500&&Number.isInteger(retry.value)&&retry.value>=30&&retry.value<=3600);
watch([messageAr,messageEn,reason,retry],()=>{if(outcome.value==='saved')outcome.value='idle';},{flush:'sync'});
const locked=computed(()=>busy.value||['pending','unknown','conflict','fresh','saved-unverified','saved-conflict'].includes(outcome.value));
const dirty=computed(()=>reason.value!==''||messageAr.value!==baseline.message_ar||messageEn.value!==baseline.message_en||retry.value!==baseline.retry_after);
function confirm(){if(locked.value)return;if(!valid.value){error.value=c('أكمل الرسالتين والسبب وحدد مدة المحاولة بين 30 و3600 ثانية.','Complete both messages, a reason and a retry interval between 30 and 3600 seconds.');const field=!messageAr.value.trim()||messageAr.value.length>500?'maintenance-ar':!messageEn.value.trim()||messageEn.value.length>500?'maintenance-en':!Number.isInteger(retry.value)||retry.value<30||retry.value>3600?'maintenance-retry':'maintenance-reason';document.getElementById(field)?.focus();return;}discarding.value=false;confirmation.value=true;}
function setDraft(state:typeof initial){baseline=state;revision.value=state.revision;target.value=!state.active;messageAr.value=state.message_ar;messageEn.value=state.message_en;retry.value=state.retry_after;reason.value='';}
function discard(){setDraft(baseline);outcome.value='idle';reviewed.value=false;error.value='';confirmation.value=false;}
async function save(){
 if(locked.value||!valid.value)return;
 const captured=stamp();confirmation.value=false;outcome.value='pending';error.value='';
 const body:Command={command_id:crypto.randomUUID(),revision:revision.value,active:target.value,message_ar:messageAr.value.trim(),message_en:messageEn.value.trim(),reason:reason.value.trim(),retry_after:retry.value};
 try{
  const acknowledgement=await sendPlatform(body,controller.signal);if(!owned(captured))return;
  if(platform.value)platform.value={...platform.value,maintenance:acknowledgement.maintenance};
  // A receipt owns one coherent draft snapshot. A newer read must never silently raise its CAS revision.
  setDraft(acknowledgement.maintenance);reviewed.value=false;
  const current=await readPlatform(false,true);if(!owned(captured))return;
  outcome.value=!current?'saved-unverified':current.maintenance.revision!==acknowledgement.maintenance.revision?'saved-conflict':'saved';
 }
 catch(failure){if(!owned(captured)||controller.signal.aborted)return;if(failure instanceof TransportError){outcome.value=failure.status===409?'conflict':failure.status===403&&failure.body.code==='fresh_authentication_required'?'fresh':failure.status>=500?'unknown':'rejected';if([401,419].includes(failure.status)||(failure.status===403&&outcome.value!=='fresh'))close();}else outcome.value='unknown';}
}
async function review(){if(busy.value)return;busy.value=true;reviewed.value=false;error.value='';reviewed.value=!!await readPlatform(false,true);busy.value=false;if(!reviewed.value)error.value=c('تعذر التحقق من الحالة. لا تكرر التغيير.','Could not verify status. Do not repeat the change.');}
function adopt(){if(busy.value||!platform.value||!reviewed.value)return;setDraft(platform.value.maintenance);outcome.value='idle';reviewed.value=false;error.value='';}
const leave=()=>outcome.value==='saved'||(!dirty.value&&!['pending','unknown'].includes(outcome.value));
confirmDeparture.value=leave;onBeforeRouteLeave(leave);
function beforeUnload(event:BeforeUnloadEvent){if(!leave()){event.preventDefault();event.returnValue='';}}
window.addEventListener('beforeunload',beforeUnload);
onBeforeUnmount(()=>{clear();closures.delete(clear);window.removeEventListener('beforeunload',beforeUnload);if(confirmDeparture.value===leave)confirmDeparture.value=()=>true;});
</script>
<template><OperationsShell><template #header><h1>{{ c('حالة المنصة والصيانة','Platform status and maintenance') }}</h1><p>{{ c('تحكم في إتاحة خدمة المعلنين، مع بقاء الإدارة والتعافي متاحين.','Control advertiser service availability while keeping administration and recovery accessible.') }}</p></template>
<section v-if="platform" class="maintenance-panel" data-maintenance-settings :data-outcome="outcome">
<div class="maintenance-status"><span class="maintenance-dot" :class="{active:platform.maintenance.active}" aria-hidden="true"></span><div><h2>{{ platform.maintenance.active?c('خدمة المعلنين متوقفة مؤقتًا','Advertiser service is temporarily paused'):c('خدمة المعلنين متاحة','Advertiser service is available') }}</h2><p>{{ c('الصفحة العامة ومسارات الأمان والإدارة تبقى متاحة.','The public site, security and administration remain accessible.') }}</p></div></div>
<p class="maintenance-note">{{ c('التفعيل يمنع الطلبات الجديدة. ما سبق قبوله قد يكتمل؛ لا تلغى نتائجه أو تعاد تلقائيًا.','Activation blocks new requests. Previously accepted work may finish; results are neither cancelled nor automatically repeated.') }}</p>
<p v-if="outcome==='saved'" role="status">{{ c('حُفظ التغيير. الحالة أعلاه هي آخر قراءة مؤهلة.','Change saved. Status above is the latest qualified read.') }}</p>
<p v-if="outcome==='saved-unverified'" role="alert">{{ c('حُفظ التغيير، لكن تعذر تحديث الحالة الحالية. لا تكرر الأمر؛ اقرأ الحالة ثم اعتمدها قبل تغيير جديد.','Change saved, but current status could not be refreshed. Do not repeat the command; read and adopt current status before another change.') }}</p>
<p v-if="outcome==='saved-conflict'" role="alert">{{ c('حُفظ التغيير، ثم تغيرت الحالة مرة أخرى. بقيت مسودتك بإصدارها؛ اقرأ الحالة الأحدث ثم اعتمدها قبل تغيير جديد.','Change saved, then status changed again. Your draft retains its revision; read and adopt the latest status before another change.') }}</p>
<p v-if="outcome==='unknown'" role="alert">{{ c('نتيجة التغيير غير معروفة وقد يكون حُفظ. راجع الحالة قبل أي تغيير جديد؛ لن نكرر الأمر تلقائيًا.','The outcome is unknown and may have saved. Review status before any new change; this command will not replay automatically.') }}</p>
<p v-if="outcome==='conflict'" role="alert">{{ c('تغيرت الحالة منذ فتح الصفحة. راجعها قبل استبدال المسودة.','Status changed since this page opened. Review it before replacing your draft.') }}</p>
<p v-if="outcome==='fresh'" role="alert">{{ c('لم يُحفظ التغيير. أعد المصادقة ثم راجع الحالة قبل طلب جديد.','The change was not saved. Reauthenticate, then review status before a new request.') }} <a href="/internal/identity/switch">{{ c('إعادة المصادقة','Reauthenticate') }}</a></p>
<p v-if="outcome==='rejected'" role="alert">{{ c('لم يُقبل التغيير. راجع المدخلات وصلاحية الجلسة.','The change was rejected. Check inputs and session.') }}</p>
<p v-if="error" id="maintenance-errors" role="alert">{{ error }}</p>
<div v-if="['unknown','conflict','saved-unverified','saved-conflict'].includes(outcome)" class="maintenance-actions"><button type="button" class="admin-action" :disabled="busy" @click="review">{{ c('قراءة الحالة دون تكرار الأمر','Read status without repeating the command') }}</button><button v-if="reviewed" type="button" class="admin-action" :disabled="busy" @click="adopt">{{ c('استبدال المسودة بالحالة الحالية','Replace draft with current status') }}</button></div>
<form novalidate @submit.prevent="confirm"><fieldset :disabled="locked"><legend>{{ target?c('تفعيل الصيانة','Activate maintenance'):c('إعادة الخدمة','Restore service') }}</legend>
<label for="maintenance-ar">{{ c('الرسالة العربية','Arabic message') }}</label><textarea id="maintenance-ar" v-model="messageAr" dir="rtl" lang="ar" rows="3" maxlength="500" class="resize-none" :aria-invalid="!!error" :aria-describedby="error?'maintenance-errors':undefined"></textarea>
<label for="maintenance-en">{{ c('الرسالة الإنجليزية','English message') }}</label><textarea id="maintenance-en" v-model="messageEn" dir="ltr" lang="en" rows="3" maxlength="500" class="resize-none"></textarea>
<label for="maintenance-retry">{{ c('المحاولة التالية بعد (ثوانٍ)','Retry interval (seconds)') }}</label><input id="maintenance-retry" v-model.number="retry" type="number" min="30" max="3600">
<label for="maintenance-reason">{{ c('سبب التغيير — للسجل الداخلي فقط','Reason — internal record only') }}</label><textarea id="maintenance-reason" ref="reasonField" v-model="reason" rows="3" maxlength="500" class="resize-none" :aria-invalid="!!error" :aria-describedby="error?'maintenance-errors':undefined"></textarea>
<button class="admin-action" type="submit" :disabled="locked">{{ outcome==='pending'?c('جارٍ الحفظ…','Saving…'):target?c('مراجعة تفعيل الصيانة','Review maintenance activation'):c('مراجعة إعادة الخدمة','Review service restoration') }}</button></fieldset><button type="button" class="admin-action" :disabled="outcome==='pending'" @click="discarding=true;confirmation=true">{{ c('إلغاء المسودة','Discard draft') }}</button></form>
<details class="maintenance-history"><summary>{{ c('سجل التغييرات','Change history') }}</summary><p v-if="!platform.history.length">{{ c('لا توجد تغييرات مسجلة بعد.','No changes have been recorded.') }}</p><ol v-else><li v-for="item in platform.history" :key="item.command_id"><b>{{ c('تغيير الحالة','Status change') }} {{ item.resulting_revision }}</b> — {{ item.reason }}<time :datetime="item.occurred_at">{{ new Date(item.occurred_at).toLocaleString(locale==='ar'?'ar-SA':'en-GB') }}</time></li></ol></details>
</section>
<Modal role="alertdialog" :show="confirmation" max-width="md" labelled-by="maintenance-confirm-title" described-by="maintenance-confirm-help" @close="confirmation=false"><section class="maintenance-confirm"><h2 id="maintenance-confirm-title">{{ discarding?c('إلغاء المسودة؟','Discard this draft?'):target?c('تفعيل الصيانة الآن؟','Activate maintenance now?'):c('إعادة خدمة المعلنين؟','Restore advertiser service?') }}</h2><p id="maintenance-confirm-help">{{ discarding?c('لن يغيّر هذا الإجراء حالة الخدمة، ولا يلغي أمرًا قد حُفظ سابقًا.','This does not change service status or undo any previously saved command.'):target?c('ستتوقف طلبات المعلنين الجديدة. تبقى الإدارة متاحة ويمكن إنهاء الصيانة منها.','New advertiser requests will be blocked. Administration remains available to restore service.'):c('سيتمكن المعلنون من استخدام وظائف المنصة مجددًا.','Advertisers will be able to use platform functions again.') }}</p><div class="maintenance-actions"><button class="admin-action" type="button" autofocus @click="confirmation=false">{{ c('العودة للمراجعة','Back to review') }}</button><button class="admin-action" type="button" @click="discarding?discard():save()">{{ discarding?c('إلغاء المسودة','Discard draft'):target?c('تفعيل الصيانة','Activate maintenance'):c('إعادة الخدمة','Restore service') }}</button></div></section></Modal>
</OperationsShell></template>
<style scoped>
.maintenance-panel{max-width:820px;padding:28px;background:var(--admin-surface,#fff);border:1px solid var(--admin-line);border-radius:12px}.maintenance-status{display:flex;gap:14px;align-items:flex-start}.maintenance-status h2{font-size:20px;margin:0}.maintenance-status p,.maintenance-note{color:var(--admin-muted)}.maintenance-dot{margin-top:10px;width:12px;height:12px;flex:none;border-radius:50%;background:#23715c}.maintenance-dot.active{background:#ad7421}.maintenance-panel fieldset{border:0;padding:0;margin-top:28px;display:grid;gap:10px}.maintenance-panel legend{font-size:18px;font-weight:650;margin-bottom:20px}.maintenance-panel textarea,.maintenance-panel input{color:inherit;background:var(--admin-surface,#fff);border:1px solid var(--ba-control-border,#738693);border-radius:6px;padding:10px;width:100%;resize:none}.maintenance-panel label{font-weight:600}.maintenance-panel button{justify-self:start;margin-block:12px}.maintenance-actions{display:flex;gap:12px;flex-wrap:wrap}.maintenance-history{border-top:1px solid var(--admin-line);margin-top:24px;padding-top:20px}.maintenance-history summary{cursor:pointer}.maintenance-history li{padding:10px}.maintenance-history time{display:block;color:var(--admin-muted);font-size:12px}.maintenance-confirm{padding:26px;color:var(--admin-ink,#202d37);background:var(--admin-surface,#fff)}@media(max-width:600px){.maintenance-panel{padding:18px}}
</style>
