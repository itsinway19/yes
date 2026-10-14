import { usePage } from '@inertiajs/vue3';
import type { Page } from '@inertiajs/core';
import { getCurrentInstance, type App, type ComponentInternalInstance } from 'vue';
import { acceptIdentityPage, requireIdentity } from './identityProtocol';

const mounted = new Map<unknown, Set<ComponentInternalInstance>>();
let active: { component: unknown; page: Page; error?: unknown } | undefined;

/** Track the actual page instance using Vue lifecycle hooks, including preserved instances. */
export function installNavigationSettlement(app: App): void {
    const record = () => {
        const instance = getCurrentInstance();
        if (!instance) return;
        const group = mounted.get(instance.type) ?? new Set<ComponentInternalInstance>();
        group.add(instance); mounted.set(instance.type, group);
    };
    app.mixin({ mounted: record, updated: record, beforeUnmount() {
        const instance = getCurrentInstance();
        if (!instance) return;
        const group = mounted.get(instance.type); group?.delete(instance);
        if (!group?.size) mounted.delete(instance.type);
    } });
    const previous = app.config.errorHandler;
    app.config.errorHandler = (error, instance, info) => {
        if (active) active.error = error;
        if (previous) previous(error, instance, info);
        else if (!active) throw error;
    };
}

export function beginNavigationSwap(page: Page, component: unknown): void {
    if (active) throw new Error('Another page swap has not settled');
    active = { page, component };
}

/** Called after Vue's flush, before Inertia emits successful completion. */
export function confirmNavigationSwap(page: Page): void {
    const candidate = active;
    try {
        if (!candidate || candidate.page !== page) throw new Error('Navigation swap ownership lost');
        if (candidate.error) throw candidate.error;
        acceptIdentityPage(page);
        const vuePage = usePage();
        if (vuePage.component !== page.component || vuePage.url !== page.url
            || requireIdentity(vuePage.props.identity).receipt !== requireIdentity(page.props.identity).receipt) {
            throw new Error('Vue page/receipt settlement mismatch');
        }
        const instances = mounted.get(candidate.component);
        const live = [...instances ?? []].some(instance => {
            const identity = instance.props.identity ?? instance.attrs.identity;
            return instance.isMounted && !instance.isUnmounted && !!instance.vnode.el?.isConnected
                && requireIdentity(identity).receipt === requireIdentity(page.props.identity).receipt;
        });
        if (!live) throw new Error('Expected page mount was not confirmed');
    } finally { if (active === candidate) active = undefined; }
}

export function abortNavigationSwap(): void { active = undefined; }
