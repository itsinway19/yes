import { onClose } from './transport';
import { QueryClient } from '@tanstack/vue-query';

export const query = new QueryClient({
    defaultOptions: { queries: { retry: false, staleTime: 0, gcTime: 300000 } },
});

onClose(() => query.clear());
