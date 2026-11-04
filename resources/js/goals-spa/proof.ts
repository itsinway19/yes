import { status } from '../spa/presentation';
import type { Router } from 'vue-router';
import { query } from '../spa/query';
import { close } from '../spa/transport';

export function exposeGoalsProof(router: Router, resume: () => void) {
    Object.assign(window, { __goalsSpa: { router, query, status, close, resume } });
}
