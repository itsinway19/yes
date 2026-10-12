<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { logoutKey } from '@/logout';
import { captureIdentity, trustIdentityState, type IdentityBinding } from '@/identityProtocol';
import { computed, inject, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { ChartNoAxesColumnIncreasing, Users, Lightbulb, Settings, Store, ArrowLeft } from '@lucide/vue';
import AdvertiserLayout from '@/Layouts/AdvertiserLayout.vue';
import { useGoalsCopy } from '@/localization/goals';
import { canContinue, cloneGoals, goalSignature, setPriority, toggleGoal, toggleResult, type GoalCatalog, type GoalValue } from '@/Components/Business/goalSelection';
type Preferences = { value: GoalValue; version: number };
const props = defineProps<{ workspace: { public_id: string; name: string }; business: { type: { name_ar: string; name_en: string } | null; canManage: boolean }; preferences: Preferences; catalog: GoalCatalog }>();
const { locale, t, localized } = useGoalsCopy();
const page = usePage();
const logout = inject(logoutKey, undefined);
const historyKey = computed(() => {
    const auth = page.props.auth as { user?: { id: number }; sessionContextId?: string } | undefined;
    return `advertiser-goals:${auth?.user?.id}:${auth?.sessionContextId}:${props.workspace.public_id}`;
});
type RememberedGoals = { baseline: Preferences; draft: GoalValue; identity: IdentityBinding };
function restoreDraft(key: string): RememberedGoals | undefined {
    const stored = props.business.canManage ? router.restore(key) as RememberedGoals | undefined : undefined;
    return stored && trustIdentityState(stored.identity) ? stored : undefined;
}
const remembered = restoreDraft(historyKey.value);
let draftIdentity = { ...captureIdentity().binding };
const ownsDraft = () => trustIdentityState(draftIdentity);
const baseline = ref<Preferences>(JSON.parse(JSON.stringify(remembered?.baseline ?? props.preferences)));
const draft = reactive(cloneGoals(remembered?.draft ?? props.preferences.value));
const busy = ref(false), reviewing = ref(false), conflict = ref(false), error = ref('');
const reviewed = ref<Preferences | null>(null);
const dirty = computed(() => goalSignature(draft) !== goalSignature(baseline.value.value));
const viewedGoal = ref<string | null>(null);
const priorityGoal = computed(() => props.catalog.find(goal => goal.id === (props.business.canManage ? draft.priority : viewedGoal.value ?? draft.priority)));
function viewResults(id: string) { if (!props.business.canManage && draft.goals.includes(id)) viewedGoal.value = id; }
const icons = { sales: ChartNoAxesColumnIncreasing, customers: Users, growth: Lightbulb, efficiency: Settings };
const mutable = computed(() => props.business.canManage && !busy.value && !reviewing.value);
watch(() => props.preferences, preferences => {
    if (!ownsDraft() || dirty.value || busy.value || conflict.value) return;
    baseline.value = JSON.parse(JSON.stringify(preferences)); Object.assign(draft, cloneGoals(preferences.value));
}, { deep: true });
watch(historyKey, key => {
    const restored = restoreDraft(key);
    draftIdentity = { ...captureIdentity().binding };
    baseline.value = JSON.parse(JSON.stringify(restored?.baseline ?? props.preferences));
    Object.assign(draft, cloneGoals(restored?.draft ?? props.preferences.value));
    viewedGoal.value = null; reviewed.value = null; conflict.value = false; error.value = '';
});
watch([draft, baseline], () => {
    if (!props.business.canManage || !ownsDraft()) return;
    router.remember({ baseline: JSON.parse(JSON.stringify(baseline.value)), draft: cloneGoals(draft), identity: { ...draftIdentity } }, historyKey.value);
}, { deep: true, flush: 'post' });
function choose(id: string) { if (mutable.value && ownsDraft()) toggleGoal(draft, id); }
function prioritize(id: string) { if (mutable.value && ownsDraft()) setPriority(draft, id); }
function outcome(goal: string, id: string) { if (mutable.value && ownsDraft()) toggleResult(draft, goal, id); }
async function save() {
    if (!mutable.value || !ownsDraft() || !canContinue(draft) || conflict.value) return;
    const workspace = props.workspace.public_id;
    busy.value = true; error.value = '';
    try {
        const response = await axios.post<Preferences>(route('workspaces.goals.update', workspace), { value: cloneGoals(draft), version: baseline.value.version }, { headers: { Accept: 'application/json' } });
        if (!ownsDraft() || workspace !== props.workspace.public_id) return;
        baseline.value = response.data; Object.assign(draft, cloneGoals(response.data.value));
        busy.value = false;
        router.visit(route('workspaces.show', workspace));
    } catch (failure) {
        if (!ownsDraft() || workspace !== props.workspace.public_id) return;
        const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;
        if (status === 409) { conflict.value = true; reviewed.value = null; }
        else if (status === 403) error.value = t('denied');
        else if (status === 422 && axios.isAxiosError(failure)) error.value = Object.values(failure.response?.data?.errors ?? {}).flat().join(' ') || t('failed');
        else error.value = t('failed');
    } finally { busy.value = false; }
}
function review() {
    if (!mutable.value || !ownsDraft()) return;
    reviewing.value = true;
    router.reload({ only: ['preferences', 'business'], onSuccess: () => { reviewed.value = JSON.parse(JSON.stringify(props.preferences)); }, onFinish: () => { reviewing.value = false; } });
}
function adopt() {
    if (!mutable.value || !ownsDraft() || !reviewed.value) return;
    baseline.value = JSON.parse(JSON.stringify(reviewed.value)); reviewed.value = null; conflict.value = false; error.value = '';
}
let removeBefore: (() => void) | undefined;
const canLeave = () => !busy.value && !reviewing.value && (!dirty.value || window.confirm(t('unsaved')));
const beforeUnload = (event: BeforeUnloadEvent) => { if (!logout?.started() && dirty.value) { event.preventDefault(); event.returnValue = ''; } };
onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    removeBefore = router.on('before', event => {
        // Locale changes preserve this mounted draft rather than navigate away from it.
        const localePath = new URL(route('locale.update'), window.location.origin).pathname;
        if (event.detail.visit.url.pathname === localePath) return;
        if (reviewing.value && event.detail.visit.url.pathname === window.location.pathname) return;
        if (!canLeave()) event.preventDefault();
    });
});
onBeforeUnmount(() => { removeBefore?.(); window.removeEventListener('beforeunload', beforeUnload); });
</script>

