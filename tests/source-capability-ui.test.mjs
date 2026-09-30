import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const filename = new URL('../resources/js/Pages/Workspaces/SourceCapabilityStatus.vue', import.meta.url);
const { descriptor } = parse(await readFile(filename, 'utf8'));
const script = compileScript(descriptor, { id: 'bb1-status-proof', genDefaultAs: '__component' });
const template = compileTemplate({ source: descriptor.template.content, filename: filename.pathname,
    id: 'bb1-status-proof', ssr: true, ssrCssVars: descriptor.cssVars, compilerOptions: { bindingMetadata: script.bindings } });
assert.deepEqual(template.errors, []);
const compiled = ts.transpileModule(script.content + '\n' + template.code.replace('export function ssrRender', 'function ssrRender')
    + '\n__component.ssrRender = ssrRender; export default __component;', { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText
    .replace(/from ["']vue["']/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`)
    .replace(/from ["']vue\/server-renderer["']/g, `from ${JSON.stringify(import.meta.resolve('vue/server-renderer'))}`);
const { default: component } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'));

for (const state of ['UNKNOWN', 'VERIFICATION_NEEDED', 'REVOKED', 'STALE', 'PARTIAL', 'FAILED', 'UNSUPPORTED']) {
    test(`real Vue rendering preserves ${state} and absence of independent verification`, async () => {
        const html = await renderToString(createSSRApp(component, { capability: {
            state, reason: 'synthetic_' + state.toLowerCase(), last_verified_at: null,
        } }));
        assert.ok(html.includes(state));
        assert.ok(html.includes('synthetic_' + state.toLowerCase()));
        assert.ok(html.includes('لا يوجد تحقق مستقل'));
        assert.doesNotMatch(html, /Connected|READY/);
    });
}
test('verified timestamp is displayed without converting stale evidence into ready', async () => {
    const html = await renderToString(createSSRApp(component, { capability: {
        state: 'STALE', reason: 'provider_evidence_expired', last_verified_at: '2026-09-30T11:00:00Z',
    } }));
    assert.ok(html.includes('2026-09-30T11:00:00Z'));
    assert.ok(html.includes('STALE'));
    assert.doesNotMatch(html, /Connected|READY/);
});
