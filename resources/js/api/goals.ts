import createClient from 'openapi-fetch';
import type { components, paths } from './generated/goals';

// Neutral schema/types do not import browser credentials or the identity protocol.
export type Preferences = components['schemas']['Preferences'];
export type GoalsContext = components['schemas']['GoalsContext'];
export type SaveGoals = components['schemas']['SaveGoals'];

// The browser supplies its admitted fetch. A future native transport needs its own
// approved authentication design; this factory does not invent one.
export function createGoalsClient(baseUrl: string, fetch: typeof globalThis.fetch) {
    return createClient<paths>({ baseUrl, fetch });
}
