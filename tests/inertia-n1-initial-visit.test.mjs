import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { webcrypto } from 'node:crypto';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';
import { buildArtifact } from '../compat/inertia-n1/build.mjs';

const filename = fileURLToPath(import.meta.url);
const root = path.resolve(path.dirname(filename), '..');
const mode = process.env.N1_INITIAL_VISIT_SCENARIO;

if (!mode) {
    for (const scenario of ['superseded', 'unexpected']) {
        test(`InitialVisit owns default initialization rejection: ${scenario}`, () => {
            const result = spawnSync(process.execPath, [filename], {
                cwd: root,
                env: { ...process.env, N1_INITIAL_VISIT_SCENARIO: scenario },
                encoding: 'utf8',
                timeout: 30000,
            });
            assert.equal(result.status, 0, result.stdout + result.stderr);
            assert.match(result.stdout, /INITIAL_VISIT_CONTRACT_PASS/);
        });
    }
} else {
    const observedErrors = [];
    process.on('unhandledRejection', error => observedErrors.push(error));
    const storage = new Map();
    const writes = [];
    const mounts = [];
    const events = [];
    globalThis.window = {
        navigator: { userAgent: 'N1 CriOS initial visit fixture' },
        location: new URL('http://localhost/initial'),
        crypto: webcrypto,
        sessionStorage: {
            getItem: key => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
            removeItem: key => storage.delete(key),
        },
        history: {
            state: null,
            replaceState(value, unused, url) { this.state = value; if (url) window.location = new URL(url, window.location); writes.push({ url: window.location.pathname, page: value.page }); },
            pushState(value, unused, url) { this.state = value; if (url) window.location = new URL(url, window.location); writes.push({ url: window.location.pathname, page: value.page }); },
        },
        scrollTo() {},
        requestAnimationFrame: callback => callback(),
        dispatchEvent: () => true,
    };
    const artifact = await buildArtifact({ root, output: path.join(root, 'storage/framework/inertia-n1/initial-visit-test'), testExports: true });
    const core = await import(pathToFileURL(artifact.target));
    globalThis.document = { querySelectorAll: () => [], dispatchEvent: event => { events.push(event); return true; } };
    globalThis.CustomEvent = class {
        constructor(type, { detail } = {}) { this.type = type; this.detail = detail; }
    };
    let resolveInitial;
    let rejectInitial;
    const resolver = new Promise((resolve, reject) => { resolveInitial = resolve; rejectInitial = reject; });
    const initial = { component: 'Initial', url: '/initial', version: 'fixture', props: { errors: {} }, flash: { notice: 'initial' }, rememberedState: {}, encryptHistory: false };
    core.page.init({ initialPage: initial, resolveComponent: name => name === 'Initial' ? resolver : Promise.resolve({ name }), swapComponent: async ({ page }) => mounts.push(page.url) });
    const originalWait = core.navigation.wait.bind(core.navigation);
    let initialToken;
    core.navigation.wait = (token, work) => {
        if (token.page.component === 'Initial') initialToken = token;
        return originalWait(token, work);
    };
    let ownedChain;
    if (mode === 'unexpected') {
        const originalSet = core.page.set.bind(core.page);
        core.page.set = (...parameters) => {
            const operation = originalSet(...parameters);
            const then = operation.then.bind(operation);
            operation.then = (...handlers) => {
                const continuation = then(...handlers);
                const catchRejection = continuation.catch.bind(continuation);
                continuation.catch = handler => { ownedChain = catchRejection(handler); return ownedChain; };
                return continuation;
            };
            return operation;
        };
    }
    core.InitialVisit.handleDefault();
    assert.ok(initialToken, 'page.set reached the real navigation.wait');
    assert.equal(initialToken.phase, 'preparing');
    assert.equal(initialToken.committed, false);
    assert.deepEqual(mounts, []);
    assert.deepEqual(writes, []);
    if (mode === 'superseded') {
        const newer = core.navigation.start('newer-request', true);
        assert.equal(initialToken.phase, 'cancelled');
        await core.page.set({ ...initial, component: 'Newer', url: '/newer', flash: {} }, { visitId: newer.id, replace: true });
        assert.equal(newer.phase, 'settled');
        resolveInitial({ name: 'Initial' });
        await new Promise(resolve => setImmediate(resolve));
        await core.history.processQueue();
        assert.equal(initialToken.committed, false);
        assert.equal(core.page.get().url, '/newer');
        assert.deepEqual(mounts, ['/newer']);
        assert.ok(writes.length > 0);
        assert.ok(writes.every(write => write.url === '/newer' && write.page.url === '/newer'));
        assert.deepEqual(events, []);
        assert.deepEqual(observedErrors, []);
    } else {
        const failure = new Error('Unexpected initial resolver failure');
        assert.ok(ownedChain);
        const diagnostic = ownedChain.then(() => assert.fail('Unexpected failure must remain rejected'), error => error);
        rejectInitial(failure);
        assert.equal(await diagnostic, failure);
        assert.deepEqual(observedErrors, []);
        assert.equal(initialToken.committed, false);
        assert.deepEqual(mounts, []);
        assert.deepEqual(writes, []);
        assert.deepEqual(events, []);
    }
    console.log('INITIAL_VISIT_CONTRACT_PASS');
}
