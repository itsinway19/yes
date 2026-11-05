export function providerContracts({ ref, text, uuid, object, list, nullable, version, response, json, errors, security, headers, workspaceParameter }) {
    const providers = ['meta', 'google', 'tiktok', 'snapchat', 'x'];
    const sourceParameter = { in: 'path', name: 'source', required: true, schema: uuid };
    const flowParameter = { in: 'path', name: 'flow', required: true, schema: ref('ProviderFlowHandle') };
    const read = (operationId, schema, parameters) => ({ operationId, security, parameters, responses: { 200: response(schema), ...errors, 404: response(ref('Error')), 502: response(ref('ProviderActionError')) } });
    const write = (operationId, body, schema, parameters, status = 200) => ({ operationId, security, parameters, requestBody: { required: true, ...json(body) }, responses: { [status]: response(schema), ...errors, 404: response(ref('Error')), 502: response(ref('ProviderActionError')) } });
    const date = { type: 'string', format: 'date' };
    const period = { start_date: date, end_date: date };
    const mode = { enum: ['SYNC', 'ASYNC'] };
    const xPeriod = { ...period, campaign_ids: { ...list({ type: 'string', pattern: '^[a-zA-Z0-9]{1,180}$' }), minItems: 1, maxItems: 20, uniqueItems: true }, placement: { enum: ['ALL_ON_TWITTER', 'SPOTLIGHT', 'TREND'] }, mode };
    const command = { command_id: uuid, expected_version: { type: 'integer', minimum: 1 }, expected_generation: version, deadline: { type: 'string', format: 'date-time' } };
    const accessAcknowledgement = object({ source_id: uuid, receipt: {}, access: ref('ProviderAccess') }, undefined, true);
    const snapchatSuccess = object({ source_id: uuid, receipt: {}, access: ref('ProviderAccess'), status: false }, ['source_id', 'receipt', 'access'], true);
    const snapchatAction = object({ status: { const: 'PROVIDER_ACTION_REQUIRED' }, reason: text, source_id: uuid, access: ref('ProviderAccess'), receipt: false }, ['status', 'reason', 'source_id', 'access']);
    const paths = {};
    for (const provider of providers) {
        const base = `/workspaces/{workspace}/providers/${provider}`;
        const account = ref(provider === 'google' ? 'GoogleAccountChoice' : 'ProviderAccountChoice');
        const page = object({ accounts: list(account), continuation: nullable(ref('ProviderFlowHandle')), coverage: text }, undefined, true);
        const periodName = provider === 'x' ? 'XReportPeriod' : provider === 'meta' ? 'MetaReportPeriod' : 'ProviderReportPeriod';
        const reportName = provider === 'x' ? 'XReportCommand' : provider === 'meta' ? 'MetaReportCommand' : 'ProviderReportCommand';
        const fields = provider === 'x' ? xPeriod : provider === 'meta' ? { ...period, mode } : period;
        const query = Object.entries(fields).map(([name, schema]) => ({ in: 'query', name: name === 'campaign_ids' ? 'campaign_ids[]' : name, schema, required: name !== 'mode', ...(name === 'campaign_ids' ? { style: 'form', explode: true } : {}) }));
        paths[base] = { get: read(`${provider}ConnectionSnapshot`, ref('ProviderSnapshot'), [workspaceParameter, ...headers]) };
        paths[`${base}/connect`] = { post: write(`${provider}BeginConnection`, provider === 'google' ? ref('GoogleConnectCommand') : object({ intent: ref('ProviderFlowHandle') }), object({ authorization_url: text }), [workspaceParameter]) };
        paths[`${base}/discovery/{flow}`] = { get: read(`${provider}DiscoverAccounts`, page, [workspaceParameter, flowParameter]) };
        paths[`${base}/select`] = { post: write(`${provider}SelectAccount`, object({ selection: ref('ProviderFlowHandle') }), ['meta', 'snapchat', 'x'].includes(provider) ? accessAcknowledgement : object({ source_id: uuid, receipt: {}, status: text }), [workspaceParameter]) };
        paths[`${base}/{source}/disconnect`] = { post: write(`${provider}DisconnectLocalAccess`, ref('ProviderVersionCommand'), accessAcknowledgement, [workspaceParameter, sourceParameter]) };
        paths[`${base}/{source}/report`] = {
            get: { ...read(`${provider}ReportStatus`, ref('ProviderReportStatus'), [workspaceParameter, sourceParameter, ...query]), description: `Existing ${periodName}; no absent observations are converted to zero or COMPLETE.` },
            post: { ...write(`${provider}QueueReport`, ref(reportName), object({ queued: { const: true }, command_id: uuid, source_id: uuid }), [workspaceParameter, sourceParameter], 202), description: 'Existing bounded background queue request, not a provider acknowledgement, report completion or business outcome. Preserve the original command, version, generation and deadline after unknown delivery.' },
        };
        if (provider === 'google') paths[`${base}/hierarchy/{flow}`] = { get: read('googleBrowseHierarchy', page, [workspaceParameter, flowParameter]) };
        if (['google', 'snapchat'].includes(provider)) paths[`${base}/{source}/renew`] = { post: write(`${provider}RenewAccess`, ref('ProviderVersionCommand'), accessAcknowledgement, [workspaceParameter, sourceParameter]) };
        if (provider === 'snapchat') {
            paths[`${base}/{source}/renew`].post.responses[200] = response(ref('SnapchatRenewalSuccess'));
            paths[`${base}/{source}/renew`].post.responses[502] = response({ oneOf: [ref('SnapchatRenewalActionRequired'), object({ status: { const: 'PROVIDER_ACTION_REQUIRED' }, reason: text })] });
        }
    }
    paths['/workspaces/{workspace}/providers/x/{source}/campaigns'] = { get: read('xCampaignChoices', object({ campaigns: list(object({ id: text, label: text })), continuation: nullable(ref('ProviderFlowHandle')) }), [workspaceParameter, sourceParameter, { in: 'query', name: 'continuation', schema: ref('ProviderFlowHandle') }]) };
    // Identical provider operations, distinct typed custody and fresh admission.
    // Keep their contracts composed so one side cannot silently lose a field.
    for (const [path, operations] of Object.entries(paths)) {
        const businessOperations = structuredClone(operations);
        for (const operation of Object.values(businessOperations)) {
            operation.operationId = 'business' + operation.operationId[0].toUpperCase() + operation.operationId.slice(1);
            operation.parameters = operation.parameters.map(parameter => parameter.in === 'path' && parameter.name === 'workspace'
                ? { ...parameter, name: 'business' } : parameter);
        }
        paths[path.replace('/workspaces/{workspace}', '/businesses/{business}')] = businessOperations;
    }
    return { paths, schemas: {
        GoogleConnectCommand: { oneOf: [object({ intent: ref('ProviderFlowHandle'), recover_offline_grant: { type: 'boolean' } }, ['intent']), object({ source_id: uuid, expected_version: version, recover_offline_grant: { type: 'boolean' } }, ['source_id', 'expected_version'])] },
        SnapchatRenewalSuccess: snapchatSuccess,
        SnapchatRenewalActionRequired: snapchatAction,
        SnapchatRenewalOutcome: { oneOf: [ref('SnapchatRenewalSuccess'), ref('SnapchatRenewalActionRequired')] },
        ProviderFlowHandle: { type: 'string', pattern: '^[A-Za-z0-9_-]{43}$' },
        ProviderActionError: object({ status: text, reason: text }, ['status', 'reason'], true),
        ProviderAccess: object({ version, connection: text, native_observation: nullable(object({ state: text, observed_at: text })), axes: { type: 'object', additionalProperties: text }, effective: object({ state: text, reason: text, last_verified_at: nullable(text) }), reporting: object({ can_report: { type: 'boolean' }, why_not: nullable(text) }) }),
        ProviderSnapshot: object({ connections: list(object({ source_id: uuid, label: text, declared_account_id: text, registration: text, access: ref('ProviderAccess') })), availability: text, connect_intent: nullable(ref('ProviderFlowHandle')), notice: nullable(object({ privacy_url: text, revision: text, deletion_instructions_url: text, purpose: text }, ['privacy_url', 'revision'], true)) }),
        ProviderAccountChoice: object({ account_id: text, label: text, existing_source_label: nullable(text), selection_handle: ref('ProviderFlowHandle') }),
        GoogleAccountChoice: object({ account_id: text, label: text, existing_source_label: nullable(text), selection_handle: nullable(ref('ProviderFlowHandle')), hierarchy_handle: nullable(ref('ProviderFlowHandle')), manager: nullable({ type: 'boolean' }) }),
        ProviderVersionCommand: object({ command_id: uuid, expected_version: { type: 'integer', minimum: 1 } }),
        ProviderReportPeriod: object(period),
        MetaReportPeriod: object({ ...period, mode }, Object.keys(period)),
        XReportPeriod: object(xPeriod, ['start_date', 'end_date', 'campaign_ids', 'placement']),
        ProviderReportCommand: object({ ...period, ...command }),
        MetaReportCommand: object({ ...period, mode, ...command }, [...Object.keys(period), ...Object.keys(command)]),
        XReportCommand: object({ ...xPeriod, ...command }, ['start_date', 'end_date', 'campaign_ids', 'placement', ...Object.keys(command)]),
        ProviderReportStatus: object({ source_id: uuid, generation: version, timezone: text, deadline: text, download: nullable(object({ generation: version, url: text })), last_run: nullable(object({ coverage: text, failure: nullable(text), recorded_at: text })) }, ['source_id', 'generation', 'timezone', 'deadline', 'last_run']),
    } };
}
