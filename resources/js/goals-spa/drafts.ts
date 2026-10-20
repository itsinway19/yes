import { onClose } from '../spa/transport';
import type { GoalValue } from '../Components/Business/goalSelection';
export type { Preferences } from '../api/goals';
import type { Preferences } from '../api/goals';
export type Draft = { baseline: Preferences; draft: GoalValue };
// Drafts live only in this admitted document and reset on reload.
// Vue Router History contains no draft. A closed identity document loses every draft.
const memory = new Map<string, Draft>();
export async function restore(workspace: string): Promise<Draft | undefined> {
    const value = memory.get(workspace); return value ? structuredClone(value) : undefined;
}
export async function remember(workspace: string, value: Draft): Promise<void> {
    memory.set(workspace, JSON.parse(JSON.stringify(value)) as Draft);
}
onClose(() => memory.clear());
