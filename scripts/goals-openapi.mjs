import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import openapiTS, { astToString } from 'openapi-typescript';

// Deliberately explicit contract for the existing, hand-validated HTTP writer.
// No inference plugin or public documentation routes are registered in Laravel.
const ref = name => ({ $ref: `#/components/schemas/${name}` });
const object = (properties, required = Object.keys(properties), extra = false) => ({ type: 'object', properties, required, additionalProperties: extra });
const text = { type: 'string' };
const list = items => ({ type: 'array', items });
const json = schema => ({ content: { 'application/json': { schema } } });
const error = description => ({ description, ...json(ref('Error')) });
const failures = {
    401: error('No current authenticated session. A stale document may instead be rejected with identityConflict 409 before auth.'),
    403: error('Unverified principal, absent Workspace scope or withdrawn grant. No write effect.'),
    409: error('CAS mismatch: preserve draft and explicitly review current version. identityConflict=true instead closes the document; never adopt it as a CAS conflict.'),
    419: error('CSRF rejection, including a transport session that no longer contains the CSRF token. No write effect.'),
    422: { description: 'Invalid exact JSON value/version shape, size or domain selection; errors.value contains validation messages.', ...json(ref('ValidationError')) },
};
const parameters = [{ name: 'workspace', in: 'path', required: true, schema: { type: 'string', format: 'uuid' } }];
const headers = {
    'X-Builderad-Identity': { description: 'Server document evidence, verified by the web transport. Not independent authority.', schema: text },
    'Cache-Control': { schema: text },
};
const response = schema => ({ description: 'Authorized canonical projection. Never shared-cache private data.', headers, ...json(schema) });
// Ordinary Goals requests admit the document receipt. Transition challenges are
// used by identity transitions, not a separate prerequisite of the Goals writer.
const security = [{ sessionCookie: [], documentReceipt: [] }];
const operation = (operationId, schema) => ({ operationId, security, parameters, responses: { 200: response(schema), ...failures } });
const preferences = object({ value: ref('GoalValue'), version: { type: 'integer', minimum: 0 } });
export const spec = {
    openapi: '3.1.0', info: { title: 'BuilderAd internal Goals contract', version: '1.0.0', description: 'Native SPA Goals. Cookies and identity headers describe the WEB transport only. Not a mobile authentication design.' },
    paths: {
        '/workspaces/{workspace}/goals': {
            get: { ...operation('readGoals', ref('Preferences')), description: 'Accept application/json for canonical preferences. Native HTML requests receive the unified SPA shell, without a business projection.' },
            post: { ...operation('saveGoals', ref('Preferences')), description: 'Canonical existing writer; exact two-field JSON object, maximum 8192 bytes. No automatic retry after uncertain delivery. Same-origin Laravel CSRF checks remain authoritative.', requestBody: { required: true, ...json(ref('SaveGoals')) }, parameters: [...parameters, { name: 'X-CSRF-TOKEN', in: 'header', schema: text, description: 'Browser CSRF token. Laravel 13 may accept trusted same-origin Fetch Metadata before token validation; absent that evidence a valid token is required.' }] },
        },
        '/workspaces/{workspace}/goals/context': {
            get: { ...operation('readCanonicalGoalsContext', { oneOf: [ref('GoalsContext'), ref('GoalsWitness')] }), description: 'Unified SPA contextual read through the existing WorkspaceContext, Goals and BusinessContext readers. Passive/witness requests preserve canonical idle authority.', parameters: [...parameters, { name: 'X-Goals-Passive', in: 'header', schema: { enum: ['1'] } }, { name: 'X-Goals-Witness', in: 'header', schema: { enum: ['1'] } }] },
        },
    },
    components: {
        securitySchemes: {
            sessionCookie: { type: 'apiKey', in: 'cookie', name: 'builderad-session', description: 'Default advertiser cookie; SESSION_COOKIE may override it. Server SQL/Redis admission remains mandatory.' },
            documentReceipt: { type: 'apiKey', in: 'header', name: 'X-Builderad-Document' },
            transitionReceipt: { type: 'apiKey', in: 'header', name: 'X-Builderad-Transition' },
        },
        schemas: {
            GoalValue: object({ goals: list(text), priority: { type: ['string', 'null'] }, results: { type: 'object', additionalProperties: list(text) } }),
            Preferences: preferences,
            SaveGoals: object({ value: ref('WriteGoalValue'), version: { type: 'integer', minimum: 0 } }),
            WriteGoalValue: { ...object({ goals: { ...list(text), minItems: 1, maxItems: 4, uniqueItems: true }, priority: text, results: { type: 'object', additionalProperties: { ...list(text), uniqueItems: true } } }), description: 'Canonical SQL additionally requires exact catalog IDs, selected priority, results only for selected goals and results from the matching catalog. It normalizes ordering and empty results. Initial read may be empty/null; that state is not a valid write.' },
            Error: object({ message: text, identityConflict: { type: 'boolean' } }, [], true),
            ValidationError: object({ message: text, errors: { type: 'object', additionalProperties: list(text) } }),
            Visibility: object({ protected: { type: 'boolean' }, path: text, context: text }),
            GoalsWitness: object({ visibility: ref('Visibility') }),
            GoalsContext: object({
                workspace: object({ public_id: { type: 'string', format: 'uuid' }, name: text }), preferences: ref('Preferences'),
                business: object({ type: { anyOf: [object({ name_ar: text, name_en: text }, ['name_ar', 'name_en'], true), { type: 'null' }] }, canManage: { type: 'boolean' } }, ['type', 'canManage'], true),
                catalog: list(object({ id: text, name_ar: text, name_en: text, description_ar: text, description_en: text, results: list(object({ id: text, name_ar: text, name_en: text })) })),
                visibility: ref('Visibility'),
            }),
        },
    },
};

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const outputs = {
        'contracts/goals.openapi.json': JSON.stringify(spec, null, 2) + '\n',
        'resources/js/api/generated/goals.d.ts': astToString(await openapiTS(spec)),
    };
    for (const [path, content] of Object.entries(outputs)) {
        if (process.argv.includes('--check')) {
            if (await readFile(path, 'utf8') !== content) throw Error(`Generated contract drift: ${path}`);
        } else {
            await mkdir(path.slice(0, path.lastIndexOf('/')), { recursive: true });
            await writeFile(path, content);
        }
    }
    console.log(process.argv.includes('--check') ? 'Goals contract and types match' : 'Goals contract and types generated privately');
}
