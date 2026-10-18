import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import openapiTS, { astToString } from 'openapi-typescript';
import { spec as goals } from './goals-openapi.mjs';
import { sourceContracts } from './sources-openapi.mjs';
import { providerContracts } from './providers-openapi.mjs';
import { accountContracts } from './account-openapi.mjs';
import { authEntryContracts } from './auth-entry-openapi.mjs';
import { internalOperationsContracts } from './internal-operations-openapi.mjs';
import { commercialContracts } from './commercial-openapi.mjs';

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
const purposeParameter = { in: 'path', name: 'purpose', required: true, schema: uuid };
const temporalParameters = ['effective_at', 'known_at'].map(name => ({ in: 'query', name, schema: text, description: 'Existing timezone-qualified temporal filter. Future knowledge and malformed instants remain invalid.' }));
const purposeRead = (operationId, selected, contextual) => ({ operationId, security, parameters: [workspaceParameter, ...(selected ? [purposeParameter] : []), ...temporalParameters, ...(contextual ? headers : [])], responses: { 200: response(contextual ? resource('ObjectPurposeResource') : ref('PurposeProjection')), 404: { description: 'Unknown exact purpose; never an empty successful read.', ...json(ref('Error')) }, ...errors } });
const purposeWrite = (operationId, selected) => ({ operationId, security, parameters: [workspaceParameter, ...(selected ? [purposeParameter] : [])], requestBody: { required: true, ...json(ref('PurposeCommand')) }, responses: { 201: response(ref('PurposeAcknowledgement')), ...errors }, description: 'Original fresh-authenticated Meta-only HTTP admission edge and canonical SQL writer. Command identity is idempotent; uncertain delivery is never automatically replayed or replaced.' });
const sources = sourceContracts({ ref, text, uuid, object, list, nullable, version, response, json, errors, security, headers, workspaceParameter, envelope, resource });
const providers = providerContracts({ ref, text, uuid, object, list, nullable, version, response, json, errors, security, headers, workspaceParameter });
const account = accountContracts({ ref, text, object, list, nullable, response, json, errors, security, headers, envelope, resource });
const authEntry = authEntryContracts({ ref, text, object, list, nullable, response, json, errors, security, headers, envelope, resource });
const internalOperations = internalOperationsContracts({ ref, text, uuid, object, list, nullable, response, json, errors, security, headers, envelope, resource });
const commercial = commercialContracts({ ref, text, uuid, object, list, nullable, response, json, errors, security, headers, envelope, resource });

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
        '/workspaces/{workspace}/object-purposes': { get: purposeRead('readObjectPurposeProjection', false, false), post: purposeWrite('declareObjectPurpose', false) },
        '/workspaces/{workspace}/object-purposes/{purpose}': { get: purposeRead('readSelectedObjectPurposeProjection', true, false) },
        '/workspaces/{workspace}/object-purposes/context': { get: purposeRead('readObjectPurposeContext', false, true) },
        '/workspaces/{workspace}/object-purposes/{purpose}/context': { get: purposeRead('readSelectedObjectPurposeContext', true, true) },
        '/workspaces/{workspace}/object-purposes/{purpose}/revisions': { post: purposeWrite('reviseObjectPurpose', true) },
        '/locale': {
            post: { operationId: 'setApplicationLocale', security,
                requestBody: { required: true, ...json(object({ locale: { enum: ['ar', 'en'] } })) },
                responses: { 200: response(object({ locale: { enum: ['ar', 'en'] } })), ...errors },
            },
        },
        ...sources.paths,
        ...providers.paths,
        ...account.paths,
        ...authEntry.paths,
        ...internalOperations.paths,
        ...commercial.paths,
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
            PurposeObject: object({ provider: text, account_namespace: text, account_id: text, object_kind: text, object_id: text, incarnation: nullable(text) }),
            MetaPurposeObject: { allOf: [ref('PurposeObject'), object({ provider: { const: 'meta' }, account_namespace: { const: 'meta_ads_account' }, account_id: { type: 'string', pattern: '^[0-9]{1,40}$' }, object_kind: { enum: ['campaign', 'ad_set'] }, object_id: { type: 'string', pattern: '^[0-9]{1,40}$' } }, undefined, true)] },
            PurposeSnapshot: object({ meaning: text, qualifications: nullable(text), phase: nullable(text), included_objects: list(ref('PurposeObject')), excluded_objects: list(ref('PurposeObject')), provenance: object({ speaker: nullable(text), statement_at: nullable(text), source: text, identity_basis: text, authority_basis: text, historical_support: nullable(text), effective_basis: text }), dtm: nullable(object({ mode: text, version, countries: list(text), subset: list(text), statement: text })), intent: nullable(object({ version, value: ref('CurrentIntentValue'), statement: text })), end_condition: nullable(text), limitations: nullable(text), conflicts: list(text) }),
            PurposeRevision: object({ purpose_id: uuid, revision_id: uuid, version, operation: text, effective_from: text, effective_until: nullable(text), accepted_at: text, actor_user_id: { type: 'integer' }, actor_generation: { type: 'integer' }, ownership_version: version, snapshot: ref('PurposeSnapshot'), reason: nullable(text), supersedes_revision_id: nullable(uuid), affected_revision_ids: list(uuid) }, undefined, true),
            PurposeItem: object({ purpose_id: uuid, head_version: version, state: text, applicable: nullable(ref('PurposeRevision')), history: list(ref('PurposeRevision')), relationship_revalidation_required: { type: 'boolean' }, continuity: text, dtm_standing: text, scheduled_revision_ids: list(uuid) }),
            PurposeProjection: object({ state: text, items: list(ref('PurposeItem')), current_context_version: version, latest_dtm_event_context_version: version, effective_at: text, known_at: text }),
            ObjectPurposeData: object({ workspace: object({ public_id: uuid, name: text }), projection: ref('PurposeProjection'), selectedPurpose: nullable(uuid), business: ref('BusinessContextProjection') }),
            ObjectPurposeResource: envelope('ObjectPurposeData'),
            PurposeCommand: object({ command_id: uuid, purpose_id: nullable(uuid), expected_version: version, operation: { enum: ['declare', 'replace', 'correct', 'withdraw'] }, declaration: nullable(ref('MetaPurposeSnapshot')), effective_from: nullable(text), effective_until: nullable(text), supersedes_revision_id: nullable(uuid), affected_revision_ids: list(uuid), correction_reason: nullable(text), withdrawal_reason: nullable(text), confirmations: object(Object.fromEntries(['meaning', 'scope', 'authority', 'identity', 'standing', 'dtm', 'intent', 'historical', 'transition'].map(name => [name, { type: 'boolean' }]))) }),
            PurposeAcknowledgement: object({ purpose_id: uuid, revision_id: uuid, version }),
            MetaPurposeSnapshot: { allOf: [ref('PurposeSnapshot'), object({ included_objects: list(ref('MetaPurposeObject')), excluded_objects: list(ref('MetaPurposeObject')) }, undefined, true)] },
            ...sources.schemas,
            ...providers.schemas,
            ...account.schemas,
            ...authEntry.schemas,
            ...internalOperations.schemas,
            ...commercial.schemas,
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
