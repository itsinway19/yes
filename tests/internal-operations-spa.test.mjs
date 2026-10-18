import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { ref } from 'vue';
import { createApplicationClient } from '../resources/js/api/application.ts';

const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
async function compile(file, ports) {
    let source = ts.transpileModule(await readFile(new URL('../resources/js/internal-operations/' + file, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
    for (const [name, target] of Object.entries({ vue: import.meta.resolve('vue'), 'openapi-fetch': import.meta.resolve('openapi-fetch'), ...ports })) source = source.replaceAll(`from '${name}'`, 'from ' + JSON.stringify(target));
    return import(moduleUrl(source));
}
test('actual staff helpers retain existing authority command methods and deliver each only once', async () => {
    const calls = [], key = 'operationsApi' + crypto.randomUUID().replaceAll('-', '');
    globalThis[key] = createApplicationClient('https://builderad.test', async request => { calls.push(request.method + ' ' + new URL(request.url).pathname); return Response.json({ saved: true }); });
    try {
        const api = await compile('adminApi.ts', { '../spa/api': moduleUrl(`export const api=globalThis.${key};`) });
        await api.inviteEmployee({ name: 'Employee', email: 'employee@example.test' });
        for (const action of ['resend-invitation', 'attest', 'activate', 'revoke', 'attest-factors']) await api.staffAction('target', action);
        await api.issueGrant({ staff: 'target', action: 'business.manage', reason: 'Explicit authority' }); await api.revokeGrant(7);
        assert.deepEqual(calls, ['POST /internal/staff', ...['resend-invitation', 'attest', 'activate', 'revoke', 'attest-factors'].map(action => 'POST /internal/staff/target/' + action), 'POST /internal/grants', 'DELETE /internal/grants/7']);
    } finally { delete globalThis[key]; }
});
async function stateFixture(reader, file = 'adminState.ts') {
    const key = 'operationsState' + crypto.randomUUID().replaceAll('-', '');
    const runtime = { revision: 0, status: ref('checking'), resumed: 0, closed: false, reader, callbacks: [] }; globalThis[key] = runtime;
    const transport = moduleUrl(`const runtime=globalThis.${key}; export const status=runtime.status,stamp=()=>runtime.revision,owned=value=>value===runtime.revision&&!runtime.closed,resumeLease=()=>runtime.resumed++,onClose=callback=>runtime.callbacks.push(callback),close=()=>{runtime.closed=true;runtime.status.value='closed';runtime.callbacks.forEach(callback=>callback())}; export class TransportError extends Error{constructor(status,body={}){super();this.status=status;this.body=body}}`);
    const state = await compile(file, { '../spa/api': moduleUrl(`export const api={GET:(...args)=>globalThis.${key}.reader(...args),POST:(...args)=>globalThis.${key}.reader(...args)};`), '../spa/transport': transport });
    return { state, runtime, transport: await import(transport), dispose: () => delete globalThis[key] };
}
const visibility = { protected: true, path: '/internal/administration', context: 'current-server-authority' };
const resource = { data: { data: { people: [] }, visibility } };
test('staff passive restoration requires the exact current witness and does not promote a read to a command acknowledgement', async () => {
    const calls = [], view = await stateFixture(async (url, options) => { calls.push(options.headers); return options.headers['X-Builderad-Witness'] ? { data: { visibility } } : resource; });
    try {
        view.state.adminOutcome.value = 'unknown'; await view.state.readAdministration(true);
        assert.deepEqual(calls, [{ 'X-Builderad-Passive': '1' }, { 'X-Builderad-Witness': '1' }]);
        assert.equal(view.runtime.status.value, 'ready'); assert.equal(view.state.adminOutcome.value, 'unknown');
    } finally { view.dispose(); }
});
test('acknowledged staff command stays acknowledged when current-view readback is unavailable', async () => {
    let fail = false; const view = await stateFixture(async (_url, options) => { if (fail) throw Error('Readback transport unavailable'); return options.headers['X-Builderad-Witness'] ? { data: { visibility } } : resource; });
    try {
        await view.state.readAdministration(); view.state.adminOutcome.value = 'acknowledged'; fail = true;
        assert.equal(await view.state.readAdministration(false, true), undefined);
        assert.equal(view.state.adminOutcome.value, 'acknowledged'); assert.equal(view.runtime.status.value, 'ready');
    } finally { view.dispose(); }
});
test('post-load authority denial closes staff presentation and clears private drafts', async () => {
    const view = await stateFixture(async () => { throw new view.transport.TransportError(403); });
    try {
        view.state.invitation.email = 'private@example.test'; view.state.adminOutcome.value = 'unknown';
        await view.state.readAdministration(false, true);
        assert.equal(view.runtime.status.value, 'closed'); assert.equal(view.state.invitation.email, ''); assert.equal(view.state.adminOutcome.value, '');
    } finally { view.dispose(); }
});
test('older staff read cannot publish after presentation ownership changes', async () => {
    let deliver; const view = await stateFixture(() => new Promise(resolve => { deliver = resolve; }));
    try {
        const pending = view.state.readAdministration(); view.runtime.revision++; view.state.clear(); deliver(resource); await pending;
        assert.equal(view.state.administration.value, undefined); assert.equal(view.runtime.resumed, 0);
    } finally { view.dispose(); }
});

test('customer witness must bind the exact Laravel query URI, including spaces and selected row', async () => {
    const query = { kind: 'users', search: 'Customer Projection', selected: 'selected' };
    const calls = [], customerVisibility = { ...visibility, path: '/internal/administration/customers?kind=users&search=Customer%20Projection&selected=selected' };
    const view = await stateFixture(async (_url, options) => { calls.push(options); return { data: { ...(options.headers['X-Builderad-Witness'] ? {} : { data: { rows: [] } }), visibility: customerVisibility } }; }, 'customerState.ts');
    try {
        await view.state.readCustomers(query, true);
        assert.equal(view.runtime.status.value, 'ready'); assert.deepEqual(calls.map(call => call.params.query), [query, query]);
        customerVisibility.path = '/internal/administration/customers'; await view.state.readCustomers(query);
        assert.equal(view.runtime.closed, true); assert.equal(view.state.customers.value, undefined);
    } finally { view.dispose(); }
});

test('customer command retains the original immutable idempotency payload and does not retry transport failure', async () => {
    const calls = [], view = await stateFixture(async (url, options) => { calls.push({ url, ...options }); throw Error('ACK lost'); }, 'customerState.ts');
    const command = Object.freeze({ command_id: crypto.randomUUID(), kind: 'workspaces', target: crypto.randomUUID(), decision: 'suspend', reason: 'Explicit reason', expected_status: 'active', expected_revision: 0 });
    try {
        await assert.rejects(view.state.changeCustomerStatus(command), /ACK lost/);
        assert.equal(calls.length, 1); assert.equal(calls[0].body, command);
    } finally { view.dispose(); }
});

test('obsolete customer read cannot publish after clear or authority denial', async () => {
    let deliver; const view = await stateFixture(() => new Promise(resolve => { deliver = resolve; }), 'customerState.ts');
    try {
        const pending = view.state.readCustomers(); view.state.clearCustomers(); deliver({ data: { data: { rows: ['obsolete'] }, visibility } }); await pending;
        assert.equal(view.state.customers.value, undefined); assert.equal(view.runtime.resumed, 0);
        view.runtime.reader = async () => { throw new view.transport.TransportError(403); }; await view.state.readCustomers({}, false, true);
        assert.equal(view.runtime.closed, true);
    } finally { view.dispose(); }
});

test('business commands retain original Laravel endpoints and each command is sent once', async () => {
    const calls = [], key = 'businessApi' + crypto.randomUUID().replaceAll('-', '');
    globalThis[key] = createApplicationClient('https://builderad.test', async request => { calls.push({ method: request.method, path: new URL(request.url).pathname, body: await request.json() }); return Response.json({ saved: true }); });
    const runtime = moduleUrl(`export const ref=()=>({value:''}),shallowRef=()=>({});`);
    try {
        const business = await compile('businessState.ts', { vue: runtime, '../spa/api': moduleUrl(`export const api=globalThis.${key};`), '../spa/transport': moduleUrl('export const close=()=>{},onClose=()=>{},owned=()=>true,resumeLease=()=>{},stamp=()=>0,status={}; export class TransportError extends Error{}') });
        const type = { key: 'operations', name_ar: 'عمليات', name_en: 'Operations', active: true };
        await business.writeBusiness({ kind: 'create', body: type });
        await business.writeBusiness({ kind: 'update', type: 7, body: { ...type, version: 1 } });
        await business.writeBusiness({ kind: 'guidance', type: 7, body: { provider: 'google', priority: 0, rationale_ar: 'توجيه', rationale_en: 'Guidance', active: true } });
        await business.writeBusiness({ kind: 'prior', type: 7, body: { provider: 'google', product: 'google.search' } });
        assert.deepEqual(calls.map(call => call.method + ' ' + call.path), ['POST /internal/business-types', 'POST /internal/business-types/7', 'POST /internal/business-types/7/guidance', 'POST /internal/business-types/7/prior']);
        assert.deepEqual(calls[0].body, type); assert.equal(calls[2].body.priority, 0);
    } finally { delete globalThis[key]; }
});

test('business passive reads do not settle an unknown writer and authority denial closes the resource', async () => {
    const businessVisibility = { ...visibility, path: '/internal/business-types' };
    const view = await stateFixture(async (_url, options) => ({ data: { ...(options.headers['X-Builderad-Witness'] ? {} : { data: { types: [] } }), visibility: businessVisibility } }), 'businessState.ts');
    try {
        view.state.businessOutcome.value = 'unknown'; await view.state.readBusinessTypes(true);
        assert.equal(view.runtime.status.value, 'ready'); assert.equal(view.state.businessOutcome.value, 'unknown');
        view.runtime.reader = async () => { throw new view.transport.TransportError(403); }; await view.state.readBusinessTypes(false, true);
        assert.equal(view.state.businessTypes.value, undefined); assert.equal(view.runtime.closed, true);
    } finally { view.dispose(); }
});

test('service issuance helper returns one-time delivery directly and never retries a lost rotation ACK', async () => {
    const calls = [], key = 'serviceApi' + crypto.randomUUID().replaceAll('-', '');
    globalThis[key] = createApplicationClient('https://builderad.test', async request => { calls.push(request.method + ' ' + new URL(request.url).pathname); if (request.url.endsWith('/rotate')) throw Error('Lost rotation ACK'); return Response.json({ public_id: 'service', credential: 'synthetic-delivery-only' }); });
    try {
        const services = await compile('serviceApi.ts', { '../spa/api': moduleUrl(`export const api=globalThis.${key};`) });
        const issued = await services.createService({ name: 'Test service' }); assert.equal(issued.credential, 'synthetic-delivery-only');
        await assert.rejects(services.rotateService('service'), /Lost rotation ACK/); assert.deepEqual(calls, ['POST /internal/services', 'POST /internal/services/service/rotate']);
    } finally { delete globalThis[key]; }
});

test('service readback neither settles unknown secret delivery nor accepts an obsolete authority witness', async () => {
    const serviceVisibility = { ...visibility, path: '/internal/services' };
    const view = await stateFixture(async (_url, options) => ({ data: { ...(options.headers['X-Builderad-Witness'] ? {} : { data: { services: [], scopes: [], grants: [] } }), visibility: serviceVisibility } }), 'serviceState.ts');
    try {
        view.state.serviceOutcome.value = 'unknown'; await view.state.readServices(true); assert.equal(view.runtime.status.value, 'ready'); assert.equal(view.state.serviceOutcome.value, 'unknown');
        assert.deepEqual(Object.keys(view.state.services.value), ['services', 'scopes', 'grants']);
        view.runtime.reader = async (_url, options) => ({ data: { ...(options.headers['X-Builderad-Witness'] ? {} : { data: { services: [] } }), visibility: { ...serviceVisibility, context: options.headers['X-Builderad-Witness'] ? 'revoked' : 'old' } } });
        await view.state.readServices(false, true); assert.equal(view.runtime.closed, true); assert.equal(view.state.services.value, undefined);
    } finally { view.dispose(); }
});
