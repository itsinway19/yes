export function commercialContracts({ ref, text, uuid, object, list, nullable, response, json, errors, security, headers, envelope, resource }) {
    const flag = { type: 'boolean' }, integer = { type: 'integer' };
    const approval = object({ id: uuid, target_id: uuid, label: text, reason: text, expires_at: text, approved_at: text,
        administrator_name: text, administrator_public_id: uuid, founder_id: integer, administrator_id: integer,
        status: text, revoked_at: nullable(text), consumed: flag, active: flag, canAuthorize: flag });
    const country = object({ ar: text, en: text, currency: text });
    const countries = { type: 'object', additionalProperties: country };
    const names = { type: 'object', additionalProperties: object({ ar: text, en: text }) };
    const direct = object({ mode: { const: 'direct' }, intent_id: uuid, intent_binding: text, country_code: text, denomination_code: text, reason: text });
    const delegated = object({ intent_id: uuid, intent_binding: text, administrator: uuid, label: text, reason: text, expires_at: text });
    const directNamed = object({ mode: { const: 'direct' }, intent_id: uuid, intent_binding: text, label: text, reason: text });
    const command = (operationId, body, result, parameters = []) => ({ operationId, security, parameters,
        ...(body ? { requestBody: { required: true, ...json(body) } } : {}), responses: { 200: response(result), ...errors } });
    const base = '/internal/administration/commercial-markets';
    const market = object({ id: uuid, label: text, country_code: nullable(text), configuration_id: nullable(uuid), revision: integer });
    const configuration = object({ id: uuid, market_id: uuid, version: integer, catalog_reference_id: nullable(uuid), denomination_code: nullable(text), recorded_at: text });
    const catalog = object({ id: uuid, market_id: uuid, catalog_identity: uuid, catalog_version: text, approval_reference: text, source_sha256: text, recorded_at: text });
    const target = object({ action: text, target_id: uuid, version_id: nullable(uuid), workspace_public_id: nullable(uuid), label: nullable(text) }, ['action', 'target_id', 'version_id', 'workspace_public_id']);
    const envelopeFields = { command_id: uuid, reason_code: text };
    const marketResult = object({ market_id: uuid, label: text });
    const configurationResult = object({ market_id: uuid, configuration_id: uuid, version: integer, catalog_reference_id: nullable(uuid), denomination_code: nullable(text) });
    const catalogResult = object({ catalog_reference_id: uuid, market_id: uuid, catalog_identity: uuid, catalog_version: text });
    const assignabilityResult = object({ market_id: uuid, configuration_id: nullable(uuid), revision: integer });
    const registrationResult = object({ scope_id: uuid, workspace_public_id: uuid, market_id: uuid, configuration_id: uuid, catalog_reference_id: uuid, catalog_identity: uuid, catalog_version: text, configuration_version: integer, denomination_code: text, formation_state: { const: 'CONTEXT_RECORDED' }, agreement_state: { const: 'UNKNOWN' }, amount_state: { const: 'UNKNOWN' } });
    const effectResult = { oneOf: [marketResult, configurationResult, catalogResult, assignabilityResult, registrationResult] };
    const identifier = name => [{ in: 'path', name, required: true, schema: uuid }];
    return {
        schemas: {
            CommercialOperationsData: object({ markets: list(market), configurations: list(configuration), catalogReferences: list(catalog),
                countryOptions: countries, currencyOptions: list(text), currencyNames: names, targets: list(target), newMarketTargets: list(target),
                obligations: list(object({ id: uuid, command_id: uuid, target_id: uuid, state: text, attempts: integer, acknowledgment_id: nullable(uuid), workspace_public_id: nullable(uuid) })),
                founderAccess: flag, newCatalogReferenceId: uuid, newConfigurationId: uuid, result: nullable({ type: 'object', additionalProperties: true }), freshAuthentication: flag }),
            CommercialOperationsResource: envelope('CommercialOperationsData'),
            CommercialMarketCommand: object({ ...envelopeFields, market_id: uuid, label: text }),
            CommercialCatalogCommand: object({ ...envelopeFields, market_id: uuid, catalog_reference_id: uuid, catalog_identity: uuid, catalog_version: text, approval_reference: text, source_sha256: text }),
            CommercialConfigurationCommand: object({ ...envelopeFields, market_id: uuid, configuration_id: uuid, catalog_reference_id: nullable(uuid), denomination_code: nullable(text) }, ['command_id', 'reason_code', 'market_id', 'configuration_id']),
            CommercialAssignabilityCommand: object({ ...envelopeFields, market_id: uuid, configuration_id: nullable(uuid), expected_revision: { type: 'integer', minimum: 1 } }, ['command_id', 'reason_code', 'market_id', 'expected_revision']),
            CommercialRegistrationCommand: object({ ...envelopeFields, scope_id: uuid, workspace_public_id: uuid, market_id: uuid, configuration_id: uuid, catalog_reference_id: uuid, denomination_code: text }),
            CommercialEffectResult: effectResult,
            CommercialCommandReceipt: object({ command_id: uuid, effect: text, target_id: uuid, workspace_id: nullable(integer), request_fingerprint: text, result: text, actor_user_id: integer, actor_generation: integer, reason_code: text, policy_version: text, decided_at: text, recorded_at: text }),
            CommercialGrantCommand: object({ grantee: uuid, effect: text, target_id: uuid, workspace_id: nullable(integer), version_id: nullable(uuid), reason_code: text, expires_at: nullable(text) }, ['grantee', 'effect', 'target_id', 'reason_code']),
            CommercialContextResult: object({ state: { const: 'CONTEXT_RECORDED' }, context: object({ scope_id: uuid, market_id: uuid, market_label: text, configuration_id: uuid, catalog_identity: uuid, catalog_version: text, denomination_code: text, decided_at: text, effective_at: text, recorded_at: text, workspace_public_id: uuid }), agreement_state: { const: 'UNKNOWN' }, amount_state: { const: 'UNKNOWN' } }),
            CommercialPreparationData: object({ canPrepare: flag, intentId: nullable(uuid), intentBinding: nullable(text),
                people: list(object({ public_id: uuid, name: text, kind: text })), approvals: list(approval),
                countryOptions: countries, currencyOptions: list(text), currencyNames: names, freshAuthentication: flag,
                directRecorded: flag, recordedMarkets: list(object({ id: uuid, label: text, country_code: nullable(text), configuration_id: nullable(uuid), revision: integer })) }),
            CommercialPreparationResource: envelope('CommercialPreparationData'),
            CommercialPreparationCommand: { oneOf: [direct, directNamed, delegated] },
            CommercialPreparationAcknowledgement: { oneOf: [
                object({ market_id: uuid, label: text, country_code: text, configuration_id: uuid, denomination_code: text }),
                marketResult,
                object({ state: { const: 'MARKET_AUTHORITY_APPROVED' }, target_id: uuid, approval_id: uuid, label: text }),
            ] },
            CommercialOperatorCommand: object({ operator: uuid, reason: text }),
            CommercialDelegationCommand: object({ market_id: uuid, operator: uuid, permission: { enum: ['read', 'manage'] }, reason: text, expires_at: text }),
        },
        paths: {
            '/internal/commercial': { get: { operationId: 'readCommercialOperations', security, parameters: headers, responses: { 200: response(resource('CommercialOperationsResource')), ...errors } } },
            '/internal/commercial/markets': { post: command('recordCommercialMarket', ref('CommercialMarketCommand'), marketResult) },
            '/internal/commercial/catalog-references': { post: command('recordCommercialCatalog', ref('CommercialCatalogCommand'), catalogResult) },
            '/internal/commercial/configurations': { post: command('recordCommercialConfiguration', ref('CommercialConfigurationCommand'), configurationResult) },
            '/internal/commercial/assignability': { post: command('setCommercialAssignability', ref('CommercialAssignabilityCommand'), assignabilityResult) },
            '/internal/commercial/registrations': { post: command('registerCommercialScope', ref('CommercialRegistrationCommand'), registrationResult) },
            '/internal/commercial/receipts/{command}': { get: { operationId: 'readCommercialCommandReceipt', security, parameters: identifier('command'), responses: { 200: response(ref('CommercialCommandReceipt')), ...errors } } },
            '/internal/commercial/workspace-reference': { get: { operationId: 'readCommercialWorkspaceReference', security, parameters: ['scope_id', 'workspace_public_id'].map(name => ({ in: 'query', name, required: true, schema: uuid })), responses: { 200: response(nullable(object({ public_id: uuid, name: text, slug: text }))), ...errors } } },
            '/internal/commercial/contexts/{scope}': { get: { operationId: 'readInternalCommercialContext', security, parameters: identifier('scope'), responses: { 200: response(ref('CommercialContextResult')), ...errors } } },
            '/internal/commercial/audit/{obligation}/recover': { post: command('recoverCommercialAudit', null, object({ obligation_id: uuid, state: { enum: ['acknowledged', 'failed'] }, acknowledgment_id: nullable(uuid) }), identifier('obligation')) },
            '/internal/commercial/grants': { post: command('issueCommercialGrant', ref('CommercialGrantCommand'), object({ grant_id: integer })) },
            '/internal/commercial/grants/{grant}': { delete: { operationId: 'revokeCommercialGrant', security, parameters: [{ in: 'path', name: 'grant', required: true, schema: integer }], responses: { 204: { description: 'Existing grant revoked.' }, ...errors } } },
            '/internal/commercial/owner-mandate': { post: command('executeCommercialOwnerMandate', object({ mandate_id: uuid }), object({ grant_id: integer })) },
            [base]: {
                get: { operationId: 'readCommercialPreparation', security, parameters: headers, responses: { 200: response(resource('CommercialPreparationResource')), ...errors } },
                post: command('prepareCommercialMarket', ref('CommercialPreparationCommand'), ref('CommercialPreparationAcknowledgement')),
            },
            [`${base}/new-intent`]: { post: command('startNextCommercialIntent', null, object({ saved: { const: true } })) },
            [`${base}/delegate`]: { post: command('delegateCommercialMarket', ref('CommercialDelegationCommand'), object({ state: { const: 'MARKET_EMPLOYEE_AUTHORIZED' } })) },
            [`${base}/{approval}/operator`]: { post: command('authorizePreparedCommercialOperator', ref('CommercialOperatorCommand'), object({ state: { const: 'MARKET_OPERATOR_AUTHORIZED' } }), [{ in: 'path', name: 'approval', required: true, schema: uuid }]) },
        },
    };
}
