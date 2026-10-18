export function authEntryContracts({ ref, text, object, list, nullable, response, json, errors, security, headers, envelope, resource }) {
    const failures = { ...errors, 429: response(ref('Error')) };
    const read = operationId => ({ operationId, security, parameters: headers, responses: { 200: response({ oneOf: [ref('AuthEntryResource'), ref('AuthEntryWitness')] }), 302: { description: 'Existing Laravel access/intended destination redirect.' }, ...failures } });
    const authenticate = (operationId, schema) => ({ operationId, security, description: 'Existing JSON identity transition contract. The shared transport verifies its acknowledgement and departs to a new native document; the browser never adopts the returned identity.', requestBody: { required: true, ...json(ref(schema)) }, responses: { 200: response(ref('AuthTransitionResponse')), 302: { description: 'Existing Laravel access middleware redirect, not an authentication success acknowledgement.' }, ...failures } });
    return {
        paths: {
            '/login': { get: read('readLoginEntry'), post: authenticate('authenticateAdvertiser', 'AuthPasswordCommand') },
            '/register': { get: read('readRegistrationEntry'), post: authenticate('registerAdvertiser', 'AuthRegistrationCommand') },
            '/identity/switch': { get: read('readIdentitySwitchEntry'), post: authenticate('switchAdvertiser', 'AuthPasswordCommand') },
            '/forgot-password': { get: read('readRecoveryEntry') },
            '/reset-password/{token}': { get: { ...read('readResetEntry'), parameters: [...headers, { in: 'path', name: 'token', required: true, schema: text }] } },
            '/verify-email': { get: read('readVerificationEntry') },
            '/confirm-password': { get: read('readConfirmationEntry') },
            '/auth/passkey/options': { get: { operationId: 'advertiserLoginOptions', security, responses: { 200: response(ref('AccountPasskeyOptions')), ...errors } } },
            '/identity/passkey/options': { get: { operationId: 'advertiserSwitchOptions', security, responses: { 200: response(ref('AccountPasskeyOptions')), ...errors } } },
            '/auth/passkey': { post: authenticate('authenticateAdvertiserPasskey', 'AuthPasskeyCommand') },
            '/identity/passkey': { post: authenticate('switchAdvertiserPasskey', 'AuthPasskeyCommand') },
            '/internal/login': { get: read('readInternalLoginEntry') },
            '/internal/identity/switch': { get: read('readInternalSwitchEntry') },
            '/internal/auth/passkey/options': { get: { operationId: 'internalLoginOptions', security, responses: { 200: response(ref('AccountPasskeyOptions')), ...errors } } },
            '/internal/identity/passkey/options': { get: { operationId: 'internalSwitchOptions', security, responses: { 200: response(ref('AccountPasskeyOptions')), ...errors } } },
            '/internal/auth/passkey': { post: { ...authenticate('authenticateInternalPasskey', 'AuthPasskeyCommand'), responses: { 200: response(ref('InternalAuthTransitionResponse')), ...failures } } },
            '/internal/identity/passkey': { post: { ...authenticate('switchInternalPasskey', 'AuthPasskeyCommand'), responses: { 200: response(ref('InternalAuthTransitionResponse')), ...failures } } },
            '/internal/locale': { post: { operationId: 'setInternalLocale', security, requestBody: { required: true, ...json(object({ locale: { enum: ['ar', 'en'] } })) }, responses: { 200: response(object({ locale: { enum: ['ar', 'en'] } })), ...errors } } },
        },
        schemas: {
            InternalAuthTransitionResponse: object({ transition: object({ lineage: { ...text, format: 'uuid' }, boundary: { const: 'internal' }, epoch: { type: 'integer', minimum: 1 }, nonce: text, kind: { const: 'login' }, status: { const: 'active' } }), identity: ref('AuthIdentityEvidence'), redirect: text }),
            AuthEntryVisibility: { oneOf: [object({ protected: { const: false }, path: text, context: { type: 'null' } }), object({ protected: { const: true }, path: text, context: text })] },
            AuthEntryWitness: object({ visibility: ref('AuthEntryVisibility') }),
            AuthEntryResource: object({ data: ref('AuthEntryData'), locale: { enum: ['ar', 'en'] }, visibility: ref('AuthEntryVisibility') }),
            AuthPasswordCommand: object({ email: { ...text, format: 'email' }, password: text, remember: { type: 'boolean' } }, ['email', 'password']),
            AuthRegistrationCommand: object({ name: { ...text, maxLength: 255 }, email: { ...text, format: 'email', maxLength: 255 }, password: text, password_confirmation: text }),
            AuthPasskeyCommand: object({ credential: object({ id: text, rawId: text, type: { const: 'public-key' }, response: object({}, [], true) }, undefined, true) }),
            AuthIdentityBinding: object({ lineage: { ...text, format: 'uuid' }, epoch: { type: 'integer', minimum: 0 }, boundary: { enum: ['advertiser', 'internal'] }, credential: text, principal: { type: 'integer', minimum: 0 }, lifecycle: { type: 'integer', minimum: 0 }, context: text, generation: text }),
            AuthIdentityEvidence: object({ receipt: text, binding: ref('AuthIdentityBinding'), csrf: text, transition: text, leaseMilliseconds: { type: 'number', exclusiveMinimum: 0 }, renewalProtocol: { enum: [0, 1] } }),
            AuthTransitionResponse: object({ transition: object({ lineage: { ...text, format: 'uuid' }, boundary: { const: 'advertiser' }, epoch: { type: 'integer', minimum: 1 }, nonce: text, kind: { const: 'login' }, status: { const: 'active' } }), identity: ref('AuthIdentityEvidence'), redirect: text }),
            AuthEntryData: object({ kind: { enum: ['login', 'register', 'forgot-password', 'reset-password', 'verify-email', 'confirm-password'] }, canResetPassword: { type: 'boolean' }, passwordPolicy: nullable(object({ min: { type: 'integer' }, max: nullable({ type: 'integer' }), browserRules: text })), status: nullable(text), errors: { anyOf: [{ type: 'object', additionalProperties: list(text) }, { type: 'array', maxItems: 0 }] }, old: object({ name: nullable(text), email: nullable(text) }) }),
        },
    };
}
