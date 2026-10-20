import { status } from '../spa/presentation';
import type { Router } from 'vue-router';
import { query, current, read } from './state';
import { readGoals, saveGoals } from './api';
import { bootstrap, request, stamp, owned, close, scope } from '../spa/transport';

export async function applyEvent(event: {workspace:string;version:number;binding:unknown}, workspace: string) {
    if (status.value !== 'ready' || event.workspace !== workspace || current.value?.workspace.public_id !== workspace
        || JSON.stringify(event.binding) !== JSON.stringify(bootstrap.identity.binding)
        || event.version <= current.value.preferences.version) return false;
    await query.invalidateQueries({queryKey:[scope,'goals',workspace]});await read(workspace,true);return true;
}
export async function eventProof(workspace: string) {
    const captured = stamp();
    const event=await request<{workspace:string;version:number;binding:unknown}>(`/workspaces/${workspace}/goals/events`);
    if(!owned(captured))return false;return applyEvent(event,workspace);
}

export function exposeGoalsProof(router: Router, resume: () => void) {
    Object.assign(window, { __goalsSpa: { router, query, status, current, close, resume, eventProof, applyEvent, readGoals, saveGoals } });
}
