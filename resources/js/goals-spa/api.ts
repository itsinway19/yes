import { createGoalsClient, type SaveGoals } from '../api/goals';
import { protectedResponse } from './transport';

const client = createGoalsClient(location.origin, async input => {
    const request = input as Request;
    return protectedResponse(request.url, {
        method: request.method,
        headers: Object.fromEntries(request.headers),
        body: request.method === 'GET' ? undefined : await request.text(),
    });
});
export async function readGoals(workspace: string) {
    const result = await client.GET('/workspaces/{workspace}/goals', { params: { path: { workspace } } });
    if (!result.data) throw Error('Missing Goals projection');
    return result.data;
}
export async function readGoalsContext(workspace: string, headers: Record<string, string> = {}) {
    const result = await client.GET('/spa/api/workspaces/{workspace}/goals', { params: { path: { workspace } }, headers });
    if (!result.data) throw Error('Missing Goals context');
    return result.data;
}
export async function saveGoals(workspace: string, body: SaveGoals) {
    const result = await client.POST('/workspaces/{workspace}/goals', { params: { path: { workspace } }, body });
    if (!result.data) throw Error('Missing Goals acknowledgement');
    return result.data;
}
