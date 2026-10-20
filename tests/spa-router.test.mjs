import test from 'node:test';
import assert from 'node:assert/strict';
import { createMemoryHistory } from 'vue-router';
import { createApplicationRouter } from '../resources/js/spa/router.ts';

const deferred = () => { let resolve, reject; const promise = new Promise((yes,no)=>{resolve=yes;reject=no}); return {promise,resolve,reject}; };
function fixture(routes, prepare = async route => () => {}) {
    const state = { view: '', pending: false, concealed: false, failures: 0, publications: [], signals: [] };
    const router = createApplicationRouter(routes, {
        pending: (active, passive) => { state.pending = active; if(active && passive)state.concealed=true; },
        prepare: async (route, passive, signal) => { state.signals.push({path:route.path,passive,signal}); return prepare(route,passive,signal); },
        commit: (route, publish) => { publish(); state.view = route.path; state.publications.push(route.path); state.pending = false; },
        failed: () => { state.failures++; state.pending = false; },
    }, createMemoryHistory());
    return {router,state};
}
const routes = () => ['/first','/second','/newer'].map(path=>({path,component:{}}));

test('source route and admitted presentation remain unchanged until the target is fully prepared', async () => {
    const waiting=deferred(), entered=deferred();
    const {router,state}=fixture(routes(),async route=>{if(route.path==='/second'){entered.resolve();await waiting.promise;}return ()=>{};});
    await router.push('/first');const pending=router.push('/second');await entered.promise;
    assert.equal(router.currentRoute.value.path,'/first');assert.equal(state.view,'/first');assert.equal(state.pending,true);
    waiting.resolve();await pending;assert.equal(router.currentRoute.value.path,'/second');assert.equal(state.view,'/second');
});
test('rejected data or witness never commits a target or destroys the admitted source', async()=>{
    const {router,state}=fixture(routes(),async route=>{if(route.path==='/second')throw Error('403 or witness refused');return ()=>{};});
    await router.push('/first');await router.push('/second');assert.equal(router.currentRoute.value.path,'/first');assert.equal(state.view,'/first');assert.equal(state.failures,1);assert.deepEqual(state.publications,['/first']);
});
test('a route guard abort does not publish the target or reload the source',async()=>{
    const {router,state}=fixture([...routes(),{path:'/denied',component:{},beforeEnter:()=>false}]);
    await router.push('/first');await router.push('/denied');assert.equal(state.view,'/first');assert.deepEqual(state.publications,['/first']);assert.equal(state.pending,false);
});
test('component loading failure rejects only its own candidate',async()=>{
    const {router,state}=fixture([...routes(),{path:'/broken',component:()=>Promise.reject(Error('missing chunk'))}]);
    await router.push('/first');await assert.rejects(router.push('/broken'),/missing chunk/);assert.equal(state.view,'/first');assert.equal(state.pending,false);
});
test('a superseded data request is aborted and cannot publish over a newer route even if it ignores abort',async()=>{
    const waiting=deferred(),entered=deferred();let obsoleteSignal;
    const {router,state}=fixture(routes(),async(route,_passive,signal)=>{if(route.path==='/second'){obsoleteSignal=signal;entered.resolve();await waiting.promise;}return ()=>{};});
    await router.push('/first');const obsolete=router.push('/second');await entered.promise;await router.push('/newer');waiting.resolve();await obsolete;
    assert.equal(obsoleteSignal.aborted,true);assert.equal(state.view,'/newer');assert.deepEqual(state.publications,['/first','/newer']);
});
for(const rejects of [false,true])test(`a delayed superseded chunk (${rejects?'rejected':'resolved'}) cannot change the admitted newer view`,async()=>{
    const chunk=deferred(),entered=deferred();
    const {router,state}=fixture([...routes(),{path:'/chunk',component:()=>{entered.resolve();return chunk.promise;}}]);
    await router.push('/first');const old=router.push('/chunk');await entered.promise;await router.push('/newer');
    const result=rejects?assert.rejects(old,/old chunk/):old;rejects?chunk.reject(Error('old chunk')):chunk.resolve({});await result;
    assert.equal(state.view,'/newer');assert.equal(state.failures,0);
});
test('rapid A to B to A cancels B even when A is a duplicated navigation',async()=>{
    const chunk=deferred(),entered=deferred();
    const {router,state}=fixture([...routes(),{path:'/chunk',component:()=>{entered.resolve();return chunk.promise;}}]);
    await router.push('/first');const old=router.push('/chunk');await entered.promise;await router.push('/first');chunk.resolve({});await old;
    assert.equal(state.view,'/first');assert.deepEqual(state.publications,['/first']);assert.equal(state.pending,false);
});
test('history traversal asks for passive evidence; ordinary navigation does not',async()=>{
    const {router,state}=fixture(routes());await router.push('/first');await router.push('/second');
    const done=deferred();router.afterEach(to=>{if(to.path==='/first')done.resolve();});router.back();await done.promise;
    assert.equal(state.signals.at(-1).passive,true);assert.equal(state.signals[1].passive,false);
});
test('a bounded stalled lazy candidate releases progress without destroying the source',async t=>{
    t.mock.timers.enable({apis:['setTimeout']});const entered=deferred(),chunk=deferred();
    const {router,state}=fixture([...routes(),{path:'/stalled',component:()=>{entered.resolve();return chunk.promise;}}]);
    await router.push('/first');const old=router.push('/stalled');await entered.promise;t.mock.timers.tick(30000);
    assert.equal(state.pending,false);assert.equal(state.view,'/first');assert.equal(state.failures,1);
    chunk.resolve({});await old;assert.equal(router.currentRoute.value.path,'/first');t.mock.timers.reset();
});

test('native history traversal conceals retained source before preparing a held target',async()=>{
    let hold=false;const entered=deferred(),release=deferred();
    const {router,state}=fixture(routes(),async route=>{if(hold && route.path==='/first'){entered.resolve();await release.promise;}return ()=>{};});
    await router.push('/first');await router.push('/second');hold=true;
    const done=deferred();router.afterEach(to=>{if(to.path==='/first')done.resolve()});
    router.back();await entered.promise;
    assert.equal(state.concealed,true);assert.equal(state.view,'/second');assert.equal(router.currentRoute.value.path,'/second');
    release.resolve();await done.promise;assert.equal(state.view,'/first');
});
