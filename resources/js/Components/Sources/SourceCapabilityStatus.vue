<script setup lang="ts">
import { providerCopy } from '../../sources/providerCopy';
defineProps<{ capability: { state: string; reason: string; last_verified_at: string | null } }>();
</script>

<template>
    <div>
        <p dir="ltr" class="mt-2 text-sm font-semibold">{{ capability.state }} — {{ capability.reason }}</p>
        <p class="mt-2 text-sm">{{ providerCopy('آخر تحقق مستقل:') }} {{ capability.last_verified_at ?? providerCopy('لا يوجد تحقق مستقل') }}</p>
    </div>
</template>
