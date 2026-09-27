import { InvalidDomainError, NotSupportedError, UserCancelledError } from '@laravel/passkeys';

export function passkeyLoginErrorKey(error: unknown): string {
    if (error instanceof UserCancelledError ||
        (error instanceof Error && ['NotAllowedError', 'AbortError'].includes(error.name))) {
        return 'login.passkeyCancelled';
    }
    if (error instanceof NotSupportedError ||
        (error instanceof Error && error.name === 'NotSupportedError')) {
        return 'login.passkeyUnsupported';
    }
    if (error instanceof InvalidDomainError ||
        (error instanceof Error && error.name === 'SecurityError')) {
        return 'login.passkeySecurityContext';
    }
    return 'login.passkeyFailed';
}
