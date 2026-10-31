export function internalOperationsContracts({ ref, text, uuid, object, list, nullable, response, json, errors, security, headers, envelope, resource }) {
    const person = object({ public_id: uuid, name: text, email: text, email_verified_at: nullable(text), kind: { enum: ['founder', 'employee'] }, status: { enum: ['pending', 'active', 'revoked'] }, attested_at: nullable(text), factor_independence_attested_at: nullable(text), passkey_count: { type: 'integer', minimum: 0 } });
    const operator = nullable(object({ application_configured: { type: 'boolean' }, notice_links_configured: { type: 'boolean' } }));
    const kind = { enum: ['users', 'workspaces'] }, decision = { enum: ['suspend', 'reactivate'] }, integer = { type: 'integer', minimum: 0 };
    const userStatus = { enum: ['pending', 'current', 'disabled'] }, workspaceStatus = { enum: ['active', 'suspended', 'archived'] };
    const row = { public_id: uuid, name: text, revision: integer };
    const history = list(object({ decision, reason: text, occurred_at: text, actor_name: text }));
    const detail = { public_id: uuid, name: text, history };
    const customerQueries = [{ name: 'kind', schema: kind }, { name: 'search', schema: { type: 'string', maxLength: 120 } }, { name: 'status', schema: { enum: ['', ...userStatus.enum, ...workspaceStatus.enum] } }, { name: 'page', schema: { type: 'integer', minimum: 1, maximum: 100000 } }, { name: 'selected', schema: uuid }].map(parameter => ({ in: 'query', ...parameter }));
    const identifier = name => ({ in: 'path', name, required: true, schema: name === 'staff' ? uuid : { type: 'integer', minimum: 1 } });
    const write = (operationId, schema, parameters = []) => ({ operationId, security, parameters, ...(schema ? { requestBody: { required: true, ...json(ref(schema)) } } : {}), responses: { 200: response(ref('InternalOperationAcknowledgement')), 302: { description: 'Existing non-JSON command redirect/flash; same authority writer and middleware.' }, ...errors } });
    const flag = { type: 'boolean' }, positive = { type: 'integer', minimum: 1 }, provider = { enum: ['meta', 'google', 'snapchat', 'tiktok', 'x'] };
    const bounded = (minLength, maxLength) => ({ type: 'string', minLength, maxLength });
    const typeFields = { key: { type: 'string', pattern: '^[a-z][a-z0-9_]{0,63}$' }, name_ar: bounded(2, 120), name_en: bounded(2, 120), active: flag };
    const guidance = { provider, priority: { type: 'integer', minimum: 0, maximum: 1000 }, rationale_ar: bounded(1, 600), rationale_en: bounded(1, 600), active: flag };
    const prior = { provider, product: { enum: ['google.search'] }, basis: { enum: ['ADMIN_APPROVED_HYPOTHESIS', 'EVIDENCE_SUPPORTED_PRIOR'] }, documented_basis: bounded(10, 4000), applicability: bounded(10, 2000), limits: bounded(10, 2000), rationale_ar: bounded(10, 600), rationale_en: bounded(10, 600), internal_rationale: bounded(10, 4000), approved: flag, active: flag };
    const serviceIdentifier = { in: 'path', name: 'service', required: true, schema: uuid };
    const credentialWrite = (operationId, schema, parameters = []) => ({ ...write(operationId, schema, parameters), responses: { ...write('', '').responses, 200: response(ref('InternalServiceCredential')) } });
    return {
        paths: {
            '/internal/administration/market-knowledge': { get: { operationId: 'readMarketOperations', security, parameters: [...headers, {in:'query',name:'cursor',schema:uuid}], responses: {200:response(resource('MarketOperationsResource')),...errors} } },
            '/internal/administration/maintenance': {
                get: { operationId: 'readPlatformMaintenance', security, parameters: headers, responses: { 200: response(resource('PlatformMaintenanceResource')), ...errors } },
                post: { operationId: 'changePlatformMaintenance', security, requestBody: { required: true, ...json(ref('PlatformMaintenanceCommand')) }, responses: { 200: response(ref('PlatformMaintenanceAcknowledgement')), ...errors } },
            },
            '/internal/administration/appearance': {
                get: { operationId: 'readGlobalAppearance', security, parameters: headers, responses: { 200: response(resource('GlobalAppearanceResource')), ...errors } },
                post: { operationId: 'publishGlobalAppearance', security, requestBody: { required: true, ...json(ref('GlobalAppearanceCommand')) }, responses: { 200: response(ref('AppearancePublication')), ...errors } },
            },
            '/internal/services': { get: { operationId: 'readInternalServices', security, parameters: headers, responses: { 200: response(resource('InternalServicesResource')), ...errors } }, post: credentialWrite('createInternalService', 'InternalServiceCreate') },
            '/internal/services/{service}/rotate': { post: credentialWrite('rotateInternalService', null, [serviceIdentifier]) },
            '/internal/services/{service}/revoke': { post: write('revokeInternalService', null, [serviceIdentifier]) },
            '/internal/service-grants': { post: write('issueInternalServiceGrant', 'InternalServiceGrantCommand') },
            '/internal/service-grants/{grant}': { delete: write('revokeInternalServiceGrant', null, [identifier('grant')]) },
            '/internal/business-types': { get: { operationId: 'readInternalBusinessTypes', security, parameters: headers, responses: { 200: response(resource('InternalBusinessTypesResource')), ...errors } }, post: write('createInternalBusinessType', 'InternalBusinessTypeCreate') },
            '/internal/business-types/{type}': { post: write('updateInternalBusinessType', 'InternalBusinessTypeUpdate', [identifier('type')]) },
            '/internal/business-types/{type}/guidance': { post: write('saveInternalBusinessGuidance', 'InternalBusinessGuidanceCommand', [identifier('type')]) },
            '/internal/business-types/{type}/prior': { post: write('saveInternalBusinessPrior', 'InternalBusinessPriorCommand', [identifier('type')]) },
            '/internal/administration/customers': { get: { operationId: 'readInternalCustomers', security, parameters: [...headers, ...customerQueries], responses: { 200: response(resource('InternalCustomersResource')), ...errors } } },
            '/internal/administration/customers/status': { post: { operationId: 'changeInternalCustomerStatus', security, requestBody: { required: true, ...json(ref('InternalCustomerStatusCommand')) }, responses: { 200: response(ref('InternalCustomerStatusAcknowledgement')), 404: response(ref('Error')), ...errors } } },
            '/internal/administration': { get: { operationId: 'readInternalAdministration', security, parameters: headers, responses: { 200: response(resource('InternalAdministrationResource')), ...errors } } },
            '/internal/staff': { post: write('inviteInternalStaff', 'InternalStaffInvitation') },
            ...Object.fromEntries(['resend-invitation', 'attest', 'activate', 'revoke', 'attest-factors'].map(action => [`/internal/staff/{staff}/${action}`, { post: write('internalStaff' + action.replaceAll('-', '_'), null, [identifier('staff')]) }])),
            '/internal/grants': { post: write('issueInternalStaffGrant', 'InternalStaffGrantCommand') },
            '/internal/grants/{grant}': { delete: { ...write('revokeInternalStaffGrant', null, [identifier('grant')]), responses: { ...write('', '').responses, 404: response(ref('Error')) } } },
        },
        schemas: {
            MarketOperationsSource: object({source_ref:uuid,custody_revision:positive,state:{enum:['active','restricted','revoked']},rights_window:{enum:['current','not_started','expired']},valid_until:nullable(text)}),
            MarketOperationsSharedKnowledge: object({method_ref:text,publication_state:{enum:['prepared','published','retention_removed']},validity:{enum:['not_published','current','review_required','unknown','unavailable']},processing_outcome:{enum:['completed','abstained','unknown','unavailable']},reason_category:{enum:['none','comparison_basis_insufficient','required_information_missing','generic_abstention','unknown','unavailable']},count:positive}),
            MarketOperationsData: object({shared_knowledge:list(ref('MarketOperationsSharedKnowledge')),shared_knowledge_window:{const:100},sources:list(ref('MarketOperationsSource')),next_cursor:nullable(uuid),methods:list(object({method_ref:text,availability:{enum:['available','unavailable','unknown']}})),private_processing:{const:'business_scoped'},connection_status:{const:'not_observed'}}),
            MarketOperationsResource: envelope('MarketOperationsData'),
            PlatformMaintenanceState: object({ revision: integer, active: flag, message_ar: bounded(1,500), message_en: bounded(1,500), retry_after: {type:'integer',minimum:30,maximum:3600}, changed_at: nullable(text) }),
            PlatformMaintenanceData: object({ maintenance: ref('PlatformMaintenanceState'), history: list(object({ command_id: uuid, actor_user_id: nullable(positive), previous_revision: integer, resulting_revision: integer, reason: text, occurred_at: text })) }),
            PlatformMaintenanceResource: envelope('PlatformMaintenanceData'),
            PlatformMaintenanceCommand: object({ command_id: uuid, revision: {type:'integer',minimum:0,maximum:2147483646}, active: flag, message_ar: bounded(1,500), message_en: bounded(1,500), retry_after: {type:'integer',minimum:30,maximum:3600}, reason: bounded(1,500) }),
            PlatformMaintenanceAcknowledgement: object({ saved: {const:true}, command_id: uuid, maintenance: ref('PlatformMaintenanceState') }),
            GlobalAppearanceColors: object({ primary: { type: 'string', pattern: '^#[0-9a-fA-F]{6}$' }, secondary: { type: 'string', pattern: '^#[0-9a-fA-F]{6}$' } }),
            GlobalAppearanceData: object({ revision: integer, primary: text, secondary: text, defaults: ref('GlobalAppearanceColors') }),
            GlobalAppearanceResource: envelope('GlobalAppearanceData'),
            GlobalAppearanceCommand: object({ revision: { type: 'integer', minimum: 0, maximum: 2147483646 }, primary: { type: 'string', pattern: '^#[0-9a-fA-F]{6}$' }, secondary: { type: 'string', pattern: '^#[0-9a-fA-F]{6}$' } }),
            AppearancePublication: object({ saved: { const: true }, appearance: ref('GlobalAppearanceData') }),
            InternalServiceCreate: object({ name: bounded(2, 120) }),
            InternalServiceCredential: object({ public_id: uuid, credential: { type: 'string', pattern: '^[A-Za-z0-9_-]{43}$' } }),
            InternalServiceGrantCommand: object({ service: uuid, workspace: uuid, reason: bounded(10, 128) }),
            InternalServicesData: object({ services: list(object({ public_id: uuid, name: text, status: { enum: ['enabled', 'revoked'] }, version: positive })), scopes: list(object({ service_public_id: uuid, service_name: text, purpose: text, workspace_public_id: uuid })), grants: list(object({ id: positive, service_name: text, reason: text, workspace_public_id: uuid })) }),
            InternalServicesResource: envelope('InternalServicesData'),
            InternalBusinessTypeCreate: object(typeFields),
            InternalBusinessTypeUpdate: object({ ...typeFields, version: positive }),
            InternalBusinessGuidanceCommand: object(guidance),
            InternalBusinessPriorCommand: { oneOf: [object(prior), object({ ...prior, id: positive, version: positive })] },
            InternalBusinessTypesData: object({ canManage: flag, types: list(object({ ...typeFields, id: positive, version: positive, source_guidance: list(object({ ...guidance, business_type_id: positive })), promotion_priors: list(object({ ...prior, id: positive, business_type_id: positive, version: positive, approved_by: nullable(positive), approved_at: nullable(text) })) })), providers: list(object({ key: provider, label: text })), products: list(object({ key: { enum: ['google.search'] }, provider, label_ar: text, label_en: text })) }),
            InternalBusinessTypesResource: envelope('InternalBusinessTypesData'),
            InternalCustomerStatusCommand: object({ command_id: uuid, kind, target: uuid, decision, reason: { type: 'string', minLength: 1, maxLength: 128 }, expected_status: { enum: [...userStatus.enum, 'active', 'suspended'] }, expected_revision: integer }),
            InternalCustomerStatusAcknowledgement: object({ status: { enum: ['pending', 'current', 'disabled', 'active', 'suspended'] }, revision: integer, replayed: { type: 'boolean' } }),
            InternalCustomersData: object({ filters: object({ kind, search: text, status: text, page: { type: 'integer', minimum: 1 } }), freshAuthentication: { type: 'boolean' }, total: integer, rows: list({ oneOf: [object({ ...row, status: userStatus, email: text, email_verified_at: nullable(text), workspace_count: integer, can_reactivate: { type: 'boolean' } }), object({ ...row, status: workspaceStatus, business_type_ar: nullable(text), business_type_en: nullable(text), owner_name: nullable(text), owner_email: nullable(text), market_label: nullable(text), member_count: integer })] }), details: nullable({ oneOf: [object({ ...detail, email: text, workspaces: list(object({ public_id: uuid, name: text, status: workspaceStatus, role: text })) }), object({ ...detail, members: list(object({ name: text, email: text, role: text, status: userStatus })) })] }) }),
            InternalCustomersResource: envelope('InternalCustomersData'),
            InternalOperationAcknowledgement: object({ saved: { const: true } }),
            InternalStaffInvitation: object({ name: { type: 'string', minLength: 2, maxLength: 255 }, email: { type: 'string', format: 'email', maxLength: 255 } }),
            InternalStaffGrantCommand: object({ staff: uuid, action: text, reason: { type: 'string', minLength: 10, maxLength: 128 } }),
            InternalAdministrationData: object({ people: list(person), staffGrants: list(object({ id: { type: 'integer' }, action: text, reason: text, public_id: uuid, name: text })), grantableActions: list(text), canAttestFactors: { type: 'boolean' }, canManageCommercialPreparation: { type: 'boolean' }, permissions: object(Object.fromEntries(['staff.invite', 'staff.attest', 'staff.activate', 'staff.revoke', 'grant.manage', 'service.manage'].map(action => [action, { type: 'boolean' }]))), metaOperatorStatus: operator, googleOperatorStatus: operator, tiktokOperatorStatus: operator, snapchatOperatorStatus: operator, xOperatorStatus: operator }),
            InternalAdministrationResource: envelope('InternalAdministrationData'),
        },
    };
}
