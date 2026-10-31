<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Menu, X, PanelRight } from '@lucide/vue';
import { locale } from '../spa/locale';
import AdministrationNavigation from './AdministrationNavigation.vue';
import './administration.css';
defineProps<{allowedPaths?: readonly string[]}>();
const drawer = ref<HTMLDialogElement | null>(null), toggle = ref<HTMLButtonElement | null>(null), open = ref(false);
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
let media: MediaQueryList | undefined;
function closeMenu() { drawer.value?.close(); open.value = false; }
function openMenu() { drawer.value?.showModal(); open.value = !!drawer.value?.open; }
function closed() { open.value = false; toggle.value?.focus(); }
function breakpoint() { if (media?.matches) closeMenu(); }
function outside(event: MouseEvent) { const element = drawer.value; if (!element || event.target !== element) return; const bounds = element.getBoundingClientRect(); if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeMenu(); }
function containFocus(event: KeyboardEvent) {
    const element = drawer.value;
    if (!element?.open || event.key !== 'Tab' || event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey) return;
    const focusable = Array.from(element.querySelectorAll<HTMLElement>('a[href],button,input,select,textarea,[tabindex]'))
        .filter(node => node.tabIndex >= 0 && !node.matches(':disabled') && node.getClientRects().length > 0 && getComputedStyle(node).visibility !== 'hidden');
    const first = focusable[0], last = focusable[focusable.length - 1], current = element.ownerDocument.activeElement;
    if (!first) { event.preventDefault(); element.focus(); return; }
    if (event.shiftKey && current === first) { event.preventDefault(); last!.focus(); }
    else if (!event.shiftKey && current === last) { event.preventDefault(); first.focus(); }
    else if (!focusable.includes(current as HTMLElement)) { event.preventDefault(); (event.shiftKey ? last! : first).focus(); }
}
onMounted(() => { media = matchMedia('(min-width: 1024px)'); media.addEventListener('change', breakpoint); });
onBeforeUnmount(() => { media?.removeEventListener('change', breakpoint); drawer.value?.close(); });
</script>
<template>
    <div data-admin-shell class="ba-admin-layout">
        <a class="ba-admin-skip" href="#administration-content">{{ copy('انتقل إلى المحتوى', 'Skip to content') }}</a>
        <header data-admin-header class="ba-admin-topbar">
            <button ref="toggle" type="button" data-admin-menu-toggle class="ba-admin-menu-toggle" :aria-label="copy('فتح قائمة الإدارة', 'Open administration menu')" :aria-expanded="open" aria-controls="administration-drawer" @click="openMenu"><Menu :size="21" aria-hidden="true" /></button>
            <slot name="header" />
        </header>
        <aside data-admin-sidebar class="ba-admin-sidebar" :aria-label="copy('التنقل الإداري', 'Administration navigation')">
            <div class="ba-admin-sidebar-title"><PanelRight :size="18" aria-hidden="true" /><span>{{ copy('مساحة الإدارة', 'Administration workspace') }}</span></div>
            <AdministrationNavigation :allowed-paths="allowedPaths" />
            <p class="ba-admin-sidebar-note">{{ copy('إدارة الفريق والخدمات وإعدادات المنصة من مكان واحد.', 'Manage your team, services, and platform settings in one place.') }}</p>
        </aside>
        <div id="administration-content" class="ba-admin-content" tabindex="-1"><slot /></div>
        <dialog id="administration-drawer" ref="drawer" data-admin-drawer class="ba-admin-drawer" :dir="locale === 'ar' ? 'rtl' : 'ltr'" aria-modal="true" aria-labelledby="administration-drawer-title" @click="outside" @keydown="containFocus" @close="closed" @cancel="open = false">
            <div class="ba-admin-drawer-heading"><strong id="administration-drawer-title">{{ copy('مساحة الإدارة', 'Administration workspace') }}</strong><button type="button" data-admin-drawer-close :aria-label="copy('إغلاق قائمة الإدارة', 'Close administration menu')" @click="closeMenu"><X :size="21" aria-hidden="true" /></button></div>
            <AdministrationNavigation :allowed-paths="allowedPaths" @select="closeMenu" />
        </dialog>
    </div>
</template>
