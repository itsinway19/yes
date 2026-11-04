<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { commercialHistory } from './commercialHistory';
import AdvertiserWorkspaceLayout from '../business-foundation/AdvertiserWorkspaceLayout.vue';
import { locale } from '../spa/locale';
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const kinds = ['context', 'markets', 'intent', 'preferences'] as const;
const label = (kind: string) => ({ context: t('الملف السابق', 'Previous profile'), markets: t('الأسواق السابقة', 'Previous markets'), intent: t('النية السابقة', 'Previous intent'), preferences: t('التفضيلات السابقة', 'Previous preferences') })[kind] ?? kind;
const value = (item: Record<string, unknown>) => item.new_name ?? item.after_codes ?? item.after_value ?? '—';
</script>
<template>
    <AdvertiserWorkspaceLayout v-if="commercialHistory" :workspace-id="commercialHistory.data.workspace.public_id" :context="commercialHistory.data.workspace.name" workspace-context>
        <section data-spa-private data-commercial-history>
            <h1>{{ t('التاريخ التجاري السابق', 'Previous commercial history') }}</h1>
            <p>{{ t('سجل محفوظ للقراءة. الحقائق الحالية تدار داخل النشاط التجاري المستقل، ولا نفترض ارتباط هذا السجل بأي نشاط.', 'A preserved read-only record. Current facts belong to the independent business; no association with a business is inferred.') }}</p>
            <RouterLink to="/businesses">{{ t('فتح أنشطتي التجارية', 'Open my businesses') }}</RouterLink>
            <nav :aria-label="t('نوع التاريخ', 'History kind')"><RouterLink v-for="kind in kinds" :key="kind" :to="{ path: `/workspaces/${commercialHistory.data.workspace.public_id}/commercial-history`, query: { kind } }" :aria-current="commercialHistory.data.kind === kind ? 'page' : undefined">{{ label(kind) }}</RouterLink></nav>
            <h2>{{ label(commercialHistory.data.kind) }}</h2>
            <p v-if="!commercialHistory.data.items.length">{{ t('لا توجد مراجعات محفوظة من هذا النوع ضمن صلاحياتك الحالية.', 'No revisions of this kind are available under your current rights.') }}</p>
            <ol><li v-for="item in commercialHistory.data.items" :key="item.id" style="overflow-wrap:anywhere"><time>{{ item.occurred_at }}</time> · {{ t('الإصدار', 'Version') }} {{ item.version ?? item.after_version }}<pre style="white-space:pre-wrap">{{ value(item) }}</pre></li></ol>
            <RouterLink v-if="commercialHistory.data.next_after !== null" :to="{ path: `/workspaces/${commercialHistory.data.workspace.public_id}/commercial-history`, query: { kind: commercialHistory.data.kind, after: commercialHistory.data.next_after } }">{{ t('المراجعات التالية', 'Next revisions') }}</RouterLink>
        </section>
    </AdvertiserWorkspaceLayout>
</template>
