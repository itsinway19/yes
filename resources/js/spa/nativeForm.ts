import { close, documentEvidence } from './transport';

export function submitDocument(action: string, body: Record<string, unknown>, method = 'POST') {
    if (new URL(action, location.origin).origin !== location.origin) throw Error('Application origin required');
    const evidence = documentEvidence();
    const form = document.createElement('form');
    form.method = 'POST'; form.action = action; form.hidden = true;
    const append = (name: string, value: unknown) => {
        if (value !== null && typeof value === 'object') {
            for (const [key, nested] of Object.entries(value)) append(`${name}[${key}]`, nested);
        } else if (value !== undefined && value !== null) {
            const field = document.createElement('input'); field.type = 'hidden'; field.name = name;
            field.value = typeof value === 'boolean' ? (value ? '1' : '0') : String(value); form.append(field);
        }
    };
    for (const [name, value] of Object.entries({ ...body, _method: method, _token: evidence.csrf, _identity_receipt: evidence.receipt, _identity_transition: evidence.transition })) append(name, value);
    document.body.append(form); close('handoff'); HTMLFormElement.prototype.submit.call(form);
}
