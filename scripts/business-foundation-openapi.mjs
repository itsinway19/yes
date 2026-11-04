/** Only implemented Business foundation endpoints; no material/migration capability is advertised here. */
export function businessFoundationContracts({ ref, text, uuid, object, list, nullable, json, response, security, errors }) {
    const version = { type: 'integer', minimum: 0 };
    const id = { type: 'integer', minimum: 1 };
    const requirement = object({ requirement_kind: { enum: ['business', 'client_data', 'data_use', 'native_source'] }, leaf_grant_id: id });
    const realm = { enum: ['direct', 'agency', 'internal_team'] };
    const authority = object({ realm_kind: realm, realm_workspace_public_id: nullable(uuid), requirements: { ...list(requirement), minItems: 1, maxItems: 32 } });
    const command = { command_id: uuid, authority_selection: ref('BusinessAuthoritySelection') };
    const versions = fields => object(Object.fromEntries(fields.map(name => [name, version])));
    const body = fields => object({ ...command, ...fields });
    const data = schema => object({ data: schema });
    const business = { in: 'path', name: 'business', required: true, schema: uuid };
    const endpoint = (operationId, request, result, status = 200, selected = true) => ({ operationId, security, parameters: selected ? [business] : [],
        description: 'This is the current web ingress; its session/document credentials do not define authentication for other clients. Resource and command/result schemas are independent of Vue. Own principal transaction; explicit selected grant paths requalified in PostgreSQL. Workspace, payer and cached state confer no Business authority. Commands are never automatically retried after an uncertain response.',
        requestBody: { required: true, ...json(request) }, responses: { [status]: response(data(result)), ...errors,
            403: { description: 'Current scoped authority denied; no authority inferred from Workspace membership.', ...json({ anyOf: [ref('BusinessFoundationError'), ref('Error')] }) },
            409: { description: 'business_relation_revision_rejected is a typed pre-effect relationship revision rejection. Generic business_conflict is not evidence of the original command outcome; retain and reconcile it.', ...json({ anyOf: [ref('BusinessFoundationError'), ref('Error')] }) },
            422: { description: 'Typed command, shape or semantic validation rejected.', ...json({ anyOf: [ref('BusinessFoundationError'), ref('ValidationError'), ref('Error')] }) },
        } });
    const fact = properties => object({ business_public_id: uuid, ...properties });
    const draft = fact({ name: text, state: { const: 'declared_draft' }, grant_refs: object(Object.fromEntries([
        'business.context.read', 'business.context.manage', 'business.intent.declare', 'business.markets.declare',
        'business.preferences.manage', 'business.link.manage', 'business.draft.archive'].map(action => [action, id]))), versions: versions(['context', 'intent', 'preferences']) });
    const projection = fact({ name: text, business_type_id: nullable(id), state: { enum: ['declared_draft', 'active', 'suspended', 'archived'] },
        current_intent: nullable(ref('CurrentIntentValue')), preferences: nullable(ref('GoalValue')), declared_markets: list(text), versions: versions(['context', 'intent', 'preferences']),
        links: list(object({ workspace_public_id: uuid, kind: { enum: ['collaboration', 'agency', 'internal_team'] }, revision: id })) });
    const contextResult = fact({ name: text, business_type_id: nullable(id), versions: versions(['context']) });
    const marketsResult = fact({ codes: list(text), versions: versions(['context']) });
    const preferenceResult = fact({ value: ref('GoalValue'), versions: versions(['preferences']) });
    const intentResult = fact({ value: nullable(ref('CurrentIntentValue')), versions: versions(['context', 'intent']) });
    const grantResult = fact({ grant_id: id, status: { enum: ['active', 'revoked'] }, versions: versions(['grant']) });
    const linkResult = fact({ workspace_public_id: uuid, kind: { enum: ['collaboration', 'agency'] }, state: { enum: ['active', 'ended'] }, versions: versions(['link']) });
    const bindingResult = object({ binding_id: nullable(uuid), status: { enum: ['active', 'ended'] }, versions: versions(['binding']) });
    const boundaryResult = object({ membership_id: uuid, status: { enum: ['active', 'ended'] }, versions: versions(['membership']) });
    const revokeResult = object({ grant_id: id, status: { const: 'revoked' }, versions: versions(['grant']) });
    const sourceGrantResult = object({ grant_id: id, status: { const: 'active' }, versions: versions(['grant']) });
    const sourceAdoptionResult = object({ source_id: uuid, fence_id: uuid, adoption_state: { const: 'v2_adopted' }, versions: versions(['source', 'rights_epoch']) });
    const sourceRetirementResult = object({ source_id: uuid, status: { enum: ['UNVERIFIED', 'REVOKED'] }, versions: versions(['source']), native_receipt: ref('SourceReceipt') });
    const lifecycleResult = { oneOf: [fact({ state: { enum: ['declared_draft', 'active', 'suspended', 'archived'] }, versions: versions(['lifecycle']) }),
        fact({ branch_public_id: uuid, status: { enum: ['active', 'archived'] }, versions: versions(['branch']) })] };
    const decimalId = { type: 'string', pattern: '^[1-9][0-9]{0,18}$' };
    const localized = { id: text, name_ar: text, name_en: text };
    const catalog = object({ business_types: list(object({ id, key: text, name_ar: text, name_en: text, active: { type: 'boolean' }, version: id })),
        countries: list(object({ code: text, name_ar: text, name_en: text })), intent_timezones: list(text),
        goals: list(object({ ...localized, description_ar: text, description_en: text, results: list(object(localized)) })) });
    const scope = object({ principal_generation: id, authority_fingerprint: { type: 'string', pattern: '^[0-9a-f]{64}$' } });
    const choices = object({ actor_public_id: uuid, items: list(fact({ name: text, state: text, read_grant_id: decimalId, realm_kind: realm, realm_workspace_public_id: nullable(uuid) })), next_cursor: nullable(decimalId), scope });
    const purposeRevision = object({ purpose_id: uuid, revision_id: uuid, version, prior_version: nullable(version), supersedes_revision_id: nullable(uuid), operation: text, effective_from: text, effective_until: nullable(text), accepted_at: text, snapshot: ref('PurposeSnapshot'), reason: nullable(text), affected_revision_ids: list(uuid), confirmations: object(Object.fromEntries(['meaning','scope','authority','identity','standing','dtm','intent','historical','transition'].map(k => [k,{type:'boolean'}]))) });
    const purposeItem = object({purpose_id:uuid,head_version:version,state:text,applicable:nullable(purposeRevision),history:list(purposeRevision),relationship_revalidation_required:{type:'boolean'},continuity:text,dtm_standing:text,scheduled_revision_ids:list(uuid)});
    const purposeProjection = object({state:text,items:list(purposeItem),current_context_version:version,latest_dtm_event_context_version:version,effective_at:text,known_at:text});
    const purposeCommand = object({command_id:uuid,purpose_id:nullable(uuid),expected_version:version,operation:{enum:['declare','replace','correct','withdraw']},declaration:nullable(ref('PurposeSnapshot')),effective_from:nullable(text),effective_until:nullable(text),supersedes_revision_id:nullable(uuid),affected_revision_ids:list(uuid),correction_reason:nullable(text),withdrawal_reason:nullable(text),confirmations:object(Object.fromEntries(['meaning','scope','authority','identity','standing','dtm','intent','historical','transition'].map(k=>[k,{type:'boolean'}])))});
    const businessPurposes = object({business_public_id:uuid,scope:{const:'native_business_purposes_only'},projection:purposeProjection});
    const purposeAck = object({purpose_id:uuid,revision_id:uuid,version,business_public_id:uuid,versions:versions(['context'])});
    const writeOptions = list(object({ grant_id: decimalId }));
    const collaboration = object({ options: list(object({ grant_id: decimalId, allows_agency: { type: 'boolean' } })),
        workspaces: list(object({ public_id: uuid, slug: text })),
        relationships: list(object({ link_ref: uuid, workspace_public_id: uuid, workspace_slug: text, kind: { enum: ['collaboration','agency','internal_team'] }, state: { enum: ['active','ended'] }, revision: id })),
        workspace_limit: { const: 32 }, workspace_more: { type: 'boolean' }, relationship_limit: { const: 64 } });
    const delegation = object({ actor_public_id: uuid,
        options: list(object({ grant_id: decimalId, action: text, can_issue: { type: 'boolean' }, max_expires_at: nullable({ type: 'string', format: 'date-time' }), chain_depth: id })),
        issued: list(object({ grant_id: decimalId, parent_grant_id: decimalId, recipient_public_id: uuid, action: text,
            recorded_status: { enum: ['active', 'revoked'] }, revision: id, expires_at: nullable({ type: 'string', format: 'date-time' }), delegable: { type: 'boolean' },
            realm_kind: realm, realm_workspace_public_id: nullable(uuid) })),
        issued_limit: { const: 64 }, issued_more: { type: 'boolean' }, option_limit: { const: 32 }, options_may_be_partial: { type: 'boolean' } });
    const surface = object({ business: projection, authority_selection: authority, context_write_options: writeOptions,
        fact_write_options: object({ markets: writeOptions, intent: writeOptions, preferences: writeOptions, purpose: writeOptions }), collaboration, delegation, catalog, scope,
        commercial: object({beneficiary:object({kind:{const:'business'},public_id:uuid}),context_state:{const:'QUALIFIED_BUSINESS_BINDING_UNAVAILABLE'},context:nullable(object({})),
            ...Object.fromEntries(['subscription_state','entitlement_state','quota_state','payer_state','coverage_state','amount_state'].map(k=>[k,{const:'UNKNOWN'}])),
            conditioned_feature_admission:{const:'HOLD'},usage_reservation:{const:'NOT_CREATED'},foundation_declarations:object({entitlement:{const:'NOT_APPLICABLE'},quota:{const:'NOT_APPLICABLE'},billing:{const:'NOT_APPLICABLE'}}),limitation:{const:'NO_OPERATING_SUBSCRIPTION_OR_METERING_POLICY'}}),
        data_use: object({ bindings: list(object({ binding_id: uuid, scope_id: uuid, state: { enum: ['active','ended'] }, revision: id })), bindings_limit: { const: 64 }, bindings_more: { type: 'boolean' } }) });
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
        next_cursor: nullable(historyVersion), history_scope: { const: 'native_business_transitions' }, has_imported_baseline: { type: 'boolean' },
        legacy_history: { const: 'not_included' }, page_limit: { const: 32 } })) };
    const legacyKinds = { enum: ['context','markets','intent','preferences','object_purpose'] };
    const legacyBase = { mapping_ref: uuid, native_key: text, native_version: historyVersion,
        recorded_at: { type: 'string', format: 'date-time' }, original_digest: { type: 'string', pattern: '^[0-9a-f]{64}$' } };
    const legacyHistory = { oneOf: [...Object.entries(historyValues).map(([kind, [before, after]]) => [kind, object({ ...legacyBase, before, after })]),
        ['object_purpose', object({ ...legacyBase, purpose_id: uuid, revision_id: uuid, operation: { enum: ['declare','replace','correct','withdraw'] },
            prior_version: nullable(historyVersion), supersedes_revision_id: nullable(uuid), effective_from: { type: 'string', format: 'date-time' },
            effective_until: nullable({ type: 'string', format: 'date-time' }), snapshot: ref('PurposeSnapshot'), reason: nullable(text),
            affected_revision_ids: list(uuid), applicability: { const: 'NOT_EVALUATED_HISTORICAL_DECLARATION' } })]]
        .map(([kind, entry]) => fact({ kind: { const: kind }, entries: list(entry), next_cursor: nullable(uuid), history_scope: { const: 'qualified_original_references' },
            completeness: { const: 'QUALIFIED_SUBSET_ONLY' }, order: { const: 'mapping_reference_not_effective_timeline' }, page_limit: { const: 32 } })) };
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
        realm_kind: realm, realm_workspace_public_id: nullable(uuid) })), next_cursor: nullable(decimalId) });
    const boundarySurface = object({ boundary: object({ boundary_id: uuid, state: { enum: ['active','restricted','archived'] }, revision: id }),
        authority_selection: authority, memberships: list(object({ membership_id: uuid, business_public_id: uuid, branch_public_id: nullable(uuid), state: { enum: ['active','ended'] }, revision: id })),
        memberships_limit: { const: 64 }, memberships_more: { type: 'boolean' } });
    const sourceParam = { in: 'path', name: 'source', required: true, schema: uuid };
    const sourceFields = { source_id: uuid, label: text, status: { enum: ['UNVERIFIED', 'REVOKED'] }, resource: text, operation: text, purpose: text };
    const sourceChoices = object({ sources: list(object({ ...sourceFields, access_grant_id: decimalId })), next_cursor: nullable(decimalId) });
    const internalTeamCommand = body({ workspace_public_id: uuid, effect: { enum: ['enroll','appoint','remove','end'] }, recipient_public_id: nullable(uuid), expected_revision: version });
    const internalTeamReceipt = fact({ workspace_public_id: uuid, link_ref: uuid, state: { enum: ['active','ended'] }, recipient_public_id: nullable(uuid),
        grant_refs: { type: 'object', additionalProperties: decimalId }, versions: versions(['link']) });
    const internalTeamSurface = fact({ creator_basis: { type: 'boolean' }, can_admin: { type: 'boolean' },
        teams: list(object({ link_ref: uuid, workspace_public_id: uuid, workspace_slug: text, state: { enum: ['active','ended'] }, revision: id })) });
    const businessSources = object({ business_public_id: uuid, scope: { const: 'current_operator_in_linked_workspaces' },
        sources: list(object({ ...sourceFields, access_grant_id: decimalId, family: text, namespace: text,
            workspace_public_id: uuid, workspace_slug: text })), next_cursor: nullable(decimalId) });
    const sourceSurface = object({ source: object({ ...sourceFields, family: text, namespace: text, revision: id, adopted: { type: 'boolean' }, scope_declared: { type: 'boolean' } }),
        authority_selection: authority, can_command: { type: 'boolean' },
        rights: list(object({ grant_id: decimalId, action: text, recorded_status: { enum: ['active', 'revoked'] }, revision: id, expires_at: nullable({ type: 'string', format: 'date-time' }) })),
        rights_limit: { const: 64 }, rights_more: { type: 'boolean' } });
    return {
        schemas: {
            InternalTeamCommand: internalTeamCommand, InternalTeamReceipt: internalTeamReceipt, InternalTeamSurface: internalTeamSurface,
            BusinessHistory: history, BusinessHistoryResult: data(history),
            BusinessLegacyHistory: legacyHistory, BusinessLegacyHistoryResult: data(legacyHistory),
            DataUseChoices: dataUseChoices, DataUseChoicesResult: data(dataUseChoices),
            NativeSourceChoices: sourceChoices, NativeSourceSurface: sourceSurface,
            BusinessSources: businessSources, BusinessSourcesResult: data(businessSources),
            NativeSourceChoicesResult: data(sourceChoices), NativeSourceSurfaceResult: data(sourceSurface),
            ClientBoundaryChoices: boundaryChoices, ClientBoundarySurface: boundarySurface,
            ClientBoundaryChoicesResult: data(boundaryChoices), ClientBoundarySurfaceResult: data(boundarySurface),
            BusinessChoices: choices, BusinessSurface: surface, BusinessCatalog: catalog,
            BusinessPurposes: businessPurposes, BusinessPurposesResult: data(businessPurposes), BusinessPurposeCommand: purposeCommand, BusinessPurposeAcknowledgement: purposeAck,
            BusinessChoicesResult: data(choices), BusinessSurfaceResult: data(surface), BusinessCatalogResult: data(catalog),
            BusinessMaterialPublishResult: data(fact({ artifact_ref: materialRef, manifest_id: uuid, closure_digest: { type: 'string', pattern: '^[0-9a-f]{64}$' }, versions: versions(['manifest']) })),
            MarketEvidencePayload: marketEvidencePayload,
            MarketProcessingPrepareCommand:processingPrepare,MarketProcessingCompleteCommand:processingComplete,MarketProcessingRunResult:data(processingRun),
            BusinessMaterialReference: materialRef, BusinessMaterialReadResult: data(materialResult),
            BusinessAuthoritySelection: authority,
            BusinessFoundationError: object({ error: { enum: ['business_authority_denied', 'business_conflict', 'business_relation_revision_rejected', 'invalid_business_command'] } }),
            BusinessDraftResult: data(draft), BusinessFoundationProjection: data(projection), BusinessContextWriteResult: data(contextResult),
            BusinessMarketWriteResult: data(marketsResult), BusinessPreferenceWriteResult: data(preferenceResult), BusinessIntentWriteResult: data(intentResult),
            BusinessGrantWriteResult: data(grantResult), BusinessLinkWriteResult: data(linkResult), BusinessBindingWriteResult: data(bindingResult),
            BusinessResourceRightResult: data({ oneOf: [boundaryResult, revokeResult, sourceGrantResult, sourceAdoptionResult, sourceRetirementResult] }), BusinessLifecycleWriteResult: data(lifecycleResult),
        },
        paths: {
            '/api/data-rights/use-scopes': { get: read('qualifiedDataUseChoices', data(dataUseChoices), [query('after')]) },
            '/api/source-rights': { get: read('qualifiedNativeSourceChoices', data(sourceChoices), [query('after')]) },
            '/api/source-rights/{source}': { get: read('qualifiedNativeSourceSurface', data(sourceSurface), [sourceParam, query('access', true)]) },
            '/source-rights': { get: read('nativeSourceChoicesPage', page(sourceChoices), [query('after')]) },
            '/source-rights/{source}': { get: read('nativeSourceDetailPage', page(sourceSurface), [sourceParam, query('access', true)]) },
            '/api/data-rights/boundaries': { get: read('qualifiedClientBoundaryChoices', data(boundaryChoices), [query('after')]) },
            '/api/data-rights/boundaries/{boundary}': { get: read('qualifiedClientBoundarySurface', data(boundarySurface), [boundaryParam, query('access', true)]) },
            '/data-rights': { get: read('clientBoundaryChoicesPage', page(boundaryChoices), [query('after')]) },
            '/data-rights/{boundary}': { get: read('clientBoundaryDetailPage', page(boundarySurface), [boundaryParam, query('access', true)]) },
            '/businesses': { get: read('businessChoicesPage', page(choices), [query('after')]) },
            '/businesses/new': { get: read('businessCreatePage', page(object({ catalog }))) },
            '/businesses/{business}': { get: read('businessDetailPage', page(surface), [business, query('access', true)]) },
            '/api/businesses/catalog': { get: read('independentBusinessCatalog', data(catalog)) },
            [`${base}/surface`]: { get: read('independentBusinessSurface', data(surface), [business, query('access', true)]) },
            [`${base}/sources`]: { get: read('qualifiedBusinessSources', data(businessSources), [business, query('access', true), query('after')]) },
            [`${base}/purposes`]: { get: read('readBusinessPurposes', data(businessPurposes), [business, query('access', true), ...['purpose','effective_at','known_at'].map(name => ({in:'query',name,schema:name==='purpose'?uuid:text}))]), post: endpoint('writeBusinessPurpose',object({command:purposeCommand,expected_context:version,authority_selection:authority}),purposeAck,201) },
            [`${base}/history`]: { get: read('independentBusinessHistory', data(history), [business, query('access', true), { in: 'query', name: 'kind', required: true, schema: historyKind }, query('after')]) },
            [`${base}/legacy-history`]: { get: read('qualifiedOriginalBusinessHistory', data(legacyHistory), [business, query('access', true), { in: 'query', name: 'kind', required: true, schema: legacyKinds }, { in: 'query', name: 'after', schema: uuid }]) },
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
            [`${base}/workspace-links`]: { post: endpoint('writeIndependentBusinessRelationship', object({ ...command, workspace_public_id: uuid, kind: { enum: ['collaboration', 'agency'] }, effect: { enum: ['add', 'end'] }, expected_revision: { ...version, description: 'Monotonic revision of this Business/workspace/kind, including its ended relationship. Only a never-existing tuple starts at zero; recreation has a new identity and a later revision.' } }, ['command_id', 'authority_selection', 'workspace_public_id', 'effect', 'expected_revision']), linkResult) },
            [`${base}/data-use-bindings`]: { post: endpoint('writeBusinessDataUseBinding', body({ data_use_scope_id: uuid, effect: { enum: ['add', 'end'] }, expected_relation: { ...version, description: 'Latest logical Business/data-use binding revision, including ended bindings; never reset on recreation.' } }), bindingResult) },
            [`${base}/grants`]: { post: endpoint('writeIndependentBusinessGrant', body({ effect: { enum: ['issue', 'revoke'] }, grantee_public_id: uuid, action: text, parent_grant_id: nullable(id), grant_id: nullable(id), expected_grant: version,
                expires_at: nullable({ type: 'string', format: 'date-time' }), delegable: { type: 'boolean' }, realm_workspace_public_id: nullable(uuid) }), grantResult) },
            [`${base}/internal-team`]: { get: read('readInternalClientFileTeam', data(internalTeamSurface), [business, query('access', true)]),
                post: endpoint('writeInternalClientFileTeam', internalTeamCommand, internalTeamReceipt) },
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
