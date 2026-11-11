<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, Teleport, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { ChevronDown, Menu, X } from '@lucide/vue';
import BrandLogo from '../Components/BrandLogo.vue';
import BusinessSwitcher from './BusinessSwitcher.vue';
import { businessChoiceDestination, createBusinessChoices, type BusinessChoice } from './businessChoices';
import { documentStatus } from '../spa/transport';
import { locale } from '../spa/locale';
import './advertiser.css';

const props = defineProps<{ context?: string; businessSelected?: boolean; selection?: BusinessChoice }>();
const route = useRoute(), router = useRouter();
const drawer = ref<HTMLDialogElement>(), menu = ref<HTMLButtonElement>(), content = ref<HTMLElement>();
const open = ref(false), headerTarget = ref(false), desktop = ref(true);
const choices = createBusinessChoices();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
let viewport: MediaQueryList | undefined;
let resizing = false;
function closeNavigation() { drawer.value?.close(); open.value = false; }
function openNavigation() { drawer.value?.showModal(); open.value = !!drawer.value?.open; }
function closed() { open.value = false; if (!desktop.value && !resizing) menu.value?.focus({ preventScroll: true }); }
function breakpoint() {
    desktop.value = viewport?.matches ?? true;
    if (!viewport?.matches || !drawer.value?.open) return;
    resizing = true; closeNavigation();
    void nextTick(() => {
        (document.getElementById('businessSelect') ?? content.value)?.focus({ preventScroll: true });
        resizing = false;
    });
}
function outside(event: MouseEvent) {
    const element = drawer.value;
    if (!element || event.target !== element) return;
    const box = element.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) closeNavigation();
}
function closeForLink(event: MouseEvent) { if ((event.target as Element | null)?.closest('a[href],button[data-workspace-section]')) closeNavigation(); }
async function selectBusiness(choice: BusinessChoice) {
    // The drawer closes before existing departure/admission guards run.
    closeNavigation(); await router.push(businessChoiceDestination(choice));
}
watch(() => route.fullPath, closeNavigation);
watch(documentStatus, value => { if (value === 'closed') { closeNavigation(); choices.clear(); } }, { flush: 'sync' });
onMounted(() => {
    headerTarget.value = !!document.getElementById('advertiser-header-context');
    viewport = window.matchMedia('(min-width: 851px)'); desktop.value = viewport.matches; viewport.addEventListener('change', breakpoint);
    void choices.load();
});
onBeforeUnmount(() => { choices.dispose(); viewport?.removeEventListener('change', breakpoint); drawer.value?.close(); });
</script>
<template>
    <div class="advertiser-layout" data-advertiser-shell>
        <a class="advertiser-skip" href="#advertiser-content">{{ t('انتقل إلى المحتوى', 'Skip to content') }}</a>
        <Teleport v-if="headerTarget" to="#advertiser-header-context">
            <div class="advertiser-header-selection" data-spa-private>
            <button ref="menu" type="button" class="advertiser-menu-toggle" data-advertiser-menu-toggle :aria-label="t('فتح القائمة', 'Open navigation')" :aria-expanded="open" aria-controls="advertiser-drawer" @click="openNavigation"><Menu aria-hidden="true" /></button>
            <button v-if="businessSelected && selection" type="button" class="advertiser-mobile-identity" data-mobile-business-identity data-business-name :aria-label="t('تغيير النشاط الحالي', 'Change current business') + ' · ' + selection.name" :aria-expanded="open" aria-controls="advertiser-drawer" @click="openNavigation"><span>{{ selection.name }}</span><ChevronDown aria-hidden="true" /></button>
            <span class="advertiser-header-label">{{ t('لوحة المعلن', 'Advertiser dashboard') }}</span>
            </div>
        </Teleport>
        <aside class="advertiser-sidebar" data-advertiser-sidebar :aria-label="t('التنقل الرئيسي', 'Main navigation')">
            <div v-if="desktop" class="advertiser-sidebar-body">
                <BrandLogo to="/businesses" />
                <BusinessSwitcher id="businessSelect" :items="choices.items.value" :selected="selection" :phase="choices.phase.value" :more="!!choices.nextCursor.value" @select="selectBusiness" @reload="choices.load()" @more="choices.load(true)" />
                <slot name="navigation" />
                <RouterLink class="advertiser-account-link" to="/profile">{{ t('الحساب والأمان', 'Account and security') }}</RouterLink>
            </div>
        </aside>
        <dialog id="advertiser-drawer" ref="drawer" class="advertiser-drawer" data-advertiser-drawer :dir="locale === 'ar' ? 'rtl' : 'ltr'" :aria-label="t('قائمة المعلن', 'Advertiser navigation')" @close="closed" @cancel="open = false" @click="outside">
            <div v-if="!desktop" class="advertiser-drawer-body" @click="closeForLink">
                <button type="button" class="advertiser-drawer-close" data-advertiser-drawer-close :aria-label="t('إغلاق القائمة', 'Close navigation')" @click="closeNavigation"><X aria-hidden="true" /></button>
                <BrandLogo to="/businesses" />
                <BusinessSwitcher id="drawerBusinessSelect" :items="choices.items.value" :selected="selection" :phase="choices.phase.value" :more="!!choices.nextCursor.value" @select="selectBusiness" @reload="choices.load()" @more="choices.load(true)" />
                <div @click="closeForLink">
                    <slot name="navigation" />
                </div>
                <RouterLink class="advertiser-account-link" to="/profile">{{ t('الحساب والأمان', 'Account and security') }}</RouterLink>
            </div>
        </dialog>
        <main id="advertiser-content" ref="content" class="bf-main advertiser-content" tabindex="-1">
            <slot />
        </main>
    </div>
</template>
