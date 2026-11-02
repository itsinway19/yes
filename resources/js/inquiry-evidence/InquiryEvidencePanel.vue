<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, onClose } from '../spa/transport';
import { eligibility, read, history, send, selection, confirmedRejection, type Eligibility, type EvidenceRead, type DeclarationValue, type DeclarationWriteValue, type Pending, type Action } from './api';
import DeclarationDetails from './DeclarationDetails.vue';
import { inquiryFormDrafts, type InquiryFormFields } from './presentationDrafts';
import { beginCorrection, countValue, freezeCommand, staleEdit, pendingCommands, type EditSnapshot } from './editor';
const props=withDefaults(defineProps<{business:string;basisAccess:string;embedded?:boolean;active?:boolean}>(),{embedded:false,active:true});
const t=(ar:string,en:string)=>locale.value==='ar'?ar:en;
const access=ref<Eligibility>();const collection=ref<EvidenceRead>();const past=ref<EvidenceRead>();
type BilingualNotice={ar:string;en:string};
const notice=(ar:string,en:string):BilingualNotice=>({ar,en});
const busy=ref(false),message=ref<BilingualNotice>(),unavailable=ref(false),confirmed=ref(false),editorOpen=ref(false),pending=ref<Pending>();
const edit=ref<EditSnapshot>();let disposed=false;let attempt=0;
const title=computed(()=>t('بيانات الاستفسارات','Inquiry evidence'));
const selectedId=ref<string>();
const selected=computed(()=>collection.value?.items.find(item=>item.declaration_id===selectedId.value));
const detailHeading=ref<HTMLElement>();
const panelRoot=ref<HTMLElement>();
let collectionPosition=0;
const period=(value:string)=>value.slice(0,10);
const channel=(value?:string)=>value==='form'?t('نموذج','Form'):value==='call'?t('مكالمات','Calls'):value==='message'?t('رسائل','Messaging'):value==='crm'?'CRM':value==='other'?t('أخرى','Other'):t('سجل استفسارات','Inquiry record');
async function openRecord(item:EvidenceRead['items'][number]){collectionPosition=window.scrollY;selectedId.value=item.declaration_id;past.value=undefined;await nextTick();detailHeading.value?.focus();}
async function backToCollection(){const previous=selectedId.value;selectedId.value=undefined;past.value=undefined;await nextTick();const button=panelRoot.value?.querySelector<HTMLElement>(`[data-inquiry-record="${previous}"] [data-inquiry-open]`);button?.focus({preventScroll:true});window.scrollTo({top:collectionPosition,behavior:'instant'});}
function cancelEditor(){if(locked.value)return;forgetUnsentDraft();editorOpen.value=false;resetFields();}

