import test from 'node:test';
import assert from 'node:assert/strict';
import { createMemoryHistory } from 'vue-router';
import { createApplicationRouter } from '../resources/js/spa/router.ts';

test('the shared router never publishes the target of a cancelled navigation', async () => {
    const events = [];
    let status = 'checking';
    const router = createApplicationRouter([
        { path: '/first', component: {} },
        { path: '/second', component: {}, beforeEnter: () => false },
    ], {
        suspend: () => { status = 'checking'; events.push('suspend'); },
        load: route => { status = 'ready'; events.push(route.path); },
        unavailable: () => events.push('unavailable'),
    }, createMemoryHistory());
    await router.push('/first');
    assert.deepEqual(events, ['suspend', '/first']);
    await router.push('/second');
    assert.equal(router.currentRoute.value.path, '/first');
    assert.deepEqual(events.filter(event => event.startsWith('/')), ['/first', '/first']);
    assert.equal(events.includes('unavailable'), false);
    assert.equal(status, 'ready');
});

test('component loading failure closes presentation and cannot publish the failed route', async () => {
    const events = [];
    const router = createApplicationRouter([
        { path: '/first', component: {} },
        { path: '/broken', component: async () => { throw Error('chunk unavailable'); } },
    ], {
        suspend: () => events.push('suspend'),
        load: route => events.push(route.path),
        unavailable: () => events.push('unavailable'),
    }, createMemoryHistory());
    await router.push('/first');
    await assert.rejects(router.push('/broken'), /chunk unavailable/);
    assert.equal(router.currentRoute.value.path, '/first');
    assert.equal(events.includes('/broken'), false);
    assert.equal(events.at(-1), 'unavailable');
});

test('a delayed component from a superseded navigation cannot publish over the newer route', async () => {
    const published = [];
    let release;
    let started;
    const entered = new Promise(resolve => { started = resolve; });
    const chunk = new Promise(resolve => { release = resolve; });
    const router = createApplicationRouter([
        { path: '/first', component: {} },
        { path: '/delayed', component: () => { started(); return chunk; } },
        { path: '/newer', component: {} },
    ], {
        suspend: () => {},
        load: route => published.push(route.path),
        unavailable: () => assert.fail('Superseded work is not a component error'),
    }, createMemoryHistory());
    await router.push('/first');
    const obsolete = router.push('/delayed');
    await entered;
    await router.push('/newer');
    release({ default: {} });
    await obsolete;
    assert.equal(router.currentRoute.value.path, '/newer');
    assert.deepEqual(published, ['/first', '/newer']);
});

test('failure of a superseded component cannot close the newer accepted presentation', async () => {
    const events = [];
    let rejectChunk;
    let started;
    const entered = new Promise(resolve => { started = resolve; });
    const chunk = new Promise((_resolve, reject) => { rejectChunk = reject; });
    const router = createApplicationRouter([
        { path: '/first', component: {} },
        { path: '/delayed', component: () => { started(); return chunk; } },
        { path: '/newer', component: {} },
    ], {
        suspend: () => events.push('suspend'),
        load: route => events.push(route.path),
        unavailable: () => events.push('unavailable'),
    }, createMemoryHistory());
    await router.push('/first');
    const obsolete = router.push('/delayed');
    await entered;
    await router.push('/newer');
    const checkpoint = [...events];
    rejectChunk(Error('obsolete component failure'));
    await assert.rejects(obsolete, /obsolete component failure/);
    assert.deepEqual(events, checkpoint);
    assert.equal(router.currentRoute.value.path, '/newer');
});

test('returning to the current route supersedes a pending chunk and restores its presentation', async () => {
    let status = 'checking';
    let rejectChunk;
    let started;
    const entered = new Promise(resolve => { started = resolve; });
    const chunk = new Promise((_resolve, reject) => { rejectChunk = reject; });
    const router = createApplicationRouter([
        { path: '/first', component: {} },
        { path: '/delayed', component: () => { started(); return chunk; } },
    ], {
        suspend: () => { status = 'checking'; },
        load: () => { status = 'ready'; },
        unavailable: () => { status = 'unavailable'; },
    }, createMemoryHistory());
    await router.push('/first');
    const obsolete = router.push('/delayed');
    await entered;
    await router.push('/first');
    rejectChunk(Error('superseded chunk'));
    await assert.rejects(obsolete, /superseded chunk/);
    assert.equal(router.currentRoute.value.path, '/first');
    assert.equal(status, 'ready');
});
