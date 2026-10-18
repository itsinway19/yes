import { createApplicationClient } from '../api/application';
import { protectedResponse } from './transport';

export const api = createApplicationClient(location.origin, async input => {
    const request = input as Request;
    const authentication = request.method === 'POST' && ['/login', '/register', '/identity/switch', '/auth/passkey', '/identity/passkey'].includes(new URL(request.url).pathname);
    return protectedResponse(request.url, {
        method: request.method,
        signal: request.signal,
        headers: Object.fromEntries(request.headers),
        body: ['GET', 'HEAD'].includes(request.method) ? undefined : await request.text(),
    }, authentication ? 'login' : false);
});
