import { http, HttpCancelledError, type Page } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { Passkeys } from '@laravel/passkeys';

export type IdentityBinding = {
    lineage: string; epoch: number; boundary: 'advertiser' | 'internal'; credential: string;
    principal: number; lifecycle: number; context: string; generation: string;
};
export type IdentityMetadata = {
    receipt: string; transition: string; csrf: string; binding: IdentityBinding; leaseMilliseconds: number; renewalProtocol?: 0 | 1;
};
let current: IdentityMetadata | undefined;
let live: Page | undefined;
let blocked = false;
let sealed = false;
let recovery: Promise<void> | undefined;
let recoveryFailed = false;
let renewal: ReturnType<typeof setTimeout> | undefined;
let renewalRevision = 0;
type RenewalAttempt = {
    revision: number; captured: IdentityMetadata; headersReceived: boolean; replacement: boolean;
    cancel: () => void;
};
let renewalAttempt: RenewalAttempt | undefined;
let renewalReplacementUsed = false;
const RENEWAL_WAIT_MS = 15000;
const CONTEXT_WAIT_MS = 10000;
let renewalPending: { revision: number; generation: string } | undefined;
let leaseDueAt = 0;
let present = true;
let resume: () => void = () => {};
export function onIdentityRecovered(handler: () => void): void { resume = handler; }

export function sameIdentity(a: IdentityBinding, b: IdentityBinding, documentGeneration = true): boolean {
    const keys: (keyof IdentityBinding)[] = ['lineage', 'epoch', 'boundary', 'credential', 'principal', 'lifecycle', 'context'];
    if (documentGeneration) keys.push('generation');
    return keys.every(key => a[key] === b[key]);
}

export function requireIdentity(value: unknown): IdentityMetadata {
    const metadata = value as IdentityMetadata | undefined;
    const binding = metadata?.binding;
    if (!binding || !['advertiser', 'internal'].includes(binding.boundary)
        || !Number.isSafeInteger(binding.epoch) || binding.epoch < 0
        || !Number.isSafeInteger(binding.principal) || binding.principal < 0
        || !Number.isSafeInteger(binding.lifecycle) || binding.lifecycle < 0
        || !/^[0-9a-f-]{36}$/.test(binding.lineage)
        || ![binding.credential, binding.context, binding.generation].every(value => /^[0-9a-f]{64}$/.test(value))
        || typeof metadata.receipt !== 'string' || typeof metadata.transition !== 'string'
        || !metadata.receipt || !metadata.transition || typeof metadata.csrf !== 'string' || !metadata.csrf
        || !Number.isFinite(metadata.leaseMilliseconds) || metadata.leaseMilliseconds <= 0) {
        throw new HttpCancelledError('Identity document evidence is unavailable.');
    }
    return metadata;
}

export function identityHeaders(metadata = current): Record<string, string> {
    if (!metadata || blocked) throw new HttpCancelledError('Identity document is closed.');
    return { 'X-CSRF-TOKEN': metadata.csrf, 'X-Builderad-Document': metadata.receipt,
        'X-Builderad-Transition': metadata.transition };
}

function configureClients() {
    const metadata = requireIdentity(current);
    const meta = document.querySelector('meta[name="csrf-token"]');
    meta?.setAttribute('content', metadata.csrf);
    Passkeys.configure({ fetch: { headers: identityHeaders(metadata), credentials: 'same-origin' } });
}

export function acceptIdentityPage(page: Page): void {
    const candidate = requireIdentity(page.props.identity);
    if (!current || blocked || !sameIdentity(current.binding, candidate.binding)) {
        throw new HttpCancelledError('Candidate belongs to another identity document.');
    }
}

export function attachIdentityPage(page: Page): void {
    acceptIdentityPage(page);
    current = requireIdentity(page.props.identity);
    live = page;
    configureClients();
}

export function closeIdentityDocument(): void {
    sealed = true;
    blocked = true;
    suspendRenewal();
}

function updateLease(): void {
    const metadata = requireIdentity(current);
    leaseDueAt = Date.now() + (metadata.renewalProtocol === 1
        ? metadata.leaseMilliseconds * 0.7 : Math.max(5000, metadata.leaseMilliseconds * 0.7));
}

function suspendRenewal(): void {
    renewalRevision++;
    invalidateRenewalAttempt();
    renewalPending = undefined;
    clearTimeout(renewal);
    renewal = undefined;
}

function renewalEligible(): boolean {
    return !sealed && !blocked && present && !!current && current.binding.principal > 0
        && document.documentElement.dataset.visibility === 'ready';
}

