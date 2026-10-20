import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const compile = source => ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
const colorsUrl = moduleUrl(compile(await readFile('resources/js/appearance/colors.ts', 'utf8')));
const colors = await import(colorsUrl);
test('hex validation rejects CSS injection, shorthand and malformed values', () => {
    for (const value of ['#000000', '#FFFFFF', '#abc123']) assert.equal(colors.validColor(value), true);
    for (const value of ['red', '#abc', '#123456;', '#zzzzzz', ' #123456']) assert.equal(colors.validColor(value), false);
});
test('foreground and text inks maintain WCAG AA across extremes and the RGB cube', () => {
    for (let r = 0; r <= 255; r += 17) for (let g = 0; g <= 255; g += 17) for (let b = 0; b <= 255; b += 17) {
        const color = '#' + [r, g, b].map(c => c.toString(16).padStart(2, '0')).join('');
        const a = colors.luminance(color), f = colors.luminance(colors.foreground(color));
        assert.ok((Math.max(a, f) + .05) / (Math.min(a, f) + .05) >= 4.5, color);
        assert.ok(1.05 / (colors.luminance(colors.ink(color)) + .05) >= 7, color);
    }
    assert.equal(colors.foreground('#ffffff'), '#000000'); assert.equal(colors.foreground('#000000'), '#ffffff');
    assert.equal(colors.colorTokens('#FFFFFF', '#000000')['--ba-brand-primary'], '#ffffff');
});
async function state() {
    const runtime = { reads: [], writes: [], close: new Set(), post: async () => { throw Error('lost acknowledgement'); }, reloads: 0, resource: { revision: 1, primary: '#155f53', secondary: '#334b73', defaults: colors.DEFAULT_COLORS } };
    const key = 'appearance' + crypto.randomUUID().replaceAll('-', ''); globalThis[key] = runtime;
    const vue = import.meta.resolve('vue');
    const transport = moduleUrl(`const r=globalThis.${key};export const owned=()=>true,stamp=()=>0,close=()=>{},onClose=fn=>r.close.add(fn);export class TransportError extends Error{constructor(status,body={}){super();this.status=status;this.body=body;}}`);
    const api = moduleUrl(`const r=globalThis.${key};export const api={GET:async(path,options)=>{r.reads.push({path,options});return {data:{data:r.resource,visibility:{protected:true,path}}}},POST:async(path,options)=>{r.writes.push(options.body);return r.post()}};`);
    const presentation = moduleUrl(`export const status={value:'ready'},publish=(_admission,fn)=>fn();`);
    let source = compile(await readFile('resources/js/appearance/state.ts', 'utf8'));
    for (const [name, url] of Object.entries({ vue, '../spa/api': api, '../spa/transport': transport, '../spa/presentation': presentation, './colors': colorsUrl })) source = source.replaceAll(`from '${name}'`, `from '${url}'`);
    source = source.replace('location.reload()', `globalThis.${key}.reloads++`);
    const module = await import(moduleUrl(source));
    return { runtime, module, draft: module.createDraft(runtime.resource), TransportError: (await import(transport)).TransportError };
}
test('admitted reads preserve a dirty draft and its originating revision; never write global theme', async () => {
    const { module: m, runtime, draft } = await state(); await m.readAppearance();
    draft.primary = '#abcdef'; runtime.resource = { ...runtime.resource, revision: 2, primary: '#111111' }; await m.readAppearance();
    assert.equal(draft.revision, 1); assert.equal(draft.primary, '#abcdef'); assert.equal(m.appearance.value.revision, 2);
    assert.equal(runtime.reads.length, 4); assert.equal(runtime.writes.length, 0);
});
test('lost ACK blocks repeat and read alone cannot unlock publication; explicit adoption required', async () => {
    const { module: m, runtime, draft } = await state(); await m.readAppearance(); draft.primary = '#abcdef';
    await m.publishAppearance(draft); assert.equal(draft.outcome, 'unknown'); await m.publishAppearance(draft); assert.equal(runtime.writes.length, 1);
    await m.readAppearance(false, true); await m.publishAppearance(draft); assert.equal(runtime.writes.length, 1);
    m.adoptPublished(draft, m.appearance.value); draft.primary = '#abcdef'; await m.publishAppearance(draft); assert.equal(runtime.writes.length, 2);
});
test('stale 409 preserves draft fields and revision; acknowledged publish reloads document', async () => {
    const { module: m, runtime, draft, TransportError } = await state(); await m.readAppearance(); draft.primary = '#abcdef';
    runtime.post = async () => { throw new TransportError(409); }; await m.publishAppearance(draft); assert.equal(draft.outcome, 'stale'); assert.equal(draft.revision, 1); assert.equal(draft.primary, '#abcdef');
    await m.publishAppearance(draft); assert.equal(runtime.writes.length, 1);
    m.adoptPublished(draft, m.appearance.value); runtime.post = async () => ({ data: { saved: true, appearance: runtime.resource } }); await m.publishAppearance(draft); assert.equal(runtime.reloads, 1); assert.equal(draft.outcome, 'acknowledged');
});
test('invalid inputs never issue a write; preview is container-scoped', async () => {
    const { module: m, runtime, draft } = await state(); await m.readAppearance(); draft.primary = 'red'; await m.publishAppearance(draft); assert.equal(runtime.writes.length, 0);
    const view = await readFile('resources/js/appearance/AppearanceView.vue', 'utf8');
    assert.match(view, /class="appearance-preview ba-theme-scope" :style="preview"/); assert.doesNotMatch(view, /documentElement|setProperty|localStorage/);
});

test('each editor owns a fresh draft and leaving aborts any late response without reload', async () => {
    const { module: m, runtime, draft } = await state(); await m.readAppearance();
    draft.primary = '#abcdef';
    const replacement = m.createDraft(m.appearance.value);
    assert.equal(replacement.primary, '#155f53'); assert.notEqual(replacement, draft);
    let acknowledge; runtime.post = () => new Promise(resolve => { acknowledge = resolve; });
    const cancellation = new AbortController(); const pending = m.publishAppearance(draft, cancellation.signal);
    cancellation.abort(); acknowledge({ data: { saved: true, appearance: runtime.resource } }); await pending;
    assert.equal(runtime.reloads, 0); assert.equal(draft.outcome, 'pending');
    await m.publishAppearance(draft, cancellation.signal); assert.equal(runtime.writes.length, 1);
    const view = await readFile('resources/js/appearance/AppearanceView.vue', 'utf8');
    assert.match(view, /const draft = createDraft/); assert.match(view, /onBeforeUnmount\(\(\) => \{ cancellation.abort\(\)/);
    assert.match(view, /draft.outcome !== 'acknowledged'/);
});

test('appearance source preserves UTF8 Arabic and representative preview states', async () => {
    const bytes = await readFile('resources/js/appearance/AppearanceView.vue');
    const source = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    assert.equal(source.includes('\ufffd'), false);
    assert.equal((source.match(/data-preview-status/g) ?? []).length, 3);
    assert.match(source, /data-preview-disabled disabled/);
    assert.ok(source.includes('\u0646\u0638\u0631\u0629 \u0639\u0644\u0649 \u0645\u0633\u0627\u062d\u0629 \u0627\u0644\u0639\u0645\u0644'));
});
