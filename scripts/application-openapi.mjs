import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import openapiTS, { astToString } from 'openapi-typescript';
import { spec as goals } from './goals-openapi.mjs';

const ref = name => ({ $ref: `#/components/schemas/${name}` });
const text = { type: 'string' };
const uuid = { type: 'string', format: 'uuid' };
const object = (properties, required = Object.keys(properties), additionalProperties = false) => ({ type: 'object', properties, required, additionalProperties });
const list = items => ({ type: 'array', items });
const json = schema => ({ content: { 'application/json': { schema } } });
const response = schema => ({ description: 'Authorized existing Laravel owner response.', ...json(schema) });
const errors = Object.fromEntries([401, 403, 409, 419, 422, 503].map(status => [status, {
    description: status === 409 ? 'Identity context conflict; close the document when identityConflict is true.' : 'Original Laravel authorization, CSRF, validation or service failure.',
    ...json(status === 422 ? ref('ValidationError') : ref('Error')),
}]));
const security = [{ sessionCookie: [], documentReceipt: [] }];
const headers = ['X-Builderad-Passive', 'X-Builderad-Witness'].map(name => ({ in: 'header', name, schema: { enum: ['1'] } }));
const resource = name => ({ oneOf: [ref(name), ref('GoalsWitness')] });
const envelope = data => object({ data: ref(data), locale: { enum: ['ar', 'en'] }, visibility: ref('Visibility') });
const version = { type: 'integer', minimum: 0 };
const nullable = schema => ({ anyOf: [schema, { type: 'null' }] });
const workspaceParameter = { in: 'path', name: 'workspace', required: true, schema: uuid };
const businessCommand = (operationId, schema) => ({ operationId, security, parameters: [workspaceParameter], requestBody: { required: true, ...json(ref(schema)) }, responses: { 200: response(ref('ApplicationDestination')), 413: { description: 'Original technical input-size boundary; preserve the full draft.', ...json(ref('Error')) }, ...errors } });

