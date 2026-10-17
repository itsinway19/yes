import { api } from '../spa/api';
import { sourceOperations } from './client';
import { providerOperations } from './providers';

export const sources = sourceOperations(api);
export const providers = providerOperations(api);
