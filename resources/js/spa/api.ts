import { createApplicationClient } from '../api/application';
import { protectedResponse } from './transport';

export const api = createApplicationClient(location.origin, async input => {
    const request = input as Request;
    return protectedResponse(request.url, {
        method: request.method,
        signal: request.signal,
        headers: Object.fromEntries(request.headers),
        body: ['GET', 'HEAD'].includes(request.method) ? undefined : await request.text(),
    });
});
