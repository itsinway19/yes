import * as core from '@inertiajs/core';
import type { NavigationApi } from './types/inertia-n1';

// The upstream declaration package remains untouched. The exact source artifact
// supplies these two additions; an unpatched runtime must fail rather than fall back.
const artifact = core as typeof core & Partial<NavigationApi>;
if (typeof artifact.configureNavigation !== 'function' || typeof artifact.navigationPhase !== 'function') {
    throw new Error('The verified Inertia N1 artifact is required.');
}
export const configureNavigation: NavigationApi['configureNavigation'] = artifact.configureNavigation;
export const navigationPhase: NavigationApi['navigationPhase'] = artifact.navigationPhase;
