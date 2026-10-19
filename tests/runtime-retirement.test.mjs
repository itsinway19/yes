import test from 'node:test';
import assert from 'node:assert/strict';
import { forbiddenRuntime, verifyRuntime } from '../scripts/runtime-retirement.mjs';

test('runtime guard rejects reintroduced imports, server writers and adapter artifacts', () => {
    for (const source of ["import {router} from '@inertiajs/vue3'", "const core = import('@inertiajs/core')", "use Inertia\\Inertia;", 'Inertia::clearHistory();', "import {buildArtifact} from './compat/inertia-n1/build.mjs'", 'createInertiaApp({})']) assert.equal(forbiddenRuntime(source), true, source);
    assert.equal(forbiddenRuntime("request.headers.has('X-Inertia')"), false);
    assert.equal(forbiddenRuntime('Historical Inertia history encryption is not cached-frame confidentiality.'), false);
});

test('production source, default build and final locks exclude removed runtime packages', async () => {
    assert.equal(await verifyRuntime(), 'Runtime retirement guard PASS');
});
