import type { PreparationCommand } from './preparationState';

const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export function parsePreparationAttempt(saved: string | null): PreparationCommand | undefined {
    if (!saved) return;
    if (saved.length > 32768) throw Error('Oversized preparation attempt');
    const value = JSON.parse(saved);
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw Error('Invalid preparation attempt');
    const command = value.payload ? { kind: value.kind, body: value.payload, ...(value.kind === 'operator' ? { approval: value.approvalId } : {}) } : value;
    if (Object.keys(command).some(key => !['kind', 'body', 'approval'].includes(key))) throw Error('Unexpected preparation fields');
    if (command.kind === 'newIntent' && Object.keys(command).length === 1) return { kind: 'newIntent' };
    const body = command.body;
    if (!body || typeof body !== 'object' || Array.isArray(body) || Object.values(body).some(item => typeof item !== 'string')) throw Error('Invalid preparation body');
    const fields = command.kind === 'prepare' ? body.mode === 'direct' ? 'country_code' in body ? ['mode', 'intent_id', 'intent_binding', 'country_code', 'denomination_code', 'reason'] : ['mode', 'intent_id', 'intent_binding', 'label', 'reason'] : ['intent_id', 'intent_binding', 'administrator', 'label', 'reason', 'expires_at']
        : command.kind === 'operator' ? ['operator', 'reason'] : command.kind === 'delegate' ? ['market_id', 'operator', 'permission', 'reason', 'expires_at'] : [];
    if (!fields.length || Object.keys(body).length !== fields.length || fields.some(field => typeof body[field] !== 'string') || Object.keys(body).some(key => !fields.includes(key))) throw Error('Invalid preparation command shape');
    for (const [key, item] of Object.entries(body)) {
        const text = item as string;
        if (text.length > (key === 'intent_binding' ? 4096 : 255) || ((key.endsWith('_id') || ['administrator', 'operator'].includes(key)) && !uuid.test(text))) throw Error('Invalid preparation value');
    }
    if (body.reason.length > 120 || (body.label && body.label.length > 120) || ('expires_at' in body && !Number.isFinite(Date.parse(body.expires_at)))) throw Error('Invalid preparation terms');
    if (command.kind === 'prepare' && (!body.intent_binding || (body.mode === 'direct' && 'country_code' in body && (!/^[A-Z]{2}$/.test(body.country_code) || !/^[A-Z]{3}$/.test(body.denomination_code))))) throw Error('Invalid original preparation identity');
    if (command.kind === 'operator' && !uuid.test(command.approval ?? '')) throw Error('Invalid approval identity');
    if (command.kind === 'delegate' && !['read', 'manage'].includes(body.permission)) throw Error('Invalid delegation permission');
    return command as PreparationCommand;
}
