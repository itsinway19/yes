const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
/** Extract identity only. This never resolves or fetches a supplied URL. */
export function businessReference(value: string, origin: string): string | undefined {
    const input = value.trim();
    if (uuid.test(input)) return input;
    try {
        const url = new URL(input, origin);
        if (url.origin !== new URL(origin).origin || url.username || url.password) return undefined;
        const match = /^\/businesses\/([0-9a-f-]+)\/?$/i.exec(url.pathname);
        return match && uuid.test(match[1]) ? match[1] : undefined;
    } catch { return undefined; }
}
