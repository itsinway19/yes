import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import openapiTS, { astToString } from 'openapi-typescript';
import { spec as goals } from './goals-openapi.mjs';
import { sourceContracts } from './sources-openapi.mjs';
import { providerContracts } from './providers-openapi.mjs';
import { accountContracts } from './account-openapi.mjs';
import { authEntryContracts } from './auth-entry-openapi.mjs';
import { internalOperationsContracts } from './internal-operations-openapi.mjs';
import { contentContracts } from './content-openapi.mjs';
import { commercialContracts } from './commercial-openapi.mjs';
import { businessFoundationContracts } from './business-foundation-openapi.mjs';
import { marketKnowledgeContracts } from './market-knowledge-openapi.mjs';
import { businessActivityContracts } from './business-activity-openapi.mjs';
import { inquiryEvidenceContracts } from './inquiry-evidence-openapi.mjs';

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
const organizationErrors = { ...errors,
    ...Object.fromEntries([403, 409, 422].map(status => [status, { description: 'Organization membership admission rejected; this does not establish an earlier command outcome.',
        ...json({ anyOf: [ref('OrganizationTeamError'), ref('Error'), ...(status === 422 ? [ref('ValidationError')] : [])] }) }])) };
const headers = ['X-Builderad-Passive', 'X-Builderad-Witness'].map(name => ({ in: 'header', name, schema: { enum: ['1'] } }));
const resource = name => ({ oneOf: [ref(name), ref('GoalsWitness')] });
const envelope = data => object({ data: ref(data), locale: { enum: ['ar', 'en'] }, visibility: ref('Visibility') });
const version = { type: 'integer', minimum: 0 };
const nullable = schema => ({ anyOf: [schema, { type: 'null' }] });
const businessParameter = { in: 'path', name: 'business', required: true, schema: uuid };
const businessCommand = (operationId, schema) => ({ operationId, security, parameters: [businessParameter], requestBody: { required: true, ...json(ref(schema)) }, responses: { 200: response(ref('ApplicationDestination')), 413: { description: 'Original technical input-size boundary; preserve the full draft.', ...json(ref('Error')) }, ...errors } });
const purposeParameter = { in: 'path', name: 'purpose', required: true, schema: uuid };
const temporalParameters = ['effective_at', 'known_at'].map(name => ({ in: 'query', name, schema: text, description: 'Existing timezone-qualified temporal filter. Future knowledge and malformed instants remain invalid.' }));
const purposeRead = (operationId, selected, contextual) => ({ operationId, security, parameters: [businessParameter, ...(selected ? [purposeParameter] : []), ...temporalParameters, ...(contextual ? headers : [])], responses: { 200: response(contextual ? resource('ObjectPurposeResource') : ref('PurposeProjection')), 404: { description: 'Unknown exact purpose; never an empty successful read.', ...json(ref('Error')) }, ...errors } });
const purposeWrite = (operationId, selected) => ({ operationId, security, parameters: [businessParameter, ...(selected ? [purposeParameter] : [])], requestBody: { required: true, ...json(ref('PurposeCommand')) }, responses: { 201: response(ref('PurposeAcknowledgement')), ...errors }, description: 'Original fresh-authenticated Meta-only HTTP admission edge and canonical SQL writer. Command identity is idempotent; uncertain delivery is never automatically replayed or replaced.' });
const sources = sourceContracts({ ref, text, uuid, object, list, nullable, version, response, json, errors, security, headers, businessParameter, envelope, resource });
const providers = providerContracts({ ref, text, uuid, object, list, nullable, version, response, json, errors, security, headers, businessParameter });
const account = accountContracts({ ref, text, object, list, nullable, response, json, errors, security, headers, envelope, resource });
const authEntry = authEntryContracts({ ref, text, object, list, nullable, response, json, errors, security, headers, envelope, resource });
const internalOperations = internalOperationsContracts({ ref, text, uuid, object, list, nullable, response, json, errors, security, headers, envelope, resource });
const content = contentContracts({ ref, text, uuid, object, list, nullable, response, json, errors, security, headers, resource, envelope });
const commercial = commercialContracts({ ref, text, uuid, object, list, nullable, response, json, errors, security, headers, envelope, resource });

const foundation = businessFoundationContracts({ ref, text, uuid, object, list, nullable, json, response, security, errors });
const marketKnowledge = marketKnowledgeContracts({ ref, text, uuid, object, list, nullable, json, response, security, errors });
const activity = businessActivityContracts({ ref, text, uuid, object, list, nullable, json, response, security, errors });

const inquiryEvidence = inquiryEvidenceContracts({ref,text,uuid,object,list,nullable,json,response,security,errors});

