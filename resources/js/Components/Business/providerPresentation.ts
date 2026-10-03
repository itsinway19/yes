import { watch, type Ref } from 'vue';

export type ProviderSummary = { state: string; availability: string | null; hasConnections: boolean; canConnect: boolean };
type Snapshot = { availability: string; connect_intent: string | null; connections: { access: { connection: string; effective?: { state: string } } }[] };

// Observe the already-mounted operational panel, never fetch or infer capability from local configuration.
export function useProviderPresentation(snapshot: Ref<Snapshot | null>, busy: Ref<boolean | string>, error: Ref<string>, emit: (event: 'summary', summary: ProviderSummary) => void) {
    watch([snapshot, busy, error], () => {
        const data = snapshot.value;
        let state = 'unknown';
        if (!data && busy.value) state = 'loading';
        else if (data && !error.value) {
            const connections = data.connections;
            if (!connections.length) state = data.availability === 'CONNECT_AVAILABLE' ? 'unconnected' : data.availability === 'CONFIGURATION_REQUIRED' ? 'incomplete' : data.availability === 'FRESH_AUTHENTICATION_REQUIRED' ? 'authenticate' : data.availability === 'OWNER_REQUIRED' ? 'restricted' : 'unknown';
            else if (connections.every(row => row.access.connection === 'LOCALLY_DISCONNECTED')) state = 'disconnected';
            else if (connections.some(row => row.access.effective?.state === 'REVOKED')) state = 'revoked';
            else if (connections.some(row => row.access.effective?.state === 'UNSUPPORTED')) state = 'unsupported';
            else if (connections.some(row => row.access.effective?.state === 'FAILED')) state = 'failed';
            else if (connections.some(row => row.access.effective?.state === 'BLOCKED_BY_COMMON_FOUNDATION')) state = 'blocked';
            else if (connections.some(row => ['REAUTHORIZATION_REQUIRED', 'RECONNECT_REQUIRED', 'TOKEN_REFRESH_REQUIRED', 'VERIFICATION_NEEDED'].includes(row.access.connection))) state = 'attention';
            else if (connections.some(row => ['STALE', 'PARTIAL', 'VERIFICATION_NEEDED'].includes(row.access.effective?.state ?? ''))) state = 'attention';
            else if (connections.every(row => row.access.connection === 'CONNECTED')) state = connections.every(row => row.access.effective?.state === 'READY') ? 'ready' : 'connected';
            else if (connections.every(row => row.access.connection === 'NOT_CONNECTED')) state = 'unconnected';
        }
        emit('summary', { state, availability: data?.availability ?? null, hasConnections: Boolean(data?.connections.length), canConnect: !error.value && !busy.value && data?.availability === 'CONNECT_AVAILABLE' && Boolean(data.connect_intent) });
    }, { immediate: true, deep: true });
}
