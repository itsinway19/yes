export function accountContracts({ ref, text, object, list, nullable, response, json, errors, security, headers, envelope, resource }) {
    const read = (operationId, schema) => ({ operationId, security, parameters: headers, responses: { 200: response(resource(schema)), ...errors } });
    const write = (operationId, schema, parameters = []) => ({ operationId, security, parameters, ...(schema ? { requestBody: { required: true, ...json(ref(schema)) } } : {}), responses: { 200: response(ref('AccountAcknowledgement')), ...errors } });
    const id = name => ({ in: 'path', name, required: true, schema: { type: 'integer', minimum: 1 } });
    return {
        paths: {
            '/profile': { get: read('readAccountProfile', 'AccountProfileResource'), patch: { ...write('updateAccountProfile', 'AccountProfileCommand'), responses: { ...write('', '').responses, 302: { description: 'Email change retains the native Laravel terminal redirect to login. Submit a native document form; never treat it as an ordinary JSON save.' } } } },
            '/password': { put: write('updateAccountPassword', 'AccountPasswordCommand') },
            '/security/devices': { get: read('readAccountDevices', 'AccountDevicesResource') },
            '/security/sessions/{session}': { delete: { ...write('revokeAccountSession', null, [id('session')]), responses: { ...write('', '').responses, 404: response(ref('Error')) } } },
            '/security/sessions/revoke-others': { post: write('revokeOtherAccountSessions') },
            '/security/passkeys/options': { get: { operationId: 'accountPasskeyOptions', security, responses: { 200: response(ref('AccountPasskeyOptions')), ...errors } } },
            '/security/passkeys': { post: { ...write('storeAccountPasskey', 'AccountPasskeyCommand'), responses: { 201: response(ref('AccountPasskeyAcknowledgement')), ...errors } } },
            '/security/passkeys/{passkey}': { delete: { ...write('deleteAccountPasskey', null, [id('passkey')]), responses: { ...write('', '').responses, 404: response(ref('Error')) } } },
        },
        schemas: {
            AccountAcknowledgement: object({ saved: { const: true } }),
            AccountProfileCommand: object({ name: { ...text, maxLength: 255, minLength: 1 }, email: { ...text, format: 'email', maxLength: 255 } }),
            AccountPasswordCommand: object({ current_password: text, password: text, password_confirmation: text }),
            AccountProfileData: object({ user: object({ name: text, email: text, email_verified_at: nullable(text) }), mustVerifyEmail: { type: 'boolean' }, canChangeEmail: { type: 'boolean' }, status: nullable(text), errors: { anyOf: [{ type: 'object', additionalProperties: list(text) }, { type: 'array', maxItems: 0 }] }, old: object({ name: nullable(text), email: nullable(text) }) }),
            AccountProfileResource: envelope('AccountProfileData'),
            AccountSession: object({ id: { type: 'integer' }, current: { type: 'boolean' }, description: text, last_seen_at: text, authenticated_at: text }),
            AccountPasskey: object({ id: { type: 'integer' }, name: text, provider: nullable(text), created_at: text, last_used_at: nullable(text) }),
            AccountDevicesData: object({ internalContext: { const: false }, sessions: list(ref('AccountSession')), passkeys: list(ref('AccountPasskey')) }),
            AccountDevicesResource: envelope('AccountDevicesData'),
            AccountPasskeyOptions: object({ options: object({}, [], true) }),
            AccountPasskeyCommand: object({ name: { ...text, minLength: 1, maxLength: 255 }, credential: object({ id: text, rawId: text, type: { const: 'public-key' }, response: object({}, [], true) }, undefined, true) }),
            AccountPasskeyAcknowledgement: object({ id: { type: 'integer' }, name: text }),
        },
    };
}