<template>
 <div class="goals-page">
  <Head :title="t('title')" />
  <AdvertiserLayout :business-name="workspace.name" :workspace-id="workspace.public_id" preserve-drafts-on-locale-change :confirm-logout="canLeave">
   <section class="goals-screen" :dir="locale === 'ar' ? 'rtl' : 'ltr'" :aria-busy="busy || reviewing">
    <p class="biz-eyebrow">{{ t('eyebrow') }}</p>
    <h1>{{ t('title') }}</h1><p class="goals-intro">{{ t('intro') }}</p>
    <div class="goals-business"><Store :size="25" aria-hidden="true" /><div><strong>{{ workspace.name }}</strong><p v-if="business.type">{{ localized(business.type) }}</p></div><Link v-if="business.canManage" :href="route('workspaces.business.edit', workspace.public_id)">{{ t('edit') }}</Link></div>
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
     <footer class="goals-footer"><button v-if="business.canManage" type="submit" data-goals="continue" class="goals-continue" :disabled="!mutable || !canContinue(draft) || conflict">{{ busy ? t('saving') : t('continue') }}<ArrowLeft :size="18" aria-hidden="true" /></button><Link :href="route('workspaces.business.edit', workspace.public_id)" class="goals-previous">{{ t('previous') }}<ArrowLeft :size="16" aria-hidden="true" /></Link></footer>
    </form>
   </section>
  </AdvertiserLayout>
 </div>
</template>

