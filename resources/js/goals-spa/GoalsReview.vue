<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { current } from './state';
import { goalsPath } from './paths';
import { locale } from '../spa/locale';
import { useGoalsCopy } from '../localization/goalsCopy';
import AdvertiserWorkspaceLayout from '../business-foundation/AdvertiserWorkspaceLayout.vue';
const { t, localized } = useGoalsCopy(locale);
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
function goalLabel(goalId: string) {
    const goal = current.value?.catalog.find(goal => goal.id === goalId);
    return goal ? localized(goal) : copy('هدف محفوظ غير متاح في القائمة الحالية', 'Saved goal unavailable in the current catalog');
}
function resultLabel(goalId: string, resultId: string) {
    const outcome = current.value?.catalog.find(goal => goal.id === goalId)?.results.find(result => result.id === resultId);
    return outcome ? localized(outcome) : copy('نتيجة محفوظة غير متاحة في القائمة الحالية', 'Saved outcome unavailable in the current catalog');
}
</script>
<template>
    <div v-if="current" data-spa="review" data-spa-private :data-goals-workspace="current.workspace.public_id">
      <AdvertiserWorkspaceLayout :context="current.workspace.name" :workspace-id="current.workspace.public_id" workspace-context>
        <nav class="advertiser-breadcrumb" :aria-label="copy('مسار الصفحة', 'Breadcrumb')"><RouterLink to="/businesses">{{ copy('أنشطتي', 'My businesses') }}</RouterLink><span aria-hidden="true">/</span><RouterLink to="/workspaces">{{ copy('مساحات العمل', 'Workspaces') }}</RouterLink><span aria-hidden="true">/</span><RouterLink :to="`/workspaces/${current.workspace.public_id}`">{{ current.workspace.name }}</RouterLink><span aria-hidden="true">/</span><span aria-current="page">{{ copy('الأهداف المحفوظة', 'Saved goals') }}</span></nav>
        <header class="bf-heading"><div><p class="bf-eyebrow">{{ copy('الأهداف', 'Goals') }}</p><h1>{{ t('savedVersion') }}</h1></div></header>
        <section class="bf-card">
        <p v-if="!current.preferences.value.goals.length">{{ t('empty') }}</p>
        <ul v-else>
            <li v-for="goalId in current.preferences.value.goals" :key="goalId" class="saved-goal">
                <h3>{{ goalLabel(goalId) }}</h3>
                <p v-if="current.preferences.value.priority === goalId">{{ t('priority') }}</p>
                <ul><li v-for="resultId in current.preferences.value.results[goalId] ?? []" :key="resultId">{{ resultLabel(goalId, resultId) }}</li></ul>
            </li>
        </ul>
        </section>
        <div class="saved-navigation"><RouterLink class="bf-primary" :to="goalsPath(current.workspace.public_id)">{{ copy('العودة إلى الأهداف', 'Back to goals') }}</RouterLink><RouterLink class="bf-link" :to="`/workspaces/${current.workspace.public_id}`">{{ copy('فتح المنصات المتصلة', 'Open connected platforms') }}</RouterLink></div>
      </AdvertiserWorkspaceLayout>
    </div>
</template>
<style scoped>.saved-goal{margin:24px 0}.saved-goal h3{font-weight:650;font-size:18px}.saved-goal ul{list-style:disc;padding-inline-start:24px;line-height:1.9}.saved-navigation{display:flex;gap:24px;flex-wrap:wrap;margin-top:32px;color:var(--ba-primary-ink);text-decoration:underline}</style>
