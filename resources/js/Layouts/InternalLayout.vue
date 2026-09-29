<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const menuOpen = ref(false);
</script>

<template>
    <div dir="rtl" class="min-h-screen bg-slate-100 text-slate-900">
        <header class="border-b border-slate-200 bg-white">
            <nav aria-label="الإدارة الداخلية" class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex min-h-16 items-center justify-between gap-4">
                    <Link :href="route($page.props.auth.canInternalAdministration ? 'internal.administration' : 'internal.security.devices')" class="flex items-center gap-3 font-semibold">
                        <ApplicationLogo class="h-8 w-auto fill-current" />
                        <span>إدارة Builderad</span>
                    </Link>
                    <button type="button" class="rounded-lg border px-3 py-2 text-sm sm:hidden" :aria-expanded="menuOpen" aria-controls="internal-menu" @click="menuOpen = !menuOpen">القائمة</button>
                    <div class="hidden items-center gap-5 text-sm sm:flex">
                        <Link :href="route('internal.commercial.index')" class="hover:underline">السياق التجاري والأسواق</Link>
                        <Link v-if="$page.props.auth.canInternalAdministration" :href="route('internal.administration')" :aria-current="route().current('internal.administration') ? 'page' : undefined" class="hover:underline">الإدارة</Link>
                        <Link v-if="$page.props.auth.canManageServices" :href="route('internal.services.index')" :aria-current="route().current('internal.services.index') ? 'page' : undefined" class="hover:underline">الخدمات</Link>
                        <Link :href="route('internal.security.devices')" :aria-current="route().current('internal.security.devices') ? 'page' : undefined" class="hover:underline">الأمان والأجهزة</Link>
                        <Link :href="route('internal.logout')" method="post" as="button" class="text-red-700 hover:underline">تسجيل الخروج</Link>
                    </div>
                </div>
                <div v-if="menuOpen" id="internal-menu" class="flex flex-col gap-3 border-t py-4 text-sm sm:hidden">
                    <Link :href="route('internal.commercial.index')">السياق التجاري والأسواق</Link>
                    <Link v-if="$page.props.auth.canInternalAdministration" :href="route('internal.administration')">الإدارة</Link>
                    <Link v-if="$page.props.auth.canManageServices" :href="route('internal.services.index')">الخدمات</Link>
                    <Link :href="route('internal.security.devices')">الأمان والأجهزة</Link>
                    <Link :href="route('internal.logout')" method="post" as="button" class="self-start text-red-700">تسجيل الخروج</Link>
                </div>
            </nav>
        </header>
        <div v-if="$slots.header" class="border-b border-slate-200 bg-white px-4 py-6 shadow-sm sm:px-6"><div class="mx-auto max-w-7xl"><slot name="header" /></div></div>
        <slot />
    </div>
</template>