/** Called only when the existing visibility owner has admitted the live document. */
export function resumeIdentityRenewal(): void { scheduleRenewal(); }

function scheduleRenewal(delay = current?.renewalProtocol === 1
    ? Math.max(0, leaseDueAt - Date.now()) : Math.max(5000, leaseDueAt - Date.now())): void {
    if (!renewalEligible() || recovery) return;
    clearTimeout(renewal);
    renewal = undefined;
    const revision = renewalRevision, generation = current!.binding.generation;
    if (renewalAttempt) {
        renewalPending = { revision, generation };
        return;
    }
    const timer = setTimeout(() => {
        if (renewal !== timer) return;
        renewal = undefined;
        if (revision !== renewalRevision || generation !== current?.binding.generation) return;
        void renewIdentity(revision);
    }, delay);
    renewal = timer;
}

function invalidateRenewalAttempt(): void {
    const attempt = renewalAttempt;
    // Compatibility marker is emitted only by the installed TTL-only backend.
    // On an older backend, retain the established pre-header containment.
    if (attempt && (attempt.captured.renewalProtocol === 1 || attempt.headersReceived)) {
        renewalAttempt = undefined;
        attempt.cancel();
    }
}

function stopRenewalRecovery(): void {
    blocked = true;
    recoveryFailed = true;
    recovery = Promise.resolve(); // Deduplicate hints; only explicit retry reopens recovery.
    router.cancelAll();
    document.documentElement.dataset.visibility = 'unavailable';
    document.getElementById('app')?.setAttribute('inert', '');
}

async function renewIdentity(revision: number): Promise<void> {
    if (!renewalEligible() || recovery || renewalAttempt) return;
    const captured = current!;
    const controller = new AbortController();
    let cancelWait: (error: Error) => void = () => {};
    const interrupted = new Promise<never>((_, reject) => { cancelWait = reject; });
    const attempt: RenewalAttempt = { revision, captured, headersReceived: false,
        replacement: renewalReplacementUsed,
        cancel: () => { controller.abort(); cancelWait(new HttpCancelledError('Renewal attempt is obsolete.')); } };
    renewalAttempt = attempt;
    const owned = () => renewalAttempt === attempt && revision === renewalRevision
        && renewalEligible() && !recovery && current === captured;
    const deadline = captured.renewalProtocol === 1 ? setTimeout(() => {
        controller.abort(); cancelWait(new Error('Identity renewal transport timed out.'));
    }, RENEWAL_WAIT_MS) : undefined;
    const prefix = captured.binding.boundary === 'internal' ? '/internal' : '';
    try {
        const exchange = (async () => {
            const response = await fetch(`${prefix}/identity/renew`, { method: 'POST', credentials: 'same-origin',
                cache: 'no-store', redirect: 'manual', signal: controller.signal,
                headers: { Accept: 'application/json', ...identityHeaders(captured) } });
            attempt.headersReceived = true;
            if (!owned()) return;
            if (!response.ok) throw new Error('Identity renewal requires recovery.');
            const result = await response.json();
            if (!owned()) return;
            return requireIdentity(result.identity);
        })();
        const fresh = await Promise.race([exchange, interrupted]);
        if (!owned() || !fresh) return;
        if (!sameIdentity(captured.binding, fresh.binding)) {
            throw new HttpCancelledError('Renewal result belongs to another identity document.');
        }
        current = fresh;
        renewalReplacementUsed = false;
        updateLease();
        if (live) live.props.identity = fresh;
        configureClients();
    } catch {
        if (!owned()) return;
        // Release this local wait only on the corrected backend. Neither abort
        // nor timeout asserts cancellation of server effects or cookie delivery.
        if (captured.renewalProtocol === 1) {
            renewalAttempt = undefined;
            if (attempt.replacement) { stopRenewalRecovery(); return; }
            renewalReplacementUsed = true;
        }
        try { await recoverIdentity(); } catch { /* Recovery owns the finite unavailable/explicit-retry state. */ }
    } finally {
        clearTimeout(deadline);
        // An obsolete finally cannot release a newer attempt or its schedule.
        if (renewalAttempt === attempt) {
            renewalAttempt = undefined;
            const pending = renewalPending;
            renewalPending = undefined;
            if ((pending && pending.revision === renewalRevision && pending.generation === current?.binding.generation)
                || (revision === renewalRevision && captured.binding.generation === current?.binding.generation)) scheduleRenewal();
        }
    }
}