const fields=reactive<InquiryFormFields>({channel:'form',country:'',cohortVersion:'1',start:'',end:'',asOf:'',timezone:'Asia/Riyadh',cost:'',currency:'SAR',precision:'2',costBasis:'declared_cohort_cost',allocationBasis:'whole_cohort',inquiries:'',inquiryCoverage:'unknown',acceptanceEnabled:false,accepted:'',pendingCount:'',criterion:'operator_defined',criterionVersion:'1',criterionDefinition:'',cutoff:'',maturity:'unknown',acceptanceCoverage:'unknown'});
const grant=(action:Action)=>access.value?.grants[action];
const pendingKey=()=>`${stamp()}:${props.business}:${props.basisAccess}`;
const mountedStamp=stamp();
let activeDraftKey=pendingKey();
const cloneDraft=<T,>(value:T):T=>JSON.parse(JSON.stringify(value));
function retainUnsentDraft(){
 if(!owned(mountedStamp)||!editorOpen.value||pending.value||!access.value)return;
 const required=edit.value?'business.inquiry_evidence.correct':'business.inquiry_evidence.record';
 if(!grant(required)||(edit.value&&!grant('business.inquiry_evidence.read')))return;
 inquiryFormDrafts.set(activeDraftKey,{fields:cloneDraft(fields),...(edit.value?{edit:cloneDraft(edit.value)}:{}),basis:access.value.basis_qualification,basisRevision:access.value.basis_revision});
}
function restoreUnsentDraft(){
 if(pending.value||!access.value)return;
 const saved=inquiryFormDrafts.get(activeDraftKey);if(!saved)return;
 if(saved.basis!==access.value.basis_qualification||saved.basisRevision!==access.value.basis_revision){inquiryFormDrafts.delete(activeDraftKey);return;}
 const required=saved.edit?'business.inquiry_evidence.correct':'business.inquiry_evidence.record';
 if(!grant(required)||(saved.edit&&!grant('business.inquiry_evidence.read'))){inquiryFormDrafts.delete(activeDraftKey);return;}
 if(editorOpen.value)return;
 // An editing baseline is never replaced with newer values. Current read must also contain this record.
 if(saved.edit&&!collection.value?.items.some(item=>item.declaration_id===saved.edit!.declaration))return;
 Object.assign(fields,cloneDraft(saved.fields));edit.value=saved.edit?cloneDraft(saved.edit):undefined;
 selectedId.value=saved.edit?.declaration;editorOpen.value=true;
 message.value=notice('استعدنا مسودتك غير المرسلة بعد التحقق من صلاحيتك الحالية. لم يُحفظ شيء تلقائيًا.','Your unsent draft was restored after checking current permissions. Nothing was saved automatically.');
}
function forgetUnsentDraft(){inquiryFormDrafts.delete(activeDraftKey);}
onClose(()=>pendingCommands.clear());
function concealReadDerived(dropRetained=true){if(dropRetained&&inquiryFormDrafts.get(activeDraftKey)?.edit)forgetUnsentDraft();selectedId.value=undefined;if(edit.value){resetFields();editorOpen.value=false;}past.value=undefined;}
const stale=computed(()=>staleEdit(edit.value,collection.value?.items??[]));
const locked=computed(()=>busy.value || !!pending.value);
const canSubmit=computed(()=>props.active && !!access.value && !!grant(edit.value?'business.inquiry_evidence.correct':'business.inquiry_evidence.record') && (!edit.value || !!grant('business.inquiry_evidence.read')));
function resetFields(){Object.assign(fields,{channel:'form',country:'',cohortVersion:'1',start:'',end:'',asOf:'',timezone:'Asia/Riyadh',cost:'',currency:'SAR',precision:'2',costBasis:'declared_cohort_cost',allocationBasis:'whole_cohort',inquiries:'',inquiryCoverage:'unknown',acceptanceEnabled:false,accepted:'',pendingCount:'',criterion:'operator_defined',criterionVersion:'1',criterionDefinition:'',cutoff:'',maturity:'unknown',acceptanceCoverage:'unknown'});edit.value=undefined;}
function suspendDisplay(){
 retainUnsentDraft();attempt++;busy.value=false;collection.value=undefined;past.value=undefined;
 selectedId.value=undefined;access.value=undefined;message.value=undefined;confirmed.value=false;
 resetFields();editorOpen.value=false;
}
async function refresh(cursor?:string){
 if(busy.value||!props.active)return;
 const previousSelection=selectedId.value;
 const needed=editorOpen.value?(edit.value?'business.inquiry_evidence.correct':'business.inquiry_evidence.record'):undefined;
 retainUnsentDraft();collection.value=undefined;past.value=undefined;selectedId.value=undefined;
 resetFields();editorOpen.value=false;message.value=undefined;access.value=undefined;
 busy.value=true;const ticket=++attempt,captured=stamp(),business=props.business,basis=props.basisAccess;
 const currentContext=()=>!disposed&&props.active&&ticket===attempt&&owned(captured)&&business===props.business&&basis===props.basisAccess;
 try {const current=await eligibility(business,basis);if(!currentContext())return;access.value=current;unavailable.value=false;
  const right=current.grants['business.inquiry_evidence.read'];
  if(right){const data=await read(business,right,cursor);if(!currentContext())return;collection.value=data;if(data.items.some(item=>item.declaration_id===previousSelection))selectedId.value=previousSelection;restoreUnsentDraft();}
  else {collection.value=undefined;concealReadDerived();restoreUnsentDraft();}
  if(needed&&!grant(needed))message.value=notice('لم تعد صلاحية هذه العملية متاحة. لم نحفظ المسودة؛ يمكنك القراءة إذا بقي حقها متاحًا.','Permission for this operation is no longer available. The draft was not saved; reading remains available under its independent right.');
  return true;
 }catch(error){if(currentContext()){const denied=confirmedRejection(error)==='denied';if(denied)forgetUnsentDraft();unavailable.value=true;collection.value=undefined;concealReadDerived(denied);access.value=undefined;message.value=notice('تعذر التحقق من صلاحية السجل. لم تُعرض بيانات محفوظة قديمة.','Could not qualify the record; old data is not displayed.');return false;}}
 finally{if(!disposed&&ticket===attempt)busy.value=false;}
}
async function execute(command?:Pending){
 if(busy.value||(!command&&!pending.value))return;
 if(command){if(pending.value)return;pending.value=freezeCommand(command);pendingCommands.set(pendingKey(),pending.value);}busy.value=true;
 const captured=stamp(),commandKey=pendingKey(),operation=++attempt,reconciling=!command;let saved=false;
 try{await send(props.business,pending.value!);if(disposed||!owned(captured)||pendingKey()!==commandKey)return;saved=true;confirmed.value=false;forgetUnsentDraft();pendingCommands.delete(commandKey);pending.value=undefined;editorOpen.value=false;edit.value=undefined;past.value=undefined;message.value=notice('تم الحفظ. جارٍ التحقق من الحالة الحالية.','Saved; checking the current state.');}
 catch(error){if(!disposed&&owned(captured)&&pendingKey()===commandKey){const rejected=confirmedRejection(error);if(rejected && !reconciling){pendingCommands.delete(commandKey);pending.value=undefined;if(rejected==='denied'){forgetUnsentDraft();collection.value=undefined;access.value=undefined;concealReadDerived();}message.value=rejected==='invalid'?notice('لم تُحفظ البيانات. صحّح النموذج ثم احفظه من جديد.','The data was not saved. Correct the form, then save again.'):rejected==='stale'?notice('رُفض التصحيح لأن الإصدار تغير. ألغِ التعديل وافتح النسخة الحالية.','Correction rejected because the revision changed. Cancel and reopen the current version.'):notice('لم تعد الصلاحية متاحة. أعد التحقق قبل أي طلب جديد.','Authority unavailable. Recheck before another request.');}else {if(rejected==='denied'){forgetUnsentDraft();collection.value=undefined;access.value=undefined;concealReadDerived();}message.value=notice('لم نتمكن من تأكيد النتيجة. احتفظنا بالأمر نفسه؛ تحقق من الصلاحية ثم أعد طلب نتيجته. لا تنشئ تصريحًا بديلًا.','Outcome is unconfirmed. The exact command is retained; qualify access and request its result again. Do not create a replacement.');}}}
 finally{if(!disposed&&operation===attempt)busy.value=false;}
 if(saved&&props.active&&pendingKey()===commandKey){const refreshTicket=attempt+1;const refreshed=await refresh();if(refreshed!==undefined&&!disposed&&props.active&&owned(captured)&&pendingKey()===commandKey&&refreshTicket===attempt)message.value=unavailable.value?notice('تم الحفظ، لكن تعذر تحديث الحالة. أعد التحقق؛ لا تسجّل البيانات نفسها من جديد.','Saved, but the current state could not be refreshed. Check again; do not record the same data again.'):notice('تم الحفظ وتحديث الحالة. تبقى البيانات تصريحات غير متحقق منها.','Saved and the current state refreshed. Data remains unverified assertions.');}
}
function enroll(){if(!access.value||!confirmed.value)return;void execute({kind:'enroll',body:{command_id:crypto.randomUUID(),authority_selection:selection(props.basisAccess),basis_revision:access.value.basis_revision,confirm:true}});}
function startNew(){if(locked.value)return;forgetUnsentDraft();selectedId.value=undefined;resetFields();editorOpen.value=true;past.value=undefined;message.value=undefined;}
function startEdit(item:EvidenceRead['items'][number]){if(locked.value||!item.value)return;edit.value=beginCorrection(item);const v=item.value;Object.assign(fields,{channel:v.cohort.channel_kind,country:v.cohort.country_code??'',cohortVersion:String(v.cohort.definition_version),start:v.observation.period_start,end:v.observation.period_end,asOf:v.observation.as_of,timezone:v.observation.timezone,cost:v.cost?.amount??'',currency:v.cost?.currency??'SAR',precision:String(v.cost?.precision??2),costBasis:v.cost?.cost_basis??'declared_cohort_cost',allocationBasis:v.cost?.allocation_basis??'whole_cohort',inquiries:v.inquiries?.count==null?'':String(v.inquiries.count),inquiryCoverage:v.inquiries?.coverage??'unknown',acceptanceEnabled:!!v.acceptance,accepted:v.acceptance?.count==null?'':String(v.acceptance.count),pendingCount:v.acceptance?.pending_count==null?'':String(v.acceptance.pending_count),criterion:v.acceptance?.criterion_code??'operator_defined',criterionVersion:String(v.acceptance?.criterion_version??1),criterionDefinition:v.acceptance?.criterion_definition??'',cutoff:v.acceptance?.cutoff??'',maturity:v.acceptance?.maturity??'unknown',acceptanceCoverage:v.acceptance?.coverage??'unknown'});editorOpen.value=true;}
function positive(value:string){if(!/^[1-9][0-9]{0,8}$/.test(value))throw Error('Version');return Number(value);}
function submit(){if(!canSubmit.value||locked.value){message.value=notice('لا تتوافر صلاحية الحفظ الحالية. أعد التحقق من الصلاحيات.','Current save permission is unavailable. Recheck permissions.');return;}try{
 const value:DeclarationWriteValue={cohort:{channel_kind:fields.channel as DeclarationValue['cohort']['channel_kind'],definition_version:positive(fields.cohortVersion),country_code:fields.country.trim()||null,population:'recorded_inquiries',counting_policy:'distinct_inquiries'},observation:{period_start:fields.start,period_end:fields.end,as_of:fields.asOf,timezone:fields.timezone},cost:fields.cost===''?null:{amount:fields.cost,currency:fields.currency,precision:Number(fields.precision),cost_basis:fields.costBasis as 'advertising_spend'|'declared_cohort_cost',allocation_basis:fields.allocationBasis as 'whole_cohort'|'declared_attribution'},inquiries:fields.inquiries===''&&fields.inquiryCoverage==='unknown'?null:{count:countValue(fields.inquiries),coverage:fields.inquiryCoverage as 'complete'|'partial'|'unknown'},acceptance:fields.acceptanceEnabled?{count:countValue(fields.accepted),pending_count:countValue(fields.pendingCount),criterion_code:fields.criterion as 'operator_defined'|'within_service_area'|'response_ready',criterion_version:positive(fields.criterionVersion),criterion_definition:fields.criterion==='operator_defined'?fields.criterionDefinition:null,cutoff:fields.cutoff,maturity:fields.maturity as 'mature'|'pending'|'unknown',coverage:fields.acceptanceCoverage as 'complete'|'partial'|'unknown'}:null};
 if(stale.value){message.value=notice('تغير الإصدار. ألغِ التعديل وافتح النسخة الحالية؛ لم نعتمدها بدل نسختك.','Revision changed. Cancel and open the current version; your baseline was not replaced.');return;}
 if(edit.value){const right=grant('business.inquiry_evidence.correct');if(!right)return;void execute({kind:'revise',declaration:edit.value.declaration,body:{command_id:crypto.randomUUID(),authority_selection:selection(right),expected_revision:edit.value.revision,effect:'correct',value}});}
 else{const right=grant('business.inquiry_evidence.record');if(!right)return;void execute({kind:'record',body:{command_id:crypto.randomUUID(),authority_selection:selection(right),value}});}
 }catch{message.value=notice('راجع الأعداد والإصدارات. اترك القيمة المجهولة فارغة؛ الصفر يعني صفرًا مُصرّحًا به.','Check counts and versions. Leave unknown values blank; zero means declared zero.');}}
