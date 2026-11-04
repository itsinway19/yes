<script setup lang="ts">
import { computed, reactive, ref, watch, onBeforeUnmount } from 'vue';
import { RouterLink as Link, useRouter, onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import { ChartNoAxesColumnIncreasing, Users, Lightbulb, Settings, Store, ArrowLeft } from '@lucide/vue';
import { current, read, confirmDeparture } from './state';
import { remember, restore, type Preferences } from './drafts';
import { bootstrap, stamp, owned, TransportError } from '../spa/transport';
import { saveGoals } from './api';
import { goalsPath } from './paths';
import { useGoalsCopy } from '../localization/goalsCopy';
import { locale as applicationLocale } from '../spa/locale';
import { canContinue, cloneGoals, goalSignature, setPriority, toggleGoal, toggleResult } from '../Components/Business/goalSelection';
import AdvertiserWorkspaceLayout from '../business-foundation/AdvertiserWorkspaceLayout.vue';
const props = current.value!;
const workspace = props.workspace;
const business = computed(() => current.value?.business ?? props.business), catalog = props.catalog;
const router = useRouter();
const { locale, t, localized } = useGoalsCopy(applicationLocale);
const baseline = ref<Preferences>(structuredClone(props.preferences));
const draft = reactive(cloneGoals(props.preferences.value));
const busy = ref(false), reviewing = ref(false), conflict = ref(false), error = ref('');
const reviewed = ref<Preferences | null>(null), viewedGoal = ref<string | null>(null);
const dirty = computed(() => goalSignature(draft) !== goalSignature(baseline.value.value));
const mutable = computed(() => business.value.canManage && !busy.value && !reviewing.value);
const priorityGoal = computed(() => props.catalog.find(goal => goal.id === (business.value.canManage ? draft.priority : viewedGoal.value ?? draft.priority)));
const icons = { sales: ChartNoAxesColumnIncreasing, customers: Users, growth: Lightbulb, efficiency: Settings };
let alive = true, persist: Promise<void> = Promise.resolve();
const captured = stamp();
void restore(workspace.public_id).then(saved => { if (alive && owned(captured) && saved && business.value.canManage && !dirty.value) { baseline.value=saved.baseline;Object.assign(draft,cloneGoals(saved.draft)); } });
watch([draft,baseline],()=>{ if(business.value.canManage) persist=remember(workspace.public_id,{baseline:baseline.value,draft}); },{deep:true,flush:'sync'});
watch(()=>current.value?.preferences,preferences=>{if(preferences&&!dirty.value&&!busy.value&&!conflict.value){baseline.value=structuredClone(preferences);Object.assign(draft,cloneGoals(preferences.value));}});
const choose=(id:string)=>{if(mutable.value)toggleGoal(draft,id);};
const prioritize=(id:string)=>{if(mutable.value)setPriority(draft,id);};
const outcome=(goal:string,id:string)=>{if(mutable.value)toggleResult(draft,goal,id);};
const viewResults=(id:string)=>{viewedGoal.value=id;};
async function save(){
 if(!mutable.value || !canContinue(draft) || !draft.priority || conflict.value)return;
 const revision=stamp();busy.value=true;error.value='';
 try{
  const saved=await saveGoals(workspace.public_id,{value:{...cloneGoals(draft),priority:draft.priority},version:baseline.value.version});
  if(!alive || !owned(revision))return;baseline.value=saved;Object.assign(draft,cloneGoals(saved.value));await persist;busy.value=false;
  await router.push(goalsPath(workspace.public_id, true));
 }catch(failure){if(!alive || !owned(revision))return;if(failure instanceof TransportError && failure.status===409){conflict.value=true;reviewed.value=null;}else error.value=failure instanceof TransportError&&failure.status===403?t('denied'):failure instanceof TransportError&&failure.status===422?Object.values(failure.body.errors??{}).flat().join(' ')||t('failed'):t('failed');}
 finally{busy.value=false;}
}
async function review(){reviewing.value=true;try{const value=await read(workspace.public_id);if(alive&&value)reviewed.value=structuredClone(value.preferences);}finally{reviewing.value=false;}}
function adopt(){if(reviewed.value){baseline.value=JSON.parse(JSON.stringify(reviewed.value));reviewed.value=null;conflict.value=false;error.value='';}}
const canLeave=()=>!busy.value&&!reviewing.value&&(!dirty.value||confirm(t('unsaved')));
confirmDeparture.value=canLeave;
const leaveAfterPersistence = async () => { if (!canLeave()) return false; await persist; };
onBeforeRouteLeave(leaveAfterPersistence); onBeforeRouteUpdate(leaveAfterPersistence);
const beforeUnload=(event:BeforeUnloadEvent)=>{if(dirty.value){event.preventDefault();event.returnValue='';}};
window.addEventListener('beforeunload',beforeUnload);
onBeforeUnmount(()=>{alive=false;if(confirmDeparture.value===canLeave)confirmDeparture.value=()=>true;window.removeEventListener('beforeunload',beforeUnload);});
</script>
<template>
 <div class="goals-page" data-spa-private :data-goals-workspace="workspace.public_id">
  <AdvertiserWorkspaceLayout :context="workspace.name" :workspace-id="workspace.public_id" workspace-context>
   <nav class="advertiser-breadcrumb" :aria-label="locale === 'ar' ? 'مسار الصفحة' : 'Breadcrumb'"><Link to="/businesses">{{ locale === 'ar' ? 'أنشطتي' : 'My businesses' }}</Link><span aria-hidden="true">/</span><Link to="/workspaces">{{ locale === 'ar' ? 'مساحات العمل' : 'Workspaces' }}</Link><span aria-hidden="true">/</span><Link :to="`/workspaces/${workspace.public_id}`">{{ workspace.name }}</Link><span aria-hidden="true">/</span><span aria-current="page">{{ locale === 'ar' ? 'الأهداف' : 'Goals' }}</span></nav>
   <section class="goals-screen" :dir="locale === 'ar' ? 'rtl' : 'ltr'" :aria-busy="busy || reviewing">
    <p class="biz-eyebrow">{{ t('eyebrow') }}</p>
    <h1>{{ t('title') }}</h1><p class="goals-intro">{{ t('intro') }}</p>
    <div class="goals-business"><Store :size="20" aria-hidden="true" /><div><small>{{ locale === 'ar' ? 'مساحة العمل' : 'Workspace' }}</small><strong>{{ workspace.name }}</strong><p v-if="business.type">{{ localized(business.type) }}</p></div><Link v-if="business.canManage" :to="`/workspaces/${workspace.public_id}/business`">{{ locale === 'ar' ? 'إعدادات المساحة' : 'Workspace settings' }}</Link></div>
    <p v-if="!business.canManage" class="goals-note">{{ t('readonly') }}</p>
    <form data-goals="form" @submit.prevent="save">
     <fieldset class="goals-grid" :disabled="business.canManage && !mutable"><legend class="sr-only">{{ t('selectLabel') }}</legend>
      <div v-for="goal in catalog" :key="goal.id" class="goal-card" :class="{ 'is-selected': draft.goals.includes(goal.id), 'is-priority': draft.priority === goal.id }">
       <label class="goal-choice"><input v-if="business.canManage" type="checkbox" :data-goals="`select-${goal.id}`" :checked="draft.goals.includes(goal.id)" @change="choose(goal.id)" /><span v-else class="goal-read-status" aria-hidden="true">{{ draft.goals.includes(goal.id) ? '✓' : '' }}</span><component :is="icons[goal.id as keyof typeof icons]" :size="30" aria-hidden="true" /><strong>{{ localized(goal) }}</strong><span class="goal-description">{{ locale === 'ar' ? goal.description_ar : goal.description_en }}</span></label>
       <span v-if="draft.priority === goal.id" class="goal-priority">{{ t('priority') }}</span>
       <button v-else-if="business.canManage && draft.goals.includes(goal.id)" type="button" :data-goals="`priority-${goal.id}`" :aria-label="`${t('makePriority')}: ${localized(goal)}`" class="goal-priority-action" @click="prioritize(goal.id)">{{ t('makePriority') }}</button>
       <button v-if="!business.canManage && draft.goals.includes(goal.id)" type="button" :data-goals="`view-${goal.id}`" :aria-pressed="priorityGoal?.id === goal.id" :aria-label="`${t('viewResults')}: ${localized(goal)}`" class="goal-priority-action" @click="viewResults(goal.id)">{{ t('viewResults') }}</button>
      </div>
     </fieldset>
     <p v-if="draft.goals.length && !draft.priority" class="goals-note goals-priority-note" role="status">{{ t('choosePriority') }}</p>
     <fieldset v-if="priorityGoal" class="goals-outcomes" :aria-label="`${t('results')}: ${localized(priorityGoal)}`" :disabled="!mutable"><legend>{{ t('results') }}</legend><div class="goals-outcome-list"><label v-for="result in priorityGoal.results" :key="result.id" :class="{ 'is-selected': draft.results[priorityGoal.id]?.includes(result.id) }"><input v-if="business.canManage" type="checkbox" :data-goals="`result-${result.id}`" :checked="draft.results[priorityGoal.id]?.includes(result.id) ?? false" @change="outcome(priorityGoal.id, result.id)" /><span v-else aria-hidden="true">{{ draft.results[priorityGoal.id]?.includes(result.id) ? '✓' : '' }}</span>{{ localized(result) }}</label></div></fieldset>
     <div v-if="conflict" class="goals-feedback" role="alert"><p>{{ t('conflict') }}</p><button type="button" data-goals="review" class="biz-link" :disabled="!mutable" @click="review">{{ t('review') }}</button><template v-if="reviewed"><h3>{{ t('savedVersion') }}</h3><p v-if="!reviewed.value.goals.length">{{ t('empty') }}</p><ul><li v-for="goal in catalog.filter(g => reviewed?.value.goals.includes(g.id))" :key="goal.id">{{ localized(goal) }}<strong v-if="reviewed.value.priority === goal.id"> — {{ t('priority') }}</strong><ul><li v-for="result in goal.results.filter(r => reviewed?.value.results[goal.id]?.includes(r.id))" :key="result.id">{{ localized(result) }}</li></ul></li></ul><button type="button" data-goals="adopt" class="biz-link" :disabled="!mutable" @click="adopt">{{ t('adopt') }}</button></template></div>
     <p v-if="error" class="goals-error" role="alert">{{ error }}</p><p v-if="busy || reviewing" role="status">{{ t('saving') }}</p>
     <footer class="goals-footer"><button v-if="business.canManage" type="submit" data-goals="continue" class="goals-continue" :disabled="!mutable || !canContinue(draft) || conflict">{{ busy ? t('saving') : t('continue') }}<ArrowLeft :size="18" aria-hidden="true" /></button><Link :to="`/workspaces/${workspace.public_id}/business`" class="goals-previous">{{ t('previous') }}<ArrowLeft :size="16" aria-hidden="true" /></Link></footer>
    </form>
   </section>
  </AdvertiserWorkspaceLayout>
 </div>
</template>

<style scoped>
.goals-page :deep(.business-shell){background:#fff}.goals-page :deep(.business-main){max-width:1040px;padding-top:44px}.goals-screen{color:#142e3d}.goals-screen h1{margin:0;font-size:30px}.goals-intro{color:#586b79;font-size:15px;margin:7px 0 30px}.goals-business{display:flex;align-items:center;gap:16px;background:#edf5f3;padding:19px 22px;border-radius:6px;margin-bottom:24px}.goals-business strong{font-size:15px}.goals-business p{font-size:13px;color:#586b79;margin:3px 0 0}.goals-business a{margin-inline-start:auto;color:var(--ba-primary-ink);text-decoration:underline;text-underline-offset:4px}.goals-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;border:0;padding:0;margin:0}.goal-card{position:relative;border:1px solid #d9e3e7;border-radius:6px;background:#fff;min-width:0;display:flex;flex-direction:column;padding:16px 14px;min-height:210px}.goal-card.is-selected{border-color:var(--ba-primary-ink);background:#f4faf7}.goal-card.is-priority{border-width:2px;padding:15px 13px}.goal-choice{display:flex;flex:1;align-items:center;flex-direction:column;gap:13px;cursor:pointer;padding-top:7px;text-align:center}.goal-choice input,.goal-read-status{position:absolute;inset-inline-end:12px;top:12px}.goals-screen input{color:var(--ba-primary-ink);accent-color:var(--ba-primary-ink);width:17px;height:17px;flex-shrink:0}.goal-choice strong{font-size:14px;line-height:1.7}.goal-description{font-size:13px;line-height:1.85;color:#586b79}.goal-priority,.goal-priority-action{margin:12px auto 0;font-size:12px;line-height:1.6;color:var(--ba-primary-ink)}.goal-priority{background:#d9f3e2;padding:3px 9px;border-radius:4px;font-weight:650}.goal-priority-action{text-decoration:underline;text-underline-offset:3px;min-height:30px}.goal-priority-action[aria-pressed=true]{font-weight:700;text-decoration-thickness:2px}.goals-priority-note{margin-top:22px}.goals-outcomes legend{font-size:16px;font-weight:700;margin:0 0 10px}.goals-outcomes{border:0;padding:0;margin:26px 0 0}.goals-outcome-list{display:flex;flex-wrap:wrap;gap:9px;margin-top:10px}.goals-outcome-list label{display:flex;align-items:center;gap:9px;padding:11px 12px;border:1px solid #d9e3e7;border-radius:5px;font-size:13px;cursor:pointer;line-height:1.7}.goals-outcome-list label.is-selected{color:var(--ba-primary-ink);border-color:var(--ba-primary-ink);background:#f4faf7}.goals-note{color:#586b79;font-size:13px;margin:7px 0;line-height:1.8}.goals-feedback{border-inline-start:3px solid var(--ba-primary-ink);padding:14px;background:#f1f7f5;margin:22px 0;font-size:14px}.goals-feedback button{margin:12px 0}.goals-feedback ul{list-style:disc;padding-inline-start:22px}.goals-error{color:#a12c27;font-size:14px;margin-top:20px}.goals-footer{display:flex;justify-content:space-between;gap:20px;margin-top:46px}.goals-continue,.goals-previous{display:flex;align-items:center;justify-content:center;gap:12px;border-radius:5px;font-size:14px;font-weight:650;min-height:44px;padding:10px 22px}.goals-continue{color:var(--ba-primary-text);background:var(--ba-brand-primary)}.goals-continue:disabled{background:#aab8bd;cursor:not-allowed}.goals-previous{border:1px solid #cbd7de}.goals-screen button:focus-visible,.goals-screen input:focus-visible,.goals-screen a:focus-visible{outline:3px solid var(--ba-primary-ink);outline-offset:4px}.goal-choice:has(input:focus-visible){outline:3px solid var(--ba-primary-ink);outline-offset:5px;border-radius:3px}.goals-screen :disabled{cursor:not-allowed}.goals-business,.goal-card{overflow-wrap:anywhere}.goals-screen[dir=ltr] .goals-footer svg{transform:rotate(180deg)}
@media(max-width:760px){.goals-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.goals-screen h1{font-size:26px}}
@media(max-width:480px){.goals-page :deep(.business-main){padding:26px 18px 34px}.goals-screen h1{font-size:24px}.goals-intro{font-size:13px;margin-bottom:22px}.goals-business{padding:15px 12px;gap:10px}.goals-grid{grid-template-columns:1fr;gap:10px}.goal-card{min-height:0;padding:13px 12px}.goal-card.is-priority{padding:12px 11px}.goal-choice{flex-direction:row;text-align:start;gap:10px;padding:0;flex-wrap:wrap;padding-inline-end:26px}.goal-choice svg{width:24px;height:24px}.goal-choice strong{flex:1;font-size:13px}.goal-description{width:100%;font-size:12px}.goal-choice input,.goal-read-status{top:16px;inset-inline-end:12px}.goal-priority,.goal-priority-action{margin:7px 0 0;align-self:flex-start}.goals-outcomes{margin-top:22px}.goal-priority-action{min-height:44px;padding:8px 4px}.goals-outcome-list{display:grid;grid-template-columns:1fr}.goals-outcome-list label{min-height:44px}.goals-footer{margin-top:30px}.goals-continue,.goals-previous{padding-inline:17px}}
.goals-screen{color:var(--ad-ink,#182f39);font-size:14px}.goals-screen h1{font-size:23px;line-height:1.5}.goals-intro{font-size:12px;line-height:1.8;color:var(--ad-muted,#5c706b);margin:6px 0 18px}.goals-business{padding:12px 15px;gap:10px;border:1px solid var(--ad-line,#dce5e0);border-radius:9px;background:var(--ad-soft,#edf4f0);margin-bottom:18px}.goals-business strong{display:block;font-size:14px}.goals-business small,.goals-business p,.goals-business a{font-size:12px;color:var(--ad-muted,#5c706b)}.goals-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.goal-card,.goal-card.is-priority{min-height:0;padding:15px 16px;border-radius:10px;background:var(--ad-paper,#fff);border-color:var(--ad-line,#dce5e0)}.goal-card.is-selected{border-color:var(--ad-accent,#155f53);background:var(--ad-soft,#edf4f0)}.goal-choice{display:grid;grid-template-columns:22px minmax(0,1fr);gap:6px 10px;align-items:start;text-align:start;padding:0;padding-inline-end:20px}.goal-choice>svg{width:21px;height:21px;margin-top:1px}.goal-choice strong{font-size:14px}.goal-choice .goal-description{grid-column:1/-1;font-size:12px;line-height:1.8;color:var(--ad-muted,#5c706b);margin-top:3px}.goal-choice input,.goal-read-status{inset-inline-end:12px;top:16px}.goal-priority,.goal-priority-action{margin:11px 0 0;align-self:flex-start;font-size:12px;min-height:28px}.goals-footer{margin-top:22px;gap:12px}.goals-continue,.goals-previous{min-height:37px;padding:7px 13px;font-size:12px;border-radius:7px}.goals-outcomes{margin-top:20px}.goals-outcomes legend{font-size:15px}.goals-outcome-list label{padding:8px 10px;min-height:37px;border-radius:7px;font-size:12px;border-color:var(--ad-line,#dce5e0)}.goals-note,.goals-feedback,.goals-error{font-size:12px}.goals-note,.goal-description{color:var(--ad-muted,#5c706b)}.goals-feedback{background:var(--ad-soft,#edf4f0)}.goals-outcome-list label.is-selected{background:var(--ad-soft,#edf4f0);color:var(--ad-accent,#155f53)}
@media(max-width:760px){.goals-screen h1{font-size:22px}.goal-priority-action,.goals-continue,.goals-previous,.goals-outcome-list label,.goals-business a{min-height:44px}.goals-business a{display:inline-flex;align-items:center}.goals-business{flex-wrap:wrap}}
@media(max-width:480px){.goals-grid{grid-template-columns:1fr}.goal-card,.goal-card.is-priority{padding:14px}.goal-choice{display:grid;grid-template-columns:22px minmax(0,1fr)}.goal-description{width:auto}.goals-footer{flex-wrap:wrap}}
</style>
