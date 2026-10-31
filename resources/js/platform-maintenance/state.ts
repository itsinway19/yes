import { shallowRef } from 'vue';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';
export type Command = components['schemas']['PlatformMaintenanceCommand'];
export const platform = shallowRef<components['schemas']['PlatformMaintenanceData']>();
let serial=0, cancellation: AbortController|undefined;
export function clearPlatform(){serial++;cancellation?.abort();platform.value=undefined;}
onClose(clearPlatform);
export async function readPlatform(passive=false,readback=false,admission?:PresentationAdmission){
 const attempt=++serial,captured=stamp();cancellation?.abort();cancellation=new AbortController();
 const signal=admission?AbortSignal.any([admission.signal,cancellation.signal]):cancellation.signal;
 try{
  const result=await api.GET('/internal/administration/maintenance',{headers:passive?{'X-Builderad-Passive':'1'}:{},signal});
  if(!owned(captured)||attempt!==serial||!result.data||!('data' in result.data))return;
  const witness=await api.GET('/internal/administration/maintenance',{headers:{'X-Builderad-Witness':'1'},signal});
  if(!owned(captured)||attempt!==serial||signal.aborted)return;
  if(!result.data.visibility.protected||result.data.visibility.path!=='/internal/administration/maintenance'||JSON.stringify(result.data.visibility)!==JSON.stringify(witness.data?.visibility)){if(admission)throw Error('Witness changed');close();return;}
  const value=result.data.data;publish(admission,()=>{platform.value=value;status.value='ready';});return value;
 }catch(failure){if(admission)throw failure;if(!owned(captured)||attempt!==serial)return;if(failure instanceof TransportError&&[401,403,419].includes(failure.status))close();else if(!readback)status.value='unavailable';}
}
export async function sendPlatform(body:Command,signal:AbortSignal){const result=await api.POST('/internal/administration/maintenance',{body,signal});if(!result.data?.saved||result.data.command_id!==body.command_id)throw Error('Unknown acknowledgement');return result.data;}