export const spec = {
    openapi: '3.1.0',
    info: { title: 'BuilderAd private web application API', version: '1.0.0', description: 'Same-origin web contracts. Laravel identity, session, grants, WorkspaceContext and RLS retain authority. Not a native authentication contract.' },
    paths: {
        ...goals.paths,
        '/workspaces': {
            get: { operationId: 'listWorkspaces', security, parameters: headers, responses: { 200: response(resource('WorkspaceIndexResource')), ...errors } },
        },
        '/business/start': {
            get: { operationId: 'readBusinessCreation', security,
                parameters: [...headers, { in: 'query', name: 'new', schema: { enum: ['1'] } }],
                responses: { 200: response(resource('BusinessCreationResource')), 302: { description: 'Existing businesses redirect to their list unless new=1. Use a new native document for this boundary.' }, ...errors } },
            post: { operationId: 'createBusiness', security,
                requestBody: { required: true, ...json(ref('CreateBusiness')) },
                responses: { 200: response(ref('ApplicationDestination')), ...errors },
                description: 'Existing atomic WorkspaceAuthority/BusinessContext writer and session creation key. No automatic retry after uncertain delivery. An explicit same-command retry preserves the original key and body.',
            },
        },
        '/workspaces/{workspace}/reassess-owner-grant': {
            post: { operationId: 'reassessOwnerGrant', security,
                parameters: [{ in: 'path', name: 'workspace', required: true, schema: uuid }],
                responses: { 200: response(ref('ApplicationDestination')), ...errors },
                description: 'Existing fresh-authenticated GrantAuthority owner reassessment; no revoked or expired grant is restored.',
            },
        },
        '/workspaces/{workspace}/business': {
            get: { operationId: 'readBusinessContext', security, parameters: [workspaceParameter, ...headers], responses: { 200: response(resource('BusinessContextResource')), ...errors } },
            post: businessCommand('updateBusinessContext', 'UpdateBusinessContext'),
        },
        '/workspaces/{workspace}/business/authority': { post: businessCommand('requestBusinessContextAuthority', 'RequestBusinessContextAuthority') },
        '/workspaces/{workspace}/business/target-markets': { post: businessCommand('replaceBusinessTargetMarkets', 'ReplaceTargetMarkets') },
        '/workspaces/{workspace}/business/current-intent': { post: businessCommand('replaceOrClearCurrentIntent', 'CurrentIntentCommand') },
        '/locale': {
            post: { operationId: 'setApplicationLocale', security,
                requestBody: { required: true, ...json(object({ locale: { enum: ['ar', 'en'] } })) },
                responses: { 200: response(object({ locale: { enum: ['ar', 'en'] } })), ...errors },
            },
        },
    },
    components: {
        securitySchemes: goals.components.securitySchemes,
        schemas: {
            ...goals.components.schemas,
            WorkspaceReference: object({ public_id: uuid, name: text, slug: text }),
            WorkspaceIndexData: object({ workspaces: list(ref('WorkspaceReference')), reassessableWorkspaces: list(ref('WorkspaceReference')) }),
            WorkspaceIndexResource: envelope('WorkspaceIndexData'),
            BusinessType: object({ id: { type: 'integer', minimum: 1 }, key: text, name_ar: text, name_en: text, active: { type: 'boolean' }, version: { type: 'integer', minimum: 0 } }),
            BusinessCreationData: object({ types: list(ref('BusinessType')), canManage: { const: true }, canRequestAuthority: { const: false }, creationKey: uuid, version: { const: 0 } }),
            BusinessCreationResource: envelope('BusinessCreationData'),
            CreateBusiness: object({ name: { type: 'string', minLength: 2, maxLength: 120 }, business_type_id: { type: 'integer', minimum: 1 }, creation_key: uuid }),
            ApplicationDestination: object({ redirect: text }),
            CountryReference: object({ code: { type: 'string', pattern: '^[A-Z]{2}$' }, name_ar: text, name_en: text }),
            CurrentIntentValue: object({ direction: { enum: ['increase_value', 'preserve_value', 'improve_efficiency_preserving_value', 'validate_business_direction'] }, scope: { const: 'business_wide' }, description: nullable(text), outcome: object({ meaning: text, clarification: nullable(text), count_unit: nullable(text) }), quantity: nullable(text), horizon: nullable(object({ deadline_date: text, timezone: text })) }),
            CurrentIntentProjection: object({ state: { enum: ['NOT_DECLARED', 'PRESENT'] }, value: nullable(ref('CurrentIntentValue')), version }),
            BusinessContextProjection: object({ type: nullable(object({ id: { type: 'integer' }, key: text, name_ar: text, name_en: text, active: { type: 'boolean' } })), version, currentIntent: ref('CurrentIntentProjection'), canManage: { type: 'boolean' }, targetMarketsDeclared: { type: 'boolean' }, targetMarkets: list(ref('CountryReference')) }),
            BusinessContextData: object({ workspace: object({ public_id: uuid, name: text, business_type_id: nullable({ type: 'integer' }), business_context_version: version }), types: list(ref('BusinessType')), canManage: { type: 'boolean' }, canRequestAuthority: { type: 'boolean' }, version, business: ref('BusinessContextProjection'), countryOptions: list(ref('CountryReference')), timezoneOptions: list(text) }),
            BusinessContextResource: envelope('BusinessContextData'),
            UpdateBusinessContext: object({ name: { type: 'string', minLength: 2, maxLength: 120 }, business_type_id: { type: 'integer', minimum: 1 }, version }),
            RequestBusinessContextAuthority: object({ decision: { const: 'authorize' }, reason: { const: 'complete_business_context' } }),
            ReplaceTargetMarkets: object({ country_codes: list(text), version }),
            CurrentIntentCommand: object({ command: { enum: ['replace', 'clear'] }, value: nullable(ref('CurrentIntentValue')), confirmations: { type: 'object', properties: Object.fromEntries(['declaration', 'business_wide', 'outcome', 'acceptance_clear', 'clarification_required', 'quantity', 'horizon'].map(name => [name, { type: 'boolean' }])), additionalProperties: false }, version }),
        },
    },
};

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    for (const [path, content] of Object.entries({
        'contracts/application.openapi.json': JSON.stringify(spec, null, 2) + '\n',
        'resources/js/api/generated/application.d.ts': astToString(await openapiTS(spec)),
    })) {
        if (process.argv.includes('--check')) {
            if (await readFile(path, 'utf8') !== content) throw Error(`Generated application contract drift: ${path}`);
        } else await writeFile(path, content);
    }
    console.log(process.argv.includes('--check') ? 'Application contract and types match' : 'Application contract and types generated privately');
}
