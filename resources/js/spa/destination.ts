export function applicationDestination(value: string, origin: string, boundary: 'advertiser' | 'internal'): string {
    const target = new URL(value, origin);
    const internal = target.pathname === '/internal' || target.pathname.startsWith('/internal/');
    if (target.origin !== origin || target.username || target.password || internal !== (boundary === 'internal')) {
        throw Error('Invalid application destination');
    }
    return target.href;
}
