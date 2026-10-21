import { Passkeys } from '@laravel/passkeys';

type CeremonyKind = 'login' | 'internal-login' | 'device-registration' | 'staff-registration';
type Ceremony = { id: string; kind: CeremonyKind; startedAt: number; cancelling: boolean; cancellationReason?: 'user' | 'document' };

let active: Ceremony | null = null;

export function passkeyBrowserFailure(error: unknown, cancellationRequested: boolean): 'cancelled' | 'not-allowed' | 'unavailable' {
    // NotAllowedError deliberately conflates user dismissal, timeout and other
    // platform refusals. Only our own cancellation signal proves cancellation.
    if (cancellationRequested) return 'cancelled';
    return error instanceof Error && error.name === 'NotAllowedError' ? 'not-allowed' : 'unavailable';
}

function trace(ceremony: Ceremony, phase: string, errorName?: string): void {
    if (!['localhost', '127.0.0.1'].includes(window.location.hostname)) return;
    console.info('builderad_webauthn ' + JSON.stringify({
        operation: ceremony.id,
        kind: ceremony.kind,
        phase,
        elapsedMs: Math.round(performance.now() - ceremony.startedAt),
        ...(errorName ? { errorName } : {}),
    }));
}

export function cancelPasskeyOperation(id: string, reason: 'user' | 'document' = 'user'): void {
    if (active?.id !== id || active.cancelling) return;
    active.cancelling = true;
    active.cancellationReason = reason;
    trace(active, reason === 'document' ? 'document_disposed' : 'cancel_requested');
    Passkeys.cancel();
    // Keep the guard until the browser promise actually settles. Aborting a
    // JavaScript promise does not prove that Windows released its WebAuthn RPC.
}

export function runPasskeyOperation<T>(
    kind: CeremonyKind,
    routes: { options: string; submit: string },
    action: (routes: { options: string; submit: string }, mark: (phase: string) => void) => Promise<T>,
): { id: string; promise: Promise<T> } {
    if (active) {
        trace(active, 'second_operation_blocked');
        throw new Error('توجد محاولة مفتاح مرور جارية. أكملها أو ألغها قبل البدء مجددًا.');
    }

    const ceremony: Ceremony = {
        id: Array.from(crypto.getRandomValues(new Uint8Array(8)), byte => byte.toString(16).padStart(2, '0')).join(''),
        kind,
        startedAt: performance.now(),
        cancelling: false,
    };
    active = ceremony;
    const withProbe = (url: string) => {
        const parsed = new URL(url, window.location.href);
        parsed.searchParams.set('webauthn_probe', ceremony.id);
        return parsed.toString();
    };
    trace(ceremony, 'started');
    const promise = (async () => {
        try {
            const perform = async () => {
                if (ceremony.cancelling) throw new Error('أُلغيت محاولة مفتاح المرور.');
                trace(ceremony, 'browser_operation_started');
                return action({ options: withProbe(routes.options), submit: withProbe(routes.submit) }, phase => trace(ceremony, phase));
            };
            // Web Locks coordinate tabs of the same browser and origin. The
            // in-memory guard above also covers browsers without Web Locks.
            const result = navigator.locks
                ? await navigator.locks.request('builderad-passkey-ceremony', { ifAvailable: true }, async lock => {
                    if (!lock) {
                        trace(ceremony, 'other_tab_operation_blocked');
                        throw new Error('توجد محاولة مفتاح مرور جارية في تبويب آخر. أكملها أو ألغها أولًا.');
                    }
                    return perform();
                })
                : await perform();
            trace(ceremony, 'completed');
            return result;
        } catch (error) {
            trace(ceremony, ceremony.cancelling ? (ceremony.cancellationReason === 'document' ? 'disposed' : 'cancelled') : 'failed', error instanceof Error ? error.name : 'unknown');
            throw error;
        } finally {
            if (active === ceremony) active = null;
        }
    })();
    return { id: ceremony.id, promise };
}
