import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
const url=code=>'data:text/javascript;base64,'+Buffer.from(code).toString('base64');
const source=await readFile('resources/js/inquiry-evidence/editor.ts','utf8');
const editorCode=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText;
const editor=await import(url(editorCode.replace("from 'vue'",`from ${JSON.stringify(import.meta.resolve('vue'))}`)));
test('unknown remains null while declared zero remains zero; invalid counts fail',()=>{
 assert.equal(editor.countValue(''),null);assert.equal(editor.countValue('0'),0);assert.equal(editor.countValue('1000000000000'),1e12);
 for(const value of ['-1','1.2','1e3','1000000000001','01','phone'])assert.throws(()=>editor.countValue(value));
});
test('uncertain command is deeply immutable and kept with its original identity',()=>{
 const original={kind:'record',body:{command_id:'original-command',value:{inquiries:{count:10}}}};
 const frozen=editor.freezeCommand(original);original.body.value.inquiries.count=99;
 assert.equal(frozen.body.value.inquiries.count,10);assert.throws(()=>{frozen.body.value.inquiries.count=22;});assert.equal(frozen.body.command_id,'original-command');
 editor.pendingCommands.set('actor-generation:business',frozen);assert.equal(editor.pendingCommands.get('actor-generation:business'),frozen);assert.equal(editor.pendingCommands.get('other-business'),undefined);editor.pendingCommands.clear();
});
test('a newer read never adopts another editor baseline or overwrites old content',()=>{
 const item={declaration_id:'a',revision:1,value:{inquiries:{count:10}}};
 const edit=editor.beginCorrection(item);item.value.inquiries.count=20;
 assert.equal(edit.value.inquiries.count,10);assert.equal(edit.revision,1);assert.equal(editor.staleEdit(edit,[{declaration_id:'a',revision:2}]),true);assert.equal(editor.staleEdit(edit,[{declaration_id:'a',revision:1}]),false);
 assert.throws(()=>editor.beginCorrection({declaration_id:'x',revision:2,value:null}));
});
test('only definite noncommitting rejection is editable; generic conflict and transport loss stay unknown',async()=>{
 const transport=url('export class TransportError extends Error {constructor(status,body={}){super();this.status=status;this.body=body;}}');
 let code=ts.transpileModule(await readFile('resources/js/inquiry-evidence/api.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText;
 code=code.replace("'../spa/api'",JSON.stringify(url('export const api={};'))).replace("'../spa/transport'",JSON.stringify(transport));
 const api=await import(url(code));const {TransportError}=await import(transport);
 assert.equal(api.confirmedRejection(new api.RejectedCommand('invalid')),'invalid');assert.equal(api.confirmedRejection(new TransportError(422)),'invalid');assert.equal(api.confirmedRejection(new TransportError(403)),'denied');assert.equal(api.confirmedRejection(new TransportError(409,{error:'inquiry_revision_rejected'})),'stale');assert.equal(api.confirmedRejection(new TransportError(409,{error:'inquiry_conflict'})),undefined);assert.equal(api.confirmedRejection(Error('lost ACK')),undefined);
});
test('Arabic source and semantic readback retain the required human meanings',async()=>{
 const panel=await readFile('resources/js/inquiry-evidence/InquiryEvidencePanel.vue','utf8'),details=await readFile('resources/js/inquiry-evidence/DeclarationDetails.vue','utf8');
 assert.ok(panel.includes('بيانات الاستفسارات'));assert.ok(panel.includes('وليست نتائج تحققت منها Builderad بصورة مستقلة'));assert.ok(details.includes('ليس بيعًا أو ربحًا'));assert.ok(details.includes('Pending qualification'));assert.ok(details.includes('Acceptance criterion'));assert.ok(details.includes('Criterion version'));assert.ok(details.includes('value.acceptance?.criterion_version'));assert.ok(details.includes('Known as of'));
});

test('unsent form snapshots remain document-private and identity closure clears them',async()=>{
 const transport=url('export const callbacks=[];export const onClose=callback=>callbacks.push(callback);');
 let code=ts.transpileModule(await readFile('resources/js/inquiry-evidence/presentationDrafts.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText.replace("'../spa/transport'",JSON.stringify(transport));
 const {inquiryFormDrafts}=await import(url(code)),{callbacks}=await import(transport);
 inquiryFormDrafts.set('document1:businessA:basis1',{fields:{inquiries:'73'},basis:'q1',basisRevision:1});
 assert.equal(inquiryFormDrafts.get('document1:businessB:basis1'),undefined);assert.equal(inquiryFormDrafts.get('document2:businessA:basis1'),undefined);
 callbacks.forEach(callback=>callback());assert.equal(inquiryFormDrafts.size,0);
});
test('workspace return context stores presentation only and closes with the transport document',async()=>{
 const transport=url('export const callbacks=[];export const onClose=callback=>callbacks.push(callback);export const stamp=()=>"document-generation";');
 let code=ts.transpileModule(await readFile('resources/js/business-foundation/workspacePresentation.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText.replace("'../spa/transport'",JSON.stringify(transport));
 const {workspacePresentation,workspacePresentationKey}=await import(url(code)),{callbacks}=await import(transport);
 const a=workspacePresentationKey('businessA:grant1'),b=workspacePresentationKey('businessB:grant1');assert.notEqual(a,b);
 workspacePresentation.set(a,{section:'inquiries',positions:{inquiries:240}});assert.equal(workspacePresentation.has(b),false);
 callbacks.forEach(callback=>callback());assert.equal(workspacePresentation.size,0);
});