async function showHistory(item:EvidenceRead['items'][number],after=0){const right=grant('business.inquiry_evidence.read');if(!right||busy.value||!props.active)return;busy.value=true;past.value=undefined;const captured=stamp(),ticket=++attempt,business=props.business;
 const currentContext=()=>!disposed&&props.active&&ticket===attempt&&owned(captured)&&business===props.business;
 try{const data=await history(business,item.declaration_id,right,after);if(currentContext())past.value=data;}catch(error){if(currentContext()){past.value=undefined;if(confirmedRejection(error)==='denied'){collection.value=undefined;access.value=undefined;concealReadDerived();}message.value=notice('تعذر تأهيل قراءة التاريخ.','History access could not be qualified.');}}finally{if(!disposed&&ticket===attempt)busy.value=false;}}
function withdraw(item:EvidenceRead['items'][number]){const right=grant('business.inquiry_evidence.correct');if(!right||locked.value)return;void execute({kind:'revise',declaration:item.declaration_id,body:{command_id:crypto.randomUUID(),authority_selection:selection(right),expected_revision:item.revision,effect:'withdraw',value:null}});}
function revoke(action:Action){const right=grant(action);if(!right||locked.value)return;void execute({kind:'revoke',body:{command_id:crypto.randomUUID(),authority_selection:selection(right),action}});}
watch(()=>[props.business,props.basisAccess,props.active],()=>{
 suspendDisplay();activeDraftKey=pendingKey();pending.value=pendingCommands.get(pendingKey());
 if(props.active)void refresh();
},{immediate:true,flush:'sync'});
onClose(()=>{attempt++;access.value=undefined;collection.value=undefined;past.value=undefined;selectedId.value=undefined;resetFields();editorOpen.value=false;message.value=undefined;});
onBeforeUnmount(()=>{retainUnsentDraft();disposed=true;attempt++;collection.value=undefined;past.value=undefined;access.value=undefined;});
</script>
<template>
<section ref="panelRoot" class="inquiry-panel" data-inquiry-panel :aria-busy="busy" :dir="locale==='ar'?'rtl':'ltr'">
 <header class="inquiry-toolbar"><h2 v-if="!embedded">{{title}}</h2><button v-if="grant('business.inquiry_evidence.record') && !editorOpen" type="button" class="workspace-button" :disabled="locked" @click="startNew">{{t('تسجيل تصريح جديد','Record a declaration')}}</button></header>
 <p class="inquiry-orientation">{{t('بيانات عن الاستفسارات سجّلها النشاط، وليست نتائج تحققت منها Builderad بصورة مستقلة.','Information about inquiries recorded by the Business, not results independently verified by Builderad.')}}</p>
 <p v-if="message" role="status" data-inquiry-message>{{locale==='ar'?message.ar:message.en}}</p>
 <p v-if="busy" role="status" data-inquiry-loading>{{t('جارٍ التحقق من البيانات والصلاحيات…','Checking data and permissions…')}}</p>
 <p v-if="pending" role="status" data-inquiry-unknown>{{t('نتيجة الأمر غير مؤكدة. احتفظنا بالأمر الأصلي ولا ننشئ بديلًا له.','The outcome is uncertain. The original command is retained; no replacement is created.')}} <button type="button" :disabled="busy" @click="execute()">{{t('إعادة طلب نتيجة الأمر نفسه','Request the same command result')}}</button></p>
 <p v-if="unavailable" role="status">{{t('السجل غير متاح بهذه الصلاحية. لا تظهر تصريحات نشاط آخر أو مستخدم آخر.','This authority cannot access the record. Other actors and businesses are not disclosed.')}}</p>
 <div v-if="access && !Object.keys(access.grants).length && !pending" data-inquiry-optin class="inquiry-enrollment">
  <h3>{{t('ابدأ سجلك الخاص','Start your private record')}}</h3><p>{{t('احفظ بيانات الاستفسارات التي تصرّح بها لهذا النشاط. تفعيل السجل لا يثبت ملكية تجارية ولا يمنح حقًا على بيانات الآخرين.','Keep inquiry information that you declare for this activity. Enabling the record does not establish commercial ownership or rights over others’ data.')}}</p>
  <label><input v-model="confirmed" type="checkbox" :disabled="busy">{{t('أختار تفعيل سجل تصريحاتي الخاصة لهذا النشاط، دون إثبات ملكية تجارية أو حقوق على بيانات الآخرين.','I choose to enable my private assertions for this Business without claiming commercial ownership or rights to others’ data.')}}</label>
  <button type="button" :disabled="locked || !confirmed" @click="enroll">{{t('تفعيل السجل الخاص','Enable private record')}}</button>
 </div>
 <details v-else-if="access && Object.keys(access.grants).length && !pending" class="inquiry-utilities">
  <summary>{{t('إدارة صلاحيات السجل','Manage record permissions')}}</summary>
  <div data-inquiry-optin><label><input v-model="confirmed" type="checkbox" :disabled="busy">{{t('أختار إعادة تفعيل الصلاحيات الخاصة بهذا النشاط باختيار جديد.','I choose to re-enable private permissions for this Business.')}}</label><button type="button" :disabled="locked || !confirmed" @click="enroll">{{t('إعادة تفعيل الصلاحيات الثلاث باختيار جديد','Re-enable the three rights by a new explicit choice')}}</button></div>
  <p>{{t('يمكن سحب كل صلاحية وحدها. إعادة التفعيل تتطلب اختيارًا صريحًا جديدًا.','Each right can be withdrawn independently. Re-enrollment requires a new explicit choice.')}}</p>
  <button v-for="action in (['business.inquiry_evidence.record','business.inquiry_evidence.correct','business.inquiry_evidence.read'] as Action[])" v-show="grant(action)" :key="action" type="button" :disabled="locked" @click="revoke(action)">{{action.endsWith('.record')?t('سحب التسجيل','Withdraw recording'):action.endsWith('.correct')?t('سحب التصحيح','Withdraw correction'):t('سحب القراءة','Withdraw reading')}}</button>
 </details>
 <div class="inquiry-page-actions"><button type="button" :disabled="busy" @click="refresh()">{{t('التحقق من الحالة الحالية','Check current state')}}</button></div>
 <form v-if="editorOpen" @submit.prevent="submit" @invalid.capture="($event.target as HTMLElement).closest('details')?.setAttribute('open', '')" data-inquiry-editor>
  <fieldset :disabled="locked"><legend>{{edit?t('تصحيح التصريح دون محو السابق','Correct without erasing history'):t('تصريح مجموعة استفسارات','Inquiry cohort declaration')}}</legend>
   <p>{{t('لا تدخل أسماء أو هواتف أو رسائل أو بيانات أشخاص. القيم الفارغة مجهولة وليست صفرًا.','Do not enter names, phone numbers, messages or personal data. Blank values are unknown, not zero.')}}</p>
   <section class="inquiry-form-group"><h3>{{t('القناة والمجموعة','Channel and cohort')}}</h3>
    <div class="inquiry-grid">
     <label>{{t('نوع قناة التسجيل','Recording channel')}}<select v-model="fields.channel"><option value="form">{{t('نموذج','Form')}}</option><option value="call">{{t('مكالمات','Calls')}}</option><option value="message">{{t('رسائل','Messaging')}}</option><option value="crm">CRM</option><option value="other">{{t('أخرى','Other')}}</option></select></label>
     <label>{{t('الدولة — اختياري','Country — optional')}}<input v-model="fields.country" maxlength="2" placeholder="SA"></label>
    </div>
   </section>
   <section class="inquiry-form-group"><h3>{{t('الفترة وزمن الملاحظة','Period and observation time')}}</h3>
    <div class="inquiry-grid">
     <label>{{t('بداية الفترة — مع المنطقة الزمنية','Period start — with timezone')}}<input v-model="fields.start" dir="ltr" placeholder="2026-10-01T00:00:00+03:00" required data-inquiry-start></label>
     <label>{{t('نهاية الفترة','Period end')}}<input v-model="fields.end" dir="ltr" placeholder="2026-10-02T00:00:00+03:00" required data-inquiry-end></label>
     <label>{{t('تاريخ المعرفة بالبيانات','Observed as of')}}<input v-model="fields.asOf" dir="ltr" placeholder="2026-10-03T00:00:00+03:00" required data-inquiry-asof></label>
     <label>{{t('المنطقة الزمنية','Timezone')}}<input v-model="fields.timezone" dir="ltr" required></label>
    </div>
   </section>
   <section class="inquiry-form-group"><h3>{{t('أعداد الاستفسارات','Inquiry counts')}}</h3>
    <div class="inquiry-grid">
     <label>{{t('عدد الاستفسارات — اختياري','Inquiry count — optional')}}<input v-model="fields.inquiries" inputmode="numeric" data-inquiry-count></label>
     <label>{{t('تغطية عدد الاستفسارات','Inquiry count coverage')}}<select v-model="fields.inquiryCoverage"><option value="unknown">{{t('غير معلومة','Unknown')}}</option><option value="partial">{{t('جزئية','Partial')}}</option><option value="complete">{{t('مكتملة بحسب التصريح','Complete as declared')}}</option></select></label>
    </div>
   </section>
   <section class="inquiry-form-group"><h3>{{t('التكلفة المصرح بها — اختياري','Declared cost — optional')}}</h3>
    <div class="inquiry-grid">
     <label>{{t('التكلفة المصرح بها — اختياري','Declared cost — optional')}}<input v-model="fields.cost" inputmode="decimal" data-inquiry-cost></label>
     <label>{{t('رمز العملة','Currency code')}}<input v-model="fields.currency" maxlength="3" dir="ltr"></label>
     <label>{{t('نوع التكلفة','Cost basis')}}<select v-model="fields.costBasis"><option value="declared_cohort_cost">{{t('تكلفة المجموعة المصرح بها','Declared cohort cost')}}</option><option value="advertising_spend">{{t('إنفاق إعلاني مصرح به','Declared advertising spend')}}</option></select></label>
     <label>{{t('إسناد التكلفة إلى المجموعة','Cost allocation')}}<select v-model="fields.allocationBasis"><option value="whole_cohort">{{t('لكامل المجموعة','Whole cohort')}}</option><option value="declared_attribution">{{t('توزيع يصرح به النشاط — ليس أثرًا سببيًا','Business-declared attribution — not causal impact')}}</option></select></label>
    </div>
   </section>
   <section class="inquiry-form-group"><h3>{{t('القبول وفق معيار النشاط — اختياري','Acceptance under the Business criterion — optional')}}</h3>
    <label><input v-model="fields.acceptanceEnabled" type="checkbox" data-inquiry-acceptance-toggle>{{t('إضافة تصريح عن القبول وفق معيار معلوم','Add acceptance under a stated criterion')}}</label>
    <div v-if="fields.acceptanceEnabled" class="inquiry-grid">
     <label>{{t('عدد المقبولين','Accepted inquiries')}}<input v-model="fields.accepted" inputmode="numeric" data-inquiry-accepted></label>
     <label>{{t('عدد ما زال ينتظر التأهيل','Pending qualification count')}}<input v-model="fields.pendingCount" inputmode="numeric"></label>
     <label>{{t('نوع معيار القبول','Acceptance criterion')}}<select v-model="fields.criterion"><option value="operator_defined">{{t('معيار حدده النشاط','Business-defined criterion')}}</option><option value="within_service_area">{{t('ضمن نطاق الخدمة','Within service area')}}</option><option value="response_ready">{{t('جاهز للاستجابة','Ready for response')}}</option></select></label>
     <label v-if="fields.criterion==='operator_defined'">{{t('تعريف معيار القبول','Criterion definition')}}<textarea v-model="fields.criterionDefinition" required minlength="8" maxlength="500" data-inquiry-criterion-definition></textarea><span>{{t('قاعدة عامة فقط، دون أسماء أشخاص أو بيانات اتصال أو شيفرة أو روابط. يبقى التعريف تصريحًا منك.','Abstract rule only: no personal names, contact details, code or links. The definition remains your assertion.')}}</span></label>
     <label>{{t('موعد قطع ملاحظة القبول','Acceptance cutoff')}}<input v-model="fields.cutoff" dir="ltr" placeholder="2026-10-03T00:00:00+03:00" data-inquiry-cutoff></label>
     <label>{{t('نضج النتائج','Maturity')}}<select v-model="fields.maturity"><option value="unknown">{{t('غير معلوم','Unknown')}}</option><option value="pending">{{t('لم يكتمل','Pending')}}</option><option value="mature">{{t('مكتمل بحسب التصريح','Mature as declared')}}</option></select></label>
     <label>{{t('تغطية القبول','Acceptance coverage')}}<select v-model="fields.acceptanceCoverage"><option value="unknown">{{t('غير معلومة','Unknown')}}</option><option value="partial">{{t('جزئية','Partial')}}</option><option value="complete">{{t('مكتملة بحسب التصريح','Complete as declared')}}</option></select></label>
    </div>
   </section>
   <details class="inquiry-form-advanced" data-inquiry-definition-details>
    <summary>{{t('تفاصيل التعريف والدقة','Definition and precision details')}}</summary>
    <p>{{t('احتفظ بهذه التعريفات عند المقارنة أو التصحيح. تغيير الإصدار لا يغيّر البيانات السابقة تلقائيًا.','Keep these definitions when comparing or correcting data. A version change does not automatically change earlier data.')}}</p>
    <div class="inquiry-grid">
     <label>{{t('إصدار تعريف المجموعة','Cohort definition version')}}<input v-model="fields.cohortVersion" inputmode="numeric" required></label>
     <label>{{t('الخانات العشرية للعملة','Currency decimal precision')}}<input v-model="fields.precision" type="number" min="0" max="8"></label>
     <label v-if="fields.acceptanceEnabled">{{t('إصدار معيار القبول','Criterion version')}}<input v-model="fields.criterionVersion" inputmode="numeric"></label>
    </div>
   </details>
   <p v-if="stale" role="alert">{{t('الإصدار الذي بدأت منه تغير. لن نكتب فوق التصحيح الأحدث.','Your baseline changed; the newer correction will not be overwritten.')}}</p>
   <button type="submit" :disabled="stale || !canSubmit">{{edit?t('حفظ التصحيح','Save correction'):t('حفظ التصريح','Save declaration')}}</button>
   <button type="button" @click="cancelEditor">{{t('إلغاء التعديل','Cancel edit')}}</button>
  </fieldset>
 </form>

 <div v-if="collection && !selected && !editorOpen" class="inquiry-list" data-inquiry-list>
  <div v-if="!collection.items.length" class="inquiry-empty"><h3>{{t('لا توجد تصريحات في هذه الصفحة','No declarations on this page')}}</h3><p>{{t('يمكنك تسجيل بيانات مجموعة استفسارات عندما تتوافر لديك معلوماتها وصلاحية تسجيلها. غياب البيانات لا يعني عدم وجود استفسارات.','You can record a cohort when you have its information and recording permission. Missing data does not mean there are no inquiries.')}}</p></div>
  <article v-for="item in collection.items" :key="item.declaration_id" :data-inquiry-record="item.declaration_id" class="inquiry-item inquiry-record">
   <div><h3>{{channel(item.value?.cohort.channel_kind)}} <span aria-hidden="true">·</span> {{t('إصدار','Revision')}} {{item.revision}}</h3>
    <p><span class="inquiry-assurance">{{item.state==='withdrawn'?t('مسحوب؛ التاريخ محفوظ','Withdrawn; history retained'):t('مصرح به، غير متحقق منه','Source asserted, not independently verified')}}</span></p>
    <span v-if="item.value" class="inquiry-record-date"><bdi>{{period(item.value.observation.period_start)}}</bdi> — <bdi>{{period(item.value.observation.period_end)}}</bdi></span>
    <div v-if="item.value" class="inquiry-record-preview"><p>{{t('الاستفسارات','Inquiries')}}: <strong>{{item.value.inquiries?.count??t('غير معلوم','Unknown')}}</strong></p><p>{{t('المقبول وفق التصريح','Accepted as declared')}}: <strong>{{item.value.acceptance?.count??t('غير معلوم','Unknown')}}</strong></p></div>
   </div>
   <div class="inquiry-record-actions"><button type="button" data-inquiry-open :disabled="busy" @click="openRecord(item)">{{t('عرض التفاصيل','View details')}}</button></div>
  </article>
  <div class="inquiry-page-actions"><button v-if="collection.next_declaration" type="button" :disabled="busy" @click="refresh(collection.next_declaration)">{{t('التصريحات الأقدم','Older declarations')}}</button></div>
 </div>
 <section v-if="selected && !editorOpen" class="inquiry-record-detail" data-inquiry-detail>
  <button type="button" data-inquiry-back @click="backToCollection">{{t('العودة إلى بيانات الاستفسارات','Back to inquiry evidence')}}</button>
  <h3 ref="detailHeading" tabindex="-1" class="inquiry-detail-heading">{{channel(selected.value?.cohort.channel_kind)}} — {{t('إصدار','Revision')}} {{selected.revision}}</h3>
  <p class="inquiry-assurance">{{selected.state==='withdrawn'?t('مسحوب؛ التاريخ محفوظ','Withdrawn; history retained'):t('مصرح به، غير متحقق منه','Source asserted, not independently verified')}}</p>
  <DeclarationDetails v-if="selected.value" :value="selected.value" />
  <p v-else>{{t('سُحب التصريح الحالي. يمكنك مراجعة القيم السابقة في التاريخ إذا كانت صلاحية القراءة متاحة.','The current declaration was withdrawn. Earlier values remain in history when reading is permitted.')}}</p>
  <details class="inquiry-metadata"><summary>{{t('المصدر ومعلومات التسجيل','Source and recording details')}}</summary><p>{{t('تصريح خاص من المستخدم؛ التسجيل لا يثبت وقوع الاستفسارات أو قبولها.','A private user assertion; recording does not establish inquiry or acceptance events.')}}</p><dl>
    <dt>{{t('معرّف السجل','Record identifier')}}</dt><dd><bdi><code>{{selected.declaration_id}}</code></bdi></dd>
    <dt>{{t('صاحب التصريح الأصلي','Original declaring actor')}}</dt><dd><bdi><code>{{selected.original_issuer}}</code></bdi></dd>
    <dt>{{t('مسجّل هذا الإصدار','Actor recording this revision')}}</dt><dd><bdi><code>{{selected.correcting_actor}}</code></bdi></dd>
    <dt>{{t('وقت التسجيل','Recorded at')}}</dt><dd><bdi>{{selected.recorded_at}}</bdi></dd>
  </dl></details>
  <div class="inquiry-detail-actions"><button type="button" :disabled="busy" @click="showHistory(selected)">{{t('قراءة التاريخ','Read history')}}</button><button v-if="selected.value && grant('business.inquiry_evidence.correct')" type="button" :disabled="locked" @click="startEdit(selected)">{{t('فتح التصحيح','Edit correction')}}</button><button v-if="selected.value && grant('business.inquiry_evidence.correct')" type="button" :disabled="locked" @click="withdraw(selected)">{{t('سحب التصريح مع حفظ التاريخ','Withdraw and retain history')}}</button></div>
  <section v-if="past" class="inquiry-history" data-inquiry-history><h3>{{t('تاريخ التصريح','Declaration history')}}</h3><p>{{t('التصحيح والسحب يضيفان إصدارًا جديدًا؛ لا يمحوان الإصدارات السابقة.','Corrections and withdrawals add a revision; they do not erase earlier revisions.')}}</p>
   <article v-for="item in past.items" :key="item.revision"><h4>{{t('الإصدار','Revision')}} {{item.revision}} — {{item.reason==='initial'?t('الأصل','Original'):item.reason==='withdrawal'?t('سحب','Withdrawal'):t('تصحيح','Correction')}}</h4><p>{{t('المقبول المصرح به','Declared accepted count')}}: {{item.value?.acceptance?.count??t('غير معلوم أو مسحوب','Unknown or withdrawn')}}</p><p>{{t('وقت التسجيل','Recorded at')}}: <bdi>{{item.recorded_at}}</bdi></p><details><summary>{{t('بيانات هذا الإصدار','This revision’s details')}}</summary><DeclarationDetails v-if="item.value" :value="item.value" /><dl class="inquiry-technical"><dt>{{t('صاحب التصريح الأصلي','Original declaring actor')}}</dt><dd><bdi><code>{{item.original_issuer}}</code></bdi></dd><dt>{{t('مسجّل هذا الإصدار','Actor recording this revision')}}</dt><dd><bdi><code>{{item.correcting_actor}}</code></bdi></dd></dl></details></article>
   <button v-if="past.next_revision && past.items[0]" type="button" :disabled="busy" @click="showHistory(past.items[0],past.next_revision)">{{t('الإصدارات التالية','Next revisions')}}</button>
  </section>
 </section>
