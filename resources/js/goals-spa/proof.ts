import type { Router } from 'vue-router';
import { query, current, eventProof, applyEvent } from './state';
import { readGoals, saveGoals } from './api';
import { status, close } from '../spa/transport';

export function exposeGoalsProof(router: Router, resume: () => void) {
    Object.assign(window, { __goalsSpa: { router, query, status, current, close, resume, eventProof, applyEvent, readGoals, saveGoals } });
}
