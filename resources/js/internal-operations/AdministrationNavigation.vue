<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { Users, ContactRound, ListTree, KeyRound, BriefcaseBusiness, Globe2, Palette, ShieldCheck } from '@lucide/vue';
import { locale } from '../spa/locale';
import { administrationNavigation } from './administrationNavigation';
const props = defineProps<{allowedPaths?: readonly string[]}>();
const groups = computed(() => administrationNavigation.map(group => ({...group,links:group.links.filter(link => props.allowedPaths?.includes(link.to))})).filter(group => group.links.length));
const emit = defineEmits<{ select: [] }>();
const icons = { users: Users, contact: ContactRound, catalog: ListTree, service: KeyRound, briefcase: BriefcaseBusiness, globe: Globe2, palette: Palette, shield: ShieldCheck };
</script>
<template>
    <nav data-admin-nav class="ba-admin-navigation" :aria-label="locale === 'ar' ? 'أقسام الإدارة' : 'Administration sections'">
        <section v-for="group in groups" :key="group.en" class="ba-admin-nav-group">
            <h2>{{ locale === 'ar' ? group.ar : group.en }}</h2>
            <RouterLink v-for="link in group.links" :key="link.to" :to="link.to" custom v-slot="{ href, navigate, isExactActive }">
                <a :href="href" :aria-current="isExactActive ? 'page' : undefined" @click="navigate($event); emit('select')">
                    <component :is="icons[link.icon]" :size="18" :stroke-width="1.7" aria-hidden="true" />
                    <span>{{ locale === 'ar' ? link.ar : link.en }}</span>
                </a>
            </RouterLink>
        </section>
    </nav>
</template>
