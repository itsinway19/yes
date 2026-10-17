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