export const spec = {
    openapi: '3.1.0',
    info: { title: 'BuilderAd private web application API', version: '1.0.0', description: 'Same-origin web contracts. Laravel identity, session, grants, Business qualification and RLS retain authority. Not a native authentication contract.' },
    paths: {
        ...goals.paths,
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
        ...content.paths,
        ...commercial.paths,
        ...foundation.paths,
        ...marketKnowledge.paths,
        ...activity.paths,
        ...inquiryEvidence.paths,
    },
    components: {
        securitySchemes: goals.components.securitySchemes,
        schemas: {
            ...goals.components.schemas,
            BusinessType: object({ id: { type: 'integer', minimum: 1 }, key: text, name_ar: text, name_en: text, active: { type: 'boolean' }, version: { type: 'integer', minimum: 0 } }),
            ApplicationDestination: object({ redirect: text }),
            CountryReference: object({ code: { type: 'string', pattern: '^[A-Z]{2}$' }, name_ar: text, name_en: text }),
            CurrentIntentValue: object({ direction: { enum: ['increase_value', 'preserve_value', 'improve_efficiency_preserving_value', 'validate_business_direction'] }, scope: { const: 'business_wide' }, description: nullable(text), outcome: object({ meaning: text, clarification: nullable(text), count_unit: nullable(text) }), quantity: nullable(text), horizon: nullable(object({ deadline_date: text, timezone: text })) }),
            CurrentIntentProjection: object({ state: { enum: ['NOT_DECLARED', 'PRESENT'] }, value: nullable(ref('CurrentIntentValue')), version }),
            BusinessContextProjection: object({ type: nullable(object({ id: { type: 'integer' }, key: text, name_ar: text, name_en: text, active: { type: 'boolean' } })), version, currentIntent: ref('CurrentIntentProjection'), canManage: { type: 'boolean' }, targetMarketsDeclared: { type: 'boolean' }, targetMarkets: list(ref('CountryReference')) }),
            PurposeObject: object({ provider: text, account_namespace: text, account_id: text, object_kind: text, object_id: text, incarnation: nullable(text) }),
            MetaPurposeObject: { allOf: [ref('PurposeObject'), object({ provider: { const: 'meta' }, account_namespace: { const: 'meta_ads_account' }, account_id: { type: 'string', pattern: '^[0-9]{1,40}$' }, object_kind: { enum: ['campaign', 'ad_set'] }, object_id: { type: 'string', pattern: '^[0-9]{1,40}$' } }, undefined, true)] },
            PurposeSnapshot: object({ meaning: text, qualifications: nullable(text), phase: nullable(text), included_objects: list(ref('PurposeObject')), excluded_objects: list(ref('PurposeObject')), provenance: object({ speaker: nullable(text), statement_at: nullable(text), source: text, identity_basis: text, authority_basis: text, historical_support: nullable(text), effective_basis: text }), dtm: nullable(object({ mode: text, version, countries: list(text), subset: list(text), statement: text })), intent: nullable(object({ version, value: ref('CurrentIntentValue'), statement: text })), end_condition: nullable(text), limitations: nullable(text), conflicts: list(text) }),
            PurposeRevision: object({ purpose_id: uuid, revision_id: uuid, version, operation: text, effective_from: text, effective_until: nullable(text), accepted_at: text, actor_user_id: { type: 'integer' }, actor_generation: { type: 'integer' }, ownership_version: version, snapshot: ref('PurposeSnapshot'), reason: nullable(text), supersedes_revision_id: nullable(uuid), affected_revision_ids: list(uuid) }, undefined, true),
            PurposeItem: object({ purpose_id: uuid, head_version: version, state: text, applicable: nullable(ref('PurposeRevision')), history: list(ref('PurposeRevision')), relationship_revalidation_required: { type: 'boolean' }, continuity: text, dtm_standing: text, scheduled_revision_ids: list(uuid) }),
            PurposeProjection: object({ state: text, items: list(ref('PurposeItem')), current_context_version: version, latest_dtm_event_context_version: version, effective_at: text, known_at: text }),
            PurposeCommand: object({ command_id: uuid, purpose_id: nullable(uuid), expected_version: version, operation: { enum: ['declare', 'replace', 'correct', 'withdraw'] }, declaration: nullable(ref('MetaPurposeSnapshot')), effective_from: nullable(text), effective_until: nullable(text), supersedes_revision_id: nullable(uuid), affected_revision_ids: list(uuid), correction_reason: nullable(text), withdrawal_reason: nullable(text), confirmations: object(Object.fromEntries(['meaning', 'scope', 'authority', 'identity', 'standing', 'dtm', 'intent', 'historical', 'transition'].map(name => [name, { type: 'boolean' }]))) }),
            PurposeAcknowledgement: object({ purpose_id: uuid, revision_id: uuid, version }),
            MetaPurposeSnapshot: { allOf: [ref('PurposeSnapshot'), object({ included_objects: list(ref('MetaPurposeObject')), excluded_objects: list(ref('MetaPurposeObject')) }, undefined, true)] },
            ...sources.schemas,
            ...providers.schemas,
            ...account.schemas,
            ...authEntry.schemas,
            ...internalOperations.schemas,
            ...content.schemas,
            ...commercial.schemas,
            ...foundation.schemas,
            ...marketKnowledge.schemas,
            ...activity.schemas,
            ...inquiryEvidence.schemas,
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
