<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { current } from './state';
import { reassessOwnerGrant } from './api';
import { bootstrap, owned, stamp } from '../spa/transport';
import { applicationDestination } from '../spa/destination';
import { locale } from '../spa/locale';

const data = computed(() => current.value && 'workspaces' in current.value.data ? current.value.data : undefined);
const busy = ref(''), error = ref('');
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
let alive = true;
onBeforeUnmount(() => { alive = false; });
async function reassess(workspace: string) {
    if (busy.value) return;
    const captured = stamp();
    busy.value = workspace; error.value = '';
    try {
        const result = await reassessOwnerGrant(workspace);
        if (alive && owned(captured)) location.assign(applicationDestination(result.redirect, location.origin, bootstrap.identity.binding.boundary));
    } catch {
        if (alive && owned(captured)) error.value = copy('تعذّرت إعادة تقييم الصلاحية. تحقّق من تسجيل الدخول الحديث وحالة النشاط.', 'Could not reassess authority. Check recent sign-in and business status.');
    } finally { busy.value = ''; }
}
</script>

<template>
    <main class="workspace-page" data-spa-private data-workspaces="index">
        <p class="workspace-eyebrow">{{ copy('أنشطتي', 'My businesses') }}</p>
        <h1>{{ copy('اختر النشاط الذي تعمل عليه', 'Choose your business') }}</h1>
        <p>{{ copy('لكل نشاط سياقه ومصادره الخاصة. اختر نشاطًا للمتابعة.', 'Each business has its own context and sources. Choose one to continue.') }}</p>
        <div class="workspace-list">
            <RouterLink v-for="workspace in data?.workspaces" :key="workspace.public_id" :to="`/workspaces/${workspace.public_id}`" class="workspace-row">
                <span>{{ workspace.name }}</span><span>{{ copy('متابعة', 'Continue') }}</span>
            </RouterLink>
            <p v-if="!data?.workspaces.length">{{ copy('لا توجد أنشطة متاحة لك حاليًا.', 'No businesses are available to you yet.') }}</p>
        </div>
        <section v-if="data?.reassessableWorkspaces.length" class="workspace-list">
            <h2>{{ copy('إعادة تقييم صلاحية المالك', 'Reassess owner authority') }}</h2>
            <p>{{ copy('بعد تغيير البريد وتأكيده، راجع أنشطتك القائمة بدخول حديث. لا تُعاد أي منحة سُحبت أو انتهت صلاحيتها.', 'After changing and verifying your email, review existing businesses with a recent sign-in. Revoked or expired grants are not restored.') }}</p>
            <p v-if="error" role="alert">{{ error }}</p>
            <div v-for="workspace in data.reassessableWorkspaces" :key="workspace.public_id" class="workspace-row">
                <span>{{ workspace.name }}</span>
                <button type="button" :disabled="!!busy" @click="reassess(workspace.public_id)">{{ busy === workspace.public_id ? copy('جارٍ التقييم…', 'Reassessing…') : copy('إعادة تقييم الصلاحية', 'Reassess authority') }}</button>
            </div>
        </section>
        <RouterLink class="workspace-action" to="/business/start?new=1">{{ copy('إضافة نشاط', 'Add a business') }}</RouterLink>
    </main>
</template>