</section>
</template>
<style scoped>
.inquiry-panel{overflow-wrap:anywhere}.inquiry-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,16rem),1fr));gap:1rem}.inquiry-panel label{display:flex;flex-direction:column;gap:.4rem;margin-block:.6rem}.inquiry-panel label:has(input[type=checkbox]){flex-direction:row;align-items:start}.inquiry-panel input:not([type=checkbox]),.inquiry-panel select{width:100%;min-width:0;padding:.7rem;border:1px solid var(--ba-control-border,#738693);border-radius:.4rem;background:var(--card,#fff);color:inherit}.inquiry-panel input[type=checkbox]{width:18px;height:18px;flex-shrink:0;margin-block-start:5px;accent-color:var(--ba-brand-primary,#155f53)}.inquiry-panel button{margin:0}.inquiry-panel form>fieldset>button{margin:12px 0;margin-inline-end:10px}.inquiry-panel fieldset{min-width:0}.inquiry-panel legend{font-size:1.1rem}.inquiry-panel form{margin-block:24px}.inquiry-panel form p{line-height:1.8}.inquiry-enrollment{max-width:74ch;background:var(--workspace-soft);padding:20px;border-radius:6px}.inquiry-enrollment h3,.inquiry-empty h3{font-weight:650;margin-block:0 10px}.inquiry-enrollment p,.inquiry-empty p{line-height:1.9}.inquiry-empty{padding-block:24px}.inquiry-technical code{overflow-wrap:anywhere;font-size:.85rem}.inquiry-detail-heading:focus{outline:none}
.inquiry-form-group{margin-block:24px;padding-block-start:18px;border-block-start:1px solid var(--workspace-line,var(--border,#d9e2e6))}.inquiry-form-group h3{font-size:1rem;font-weight:650;margin-block:0 12px}.inquiry-form-advanced{border-block:1px solid var(--workspace-line,var(--border,#d9e2e6));padding-block:8px;margin-block:24px}.inquiry-form-advanced>summary{min-height:44px;font-weight:600;cursor:pointer;padding-block:10px}.inquiry-form-advanced>p{color:var(--workspace-muted,var(--muted-foreground,#536473));max-width:78ch}
</style>
