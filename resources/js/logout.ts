import type { InjectionKey } from 'vue';
import { identityHeaders, captureIdentity, closeIdentityDocument } from './identityProtocol';

export type LogoutOperation = { start: (confirm: () => boolean, endpoint?: string) => Promise<void>; started: () => boolean };
export const logoutKey: InjectionKey<LogoutOperation> = Symbol('advertiserLogout');

// This attempt owns its POST. Handoff is a terminal policy, not proof of revocation.
export function createLogoutOperation(closeDocument: () => void): LogoutOperation {
    let started = false;
    return { started: () => started, async start(confirm, endpoint) {
        if (started || !confirm()) return;
        let headers: Record<string, string>;
        let internal: boolean;
        try {
            const captured = captureIdentity();
            headers = identityHeaders(captured);
            internal = captured.binding.boundary === 'internal';
        } catch (error) {
            console.warn('Logout was not handed off', error);
            window.alert(document.documentElement.lang === 'ar' ? 'تعذّر بدء طلب الخروج. لم يُرسل الطلب.' : 'Could not start logout. No request was sent.');
            return;
        }
        // All locally cancellable checks are complete. Seal before handing off;
        // no await or application callback can reopen the document between them.
        started = true;
        closeIdentityDocument();
        closeDocument();
        try {
            const response = await fetch(endpoint || (internal ? '/internal/logout' : '/logout'), { method: 'POST', credentials: 'same-origin', redirect: 'manual',
                headers: { Accept: 'application/json', ...headers } });
            // The existing Laravel route redirects. The owned response permits a
            // document departure, not a success claim. The server checks the next document.
            if (response.ok) {
                const result = await response.json();
                const target = new URL(result.redirect, window.location.origin);
                if (target.origin === window.location.origin) window.location.assign(target.href);
            }
            else console.warn('Logout did not return its document redirect', response.status);
        } catch (error) {
            console.warn('Logout transport or document departure unavailable', error);
        }
    } };
}
