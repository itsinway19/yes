import { close, documentEvidence } from '../spa/transport';
import type { ProfileCommand } from './api';
export function changeEmail(body: ProfileCommand) {
    const evidence = documentEvidence();
    const form = document.createElement('form');
    form.method = 'POST'; form.action = '/profile'; form.hidden = true;
    for (const [name, value] of Object.entries({ ...body, _method: 'PATCH', _token: evidence.csrf, _identity_receipt: evidence.receipt, _identity_transition: evidence.transition })) {
        const field = document.createElement('input'); field.type = 'hidden'; field.name = name; field.value = value; form.append(field);
    }
    document.body.append(form); close();
    HTMLFormElement.prototype.submit.call(form);
}
