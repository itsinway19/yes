import type { CommercialCommand } from './operationsState';
import type { components } from '../api/generated/application';

export async function matchesOriginalReceipt(command: CommercialCommand, receipt: components['schemas']['CommercialCommandReceipt'], principal: number) {
    const body = command.body;
    const signatures = {
        market: ['ConfigureCommercialMarket', 'market_id', 'label', 'reason_code'],
        catalog: ['RecordOrVerifyApprovedMarketReference', 'market_id', 'catalog_reference_id', 'catalog_identity', 'catalog_version', 'approval_reference', 'source_sha256', 'reason_code'],
        configuration: ['ConfigureCommercialMarketVersion', 'market_id', 'configuration_id', 'catalog_reference_id', 'denomination_code', 'reason_code'],
        assignability: ['MarketAssignability', 'market_id', 'configuration_id', 'expected_revision', 'reason_code'],
        register: ['RegisterCommercialScope', 'scope_id', 'workspace_public_id', 'market_id', 'configuration_id', 'catalog_reference_id', 'denomination_code', 'reason_code'],
    };
    const [label, ...fields] = signatures[command.kind];
    const input = body as unknown as Record<string, unknown>;
    const serialized = '[' + [label, ...fields.map(field => {
        const value = input[field] ?? null;
        return typeof value === 'string' && (field.endsWith('_id') || field === 'catalog_identity') ? value.toLowerCase() : value;
    })].map(value => JSON.stringify(value)).join(', ') + ']';
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(serialized));
    const fingerprint = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
    const effect = command.kind === 'assignability' ? (input.configuration_id ? 'EnableCommercialMarket' : 'DisableCommercialMarket') : label;
    const target = command.kind === 'register' ? input.scope_id : input.market_id;
    return receipt.command_id === body.command_id.toLowerCase() && receipt.actor_user_id === principal
        && receipt.effect === effect && receipt.target_id === String(target).toLowerCase()
        && receipt.reason_code === body.reason_code && receipt.request_fingerprint === fingerprint;
}