<style scoped>
.goals-page :deep(.business-shell){background:#fff}.goals-page :deep(.business-main){max-width:1040px;padding-top:44px}.goals-screen{color:#142e3d}.goals-screen h1{margin:0;font-size:30px}.goals-intro{color:#586b79;font-size:15px;margin:7px 0 30px}.goals-business{display:flex;align-items:center;gap:16px;background:#edf5f3;padding:19px 22px;border-radius:6px;margin-bottom:24px}.goals-business strong{font-size:15px}.goals-business p{font-size:13px;color:#586b79;margin:3px 0 0}.goals-business a{margin-inline-start:auto;color:#00665c;text-decoration:underline;text-underline-offset:4px}.goals-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;border:0;padding:0;margin:0}.goal-card{position:relative;border:1px solid #d9e3e7;border-radius:6px;background:#fff;min-width:0;display:flex;flex-direction:column;padding:16px 14px;min-height:210px}.goal-card.is-selected{border-color:#006c62;background:#f4faf7}.goal-card.is-priority{border-width:2px;padding:15px 13px}.goal-choice{display:flex;flex:1;align-items:center;flex-direction:column;gap:13px;cursor:pointer;padding-top:7px;text-align:center}.goal-choice input,.goal-read-status{position:absolute;inset-inline-end:12px;top:12px}.goals-screen input{color:#006c62;accent-color:#006c62;width:17px;height:17px;flex-shrink:0}.goal-choice strong{font-size:14px;line-height:1.7}.goal-description{font-size:13px;line-height:1.85;color:#586b79}.goal-priority,.goal-priority-action{margin:12px auto 0;font-size:12px;line-height:1.6;color:#005e54}.goal-priority{background:#d9f3e2;padding:3px 9px;border-radius:4px;font-weight:650}.goal-priority-action{text-decoration:underline;text-underline-offset:3px;min-height:30px}.goal-priority-action[aria-pressed=true]{font-weight:700;text-decoration-thickness:2px}.goals-priority-note{margin-top:22px}.goals-outcomes legend{font-size:16px;font-weight:700;margin:0 0 10px}.goals-outcomes{border:0;padding:0;margin:26px 0 0}.goals-outcome-list{display:flex;flex-wrap:wrap;gap:9px;margin-top:10px}.goals-outcome-list label{display:flex;align-items:center;gap:9px;padding:11px 12px;border:1px solid #d9e3e7;border-radius:5px;font-size:13px;cursor:pointer;line-height:1.7}.goals-outcome-list label.is-selected{color:#005e54;border-color:#007168;background:#f4faf7}.goals-note{color:#586b79;font-size:13px;margin:7px 0;line-height:1.8}.goals-feedback{border-inline-start:3px solid #006c62;padding:14px;background:#f1f7f5;margin:22px 0;font-size:14px}.goals-feedback button{margin:12px 0}.goals-feedback ul{list-style:disc;padding-inline-start:22px}.goals-error{color:#a12c27;font-size:14px;margin-top:20px}.goals-footer{display:flex;justify-content:space-between;gap:20px;margin-top:46px}.goals-continue,.goals-previous{display:flex;align-items:center;justify-content:center;gap:12px;border-radius:5px;font-size:14px;font-weight:650;min-height:44px;padding:10px 22px}.goals-continue{color:#fff;background:#006c62}.goals-continue:disabled{background:#aab8bd;cursor:not-allowed}.goals-previous{border:1px solid #cbd7de}.goals-screen button:focus-visible,.goals-screen input:focus-visible,.goals-screen a:focus-visible{outline:3px solid #006c62;outline-offset:4px}.goal-choice:has(input:focus-visible){outline:3px solid #006c62;outline-offset:5px;border-radius:3px}.goals-screen :disabled{cursor:not-allowed}.goals-business,.goal-card{overflow-wrap:anywhere}.goals-screen[dir=ltr] .goals-footer svg{transform:rotate(180deg)}
@media(max-width:760px){.goals-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.goals-screen h1{font-size:26px}}
@media(max-width:480px){.goals-page :deep(.business-main){padding:26px 18px 34px}.goals-screen h1{font-size:24px}.goals-intro{font-size:13px;margin-bottom:22px}.goals-business{padding:15px 12px;gap:10px}.goals-grid{grid-template-columns:1fr;gap:10px}.goal-card{min-height:0;padding:13px 12px}.goal-card.is-priority{padding:12px 11px}.goal-choice{flex-direction:row;text-align:start;gap:10px;padding:0;flex-wrap:wrap;padding-inline-end:26px}.goal-choice svg{width:24px;height:24px}.goal-choice strong{flex:1;font-size:13px}.goal-description{width:100%;font-size:12px}.goal-choice input,.goal-read-status{top:16px;inset-inline-end:12px}.goal-priority,.goal-priority-action{margin:7px 0 0;align-self:flex-start}.goals-outcomes{margin-top:22px}.goal-priority-action{min-height:44px;padding:8px 4px}.goals-outcome-list{display:grid;grid-template-columns:1fr}.goals-outcome-list label{min-height:44px}.goals-footer{margin-top:30px}.goals-continue,.goals-previous{padding-inline:17px}}
</style>
