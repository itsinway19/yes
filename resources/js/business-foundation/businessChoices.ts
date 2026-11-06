import { ref, shallowRef } from 'vue';
import { businessChoices, type Choices } from './api';
import { owned, stamp } from '../spa/transport';

export type BusinessChoice = Choices['items'][number];
export const businessChoiceKey = (choice: BusinessChoice) => JSON.stringify([
    choice.business_public_id, choice.read_grant_id, choice.realm_kind, choice.realm_workspace_public_id,
]);
export const businessChoiceDestination = (choice: BusinessChoice) => ({
    path: `/businesses/${encodeURIComponent(choice.business_public_id)}`,
    query: { access: choice.read_grant_id },
});

/** A layout-local read model. Choices never authorize the destination. */
export function createBusinessChoices() {
    const items = shallowRef<BusinessChoice[]>([]);
    const nextCursor = ref<string | null>(null);
    const phase = ref<'loading' | 'ready' | 'error' | 'closed'>('loading');
    let scope: Choices['scope'] | undefined;
    let serial = 0, disposed = false;

    function clear() {
        serial++; items.value = []; nextCursor.value = null; scope = undefined; phase.value = 'closed';
    }
    function dispose() { disposed = true; clear(); }
    async function load(more = false) {
        if (disposed || phase.value === 'closed' || (more && (!nextCursor.value || phase.value === 'loading'))) return;
        const captured = stamp(), attempt = ++serial;
        const after = more ? nextCursor.value : null;
        phase.value = 'loading';
        if (!more) { items.value = []; nextCursor.value = null; scope = undefined; }
        try {
            if (after && (!/^\d+$/.test(after) || !Number.isSafeInteger(Number(after)) || Number(after) <= 0)) throw Error('Invalid Business cursor');
            const result = await businessChoices(after ? Number(after) : undefined);
            if (disposed || !owned(captured) || attempt !== serial) return;
            if (more && (!scope || scope.principal_generation !== result.scope.principal_generation
                || scope.authority_fingerprint !== result.scope.authority_fingerprint)) {
                throw Error('Business choices changed during pagination');
            }
            const combined = more ? [...items.value, ...result.items] : result.items;
            const seen = new Set<string>();
            items.value = combined.filter(item => {
                const identity = businessChoiceKey(item);
                if (seen.has(identity)) return false;
                seen.add(identity); return true;
            });
            scope = result.scope; nextCursor.value = result.next_cursor; phase.value = 'ready';
        } catch {
            if (disposed || !owned(captured) || attempt !== serial) return;
            items.value = []; nextCursor.value = null; scope = undefined; phase.value = 'error';
        }
    }
    return { items, nextCursor, phase, load, clear, dispose };
}
