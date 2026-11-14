import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, access } from 'node:fs/promises';
import { spec } from '../scripts/application-openapi.mjs';
import Ajv from 'ajv/dist/2020.js';
test('retired model has no routes or placeholder contracts',async()=>{
 assert.doesNotMatch(JSON.stringify(spec),/workspace_public_id|realm_workspace|workspace_id|\/workspaces/);
 for(const file of ['resources/js/workspaces/routes.ts','resources/js/sources/SourceView.vue','resources/js/object-purpose/PurposeView.vue'])await assert.rejects(access(file),{code:'ENOENT'});
 const shell=await readFile('resources/js/spa/main.ts','utf8');assert.doesNotMatch(shell,/workspaceRoutes|params\.workspace|\/workspaces/);
});
test('only direct Business selectors are admitted; exact grant paths remain required',()=>{
 const valid=new Ajv({strict:false,validateFormats:false}).compile({...spec,$ref:'#/components/schemas/BusinessAuthoritySelection'});
 const selection={realm_kind:'direct',requirements:[{requirement_kind:'business',leaf_grant_id:7}]};
 assert.equal(valid(selection),true);
 for(const invalid of [{...selection,realm_workspace_public_id:null},{...selection,realm_kind:'agency'},{...selection,realm_kind:'internal_team'},{...selection,requirements:[]}])assert.equal(valid(invalid),false);
});
test('commercial registration uses Business beneficiary while uncertainty stays explicit',()=>{
 const schema=spec.components.schemas.CommercialRegistrationCommand;
 assert.ok(schema.properties.business_public_id);assert.equal(schema.properties.workspace_public_id,undefined);
 assert.equal(spec.components.schemas.CommercialContextResult.properties.agreement_state.const,'UNKNOWN');
 assert.equal(spec.components.schemas.CommercialContextResult.properties.amount_state.const,'UNKNOWN');
});

test('administration command admits declared drafts and active Business suspension without archival revival',()=>{
 const valid=new Ajv({strict:false,validateFormats:false}).compile({...spec,$ref:'#/components/schemas/InternalCustomerStatusCommand'});
 const command={command_id:'command',kind:'businesses',target:'business',decision:'suspend',reason:'Owner administration',expected_status:'declared_draft',expected_revision:2};
 assert.equal(valid(command),true);assert.equal(valid({...command,expected_status:'active'}),true);
 assert.equal(valid({...command,expected_status:'archived'}),false);assert.equal(valid({...command,kind:'workspaces'}),false);
});
