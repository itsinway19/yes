<script setup lang="ts">
import { nativeSourceReference, commandOwner, ownerKey } from './nativeOwner';
import { computed, onBeforeUnmount, ref } from 'vue';
import type { Provider } from './providers';
import { commandsFor, retry, type PendingCommand } from './commands';
import { TransportError } from '../spa/transport';
const props = defineProps<{ workspaceId?: string; businessId?: string; provider?: Provider }>();
const owner = nativeSourceReference(props);
const emit = defineEmits<{ settled: [] }>();
const items = computed(() => commandsFor(owner, props.provider));
const error = ref('');
const cancellation = new AbortController();
let active = true;
async function resolve(value: PendingCommand) {
    error.value = '';
    try { await retry(value, cancellation.signal); if (active) emit('settled'); }
    catch (failure) {
        if (!active) return;
        if (failure instanceof TransportError && failure.status === 502 && 'status' in failure.body && failure.body.status === 'PROVIDER_ACTION_REQUIRED') {
            error.value = 'رفض المزوّد تجديد الوصول. يلزم إعادة التفويض؛ لم ينجح التجديد.';
            emit('settled');
        } else error.value = 'لم نؤكد نتيجة الطلب. بقي الأمر الأصلي محفوظًا ما لم يرفضه الخادم بصورة حاسمة.';
    }
}
onBeforeUnmount(() => { active = false; cancellation.abort(); });
</script>
<template>
    <section v-if="items.length" class="my-4 rounded border border-amber-500 p-4" data-source-pending role="status">
        <h3>أوامر مصادر لم تُحسم</h3>
        <p>لا يُرسل أي أمر تلقائيًا. أعد إرسال الأمر الأصلي فقط لحسم نتيجته.</p>
        <div v-for="item in items" :key="item.key" class="mt-3">
            <p dir="ltr">{{ item.command.effect }} {{ 'source' in item.command ? item.command.source : '' }}</p>
            <button type="button" :disabled="item.busy" class="biz-link" @click="resolve(item)">إعادة إرسال الأمر الأصلي</button>
        </div>
        <p v-if="error" role="alert">{{ error }}</p>
    </section>
</template>
