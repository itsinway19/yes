import createClient from 'openapi-fetch';
import type { paths } from './generated/application';

export function createApplicationClient(baseUrl: string, fetch: typeof globalThis.fetch) {
    return createClient<paths>({ baseUrl, fetch });
}
