export type AuthErrors = { fields: Record<string, string>, general: string };

export function authErrors(
    input: { errors?: Record<string, string[]>, message?: string },
    visibleFields: readonly string[],
    fallback = '',
): AuthErrors {
    const fields: Record<string, string> = {};
    const messages = new Set<string>();
    for (const field of visibleFields) {
        const message = input.errors?.[field]?.find(value => value.trim() !== '');
        if (message && !messages.has(message)) {
            fields[field] = message;
            messages.add(message);
        }
    }
    // API validation summaries commonly repeat the first field error.
    const general = messages.size ? '' : input.message || Object.values(input.errors ?? {}).flat().find(value => value.trim() !== '') || fallback;
    return { fields, general };
}
