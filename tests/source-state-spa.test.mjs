import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import ts from 'typescript';
const url=s=>'data:text/javascript;base64,'+Buffer.from(s).toString('base64');let count=0;
async function fixture(send){
 const name='__nativeRegistry'+(++count);const runtime={send,generation:1,closed:false,closures:[],calls:[]};globalThis[name]=runtime;
 const ports={vue:import.meta.resolve('vue'),'../spa/transport':url(`const r=globalThis.${name};export const stamp=()=>r.generation,owned=v=>!r.closed&&v===r.generation,onClose=f=>r.closures.push(f);`),'./api':url(`const r=globalThis.${name};export const sources={read:(...args)=>{r.calls.push(args);return r.send(...args);}};`)};
 let source=ts.transpileModule(await readFile('resources/js/sources/state.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.ESNext}}).outputText;
 for(const [from,to]of Object.entries(ports))source=source.replaceAll(`from '${from}'`,`from ${JSON.stringify(to)}`);
 return {state:await import(url(source)),runtime,close(){runtime.closed=true;runtime.closures.forEach(f=>f());}};
}
const visibility={protected:true,path:'/businesses/business/source-registry',context:'current-qualified-grants'};
const resource=(id='business')=>({data:{business:{public_id:id,name:'Private Business'},sources:{items:[]}},visibility});
test('Business registry publishes only its exact subject with matching route witness',async()=>{
 const {state,runtime}=await fixture(async(_id,headers)=>headers['X-Builderad-Witness']?{visibility}:resource());
 assert.deepEqual(await state.read('business',true),resource().data);assert.deepEqual(state.current.value,resource());assert.deepEqual(runtime.calls.map(x=>x[1]),[{'X-Builderad-Passive':'1'},{'X-Builderad-Witness':'1'}]);
});
for(const scenario of ['wrong subject','wrong path','unprotected','witness mismatch','witness denied'])test(`registry ${scenario} cannot publish private data`,async()=>{
 const f=await fixture(async(_id,headers)=>{
  if(headers['X-Builderad-Witness']){if(scenario==='witness denied')throw Error('denied');return {visibility:scenario==='witness mismatch'?{...visibility,context:'withdrawn'}:visibility};}
  if(scenario==='wrong subject')return resource('sibling');if(scenario==='wrong path')return {...resource(),visibility:{...visibility,path:'/businesses/sibling/source-registry'}};
  if(scenario==='unprotected')return {...resource(),visibility:{...visibility,protected:false}};return resource();
 });await assert.rejects(f.state.read('business'));assert.equal(f.state.current.value,undefined);
});
test('closure and changed identity suppress pending witness publication',async()=>{
 for(const close of [false,true]){let finish;const f=await fixture(async(_id,headers)=>headers['X-Builderad-Witness']?new Promise(r=>finish=r):resource());const pending=f.state.read('business');await new Promise(r=>setTimeout(r,0));if(close)f.close();else f.runtime.generation++;finish({visibility});await pending;assert.equal(f.state.current.value,undefined);}
});
test('a superseded registry read cannot replace the newer subject',async()=>{
 let finish;const f=await fixture(async(id,headers)=>id==='business'?new Promise(r=>finish=r):headers['X-Builderad-Witness']?{visibility:{...visibility,path:'/businesses/sibling/source-registry'}}:{...resource('sibling'),visibility:{...visibility,path:'/businesses/sibling/source-registry'}});
 const old=f.state.read('business');await f.state.read('sibling');finish(resource());await old;assert.equal(f.state.current.value.data.business.public_id,'sibling');
});
