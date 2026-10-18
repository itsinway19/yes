<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { current } from './state';
import { goalsPath } from './paths';
import { locale } from '../spa/locale';
import { useGoalsCopy } from '../localization/goalsCopy';
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
    <main v-if="current" class="workspace-page" data-spa="review" data-spa-private :data-goals-workspace="current.workspace.public_id">
        <h1>{{ current.workspace.name }}</h1><h2>{{ t('savedVersion') }}</h2>
        <p v-if="!current.preferences.value.goals.length">{{ t('empty') }}</p>
        <ul v-else>
            <li v-for="goalId in current.preferences.value.goals" :key="goalId" class="saved-goal">
                <h3>{{ goalLabel(goalId) }}</h3>
                <p v-if="current.preferences.value.priority === goalId">{{ t('priority') }}</p>
                <ul><li v-for="resultId in current.preferences.value.results[goalId] ?? []" :key="resultId">{{ resultLabel(goalId, resultId) }}</li></ul>
            </li>
        </ul>
        <div class="saved-navigation"><RouterLink :to="goalsPath(current.workspace.public_id)">{{ copy('العودة إلى الأهداف', 'Back to goals') }}</RouterLink><a :href="`/workspaces/${current.workspace.public_id}`">{{ t('continue') }}</a></div>
    </main>
</template>
<style scoped>.saved-goal{margin:24px 0}.saved-goal h3{font-weight:650;font-size:18px}.saved-goal ul{list-style:disc;padding-inline-start:24px;line-height:1.9}.saved-navigation{display:flex;gap:24px;flex-wrap:wrap;margin-top:32px;color:#14635b;text-decoration:underline}</style>
