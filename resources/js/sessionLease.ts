/** Count a transport round trip against availability; it never creates authority. */
export function remainingSessionLease(milliseconds: number, startedAt: number, now = Date.now()): number {
    const remaining = milliseconds - Math.max(0, now - startedAt);
    if (!Number.isFinite(remaining) || remaining <= 0) throw new Error('Session transport lease elapsed.');
    return remaining;
}
