<script setup lang="ts">
import { locale } from '../spa/locale';
import type { DeclarationValue } from './api';
defineProps<{value:DeclarationValue}>();
const t=(ar:string,en:string)=>locale.value==='ar'?ar:en;
const coverage=(v?:string)=>v==='complete'?t('مكتملة بحسب التصريح','Complete as declared'):v==='partial'?t('جزئية','Partial'):t('غير معلومة','Unknown');
const channel=(v:string)=>({form:t('نموذج','Form'),call:t('مكالمات','Calls'),message:t('رسائل','Messaging'),crm:'CRM',other:t('أخرى','Other')}[v]);
</script>
<template>
<div class="declaration-details" data-inquiry-semantics>
 <section class="declaration-group"><h4>{{t('ما الذي سجّله النشاط؟','What did the Business record?')}}</h4><dl>
  <dt>{{t('قناة تسجيل الاستفسارات','Inquiry recording channel')}}</dt><dd>{{channel(value.cohort.channel_kind)}}</dd>
  <dt>{{t('الدولة','Country')}}</dt><dd><bdi>{{value.cohort.country_code??t('غير محددة','Not specified')}}</bdi></dd>
  <dt>{{t('إصدار تعريف المجموعة','Cohort definition version')}}</dt><dd>{{value.cohort.definition_version}}</dd>
  <dt>{{t('وحدة العد','Counting unit')}}</dt><dd>{{t('استفسارات مميزة وفق تصريح المجموعة؛ ليست بالضرورة أشخاصًا مختلفين','Distinct declared inquiries, not necessarily distinct people')}}</dd>
 </dl></section>
 <section class="declaration-group"><h4>{{t('الفترة وزمن الملاحظة','Period and observation time')}}</h4><dl>
  <dt>{{t('بداية الفترة','Period start')}}</dt><dd><bdi>{{value.observation.period_start}}</bdi></dd>
  <dt>{{t('نهاية الفترة','Period end')}}</dt><dd><bdi>{{value.observation.period_end}}</bdi></dd>
  <dt>{{t('المعرفة حتى تاريخ','Known as of')}}</dt><dd><bdi>{{value.observation.as_of}}</bdi></dd>
  <dt>{{t('المنطقة الزمنية','Timezone')}}</dt><dd><bdi>{{value.observation.timezone}}</bdi></dd>
 </dl></section>
 <section class="declaration-group"><h4>{{t('التكلفة وأعداد الاستفسارات','Declared costs and inquiry counts')}}</h4><dl>
  <dt>{{t('التكلفة المصرح بها','Declared cost')}}</dt><dd><bdi>{{value.cost?`${value.cost.amount} ${value.cost.currency}`:t('غير معلومة','Unknown')}}</bdi></dd>
  <dt>{{t('الخانات العشرية للعملة','Currency decimal precision')}}</dt><dd>{{value.cost?.precision??t('غير معلومة','Unknown')}}</dd>
  <dt>{{t('تعريف التكلفة','Cost meaning')}}</dt><dd>{{!value.cost?t('غير معلوم','Unknown'):value.cost.cost_basis==='advertising_spend'?t('إنفاق إعلاني مصرح به','Declared advertising spend'):t('تكلفة المجموعة المصرح بها','Declared cohort cost')}}</dd>
  <dt>{{t('طريقة إسناد التكلفة','Cost allocation')}}</dt><dd>{{!value.cost?t('غير معلومة','Unknown'):value.cost.allocation_basis==='whole_cohort'?t('لكامل المجموعة','Whole cohort'):t('إسناد يصرح به النشاط، لا أثر سببي','Business-declared attribution, not causal effect')}}</dd>
  <dt>{{t('عدد الاستفسارات','Inquiry count')}}</dt><dd data-inquiry-detail-count>{{value.inquiries?.count??t('غير معلوم','Unknown')}}</dd>
  <dt>{{t('تغطية عدد الاستفسارات','Inquiry count coverage')}}</dt><dd>{{coverage(value.inquiries?.coverage)}}</dd>
 </dl></section>
 <section class="declaration-group"><h4>{{t('القبول ومعياره','Acceptance and its criterion')}}</h4><dl>
  <dt>{{t('عدد الاستفسارات المقبولة','Accepted inquiries')}}</dt><dd data-inquiry-detail-accepted>{{value.acceptance?.count??t('غير معلوم','Unknown')}}</dd>
  <dt>{{t('تغطية عدد المقبولين','Accepted-inquiry count coverage')}}</dt><dd>{{coverage(value.acceptance?.coverage)}}</dd>
  <dt>{{t('ما زال ينتظر التأهيل','Pending qualification')}}</dt><dd>{{value.acceptance?.pending_count??t('غير معلوم','Unknown')}}</dd>
  <dt>{{t('معيار القبول','Acceptance criterion')}}</dt><dd>{{!value.acceptance?t('غير محدد','Not specified'):value.acceptance.criterion_code==='within_service_area'?t('ضمن نطاق الخدمة','Within service area'):value.acceptance.criterion_code==='response_ready'?t('جاهز للاستجابة','Ready for response'):t('معيار خاص حدده النشاط','Business-defined criterion')}}</dd>
  <dt>{{t('تعريف معيار القبول','Criterion definition')}}</dt><dd data-inquiry-criterion-definition>{{value.acceptance?.criterion_definition??t('التعريف التاريخي غير معلوم؛ لم نختلق تعريفًا له.','Historical definition unknown; no definition has been invented.')}}</dd>
  <dt>{{t('إصدار معيار القبول','Criterion version')}}</dt><dd>{{value.acceptance?.criterion_version??t('غير معلوم','Unknown')}}</dd>
  <dt>{{t('موعد قطع ملاحظة القبول','Acceptance cutoff')}}</dt><dd><bdi>{{value.acceptance?.cutoff??t('غير معلوم','Unknown')}}</bdi></dd>
  <dt>{{t('نضج القبول','Acceptance maturity')}}</dt><dd>{{value.acceptance?.maturity==='mature'?t('مكتمل بحسب التصريح','Mature as declared'):value.acceptance?.maturity==='pending'?t('لم يكتمل','Pending'):t('غير معلوم','Unknown')}}</dd>
 </dl></section>
 <p class="declaration-limit">{{t('القيمة غير المعلومة ليست صفرًا. القبول وفق معيار التصريح ليس بيعًا أو ربحًا. لا يحسب هذا السجل تكلفة اكتساب أو يختار قناة أو ميزانية.','An unknown value is not zero. Acceptance under the declared criterion is not a sale or profit. This record does not calculate acquisition cost or choose a channel or budget.')}}</p>
</div>
</template>
<style scoped>
.declaration-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 32px;margin-block:24px;min-width:0}.declaration-group{min-width:0;padding-block:20px;border-block-start:1px solid var(--workspace-line,var(--border,#d9e2e6))}.declaration-group h4{font-size:1rem;font-weight:650;margin-block:0 18px}.declaration-group dl{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:12px 16px;line-height:1.8}.declaration-group dt{color:var(--workspace-muted,var(--muted-foreground,#536473))}.declaration-group dd{margin:0;overflow-wrap:anywhere;color:inherit}.declaration-group bdi{unicode-bidi:isolate}.declaration-limit{grid-column:1/-1;line-height:1.9;padding:14px 0;border-block-start:1px solid var(--workspace-line,var(--border,#d9e2e6));color:var(--workspace-muted,var(--muted-foreground,#536473));max-width:90ch}@media(max-width:1100px){.declaration-details{grid-template-columns:1fr}}@media(max-width:540px){.declaration-group dl{grid-template-columns:1fr;gap:5px}.declaration-group dd{margin-block-end:10px}.declaration-group{padding-block:18px}.declaration-details{margin-block:16px}}
</style>
