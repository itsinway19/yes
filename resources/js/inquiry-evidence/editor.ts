import { shallowReactive } from 'vue';
import type { DeclarationValue, Pending } from './api';
export const clone = <T>(value:T):T => JSON.parse(JSON.stringify(value));
export function freezeCommand<T extends Pending>(command:T):T {
 const value=clone(command);
 const freeze=(o:unknown):void=>{if(o && typeof o==='object'){Object.values(o).forEach(freeze);Object.freeze(o);}};
 freeze(value);return value;
}
export type EditSnapshot={declaration:string;revision:number;value:DeclarationValue};
export function beginCorrection(item:{declaration_id:string;revision:number;value:DeclarationValue|null}):EditSnapshot {
 if(!item.value)throw Error('Withdrawn declaration cannot be corrected');
 return {declaration:item.declaration_id,revision:item.revision,value:clone(item.value)};
}
export function staleEdit(edit:EditSnapshot|undefined,current:{declaration_id:string;revision:number}[]):boolean {
 if(!edit)return false;const latest=current.find(x=>x.declaration_id===edit.declaration);return !!latest && latest.revision!==edit.revision;
}
export function countValue(raw:string):number|null {
 if(raw.trim()==='')return null;
 if(!/^(0|[1-9][0-9]{0,12})$/.test(raw)||Number(raw)>1000000000000)throw Error('Invalid count');return Number(raw);
}

// Memory only, scoped to this document/actor generation; never localStorage or cross-session export.
export const pendingCommands = shallowReactive(new Map<string,Pending>());
