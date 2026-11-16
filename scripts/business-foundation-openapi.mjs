/** Only implemented Business foundation endpoints; no material/migration capability is advertised here. */
export function businessFoundationContracts({ ref, text, uuid, object, list, nullable, json, response, security, errors }) {
    const version = { type: 'integer', minimum: 0 };
    const id = { type: 'integer', minimum: 1 };
    const requirement = object({ requirement_kind: { enum: ['business', 'client_data', 'data_use', 'native_source'] }, leaf_grant_id: id });
    const realm = { enum: ['direct'] };
    const authority = object({ realm_kind: realm, requirements: { ...list(requirement), minItems: 1, maxItems: 32 } });
    const command = { command_id: uuid, authority_selection: ref('BusinessAuthoritySelection') };
    const versions = fields => object(Object.fromEntries(fields.map(name => [name, version])));
    const body = fields => object({ ...command, ...fields });
    const data = schema => object({ data: schema });
    const licenseSelection = object({ native_namespaces: { ...list(text), minItems: 1, maxItems: 50 }, native_keys: { ...list(text), minItems: 1, maxItems: 1000 },
        field_keys: { ...list(text), minItems: 1, maxItems: 100 }, partition_ref: { type: 'null' } });
    const licenseDeclaration = object({ source_id: uuid, resource: { ...text, minLength: 1, maxLength: 180 },
        operation: { enum: ['retained_read', 'display', 'process', 'reuse', 'combine', 'export'] }, purpose: { ...text, minLength: 1, maxLength: 128 },
        selection: ref('DataLicenseSelection'),
        recipient_public_id: uuid, valid_until: { type: 'string', format: 'date-time' }, capacity: { enum: ['data_holder', 'authorized_representative'] },
        holder_name: { ...text, minLength: 2, maxLength: 160 }, authority_reference: { ...text, minLength: 8, maxLength: 512 },
        confirmed: { const: true }, requires_review: { const: false } });
    const licenseCommand = { oneOf: [body({ license_id: uuid, effect: { const: 'issue' }, expected_revision: { const: 0 }, declaration: licenseDeclaration }),
        body({ license_id: uuid, effect: { const: 'withdraw' }, expected_revision: { const: 1 }, declaration: object({}) })] };
    const licenseResult = object({ license_id: uuid, boundary_id: uuid, boundary_grant_id: { ...text, pattern: '^[1-9][0-9]{0,18}$' },
        use_grant_id: { ...text, pattern: '^[1-9][0-9]{0,18}$' }, status: { enum: ['active', 'withdrawn'] },
        assurance: { const: 'DECLARED_AUTHORITY' }, versions: object({ license: { enum: [1, 2] } }) });
    const business = { in: 'path', name: 'business', required: true, schema: uuid };
    const endpoint = (operationId, request, result, status = 200, selected = true) => ({ operationId, security, parameters: selected ? [business] : [],
        description: 'This is the current web ingress; its session/document credentials do not define authentication for other clients. Resource and command/result schemas are independent of Vue. Own principal transaction; explicit selected grant paths requalified in PostgreSQL. Payer and cached state confer no Business authority. Commands are never automatically retried after an uncertain response.',
        requestBody: { required: true, ...json(request) }, responses: { [status]: response(data(result)), ...errors,
            403: { description: 'Current scoped authority denied; no authority inferred from membership.', ...json({ anyOf: [ref('BusinessFoundationError'), ref('Error')] }) },
            409: { description: 'business_relation_revision_rejected is a typed pre-effect relationship revision rejection. Generic business_conflict is not evidence of the original command outcome; retain and reconcile it.', ...json({ anyOf: [ref('BusinessFoundationError'), ref('Error')] }) },
            422: { description: 'Typed command, shape or semantic validation rejected.', ...json({ anyOf: [ref('BusinessFoundationError'), ref('ValidationError'), ref('Error')] }) },
        } });
    const fact = properties => object({ business_public_id: uuid, ...properties });
    const draft = fact({ name: text, state: { const: 'declared_draft' }, grant_refs: object(Object.fromEntries([
        'business.context.read', 'business.context.manage', 'business.intent.declare', 'business.markets.declare',
        'business.preferences.manage', 'business.link.manage', 'business.draft.archive'].map(action => [action, id]))), versions: versions(['context', 'intent', 'preferences']) });
    const projection = fact({ name: text, business_type_id: nullable(id), state: { enum: ['declared_draft', 'active', 'suspended', 'archived'] },
        current_intent: nullable(ref('CurrentIntentValue')), preferences: nullable(ref('GoalValue')), declared_markets: list(text), versions: versions(['context', 'intent', 'preferences']),
         });
    const contextResult = fact({ name: text, business_type_id: nullable(id), versions: versions(['context']) });
    const marketsResult = fact({ codes: list(text), versions: versions(['context']) });
    const preferenceResult = fact({ value: ref('GoalValue'), versions: versions(['preferences']) });
    const intentResult = fact({ value: nullable(ref('CurrentIntentValue')), versions: versions(['context', 'intent']) });
    const grantResult = fact({ grant_id: id, status: { enum: ['active', 'revoked'] }, versions: versions(['grant']) });
    const agencyCommand = body({ effect: { enum: ['mandate', 'assign'] }, agency_business_public_id: uuid,
        recipient_public_id: uuid, membership_grant_id: id,
        action: { enum: ['business.context.read', 'business.context.manage', 'business.intent.declare', 'business.markets.declare', 'business.preferences.manage'] },
        parent_grant_id: id, expires_at: { type: 'string', format: 'date-time' }, confirmed: { const: true } });
    // Reuse the existing wire selection. Direct realm and
    // one exact Business parent are semantic admission rules enforced by SQL.
    agencyCommand.properties.authority_selection = ref('BusinessAuthoritySelection');
    const agencyResult = fact({ agency_business_public_id: uuid, grant_id: id, status: { const: 'active' },
        qualification_ref: uuid, versions: versions(['grant']) });
    const agencyOptions = fact({ agency_business_public_id: uuid, effect: { enum: ['mandate', 'assign'] },
        recipient: object({ public_id: uuid, membership_grant_id: id }),
        options: { ...list(object({ action: agencyCommand.properties.action, parent_grant_id: id,
            authority_selection: agencyCommand.properties.authority_selection, max_expires_at: nullable({ type: 'string', format: 'date-time' }) })), maxItems: 30 },
        scope: { const: 'current_declaration_agency_only' } });
    const teamIdentity = object({ public_id: uuid, name: text, email: { type: 'string', format: 'email', maxLength: 254 } });
    const teamGrant = object({ grant_id: { type: 'string', pattern: '^[1-9][0-9]{0,18}$' }, version: id,
        action: agencyCommand.properties.action, expires_at: nullable({ type: 'string', format: 'date-time' }) });
    const teamMembers = fact({ members: { ...list(object({ ...teamIdentity.properties, grants: list(teamGrant) })), maxItems: 64 },
        next_cursor: nullable(id), actions: list(agencyCommand.properties.action) });
    const teamCommand = body({ recipient_public_id: uuid,
        expected_grants: { ...list(object({ grant_id: { ...teamGrant.properties.grant_id, pattern: '^[1-9][0-9]{0,17}$' }, version: id })), maxItems: 256 },
        permissions: { ...list(agencyCommand.properties.action), uniqueItems: true, maxItems: 5 },
        expires_at: nullable({ type: 'string', format: 'date-time' }), confirmed: { const: true } });
    const teamResult = fact({ recipient_public_id: uuid, status: { const: 'recorded' },
        grant_refs: { type: 'object', additionalProperties: teamGrant.properties.grant_id }, versions: versions(['authority_epoch']) });
    const agencyMandates = fact({ items: list(object({ agency_business_public_id: uuid,
        grants: list(object({ grant_id: teamGrant.properties.grant_id, version: id })), complete: { type: 'boolean' } })), more: { type: 'boolean' }, next_cursor: nullable(uuid) });
    const agencyMandatesCommand = body({ agency_business_public_id: uuid,
        expected_grants: { ...list(object({ grant_id: { ...teamGrant.properties.grant_id, pattern: '^[1-9][0-9]{0,17}$' }, version: id })), maxItems: 256 }, confirmed: { const: true } });
    const agencyMandatesResult = fact({ agency_business_public_id: uuid, status: { const: 'withdrawn' }, versions: versions(['authority_epoch']) });
    const bindingResult = object({ binding_id: nullable(uuid), status: { enum: ['active', 'ended'] }, versions: versions(['binding']) });
    const boundaryResult = object({ membership_id: uuid, status: { enum: ['active', 'ended'] }, versions: versions(['membership']) });
    const revokeResult = object({ grant_id: id, status: { const: 'revoked' }, versions: versions(['grant']) });
    const sourceGrantResult = object({ grant_id: id, status: { const: 'active' }, versions: versions(['grant']) });
    const sourceAdoptionResult = object({ source_id: uuid, fence_id: uuid, adoption_state: { const: 'v2_adopted' }, versions: versions(['source', 'rights_epoch']) });
    const sourceRetirementResult = object({ source_id: uuid, status: { enum: ['UNVERIFIED', 'REVOKED'] }, versions: versions(['source']), native_receipt: ref('SourceReceipt') });
    const lifecycleResult = { oneOf: [fact({ state: { enum: ['declared_draft', 'active', 'suspended', 'archived'] }, versions: versions(['lifecycle']) }),
        fact({ branch_public_id: uuid, status: { enum: ['active', 'archived'] }, versions: versions(['branch']) })] };
    const decimalId = { type: 'string', pattern: '^[1-9][0-9]{0,18}$' };
    // Historical installed surfaces omit agency metadata; it describes the exact
    // read/grant basis without changing the evaluator's authority selection.
    const agencyMetadata = schema => object({ ...schema.properties, agency_business_public_id: nullable(uuid) }, schema.required);
    const localized = { id: text, name_ar: text, name_en: text };
    const sector = { id, key: text, name_ar: text, name_en: text, active: { type: 'boolean' } };
    const catalog = object({ business_types: list(object({ ...sector, version: id })),
        countries: list(object({ code: text, name_ar: text, name_en: text })), intent_timezones: list(text),
        goals: list(object({ ...localized, description_ar: text, description_en: text, results: list(object(localized)) })) });
    const scope = object({ principal_generation: id, authority_fingerprint: { type: 'string', pattern: '^[0-9a-f]{64}$' } });
    const choices = object({ actor_public_id: uuid, items: list(agencyMetadata(fact({ name: text, state: text, read_grant_id: decimalId, realm_kind: realm, }))), next_cursor: nullable(decimalId), scope });
    const purposeRevision = object({ purpose_id: uuid, revision_id: uuid, version, prior_version: nullable(version), supersedes_revision_id: nullable(uuid), operation: text, effective_from: text, effective_until: nullable(text), accepted_at: text, snapshot: ref('PurposeSnapshot'), reason: nullable(text), affected_revision_ids: list(uuid), confirmations: object(Object.fromEntries(['meaning','scope','authority','identity','standing','dtm','intent','historical','transition'].map(k => [k,{type:'boolean'}]))) });
    const currentPurposeRevision = object({ purpose_id: uuid, revision_id: uuid, version, operation: text, effective_from: text, effective_until: nullable(text), accepted_at: text, snapshot: ref('PurposeSnapshot') });
    const purposeItem = object({purpose_id:uuid,head_version:version,state:text,applicable:nullable({oneOf:[purposeRevision,currentPurposeRevision]}),history:list(purposeRevision),relationship_revalidation_required:{type:'boolean'},continuity:text,dtm_standing:text,scheduled_revision_ids:list(uuid)});
    const purposeProjection = object({state:text,items:list(purposeItem),current_context_version:version,latest_dtm_event_context_version:version,effective_at:text,known_at:text});
    const purposeCommand = object({command_id:uuid,purpose_id:nullable(uuid),expected_version:version,operation:{enum:['declare','replace','correct','withdraw']},declaration:nullable(ref('PurposeSnapshot')),effective_from:nullable(text),effective_until:nullable(text),supersedes_revision_id:nullable(uuid),affected_revision_ids:list(uuid),correction_reason:nullable(text),withdrawal_reason:nullable(text),confirmations:object(Object.fromEntries(['meaning','scope','authority','identity','standing','dtm','intent','historical','transition'].map(k=>[k,{type:'boolean'}])))});
    const businessPurposes = object({business_public_id:uuid,scope:{const:'native_business_purposes_only'},authority_scope:{const:'business_declaration_only'},establishes_external_authority:{const:false},projection:purposeProjection});
    const purposeAck = object({purpose_id:uuid,revision_id:uuid,version,business_public_id:uuid,authority_scope:{const:'business_declaration_only'},establishes_external_authority:{const:false},versions:versions(['context'])});
    const writeOptions = list(object({ grant_id: decimalId }));
    const delegation = object({ actor_public_id: uuid,
        options: list(agencyMetadata(object({ grant_id: decimalId, action: text, can_issue: { type: 'boolean' }, can_delegate_onward: { type: 'boolean' }, can_choose_agency: { type: 'boolean' }, max_expires_at: nullable({ type: 'string', format: 'date-time' }), chain_depth: id }))),
        issued: list(agencyMetadata(object({ grant_id: decimalId, parent_grant_id: decimalId, recipient_public_id: uuid, action: text,
            recorded_status: { enum: ['active', 'revoked'] }, revision: id, expires_at: nullable({ type: 'string', format: 'date-time' }), delegable: { type: 'boolean' },
            realm_kind: realm, }))),
        issued_limit: { const: 64 }, issued_more: { type: 'boolean' }, option_limit: { const: 32 }, options_may_be_partial: { type: 'boolean' } });
    const commercialCommon = {
        beneficiary: object({ kind: { const: 'business' }, public_id: uuid }),
        ...Object.fromEntries(['subscription_state', 'entitlement_state', 'quota_state', 'payer_state', 'coverage_state', 'amount_state'].map(key => [key, { const: 'UNKNOWN' }])),
        conditioned_feature_admission: { const: 'HOLD' },
        usage_reservation: { const: 'NOT_CREATED' },
        foundation_declarations: object({ entitlement: { const: 'NOT_APPLICABLE' }, quota: { const: 'NOT_APPLICABLE' }, billing: { const: 'NOT_APPLICABLE' } }),
        limitation: { const: 'NO_OPERATING_SUBSCRIPTION_OR_METERING_POLICY' },
    };
    const commercial = {
        ...object({ ...commercialCommon, context_state: { enum: ['QUALIFIED_BUSINESS_BINDING_UNAVAILABLE', 'CONTEXT_RECORDED'] },
            context: nullable(object({
            scope_id: uuid, market_id: uuid, market_label: text, configuration_id: uuid, catalog_identity: uuid,
            catalog_version: text, denomination_code: text, effective_at: text,
        })) }),
        if: { properties: { context_state: { const: 'CONTEXT_RECORDED' } } },
        then: { properties: { context: { type: 'object' } } },
        else: { properties: { context: { type: 'null' } } },
    };
    const practiceChoice = { practice_id: uuid, name: text };
    const firstUsefulStep = object({
        function_id: { const: 'commercial.first_useful_step' },
        method_revision: { const: 2 },
        status: { enum: ['ACTIONABLE', 'NEEDS_INPUT', 'INAPPLICABLE', 'UNAVAILABLE'] },
        reason: { enum: ['choose_goal', 'choose_practice', 'add_practice', 'describe_offering', 'declare_target_market', 'declare_fulfillment',
            'check_market_coverage', 'clarify_cross_market_handling', 'test_offer_in_declared_market', 'record_buyer_roles', 'record_brokerage_qualifier',
            'record_sale_evidence', 'spend_evidence_required', 'goal_not_supported', 'practice_unavailable', 'business_unavailable'] },
        basis: fact({ qualification: { const: 'business_declared' }, context_version: version, preference_version: version,
            goal: nullable(text),
            market_check: nullable(object({ target_countries: list(text), fulfillment_countries: list(text), shared_countries: list(text) })),
            practice: nullable(object({ ...practiceChoice, revision: id, offering: nullable(text) })) }),
        practice_choices: list(object(practiceChoice)),
    });
    const surface = agencyMetadata(object({ service_approval: object({enrollment:object({available:{type:'boolean'},mandate_grant_id:nullable(decimalId)}),available:{type:'boolean'},selection:nullable(authority),business_revision:version,scopes:list(object({public_id:uuid,name:text,purpose:text,revision:version}))}), business: projection, authority_selection: authority, context_write_options: writeOptions,
        lifecycle: object({ revision: version, archive_options: writeOptions }),
        private_history_available: { type: 'boolean' }, direct_team_enrollment_available: { type: 'boolean' },
        purpose_statement: object({ authority_scope: { const: 'own_business_statement_only' }, establishes_external_authority: { const: false }, enrollment_available: { type: 'boolean' }, previous_grant: { type: 'string', pattern: '^(0|[1-9][0-9]{0,18})$' }, current_grant: nullable(object({ id: decimalId, version })) }),
        fact_write_options: object({ markets: writeOptions, intent: writeOptions, preferences: writeOptions, purpose: writeOptions }), delegation, catalog, scope,
        commercial, first_useful_step: ref('FirstUsefulStep'),
        data_use: object({ binding_options: list(object({ grant_id: decimalId })), bindings: list(object({ binding_id: uuid, scope_id: uuid, state: { enum: ['active','ended'] }, revision: id })), bindings_limit: { const: 64 }, bindings_more: { type: 'boolean' } }) }));
    surface.properties.agency_task = object({ can_mandate: { type: 'boolean' }, can_assign: { type: 'boolean' }, agency_business_public_id: nullable(uuid) });
    const dataUseChoices = object({ scopes: list(object({ scope_id: uuid, grant_id: decimalId, grant_revision: id,
        operation: { enum: ['retained_read','process','combine','display','reuse','export','train','retain','delete','forward'] }, purpose: text, resource: text,
        source_ref: uuid, boundary_ref: uuid, field_keys: list(text), record_count: id, namespace_count: id, authority_selection: authority })), next_cursor: nullable(decimalId) });
    const historyKind = { enum: ['context','markets','intent','preferences'] };
    const historyVersion = { type: 'string', pattern: '^(0|[1-9][0-9]{0,18})$' };
    const historyValues = {
        context: [object({ name: text, business_type_id: nullable(id) }), object({ name: text, business_type_id: nullable(id) })],
        markets: [list(text), list(text)], intent: [nullable(ref('CurrentIntentValue')), nullable(ref('CurrentIntentValue'))],
        preferences: [nullable(ref('GoalValue')), ref('GoalValue')],
    };
    const history = { oneOf: Object.entries(historyValues).map(([kind, [before, after]]) => fact({ kind: { const: kind },
        entries: list(object({ event_id: uuid, before_version: historyVersion, version: historyVersion,
            before_context_version: nullable(historyVersion), context_version: nullable(historyVersion), before, after, recorded_at: { type: 'string', format: 'date-time' } })),
        next_cursor: nullable(historyVersion), history_scope: { const: 'native_business_transitions' }, page_limit: { const: 32 } })) };
    const query = (name, required = false) => ({ in: 'query', name, required, schema: { type: 'integer', minimum: name === 'after' ? 0 : 1 } });
    const read = (operationId, schema, parameters = []) => ({ operationId, security, parameters, responses: { 200: response(schema), ...errors } });
    const page = schema => ({ oneOf: [object({ data: schema, locale: { enum: ['ar', 'en'] }, visibility: ref('Visibility') }), ref('GoalsWitness')] });
    const materialRef = object({ namespace: { type: 'string', pattern: '^[a-z][a-z0-9_.]{0,79}$', description: 'Fixed server-registered native/producer owners only. Test namespaces remain unavailable in production; a string does not register a producer.' }, key: { type: 'string', minLength: 1, maxLength: 4096 }, version: { type: 'string', pattern: '^[0-9a-f]{64}$' } });
    const materialOperation = { enum: ['retained_read', 'display', 'reuse', 'process', 'combine', 'export'] };
    const marketSubject = object({ key: nullable(text), label: { type: 'string', minLength: 1, maxLength: 180 }, identity_state: { enum: ['unresolved', 'source_asserted'] } });
    const marketGeography = object({ ref: nullable(text), level: { enum: ['unknown', 'country', 'region', 'city', 'district'] } });
    const evidencePayload = (kind, fields) => object({ record_key: { type: 'string', minLength: 1, maxLength: 180 }, evidence_type: { const: kind }, fields,
        coverage: { enum: ['COMPLETE', 'PARTIAL', 'FAILED', 'UNKNOWN', 'PENDING'] }, provenance: { type: 'object', additionalProperties: true },
        observed_at: { type: 'string', format: 'date-time' }, effective_at: { type: 'string', format: 'date-time' }, valid_until: nullable({ type: 'string', format: 'date-time' }),
        reason: { type: 'string', minLength: 1, maxLength: 600 } });
    const productIdentity = object(Object.fromEntries(['manufacturer','model','variant','unit','condition'].map(key => [key, nullable({type:'string',minLength:1,maxLength:180})])));
    const serviceTerms = object({inclusions: nullable({...list(text),maxItems:128,uniqueItems:true}), exclusions: nullable({...list(text),maxItems:128,uniqueItems:true}), warranty_months: nullable({type:'integer',minimum:0,maximum:99999}), fulfillment_days: nullable({type:'integer',minimum:0,maximum:99999})});
    const comparisonBasis = object({...Object.fromEntries(['sampling','normalization','population','aggregation'].map(key => [key, nullable({type:'string',minLength:1,maxLength:180})])), ratio_scale:{type:'boolean'}, period_start:{type:'string',format:'date-time'}, period_end:{type:'string',format:'date-time'}});
    const marketEvidencePayload = { oneOf: [
        evidencePayload('market.offer:1', object({ subject: marketSubject, geography: marketGeography, currency: { type: 'string', pattern: '^[A-Z]{3}$' }, price: nullable({ type: 'number', minimum: 0 }), inclusions: list(text) })),
        evidencePayload('market.interest_signal:1', object({ subject: marketSubject, geography: marketGeography, metric: object({ name: text, unit: text, definition_version: text }), value: nullable({ type: 'number' }) })),
        evidencePayload('market.offer:2', object({subject:marketSubject,geography:marketGeography,currency:{type:'string',pattern:'^[A-Z]{3}$'},price:nullable({type:'number',minimum:0}),inclusions:list(text),product:productIdentity,service:serviceTerms})),
        evidencePayload('market.interest_signal:2', object({subject:marketSubject,geography:marketGeography,metric:object({name:text,unit:text,definition_version:text}),value:nullable({type:'number'}),comparison_basis:comparisonBasis})),
    ] };
    const processingQuestion = {enum:['compare_offers','compare_interest','assess_business_feasibility']};
    const processingFinding = object({method_ref:{enum:['offer_equivalence:1','qualified_interest_change:1','business_feasibility:1']}, status:{enum:['equivalent','different','unresolved','comparable','not_comparable','within_declared_market','outside_declared_market','requires_information']}, reason_codes:list(text), values:{oneOf:[object({}),object({delta:{type:'number'},percent_change:nullable({type:'number'})})]}, limitations:list(text), evidence_refs:list(materialRef)});
    const processingRun = fact({run_ref:uuid,question:processingQuestion,method_ref:{enum:['offer_equivalence:1','qualified_interest_change:1','business_feasibility:1']},purpose:text,state:{enum:['prepared','validated','abstained','failed','cancelled','unavailable']},finding:nullable(processingFinding),reason_code:nullable(text),material_refs:list(materialRef),capture_ref:uuid,dependency_digest:{type:'string',pattern:'^[0-9a-f]{64}$'},published:{const:false},usage:object({executions:{type:'integer',minimum:0,maximum:1},elapsed_microseconds:{type:'integer',minimum:0,maximum:30000000},external_cost:{const:'NOT_INCURRED'},unmeasured_cost:{const:'UNKNOWN'},billable:{const:false}}),versions:versions(['processing_contract'])});
    const processingPrepare = body({question:processingQuestion,material_refs:{...list(materialRef),minItems:1,maxItems:2},purpose:{type:'string',minLength:1,maxLength:128},context_selection:nullable(ref('BusinessAuthoritySelection'))});
    const processingComplete = body({effect:{enum:['complete','cancel']},context_selection:nullable(ref('BusinessAuthoritySelection'))});
    const materialResult = fact({ operation: materialOperation, purpose: text, recipient_ref: uuid,
        materials: list(object({ ref: materialRef, fields: { type: 'object', additionalProperties: true }, coverage: { enum: ['COMPLETE', 'PARTIAL', 'FAILED', 'UNKNOWN', 'PENDING'] },
            provenance: { type: 'object', additionalProperties: true }, data_use_scope_id: uuid,
            validity: object({ status: { enum: ['current', 'historical', 'review_required', 'unknown'] }, currentness_revision: id, checked_at: { type: 'string', format: 'date-time' } }),
            evidence: object({ source_ref: uuid, schema: { enum: ['market.offer:1', 'market.interest_signal:1'] }, revision: id,
                observed_at: { type: 'string', format: 'date-time' }, effective_at: { type: 'string', format: 'date-time' }, valid_until: nullable({ type: 'string', format: 'date-time' }) }) }, ['ref', 'fields', 'coverage', 'provenance', 'data_use_scope_id'])),
        dependency_digest: { type: 'string', pattern: '^[0-9a-f]{64}$' }, versions: versions(['business_authority']) });
    const base = '/api/businesses/{business}';
    const boundaryParam = { in: 'path', name: 'boundary', required: true, schema: uuid };
    const boundaryChoices = object({ boundaries: list(object({ boundary_id: uuid, state: { enum: ['active','restricted','archived'] }, read_grant_id: decimalId,
        realm_kind: realm, })), next_cursor: nullable(decimalId) });
    const boundarySurface = object({ boundary: object({ boundary_id: uuid, state: { enum: ['active','restricted','archived'] }, revision: id }),
        authority_selection: authority, memberships: list(object({ membership_id: uuid, business_public_id: uuid, branch_public_id: nullable(uuid), state: { enum: ['active','ended'] }, revision: id })),
        memberships_limit: { const: 64 }, memberships_more: { type: 'boolean' } });
    const sourceParam = { in: 'path', name: 'source', required: true, schema: uuid };
    const sourceFields = { source_id: uuid, label: text, status: { enum: ['UNVERIFIED', 'REVOKED'] }, resource: text, operation: text, purpose: text };
    const sourceChoices = object({ sources: list(object({ ...sourceFields, access_grant_id: decimalId })), next_cursor: nullable(decimalId) });
    const sourceSurface = object({ source: object({ ...sourceFields, family: text, namespace: text, revision: id, adopted: { type: 'boolean' }, scope_declared: { type: 'boolean' } }),
        authority_selection: authority, can_command: { type: 'boolean' },
        rights: list(object({ grant_id: decimalId, action: text, recorded_status: { enum: ['active', 'revoked'] }, revision: id, expires_at: nullable({ type: 'string', format: 'date-time' }) })),
        rights_limit: { const: 64 }, rights_more: { type: 'boolean' } });
    const listCompleteness = { enum: ['COMPLETE', 'PARTIAL', 'UNKNOWN'] };
    const recordMetadata = object({ ref: materialRef, campaign_id: nullable(text), start: nullable(text), end: nullable(text),
        boundary: nullable({ enum: ['INCLUSIVE_DATES', 'HALF_OPEN_INSTANTS'] }), grain: nullable({ enum: ['TOTAL', 'DAY', 'HOUR'] }), timezone: nullable(text) });
    const licenseDataset = object({ dataset_id: text, coverage: { ...text, description: 'Acquisition coverage reported by the observation run; independent of visible selection completeness.' },
        records_completeness: listCompleteness, record_count: { ...id, description: 'Number of displayed selectable records, not the total report size.' },
        record_metadata: list(recordMetadata), selection: ref('DataLicenseSelection'), material_refs: list(materialRef) });
    const licenseJourney = object({ business_public_id: uuid, actor_public_id: uuid,
        sources: list(object({ source_id: uuid, label: text, family: text, resource: text, manager_grant_id: nullable(decimalId), read_grant_id: nullable(decimalId), datasets_completeness: listCompleteness, datasets: list(licenseDataset) })),
        sources_more: { type: 'boolean' }, licenses_more: { type: 'boolean' },
        licenses: list(object({ license_id: uuid, boundary_id: uuid, boundary_grant_id: nullable(decimalId), use_grant_id: nullable(decimalId), source_id: uuid,
            resource: text, operation: text, purpose: text, valid_until: { type: 'string', format: 'date-time' }, status: { enum: ['active', 'expired', 'withdrawn'] },
            selection: ref('DataLicenseSelection'), recipient_public_id: uuid, material_refs: list(materialRef), record_metadata: list(recordMetadata), covered: { type: 'boolean' }, bound: { type: 'boolean' },
            coverage_revision: version, binding_revision: version, is_declarant: { type: 'boolean' }, summary: nullable(object({state:{enum:['prepared','published']},input_license_id:uuid,process_grant_id:decimalId,dependencies:list(object({input:materialRef,data_use_scope_id:uuid,policy_id:text,policy_version:id}))})) })),
        business_use_options: list(object({ grant_id: decimalId })), binding_options: list(object({ grant_id: decimalId })),
        beneficiaries: list(object({ public_id: uuid, name: text, realm_kind: { enum: ['direct'] }, })),
        assignments: list(object({ license_id: uuid, recipient_public_id: uuid, grant_id: decimalId, status: { enum: ['active', 'revoked'] }, revision: id, valid_until: { type: 'string', format: 'date-time' } })),
        assigned_licenses: list(object({ license_id: uuid, operation: text, purpose: text, valid_until: { type: 'string', format: 'date-time' },artifact_ref:materialRef,use_grant_id:decimalId },['license_id','operation','purpose','valid_until'])),
        operating_mandate: object({ available: { type: 'boolean' }, previous_grant: { type: 'string', pattern: '^(0|[1-9][0-9]{0,18})$' } }) });
    const mandateCommand = body({ confirmed: { const: true }, capacity: { enum: ['responsible_business', 'client_mandate'] },
        authority_reference: { ...text, minLength: 8, maxLength: 512 }, valid_until: { type: 'string', format: 'date-time' }, previous_grant: { ...text, pattern: '^(0|[1-9][0-9]{0,17})$' } });
    const mandateResult = object({ business_public_id: uuid, grant_refs: object({ 'business.data.use': decimalId, 'business.link.manage': decimalId }),
        qualification_ref: uuid, assurance: { const: 'DECLARED_AUTHORITY' }, versions: object({ qualification: { const: 1 } }) });
    const beneficiaryCommand = body({ license_id: uuid, effect: { enum: ['assign', 'revoke'] }, recipient_public_id: uuid,
        valid_until: { type: 'string', format: 'date-time' }, expected_revision: version });
    const beneficiaryResult = object({ license_id: uuid, recipient_public_id: uuid, grant_id: decimalId, status: { enum: ['active', 'revoked'] }, versions: versions(['grant']) });
    return {
        schemas: {
            BusinessServiceApprovalEnrollmentCommand: body({confirmed:{const:true},authority_reference:{...text,minLength:8,maxLength:512},expires_at:{type:'string',format:'date-time'},mandate_grant_id:decimalId}),
            InternalTeamCommand: body({effect:{enum:['appoint','remove']},recipient_public_id:uuid,expected_revision:version}),
            InternalTeamSurface: fact({creator_basis:{type:'boolean'},can_admin:{type:'boolean'},can_appoint:{type:'boolean'},versions:versions(['authority_epoch']),custodians:list(object({public_id:uuid,name:text,email:text,qualification_ref:uuid,status:{const:'recorded'},grant_refs:nullable({type:'object',additionalProperties:decimalId})}))}),
            InternalTeamReceipt: fact({state:{const:'active'},recipient_public_id:uuid,grant_refs:{type:'object',additionalProperties:decimalId},versions:versions(['authority_epoch'])}),
            BusinessHistory: history, BusinessHistoryResult: data(history),
            DataUseChoices: dataUseChoices, DataUseChoicesResult: data(dataUseChoices),
            NativeSourceChoices: sourceChoices, NativeSourceSurface: sourceSurface,
            NativeSourceChoicesResult: data(sourceChoices), NativeSourceSurfaceResult: data(sourceSurface),
            ClientBoundaryChoices: boundaryChoices, ClientBoundarySurface: boundarySurface,
            ClientBoundaryChoicesResult: data(boundaryChoices), ClientBoundarySurfaceResult: data(boundarySurface),
            BusinessChoices: choices, BusinessSurface: surface, BusinessCatalog: catalog, FirstUsefulStep: firstUsefulStep,
            BusinessPurposes: businessPurposes, BusinessPurposesResult: data(businessPurposes), BusinessPurposeCommand: purposeCommand, BusinessPurposeAcknowledgement: purposeAck,
            BusinessChoicesResult: data(choices), BusinessSurfaceResult: data(surface), BusinessCatalogResult: data(catalog),
            BusinessMaterialPublishResult: data(fact({ artifact_ref: materialRef, manifest_id: uuid, closure_digest: { type: 'string', pattern: '^[0-9a-f]{64}$' }, versions: versions(['manifest']) })),
            MarketEvidencePayload: marketEvidencePayload,
            MarketProcessingPrepareCommand:processingPrepare,MarketProcessingCompleteCommand:processingComplete,MarketProcessingRunResult:data(processingRun),
            BusinessMaterialReference: materialRef, BusinessMaterialReadResult: data(materialResult),
            BusinessAuthoritySelection: authority,
            BusinessDataUseLicenseCommand: licenseCommand, BusinessDataUseLicenseResult: data(licenseResult),
            DataLicenseSelection: licenseSelection, DataLicenseJourney: licenseJourney, DataLicenseJourneyResult: data(ref('DataLicenseJourney')), BusinessOperatingMandateCommand: mandateCommand, BusinessOperatingMandateResult: data(mandateResult),
            BusinessDataUseBeneficiaryCommand: beneficiaryCommand, BusinessDataUseBeneficiaryResult: data(beneficiaryResult),
            BusinessFoundationError: object({ error: { enum: ['business_authority_denied', 'business_conflict', 'business_relation_revision_rejected', 'invalid_business_command'] } }),
            BusinessDraftResult: data(draft), BusinessFoundationProjection: data(projection), BusinessContextWriteResult: data(contextResult),
            BusinessMarketWriteResult: data(marketsResult), BusinessPreferenceWriteResult: data(preferenceResult), BusinessIntentWriteResult: data(intentResult),
            BusinessGrantWriteResult: data(grantResult), BusinessAgencyGrantCommand: agencyCommand, BusinessAgencyGrantResult: data(agencyResult),
            BusinessAgencyOptions: agencyOptions,
            BusinessTeamMembers: teamMembers, BusinessTeamCommand: teamCommand, BusinessTeamResult: data(teamResult),
            BusinessAgencyMandates: agencyMandates, BusinessAgencyMandatesCommand: agencyMandatesCommand, BusinessAgencyMandatesResult: data(agencyMandatesResult),
            BusinessBindingWriteResult: data(bindingResult),
            BusinessResourceRightResult: data({ oneOf: [boundaryResult, revokeResult, sourceGrantResult, sourceAdoptionResult, sourceRetirementResult] }), BusinessLifecycleWriteResult: data(lifecycleResult),
        },
        paths: {
            [`${base}/service-approval/enrollment`]: {post:endpoint('enrollBusinessServiceApproval',ref('BusinessServiceApprovalEnrollmentCommand'),object({business_public_id:uuid,grant_id:decimalId,revision:{...version,description:'Resulting Business authority epoch; re-read current qualification before use.'}}))},
            [`${base}/internal-team`]: {get:read('readBusinessFileCustody',data(ref('InternalTeamSurface')),[business,query('access',true)]),post:endpoint('writeBusinessFileCustody',ref('InternalTeamCommand'),ref('InternalTeamReceipt'))},
            '/api/businesses/{business}/data-use-journey': { get: read('readDataLicenseJourney', ref('DataLicenseJourneyResult'), [business, query('access', true)]) },
            '/api/businesses/{business}/operating-mandate': { post: endpoint('declareBusinessOperatingMandate', ref('BusinessOperatingMandateCommand'), mandateResult) },
            '/api/businesses/{business}/data-use-beneficiaries': { post: endpoint('assignDataUseBeneficiary', ref('BusinessDataUseBeneficiaryCommand'), beneficiaryResult) },
            '/api/businesses/{business}/expenditure-summaries': { post: endpoint('prepareExpenditureSummary', body({summary_id:uuid,input_ref:materialRef,input_license_id:uuid,read_command_id:uuid,confirmed:{const:true},valid_until:{type:'string',format:'date-time'},consent:object({derive:{const:true},deliver_summary:{const:true},retain_payload:{const:false}})}),fact({artifact_ref:materialRef,dependencies:list(object({input:materialRef,data_use_scope_id:uuid,policy_id:text,policy_version:id})),license_id:uuid,use_grant_id:decimalId,status:{const:'prepared'},versions:object({summary:{const:1}})})) },
            '/api/businesses/{business}/data-use-licenses': { post: endpoint('declareBoundedDataUseLicense', ref('BusinessDataUseLicenseCommand'), licenseResult) },
            '/api/data-rights/use-scopes': { get: read('qualifiedDataUseChoices', data(dataUseChoices), [query('after')]) },
            '/api/source-rights': { get: read('qualifiedNativeSourceChoices', data(sourceChoices), [query('after')]) },
            '/api/source-rights/{source}': { get: read('qualifiedNativeSourceSurface', data(sourceSurface), [sourceParam, query('access', true)]) },
            '/source-rights': { get: read('nativeSourceChoicesPage', page(sourceChoices), [query('after')]) },
            '/source-rights/{source}': { get: read('nativeSourceDetailPage', page(sourceSurface), [sourceParam, query('access', true)]) },
            '/api/data-rights/boundaries': { get: read('qualifiedClientBoundaryChoices', data(boundaryChoices), [query('after')]) },
            '/api/data-rights/boundaries/{boundary}': { get: read('qualifiedClientBoundarySurface', data(boundarySurface), [boundaryParam, query('access', true)]) },
            '/data-rights': { get: read('clientBoundaryChoicesPage', page(boundaryChoices), [query('after')]) },
            '/data-rights/{boundary}': { get: read('clientBoundaryDetailPage', page(boundarySurface), [boundaryParam, query('access', true)]) },
            '/businesses': { get: read('businessChoicesPage', page(object({
                ...choices.properties, registration_attempt: nullable(uuid),
            }, choices.required)), [query('after'), { in: 'query', name: 'entry', schema: { const: '1' } }]) },
            '/businesses/new': { get: read('businessCreatePage', page(object({ catalog, registration_attempt: nullable(uuid) }))) },
            '/business-registration/catalog': { get: {
                ...read('publicBusinessSetupCatalog', data(object({ business_types: list(object(sector)), countries: catalog.properties.countries, goals: catalog.properties.goals }))),
                description: 'Published onboarding choices, also available to an admitted guest document. This response contains no Business, user or source records and does not authorize creation.',
            } },
            '/businesses/{business}': { get: read('businessDetailPage', page(surface), [business, query('access', true)]) },
            '/api/businesses/catalog': { get: read('independentBusinessCatalog', data(catalog)) },
            [`${base}/surface`]: { get: read('independentBusinessSurface', data(surface), [business, query('access', true), { in: 'query', name: 'focus_practice', schema: uuid }]) },
            [`${base}/purposes`]: { get: read('readBusinessPurposes', data(businessPurposes), [business, query('access', true), ...['purpose','effective_at','known_at'].map(name => ({in:'query',name,schema:name==='purpose'?uuid:text}))]), post: endpoint('writeBusinessPurpose',object({command:purposeCommand,expected_context:version,authority_selection:authority}),purposeAck,201) },
            [`${base}/history`]: { get: read('independentBusinessHistory', data(history), [business, query('access', true), { in: 'query', name: 'kind', required: true, schema: historyKind }, query('after')]) },
            '/api/businesses': { get: read('independentBusinessChoices', data(choices), [query('after')]), post: endpoint('createIndependentBusinessDraft', object({ command_id: uuid, name: { type: 'string', minLength: 2, maxLength: 120 }, business_type_id: nullable(id) }, ['command_id', 'name']), draft, 201, false) },
            [`${base}/market-analysis/prepare`]: {post:endpoint('prepareQualifiedMarketProcessing',processingPrepare,processingRun)},
            [`${base}/market-analysis/{run}/complete`]: {post:{...endpoint('completeQualifiedMarketProcessing',processingComplete,processingRun),parameters:[business,{in:'path',name:'run',required:true,schema:uuid}]}},
            [`${base}/materials/publish`]: { post: endpoint('publishQualifiedRegisteredMaterial', body({ artifact_ref: materialRef, dependencies: { ...list(object({ input: materialRef, data_use_scope_id: uuid, policy_id: text, policy_version: id })), minItems: 1, maxItems: 64 }, purpose: { type: 'string', minLength: 1, maxLength: 128 }, recipient_ref: uuid }), fact({ artifact_ref: materialRef, manifest_id: uuid, closure_digest: { type: 'string', pattern: '^[0-9a-f]{64}$' }, versions: versions(['manifest']) })) },
            [`${base}/materials/read`]: { post: endpoint('readQualifiedOriginalMaterial', body({ material_refs: { ...list(materialRef), minItems: 1, maxItems: 64 }, operation: materialOperation, purpose: { type: 'string', minLength: 1, maxLength: 128 }, recipient_ref: uuid }), materialResult) },
            [`${base}/read`]: { post: endpoint('readIndependentBusiness', object({ authority_selection: ref('BusinessAuthoritySelection') }), projection) },
            [`${base}/context`]: { put: endpoint('writeIndependentBusinessContext', body({ name: { type: 'string', minLength: 2, maxLength: 120 }, business_type_id: nullable(id), expected_context: version }), contextResult) },
            [`${base}/markets`]: { put: endpoint('writeIndependentBusinessMarkets', body({ codes: { ...list(text), maxItems: 300 }, expected_context: version }), marketsResult) },
            [`${base}/preferences`]: { put: endpoint('writeIndependentBusinessPreferences', body({ value: ref('WriteGoalValue'), expected_preference: version }), preferenceResult) },
            [`${base}/intent`]: { put: endpoint('writeIndependentBusinessIntent', body({ value: nullable(ref('CurrentIntentValue')), confirmations: object(Object.fromEntries(['declaration', 'business_wide', 'outcome', 'acceptance_clear', 'clarification_required', 'quantity', 'horizon'].map(name => [name, { type: 'boolean' }])), []), expected_context: version, expected_intent: version }), intentResult) },
            [`${base}/data-use-bindings`]: { post: endpoint('writeBusinessDataUseBinding', body({ data_use_scope_id: uuid, effect: { enum: ['add', 'end'] }, expected_relation: { ...version, description: 'Latest logical Business/data-use binding revision, including ended bindings; never reset on recreation.' } }), bindingResult) },
            [`${base}/grants`]: { post: endpoint('writeIndependentBusinessGrant', body({ effect: { enum: ['issue', 'revoke'] }, grantee_public_id: uuid, action: text, parent_grant_id: nullable(id), grant_id: nullable(id), expected_grant: version,
                expires_at: nullable({ type: 'string', format: 'date-time' }), delegable: { type: 'boolean' }, }), grantResult) },
            [`${base}/agency-grants`]: { post: {
                ...endpoint('writeBusinessAgencyGrant', ref('BusinessAgencyGrantCommand'), agencyResult),
                description: 'Explicit finite Business-to-Business declaration mandate or named employee assignment through the existing grant graph. authority_selection must select direct realm, exactly one Business requirement matching parent_grant_id; PostgreSQL rejects every other selection. Current agency eligibility, exact parent authority and finite expiry are requalified in PostgreSQL. Grants no Source, client-data, private-history or external-execution rights. Exact existing-grant revocation remains at /grants. Uncertain delivery retains the original command without automatic retry.',
            } },
            [`${base}/agency-options`]: { get: read('readBusinessAgencyOptions', data(agencyOptions), [business, query('access', true),
                { in: 'query', name: 'agency', required: true, schema: uuid }, { in: 'query', name: 'recipient', schema: uuid }]) },
            [`${base}/team`]: {
                get: read('readBusinessTeamMembers', data(teamMembers), [business, query('access', true), query('after')]),
                post: { ...endpoint('writeBusinessTeamMembers', ref('BusinessTeamCommand'), teamResult),
                    description: 'Original Business creator and current independent team enrollment required. authority_selection is the exact current Business read path; SQL selects and requalifies the five canonical team roots. expected_grants must contain every active recorded direct-team grant of this employee. Nonempty permissions require business.context.read; empty permissions withdraw only this direct team set. A common new expiry is explicit, including replacement of differing historical expiries. No invitation or independent authority or Source/data right is created or withdrawn. Unknown delivery retains the original command.',
                },
            },
            [`${base}/team/recipient`]: { post: endpoint('resolveBusinessTeamRecipient', object({ access: id, email: teamIdentity.properties.email }), fact({ recipient: teamIdentity })) },
            [`${base}/agency-mandates`]: {
                get: read('readBusinessAgencyMandates', data(agencyMandates), [business, query('access', true), { in: 'query', name: 'after', schema: uuid }]),
                post: { ...endpoint('withdrawBusinessAgencyMandates', ref('BusinessAgencyMandatesCommand'), agencyMandatesResult),
                    description: 'Original client creator and current direct team authority required. Withdraw exactly the complete selected Business-to-Business current-declaration mandate group using all returned grant identities/versions. Partial groups cannot authorize bulk withdrawal. Agency expiry or suspension does not confer authority or prevent client-owned withdrawal. Descendant assignments lose their basis; independent grants and Source/data rights remain separate. Uncertain delivery retains the exact original command.',
                },
            },
            [`${base}/direct-team`]: { post: endpoint('enrollIndependentBusinessTeam', body({}), fact({ grant_refs: { type: 'object', additionalProperties: decimalId }, qualification_ref: uuid, versions: versions(['qualification']) })) },
            [`${base}/purpose-enrollment`]: { post: endpoint('enrollOwnBusinessPurposeStatements', body({ confirmed: { const: true }, previous_grant: { type: 'string', pattern: '^(0|[1-9][0-9]{0,18})$' } }), fact({ grant_refs: object({ 'business.purpose.declare': decimalId }), qualification_ref: uuid, authority_scope: { const: 'own_business_statement_only' }, establishes_external_authority: { const: false }, versions: versions(['qualification', 'grant']) })) },
            [`${base}/lifecycle`]: { post: endpoint('writeIndependentBusinessLifecycle', body({ effect: { enum: ['suspend', 'archive', 'restore', 'branch_create', 'branch_archive'] }, target_ref: nullable(uuid), payload: object({ label: text }, []), expected_revision: version }), lifecycleResult) },
            '/api/market-evidence/{evidence}': { post: {
                ...endpoint('writeQualifiedMarketEvidence', body({ data_use_scope_id: uuid, expected_revision: version, payload: ref('MarketEvidencePayload') }),
                    object({ material_ref: materialRef, versions: versions(['evidence']) }), 200, false),
                parameters: [{ in: 'path', name: 'evidence', required: true, schema: uuid }],
            } },
            '/api/data-rights/commands': { post: endpoint('writeQualifiedDataResourceRight', body({ target_kind: { enum: ['client_boundary', 'boundary_subject', 'data_use', 'source'] }, target_ref: uuid,
                effect: { enum: ['attach_subject', 'detach_subject', 'revoke', 'adopt', 'qualify_issue', 'retire', 'rename', 'declare_scope'] }, payload: { oneOf: [object({ business_public_id: uuid, branch_id: nullable(id) }), object({}), object({ grant_id: { type: 'string', pattern: '^[1-9][0-9]{0,17}$' } }), object({ action: { enum: ['source.register', 'source.read', 'source.manage', 'source.audit.recover', 'provider.connect', 'provider.read', 'provider.revoke'] }, expires_at: nullable({ type: 'string', format: 'date-time' }), reason: { type: 'string', pattern: '^[a-z][a-z0-9_]{2,79}$' } }), object({ reason: { type: 'string', pattern: '^[a-z][a-z0-9_]{2,79}$' } }), object({ label: { type: 'string', minLength: 1, maxLength: 120 }, reason: { type: 'string', pattern: '^[a-z][a-z0-9_]{2,79}$' } })] }, expected_revision: version }), { oneOf: [boundaryResult, revokeResult, sourceGrantResult, sourceAdoptionResult, sourceRetirementResult] }, 200, false) },
        },
    };
}
