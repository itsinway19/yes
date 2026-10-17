import { ref } from 'vue';
import type { IdentityMetadata } from '../identityProtocol';
import { remainingSessionLease } from '../sessionLease';

export const bootstrap = JSON.parse((document.getElementById('application-bootstrap') ?? document.getElementById('goals-bootstrap'))!.textContent!) as { identity: IdentityMetadata; locale: 'ar' | 'en'; application?: boolean };
let identity = bootstrap.identity;
let leaseExpiresAt = Date.now() + identity.leaseMilliseconds;
export const scope = JSON.stringify(Object.fromEntries(Object.entries(identity.binding).filter(([key]) => key !== 'generation')));
export const status = ref<'checking' | 'ready' | 'unavailable' | 'closed'>('checking');
let revision = 0;
let timer: ReturnType<typeof setTimeout> | undefined;
let expiry: ReturnType<typeof setTimeout> | undefined;
const pending = new Set<AbortController>();
const closures = new Set<() => void>();
export const onClose = (fn: () => void) => closures.add(fn);
export const stamp = () => revision;
export const owned = (value: number) => value === revision && status.value !== 'closed';
export function suspend() {
    revision++; if (status.value !== 'closed') status.value = 'checking'; clearTimeout(timer); clearTimeout(expiry);
    pending.forEach(controller => controller.abort()); pending.clear();
}
export function close() {
    suspend(); status.value = 'closed'; closures.forEach(fn => fn());
}
const equal = (value: IdentityMetadata) => JSON.stringify(value.binding) === JSON.stringify(identity.binding);
export class TransportError extends Error {
    constructor(public status: number, public body: { message?: string; errors?: Record<string, string[]>; identityConflict?: boolean }) { super(body.message ?? 'تعذّر إكمال الطلب'); }
}
/** Consume the existing server document protocol; no client authentication or authority. */
export async function request<T>(url: string, options: RequestInit = {}, terminal = false): Promise<T> {
    const response = await protectedResponse(url, options, terminal);
    return response.headers.get('Content-Type')?.startsWith('text/event-stream')
        ? JSON.parse((await response.text()).trim().slice('data: '.length)) as T : await response.json() as T;
}
/** Fetch boundary shared by the generated Goals client and existing identity endpoints. */
export async function protectedResponse(url: string, options: RequestInit = {}, terminal = false): Promise<Response> {
    if (status.value === 'closed') throw new Error('Document is closed');
    if (new URL(url, location.origin).origin !== location.origin) throw new Error('Application origin required');
    options.signal?.throwIfAborted();
    const captured = stamp(), input = identity, controller = new AbortController(); pending.add(controller);
    const cancel = () => controller.abort(options.signal?.reason);
    options.signal?.addEventListener('abort', cancel, { once: true });
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
        const response = await fetch(url, { ...options, signal: controller.signal, credentials: 'same-origin', cache: 'no-store', redirect: 'manual',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': identity.csrf,
                'X-Builderad-Document': identity.receipt, 'X-Builderad-Transition': identity.transition, ...options.headers } });
        const observed = response.clone();
        const body = observed.headers.get('Content-Type')?.startsWith('text/event-stream')
            ? JSON.parse((await observed.text()).trim().slice('data: '.length)) : await observed.json();
        if (!owned(captured)) throw new Error('Obsolete response');
        if ([401, 419].includes(response.status) || (response.status === 409 && body.identityConflict)) close();
        if (!response.ok) throw new TransportError(response.status, body);
        if (terminal) {
            // The existing terminal protocol intentionally emits no new document identity.
            const nonce = JSON.parse(atob(input.transition.split('.')[0].replace(/-/g, '+').replace(/_/g, '/'))).binding.nonce;
            const result = body.transition;
            if (!result || result.lineage !== input.binding.lineage || result.boundary !== input.binding.boundary
                || result.epoch !== input.binding.epoch + 1 || result.nonce !== nonce || result.kind !== 'logout' || result.status !== 'closed' || body.redirect !== '/') {
                close(); throw new Error('Unowned terminal reply');
            }
            return response;
        }
        const raw = response.headers.get('X-Builderad-Identity');
        const evidence = raw ? JSON.parse(raw) as IdentityMetadata : undefined;
        if (!evidence || !equal(evidence)) { close(); throw new Error('Document binding changed'); }
        controller.signal.throwIfAborted();
        // Refresh signed request evidence without inventing a new cookie lease.
        identity = { ...evidence, leaseMilliseconds: identity.leaseMilliseconds };
        return response;
    } finally { clearTimeout(timeout); options.signal?.removeEventListener('abort', cancel); pending.delete(controller); }
}
export function resumeLease() {
    clearTimeout(timer); clearTimeout(expiry);
    if (status.value !== 'ready') return;
    const captured = stamp();
    const remaining = leaseExpiresAt - Date.now();
    if (remaining <= 0) { suspend(); status.value = 'unavailable'; return; }
    // Conservative transport lease. It never extends canonical idle/absolute authority.
    expiry = setTimeout(() => { if (owned(captured)) { suspend(); status.value = 'unavailable'; } }, remaining);
    timer = setTimeout(async () => {
        const startedAt = Date.now();
        try {
            const result = await request<{ identity: IdentityMetadata }>('/identity/renew', { method: 'POST' });
            if (!owned(captured) || status.value !== 'ready' || !equal(result.identity)) return;
            identity = result.identity; leaseExpiresAt = Date.now() + remainingSessionLease(identity.leaseMilliseconds, startedAt); resumeLease();
        } catch { if (owned(captured) && status.value !== 'closed') { suspend(); status.value = 'unavailable'; } }
    }, Math.max(1, remaining * 0.7));
}
export async function logout() {
    // Close the UI before the actual existing server-owned terminal transition.
    const work = request<{ redirect: string }>('/logout', { method: 'POST' }, true);
    status.value = 'checking';
    try { const result = await work; close(); location.replace(result.redirect); }
    catch { close(); }
}
