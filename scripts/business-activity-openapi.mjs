/** Additive practice declarations and licensed, conditional customer-discovery assessment. */
export function businessActivityContracts({ ref, text, uuid, object, list, nullable, json, response, security, errors }) {
    const authority = ref('BusinessAuthoritySelection'), material = ref('BusinessMaterialReference');
    const version = { type: 'integer', minimum: 0 }, boolean = { type: 'boolean' };
    const area = object({ authority: text, level: text, ref: text });
    const geography = object({ status: { enum: ['unspecified', 'declared'] }, areas: list(area) });
    const operation = { enum: ['create', 'update', 'correct', 'reclassify', 'deactivate'] };
    const value = object({ name: text, description: text, kind: { enum: ['sale', 'installation', 'maintenance', 'service', 'production', 'brokerage', 'rental', 'other'] },
        sectors: list(text), specializations: list(text), offering: nullable(text), commercial_model: nullable(text), channels: list(text), target_markets: geography, fulfillment: geography });
    const practice = object({ practice_id: uuid, revision: version, operation, corrects_revision: nullable(version), correction_reason: nullable(text), state: { enum: ['active', 'inactive'] }, value, recorded_at: text });
    const command = object({ command_id: uuid, practice_id: uuid, operation, expected_revision: version, value: nullable(value), authority_selection: authority, read_selection: authority, correction_reason: nullable({ type: 'string', minLength: 3, maxLength: 1000 }) }, ['command_id', 'practice_id', 'operation', 'expected_revision', 'value', 'authority_selection', 'read_selection']);
    command.description = 'update preserves economic kind and requires a non-classification change; accompanying sector/specialization evolution is permitted. reclassify changes only sector/specialization. correct requires correction_reason and records the prior revision; changing kind requires the same nonempty offering. No-op revisions are rejected. Another economic practice requires create/deactivate.';
    command.allOf = [{ if: { properties: { operation: { const: 'correct' } }, required: ['operation'] },
        then: { required: ['correction_reason'], properties: { correction_reason: { type: 'string', minLength: 3, maxLength: 1000 } } },
        else: { properties: { correction_reason: { type: 'null' } } } }];
    const receipt = object({ business_public_id: uuid, practice, historical_receipt: boolean, current_revision: version, versions: object({ practice: version }) });
    const practices = object({ business_public_id: uuid, practices: list(practice), history_available: boolean, history: list(practice) }, ['business_public_id', 'practices', 'history_available']);
    const relation = object({ basis: { enum: ['business_declared', 'unknown', 'inferred_unverified', 'conflicting'] }, applicability: { enum: ['related', 'unrelated', 'unknown'] }, rationale: text });
    const question = object({ geography: nullable(area), period: nullable(object({ from: text, until: text })), offering: nullable(text) });
    const projection = object({ schema: { const: 'market.practice_basis:1' }, signals: list(object({ source_ref: uuid,
        subject: object({ key: nullable(text), label: text, identity_state: text }), geography: object({ ref: nullable(text), level: text, authority: text }, ['ref', 'level']),
        metric: object({}, [], true), period: object({ from: text, until: text }), measurement: object({}, [], true), coverage: text })) });
    const finding = object({}, [], true);
    const option = object({ material_ref: material, purpose: text, authority_selection: authority, finding, practice_basis: projection,
        saved_relations: list(object({ practice_id: uuid, practice_revision: version, assessment_id: uuid, relation, question })) }, ['material_ref', 'purpose', 'authority_selection', 'finding', 'practice_basis']);
    const options = object({ business_public_id: uuid, options: list(option), next_cursor: nullable(version) });
    const assessmentCommand = object({ command_id: uuid, practice_id: uuid, expected_revision: version, material_ref: material, purpose: text, relation, question, read_selection: authority, authority_selection: authority });
    const status = { enum: ['unknown', 'within_declared', 'not_listed_in_declaration'] };
    const assessment = object({ assessment_id: uuid, business_public_id: uuid, practice_id: uuid, practice_revision: version, material_ref: material,
        method_ref: { const: 'practice_interest_eligibility:1' }, assessment: { enum: ['applicable', 'not_applicable', 'inconclusive', 'review_required'] }, reason_code: text,
        relation_basis: relation.properties.basis, relation_independently_verified: { const: false }, target_market: status, fulfillment: status,
        supports_investigating_reach: boolean, limitations: list(text),
        relation: object({ ...relation.properties, subject_basis: nullable(projection), subject_binding: nullable(text) }), question, historical_receipt: boolean,
        currentness: { enum: ['current', 'review_required'] }, current_practice_revision: version },
    ['assessment_id', 'business_public_id', 'practice_id', 'practice_revision', 'material_ref', 'method_ref', 'assessment', 'reason_code', 'relation_basis', 'relation_independently_verified', 'target_market', 'fulfillment', 'supports_investigating_reach', 'relation', 'question', 'historical_receipt', 'currentness', 'current_practice_revision']);
    const data = value => object({ data: value });
    const business = { in: 'path', name: 'business', required: true, schema: uuid };
    const access = { in: 'query', name: 'access', required: true, schema: { type: 'integer', minimum: 1 } };
    const endpoint = (operationId, result, body, query = []) => ({ operationId, security, parameters: [business, ...query],
        ...(body ? { requestBody: { required: true, ...json(body) } } : {}), responses: { 200: response(data(result)), ...errors,
            ...Object.fromEntries([403, 409, 422].map(code => [code, response(object({ error: text }))])) } });
    const base = '/api/businesses/{business}';
    return { schemas: { BusinessPracticeValue: value, BusinessPractice: practice, BusinessPracticeCommand: command, BusinessPracticeReceipt: data(receipt), BusinessPracticesResult: data(practices),
        PracticeKnowledgeOptionsResult: data(options), PracticeKnowledgeCommand: assessmentCommand, PracticeKnowledgeResult: data(assessment) }, paths: {
        [`${base}/practices`]: { get: endpoint('readBusinessPractices', practices, null, [access, { in: 'query', name: 'practice', schema: uuid }]), post: endpoint('writeBusinessPractice', receipt, command) },
        [`${base}/practices/knowledge`]: { get: endpoint('practiceKnowledgeOptions', options, null, [access, { in: 'query', name: 'after', schema: version }]) },
        [`${base}/knowledge/customer-discovery/practice`]: { post: endpoint('inspectPracticeKnowledge', assessment, assessmentCommand) },
    } };
}
