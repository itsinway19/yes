export function sourceContracts({ ref, text, uuid, object, list, nullable, version, response, json, errors, security, headers, workspaceParameter, envelope, resource }) {
    const scoped = { source_id: uuid, resource: text, operation: text, purpose: text, reason: text };
    const tuple = { resource: text, operation: text, purpose: text };
    const command = (operationId, body, result, parameters = [workspaceParameter]) => ({ operationId, security, parameters,
        ...(body ? { requestBody: { required: true, ...json(body) } } : {}), responses: { 200: response(result), ...errors } });
    const common = { ...scoped, command_id: uuid, expected_version: version };
    return {
        paths: {
            '/workspaces/{workspace}': { get: { operationId: 'readWorkspaceSources', security, parameters: [workspaceParameter, ...headers], responses: {
                200: response(resource('WorkspaceSourcesResource')), 302: { description: 'Existing missing Business prerequisite redirects to the canonical Business context.' }, ...errors } } },
            '/workspaces/{workspace}/source-grants': { post: command('requestSourceAuthority', ref('SourceAuthorityCommand'), object({ decision: { enum: ['authorize', 'revoke'] } })) },
            '/workspaces/{workspace}/sources': { post: command('applySourceCommand', ref('SourceCommand'), ref('SourceReceipt')) },
            '/workspaces/{workspace}/source-audit/{obligation}/recover': { post: command('recoverSourceAudit', undefined, object({ obligation_id: uuid, delivery_state: text }), [workspaceParameter, { in: 'path', name: 'obligation', required: true, schema: uuid }]) },
            '/workspaces/{workspace}/services': { post: command('authorizeServiceScope', object({ service: uuid, purpose: { type: 'string', minLength: 10, maxLength: 128 } }), ref('ApplicationDestination')) },
            '/workspaces/{workspace}/services/{service}': { delete: command('revokeServiceScope', undefined, ref('ApplicationDestination'), [workspaceParameter, { in: 'path', name: 'service', required: true, schema: uuid }]) },
        },
        schemas: {
            SourceScope: object(tuple),
            SourceAuthorityCommand: object({ ...scoped, action: { enum: ['source.register', 'source.manage', 'source.read', 'source.audit.recover', 'provider.connect', 'provider.read', 'provider.revoke'] }, decision: { enum: ['authorize', 'revoke'] }, expires_at: nullable(text) }, [...Object.keys(scoped), 'action', 'decision']),
            SourceCommand: { oneOf: [
                object({ ...common, effect: { const: 'register' }, family: text, namespace: text, external_id: text, label: text }),
                object({ ...common, effect: { const: 'rename' }, label: text }),
                object({ ...common, effect: { const: 'declare_scope' } }),
                object({ ...common, effect: { const: 'revoke' } }),
            ] },
            SourceReceipt: object({ command_id: uuid, source_id: uuid, version, registration_state: text, obligation_id: uuid, local_audit: { const: 'DURABLE' }, reason: text }),
            SourceItem: object({ ...tuple, id: uuid, family: text, namespace: text, declared_external_id: text, label: text, version, status: text, identity_verification: { const: 'UNVERIFIED' }, axes: { type: 'object', additionalProperties: text }, capability: object({ state: text, reason: text, last_verified_at: nullable(text) }), can_manage: { type: 'boolean' }, scope_choices: list(ref('SourceScope')), next_repair: text,
                audit_delivery: list(object({ ...tuple, id: uuid, command_id: uuid, receipt_version: version, state: text, attempts: version, can_recover: { type: 'boolean' }, recovery_reason: nullable(text) })) }, undefined, true),
            SourceRegistryProjection: object({ state: text, items: list(ref('SourceItem')), registration_authorities: list(object({ source_target_id: uuid, source_resource: text, source_operation: text, source_purpose: text })) }),
            WorkspaceSourcesData: object({ workspace: ref('WorkspaceReference'),
                canRequestSourceAuthority: { type: 'boolean' }, canManageServices: { type: 'boolean' }, sourceCandidateId: uuid, sources: ref('SourceRegistryProjection'), services: list(object({ public_id: uuid, name: text, purpose: text })),
                commercial: object({ state: text, context: nullable(object({ scope_id: text, market_id: uuid, market_label: text, configuration_id: text, catalog_identity: text, catalog_version: text, denomination_code: text, effective_at: text })), agreement_state: text, amount_state: text }) }),
            WorkspaceSourcesResource: envelope('WorkspaceSourcesData'),
        },
    };
}
