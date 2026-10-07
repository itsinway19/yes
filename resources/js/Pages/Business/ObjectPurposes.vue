<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdvertiserLayout from '@/Layouts/AdvertiserLayout.vue';
import ObjectPurposeEditor from '@/Components/Business/ObjectPurposeEditor.vue';
import { useBusinessCopy } from '@/localization/business';
import type { PurposeItem, PurposeIntent } from '@/types/object-purpose';
import { computed } from 'vue';
const props = defineProps<{workspace: {public_id:string;name:string}; projection: {state:string;items:PurposeItem[];current_context_version:number;latest_dtm_event_context_version:number;effective_at:string;known_at:string}; selectedPurpose:string|null; business:{currentIntent:{value:PurposeIntent|null;version:number}}}>();
const { t } = useBusinessCopy();
const selected = computed(() => props.projection.items.find(item => item.purpose_id === props.selectedPurpose) ?? null);
</script>
<template>
<Head :title="t('purposeTitle')" />
<AdvertiserLayout :business-name="workspace.name" :workspace-id="workspace.public_id" :preserve-drafts-on-locale-change="true">
    <h1>{{ t('purposeTitle') }}</h1><p>{{ workspace.name }}</p>
    <Link class="biz-link" :href="route('workspaces.business.edit',workspace.public_id)">{{ t('context') }}</Link>
    <p v-if="projection.state === 'UNKNOWN'">{{ t('purposeUnknown') }}</p>
    <ul v-if="!selectedPurpose"><li v-for="item in projection.items" :key="item.purpose_id"><Link class="biz-link" :href="route('workspaces.object-purposes.show',[workspace.public_id,item.purpose_id])">{{ t('purposeRead') }} · {{ item.applicable?.snapshot.meaning ?? item.purpose_id }} · {{ item.head_version }}</Link></li></ul>
    <section v-if="selected" class="grid gap-4" style="overflow-wrap:anywhere">
        <p>{{ t('purposeRevision') }} · {{ selected.applicable?.version ?? '—' }} · {{ selected.purpose_id }}</p>
        <p v-if="selected.relationship_revalidation_required" role="status">{{ t('purposeRevalidation') }}</p>
        <p v-if="selected.dtm_standing === 'HISTORICAL_ATTESTATION'">{{ t('purposeHistorical') }}</p>
        <p v-if="selected.continuity === 'CONDITION_CONTINUITY_NOT_ESTABLISHED'">{{ t('purposeConditional') }}</p>
        <h2>{{ t('purposeHistory') }}</h2>
        <article v-for="revision in selected.history" :key="revision.revision_id" class="border p-4 grid gap-2">
            <h3>{{ t('purposeRevision') }} {{ revision.version }} · {{ revision.revision_id }}</h3>
            <p>{{ revision.operation }} · {{ revision.reason }}</p><p>{{ revision.snapshot.meaning }}</p><p>{{ revision.snapshot.qualifications }}</p><p>{{ revision.snapshot.phase }}</p>
            <p>{{ t('purposeStart') }}: {{ revision.effective_from }} · {{ t('purposeEnd') }}: {{ revision.effective_until ?? '—' }}</p>
            <p>{{ t('purposeAccepted') }}: {{ revision.accepted_at }} · {{ t('purposeConfirmedActor') }}: {{ revision.actor_user_id }}</p>
            <p>{{ t('purposeSpeaker') }}: {{ revision.snapshot.provenance.speaker ?? '—' }} · {{ revision.snapshot.provenance.statement_at ?? '—' }}</p>
            <p>{{ t('purposeSource') }}: {{ revision.snapshot.provenance.source }}</p><p>{{ revision.snapshot.provenance.identity_basis }}</p><p>{{ revision.snapshot.provenance.authority_basis }}</p><p>{{ revision.snapshot.provenance.effective_basis }}</p><p>{{ revision.snapshot.provenance.historical_support }}</p>
            <div v-for="(objects,index) in [revision.snapshot.included_objects,revision.snapshot.excluded_objects]" :key="index"><h4>{{ index === 0 ? t('purposeIncluded') : t('purposeExcluded') }}</h4><ul><li v-for="(object,i) in objects" :key="i">{{ object.provider }} · {{ object.account_namespace }} · {{ object.account_id }} · {{ object.object_kind }} · {{ object.object_id }} · {{ object.incarnation ?? '—' }}</li></ul></div>
            <p v-if="revision.snapshot.dtm">{{ t('purposeDtm') }} · {{ revision.snapshot.dtm.mode === 'historical_attestation' ? t('purposeHistorical') : (revision.snapshot.dtm.mode === 'current_alignment' ? t('purposeAlignment') : t('purposeDependency')) }} · {{ t('purposeContextVersion') }} {{ revision.snapshot.dtm.version }}: {{ revision.snapshot.dtm.countries.join(', ') }} · {{ revision.snapshot.dtm.subset.join(', ') }} · {{ revision.snapshot.dtm.statement }}</p>
            <p v-if="revision.snapshot.intent">{{ t('purposeIntent') }} · {{ revision.snapshot.intent.version }} · {{ revision.snapshot.intent.statement }}</p>
            <p>{{ revision.snapshot.end_condition }}</p><p>{{ revision.snapshot.limitations }}</p><p v-for="(conflict,i) in revision.snapshot.conflicts" :key="i" role="status">{{ conflict }}</p>
            <p v-if="revision.supersedes_revision_id">{{ t('purposeAffected') }}: {{ revision.supersedes_revision_id }} · {{ revision.affected_revision_ids.join(', ') }}</p>
        </article>
    </section>
    <ObjectPurposeEditor :key="selectedPurpose ?? 'new'" :workspace-id="workspace.public_id" :purpose="selected" :context-version="projection.current_context_version" :dtm-transition-version="projection.latest_dtm_event_context_version" :current-intent="business.currentIntent" />
</AdvertiserLayout>
</template>