export function captureIdentity(): IdentityMetadata { return requireIdentity(current); }
export function identityBlocked(): boolean { return blocked; }

/** Remembered data is scoped evidence, never an authentication source. */
export function trustIdentityState(binding: unknown): boolean {
    if (!current || blocked || !binding || typeof binding !== 'object') return false;
    return sameIdentity(current.binding, binding as IdentityBinding, false);
}

export function identityDestination(result: { redirect?: string; transition?: { lineage: string; boundary: string; epoch: number; nonce: string } }, captured: IdentityMetadata): string {
    const payload = captured.transition.split('.')[0].replace(/-/g, '+').replace(/_/g, '/');
    const nonce = JSON.parse(atob(payload)).binding.nonce;
    if (current !== captured || blocked || !result.transition || result.transition.lineage !== captured.binding.lineage
        || result.transition.boundary !== captured.binding.boundary || result.transition.epoch !== captured.binding.epoch + 1
        || result.transition.nonce !== nonce || typeof result.redirect !== 'string') {
        throw new HttpCancelledError('Authentication reply is obsolete or unowned.');
    }
    const target = new URL(result.redirect, window.location.origin);
    if (target.origin !== window.location.origin || target.pathname.startsWith('/internal/') !== (captured.binding.boundary === 'internal')) throw new HttpCancelledError('Invalid identity destination.');
    if (typeof BroadcastChannel !== 'undefined') {
        const hints = new BroadcastChannel('builderad-visibility');
        hints.postMessage({ boundary: result.transition.boundary, epoch: result.transition.epoch });
        hints.close();
    }
    return target.href;
}

export function receiveIdentityHint(value: unknown): void {
    const hint = value as { boundary?: unknown; epoch?: unknown } | undefined;
    if (!current || sealed || !hint || hint.boundary !== current.binding.boundary
        || !Number.isSafeInteger(hint.epoch) || (hint.epoch as number) < 0 || hint.epoch === current.binding.epoch) return;
    // Domain/epoch are merely a hint. Only the actual credential/context endpoint
    // can decide whether this document resumes or must be replaced.
    void recoverIdentity();
}

/** One no-store context request, no replay of the rejected action or ceremony. */
export function recoverIdentity(): Promise<void> {
    if (sealed) return Promise.resolve();
    if (recovery) return recovery;
    recoveryFailed = false;
    const previous = requireIdentity(current);
    const prefix = previous.binding.boundary === 'internal' ? '/internal' : '';
    blocked = true;
    // Invalidate queued callbacks and in-flight response ownership. The existing
    // timer may expire while blocked; successful recovery owns its replacement.
    renewalRevision++;
    if (renewalAttempt?.captured.renewalProtocol === 1) renewalReplacementUsed = true;
    invalidateRenewalAttempt();
    renewalPending = undefined;
    router.cancelAll();
    document.documentElement.dataset.visibility = 'checking';
    document.getElementById('app')?.setAttribute('inert', '');
    let contextExpired = false;
    const contextController = new AbortController();
    let failContext: (error: Error) => void = () => {};
    const contextInterrupted = new Promise<never>((_, reject) => { failContext = reject; });
    const contextDeadline = previous.renewalProtocol === 1 ? setTimeout(() => {
        contextExpired = true;
        contextController.abort();
        failContext(new Error('Identity context timed out; explicitly retry recovery.'));
    }, CONTEXT_WAIT_MS) : undefined;
    const contextRequest = (async () => {
        const response = await fetch(`${prefix}/identity/context`, {
            credentials: 'same-origin', cache: 'no-store', redirect: 'manual', signal: contextController.signal, headers: { Accept: 'application/json' },
        });
        if (sealed || contextExpired) return;
        if (response.status === 401) {
            router.flushAll(); router.clearHistory();
            window.location.replace(`${prefix}/login`);
            return;
        }
        if (!response.ok) throw new Error('Identity context is unavailable; explicitly retry recovery.');
        const fresh = requireIdentity((await response.json()).identity);
        if (sealed || contextExpired) return;
        if (!sameIdentity(previous.binding, fresh.binding, false)) {
            router.flushAll(); router.clearHistory();
            window.location.replace(window.location.href);
            return;
        }
        current = fresh;
        updateLease();
        if (live) live.props.identity = fresh;
        blocked = false;
        configureClients();
        resume();
    })();
    const ownedRecovery = Promise.race([contextRequest, contextInterrupted]).finally(() => clearTimeout(contextDeadline));
    recovery = ownedRecovery;
    void ownedRecovery.then(() => {
        if (recovery !== ownedRecovery || sealed) return;
        if (!blocked) {
            recovery = undefined;
            if (renewalReplacementUsed && previous.renewalProtocol === 1 && current?.renewalProtocol !== 1) {
                stopRenewalRecovery();
                return;
            }
            scheduleRenewal();
        }
    }, () => {
        if (recovery !== ownedRecovery || sealed) return;
        recoveryFailed = true;
        document.documentElement.dataset.visibility = 'unavailable';
    });
    return recovery;
}

