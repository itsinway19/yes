import test from 'node:test';
import assert from 'node:assert/strict';
import { nativeSourceReference, ownerKey, providerRoute, commandOwner } from '../resources/js/sources/nativeOwner.ts';
test('native OAuth/provider routing preserves an exact Business owner without retired URL custody',()=>{
 const owner=nativeSourceReference({businessId:'business-a'});
 assert.deepEqual(providerRoute(owner),{root:'/businesses/{business}',path:{business:'business-a'}});
 assert.deepEqual(commandOwner(owner),{business:'business-a'});
 assert.notEqual(ownerKey(owner),ownerKey({kind:'business',publicId:'business-b'}));
});
test('retired string references and mixed custody never become Business authority',()=>{
 for(const value of ['workspace-a',null,{kind:'workspace',publicId:'business-a'}])assert.throws(()=>providerRoute(value));
 assert.throws(()=>nativeSourceReference({workspaceId:'old'}));
 assert.throws(()=>nativeSourceReference({workspaceId:'old',businessId:'business-a'}));
});
