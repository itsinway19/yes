import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
const moduleUrl = value => 'data:text/javascript;base64,' + Buffer.from(value).toString('base64');
const languageUrl = moduleUrl(`import {ref} from ${JSON.stringify(import.meta.resolve('vue'))};export const locale=ref('ar');`);
const { locale } = await import(languageUrl);
const copyUrl = moduleUrl(ts.transpileModule(await readFile('resources/js/sources/providerCopy.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText.replace("'../spa/locale'", JSON.stringify(languageUrl)));

const filename = new URL('../resources/js/Components/Sources/SourceCapabilityStatus.vue', import.meta.url);
const { descriptor } = parse(await readFile(filename, 'utf8'));
const script = compileScript(descriptor, { id: 'bb1-status-proof', genDefaultAs: '__component' });
const template = compileTemplate({ source: descriptor.template.content, filename: filename.pathname,
    id: 'bb1-status-proof', ssr: true, ssrCssVars: descriptor.cssVars, compilerOptions: { bindingMetadata: script.bindings } });
assert.deepEqual(template.errors, []);
const compiled = ts.transpileModule(script.content + '\n' + template.code.replace('export function ssrRender', 'function ssrRender')
    + '\n__component.ssrRender = ssrRender; export default __component;', { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText
    .replace(/from ["']vue["']/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`)
    .replace(/from ["']vue\/server-renderer["']/g, `from ${JSON.stringify(import.meta.resolve('vue/server-renderer'))}`)
    .replace(/from ["']\.\.\/\.\.\/sources\/providerCopy["']/g, `from ${JSON.stringify(copyUrl)}`);
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
test('English capability copy preserves raw status, reason and timestamp', async () => {
    locale.value = 'en';
    try {
        const html = await renderToString(createSSRApp(component, { capability: { state: 'UNKNOWN', reason: 'provider_unverified', last_verified_at: null } }));
        assert.match(html, /Last independent verification:/); assert.match(html, /No independent verification/); assert.match(html, /UNKNOWN/); assert.match(html, /provider_unverified/); assert.doesNotMatch(html, /آخر تحقق|لا يوجد تحقق/);
    } finally { locale.value = 'ar'; }
});
