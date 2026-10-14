import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import path from 'node:path';
import { buildArtifact, verifyUpstream, verifySourcePatch } from '../compat/inertia-n1/build.mjs';

const root = path.resolve(new URL('..', import.meta.url).pathname.replace(/^\/(\w:)/, '$1'));
const manifest = JSON.parse(await readFile(new URL('../compat/inertia-n1/provenance.json', import.meta.url), 'utf8'));
const out = path.join(root, 'storage/framework/inertia-n1/infrastructure-test');
const built = await buildArtifact({ root, output: out, patched: false, testExports: true });
const original = await readFile(path.join(root, 'node_modules/@inertiajs/core/dist/index.js'), 'utf8');
await writeFile(path.join(out, 'original-fixture.mjs'), original + '\nexport { page, history, Queue, Request };\n');
const windowFixture = () => {
    const calls = [];
    globalThis.window = { navigator: { userAgent: 'N1 infrastructure fixture' }, location: new URL('http://localhost/a'),
        history: { state: null, replaceState(state, _, url) { this.state = state; calls.push(['replace',url]); },
            pushState(state, _, url) { this.state = state; calls.push(['push',url]); } } };
    return calls;
};
windowFixture();
const stock = await import(pathToFileURL(path.join(out, 'original-fixture.mjs')));
const rebuilt = await import(pathToFileURL(built.target));

test('N1 infrastructure pins installed source, declarations, and both 3.7.1 versions', async () => {
    assert.equal((await verifyUpstream(root)).size, 52);
    assert.equal(manifest.packages.core.version, '3.7.1');
    assert.equal(manifest.packages.vue3.version, '3.7.1');
});
test('N1 infrastructure rejects version, compiled-source, source-map, and builder drift', async () => {
    for (const change of [m=>m.packages.core.version='3.7.2',m=>m.packages.vue3.version='3.7.2',
        m=>m.packages.core.files['dist/index.js']='invalid',m=>m.sources['history.ts']='invalid',m=>m.builderVersion='invalid']) {
        const changed = structuredClone(manifest);change(changed);
        await assert.rejects(verifyUpstream(root, changed), /drift/);
    }
});
test('N1 unmodified artifact rebuild is deterministic and preserves runtime exports', async () => {
    const second = await buildArtifact({ root, output: out, patched: false, testExports: true });
    assert.equal(second.code, built.code);
    assert.deepEqual(Object.keys(rebuilt).sort(), Object.keys(stock).sort());
});
test('N1 overlay, review diff and overlay file-set drift are rejected',async()=>{
    for(const change of [m=>m.patchHashes['history.ts']='invalid',m=>m.patchSha256='invalid',m=>delete m.patchHashes['navigation.ts']]){
        const changed=structuredClone(manifest);change(changed);await assert.rejects(verifySourcePatch(changed),/drift/);
    }
});
test('N1 patched artifact rebuild is deterministic without test-only exports',async()=>{
    const directory=path.join(root,'storage/framework/inertia-n1/production-rebuild-test');
    const first=await buildArtifact({root,output:directory});const second=await buildArtifact({root,output:directory+'-other'});
    assert.equal(first.sha256,second.sha256);assert.equal(first.code,second.code);
    const module=await import(pathToFileURL(first.target));assert.equal(typeof module.configureNavigation,'function');
    assert.equal('navigation' in module,false);assert.equal('history' in module,false);
});
test('N1 unmodified artifact preserves ordinary History writes, remembered state, and callbacks', async () => {
    const run = async module => {
        const calls = windowFixture();
        const initial = { component:'Fixture', url:'/a', props:{errors:{}}, flash:{}, rememberedState:{}, encryptHistory:false };
        module.page.init({ initialPage:initial,resolveComponent:async()=>({}),swapComponent:async()=>{} });
        await new Promise(resolve=>module.history.replaceState(initial,resolve));
        module.history.remember({ draft:'sales' },'principal:context:workspace');
        await module.history.processQueue();
        await new Promise(resolve=>module.history.pushState({...initial,url:'/b'},resolve));
        return {calls,remembered:module.history.restore('principal:context:workspace'),url:module.history.getAllState().url};
    };
    assert.deepEqual(await run(rebuilt),await run(stock));
});
