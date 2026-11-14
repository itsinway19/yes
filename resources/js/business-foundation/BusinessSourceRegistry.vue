<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp } from '../spa/transport';
import SourceRegistryPanel from '../sources/SourceRegistryPanel.vue';
import PendingCommands from '../sources/PendingCommands.vue';
import { current, read } from '../sources/state';
import { commandsFor } from '../sources/commands';
const props=defineProps<{businessId:string}>();
const t=(ar:string,en:string)=>locale.value==='ar'?ar:en;
const error=ref(false),loading=ref(false);let disposed=false;
onBeforeUnmount(()=>{disposed=true;});
const data=computed(()=>current.value && 'data' in current.value && current.value.data.business.public_id===props.businessId ? current.value.data:undefined);
const pending=computed(()=>commandsFor({kind:'business',publicId:props.businessId}).length>0);
async function refresh(){const identity=stamp();loading.value=true;error.value=false;try{await read(props.businessId,true);}catch{if(!disposed&&owned(identity))error.value=true;}finally{if(!disposed&&owned(identity))loading.value=false;}}
watch(()=>props.businessId,()=>void refresh(),{immediate:true});
</script>
<template><details class="workspace-card" data-business-source-registry><summary>{{ t('هوية المصادر وصلاحياتها','Source identities and rights') }}</summary>
<p v-if="loading" role="status">{{ t('جارٍ التحقق…','Checking…') }}</p><p v-if="error" role="alert">{{ t('تعذر تأهيل سجل المصادر. لا يعني ذلك غياب البيانات.','The source registry could not be qualified. This does not establish absence of data.') }} <button type="button" @click="refresh">{{ t('إعادة التحقق','Check again') }}</button></p>
<PendingCommands :business-id="businessId" @settled="refresh" />
<fieldset v-if="data && !error" :disabled="pending || loading"><SourceRegistryPanel :business-id="businessId" :candidate-id="data.sourceCandidateId" :can-request-authority="data.canRequestSourceAuthority" :sources="data.sources" /></fieldset>
</details></template>
