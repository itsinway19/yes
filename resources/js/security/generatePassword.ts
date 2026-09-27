/** Rejection sampling avoids modulo bias; generated secrets never leave the caller. */
export function generatePassword(policy: { min: number; max: number }): string {
    if (policy.min > 24 || policy.max < 24) {
        throw new Error('Password policy does not permit the generator length.');
    }
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()-_=+';
    const limit = 256 - (256 % alphabet.length);
    const bytes = new Uint8Array(32);
    let password = '';
    while (password.length < 24) {
        crypto.getRandomValues(bytes);
        for (const byte of bytes) {
            if (byte < limit) password += alphabet[byte % alphabet.length];
            if (password.length === 24) break;
        }
    }
    bytes.fill(0);
    return password;
}