/** User-triggered recovery may begin a new bounded attempt after transport failure. */
export function retryIdentityRecovery(): Promise<void> {
    if (sealed) return Promise.resolve();
    if (recoveryFailed) { recovery = undefined; renewalReplacementUsed = false; }
    return recoverIdentity();
}

export function installIdentityProtocol(): void {
    const raw = document.querySelector('meta[name="identity-context"]')?.getAttribute('content');
    current = requireIdentity(raw ? JSON.parse(raw) : undefined);
    updateLease();
    axios.defaults.withXSRFToken = false;
    axios.interceptors.request.use(config => {
        if (new URL(config.url || '', window.location.href).origin !== window.location.origin) return config;
        for (const [name, value] of Object.entries(identityHeaders())) config.headers.set(name, value);
        return config;
    });
    axios.interceptors.response.use(response => {
        if (new URL(response.config.url || '', window.location.href).origin !== window.location.origin) return response;
        const raw = response.headers['x-builderad-identity'];
        const candidate = requireIdentity(typeof raw === 'string' ? JSON.parse(raw) : undefined);
        if (!current || blocked || !sameIdentity(current.binding, candidate.binding)) {
            void recoverIdentity();
            throw new HttpCancelledError('Obsolete identity response discarded.');
        }
        return response;
    }, error => {
        const response = error.response;
        if (response && ([401, 419].includes(response.status) || (response.status === 409 && response.data?.identityConflict))) {
            void recoverIdentity();
        }
        return Promise.reject(error);
    });
    // Inertia 3.7.1 exposes supported HTTP configuration/hooks. No History patch.
    http.setClient({ xsrfCookieName: '', xsrfHeaderName: '' });
    http.onRequest(config => {
        if (new URL(config.url, window.location.href).origin !== window.location.origin) throw new HttpCancelledError('Identity headers require the application origin.');
        return { ...config, headers: { ...config.headers, ...identityHeaders() } };
    });
    http.onResponse(response => {
        const raw = response.headers['x-builderad-identity'];
        const candidate = requireIdentity(raw ? JSON.parse(raw) : undefined);
        if (!current || blocked || !sameIdentity(current.binding, candidate.binding)) {
            void recoverIdentity();
            throw new HttpCancelledError('Obsolete identity response discarded.');
        }
        return response;
    });
    http.onError(error => {
        if ('response' in error) {
            let data: { identityConflict?: boolean } = {};
            try { data = JSON.parse(error.response.data); } catch { /* Non-protocol errors retain their existing handling. */ }
            if ([401, 419].includes(error.response.status) || (error.response.status === 409 && data.identityConflict)) void recoverIdentity();
        }
    });
    configureClients();
    scheduleRenewal();
    window.addEventListener('pagehide', () => { present = false; suspendRenewal(); });
    window.addEventListener('pageshow', event => {
        present = true;
        if (event.persisted && current && !sealed && !blocked) {
            // Restoration does not grant a new lease; renew checks the actual
            // credential and canonical deadlines without extending authority.
            scheduleRenewal(1000);
        }
    });
}

export async function authenticateIdentity(url: string, data: unknown): Promise<{ redirect: string }> {
    const captured = requireIdentity(current);
    const response = await fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'manual',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...identityHeaders(captured) }, body: JSON.stringify(data) });
    const result = await response.json();
    if (!response.ok) {
        if (response.status === 503 && result.identityUnknown) {
            const prefix = captured.binding.boundary === 'internal' ? '/internal' : '';
            await fetch(`${prefix}/identity/transition`, { credentials: 'same-origin', cache: 'no-store', redirect: 'manual',
                headers: { Accept: 'application/json', 'X-Builderad-Transition': captured.transition } });
        }
        if ([409, 419, 503].includes(response.status)) await recoverIdentity();
        throw result;
    }
    const target = identityDestination(result, captured);
    closeIdentityDocument();
    router.flushAll(); router.clearHistory();
    return { redirect: target };
}
