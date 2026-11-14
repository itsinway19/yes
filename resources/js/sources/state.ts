import { shallowRef } from 'vue';
import { sources } from './api';
import { onClose, owned, stamp } from '../spa/transport';
export const current = shallowRef<Awaited<ReturnType<typeof sources.read>>>();
let attempt = 0;
export async function read(business: string, passive = false) {
    const serial = ++attempt, identity = stamp();
    const result = await sources.read(business, passive ? { 'X-Builderad-Passive': '1' } : {});
    if (!owned(identity) || serial !== attempt) return;
    const witness = await sources.read(business, { 'X-Builderad-Witness': '1' });
    if (!owned(identity) || serial !== attempt) return;
    if (!('data' in result) || result.data.business.public_id !== business || !result.visibility.protected || result.visibility.path !== `/businesses/${business}/source-registry` || JSON.stringify(result.visibility) !== JSON.stringify(witness.visibility)) throw Error('Source registry witness changed');
    current.value = result;
    return result.data;
}
onClose(() => { attempt++; current.value = undefined; });
