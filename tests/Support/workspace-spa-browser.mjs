import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import Ajv2020 from 'ajv/dist/2020.js';

// Keep the official historical selection name. Its operational journeys now
// exercise Business and verify retired routes reject requests.
export async function runWorkspaceCases({ browser, check, login, meta, result, fixture, command, get, post }) {
    const origin='http://localhost:8138';
    const spec=JSON.parse(await readFile('contracts/application.openapi.json','utf8'));
    const ajv=new Ajv2020({strict:false,validateFormats:false});ajv.addSchema({$id:'application',components:spec.components});
    const validate=(name,body)=>{const check=ajv.compile({$ref:`application#/components/schemas/${name}`});assert.ok(check(body),JSON.stringify(check.errors));};
    const context=await browser.newContext({viewport:{width:1280,height:900}}),page=await context.newPage(),errors=[];
    page.setDefaultTimeout(20000);page.on('dialog',dialog=>dialog.accept());page.on('pageerror',error=>errors.push(error.message));
    context.on('response',response=>{if(response.url().includes('/build/')&&response.status()===200)result.assets.push({url:response.url(),status:200});});
    const ready=async(selector='[data-business-surface]')=>page.waitForFunction(selector=>document.querySelector('[data-application-spa]')?.dataset.state==='ready'&&!!document.querySelector(selector),selector);
    const push=async path=>{await page.evaluate(path=>document.getElementById('application-spa').__vue_app__.config.globalProperties.$router.push(path),path);await ready();};
    const list=async()=>{const response=await get(page,'/api/businesses');assert.equal(response.status,200);validate('BusinessChoicesResult',response.body);return response.body.data.items;};
    const raw=async(path,method,body,extra={})=>{const identity=await meta(page);const response=await context.request.fetch(origin+path,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':identity.csrf,'X-Builderad-Document':identity.receipt,'X-Builderad-Transition':identity.transition,...extra},data:body,maxRedirects:0});return{status:response.status(),body:await response.json()};};
    const language=async wanted=>{const menu=page.locator('.advertiser-account-menu');if(await menu.getAttribute('open')===null)await menu.locator(':scope > summary').click();await page.locator('[data-application="locale"]').click();await page.waitForFunction(wanted=>document.documentElement.lang===wanted,wanted);await ready();if(await menu.getAttribute('open')!==null)await menu.locator(':scope > summary').click();};
    let apiCreated,created;
    try{
        await check('Retired Workspace routes are absent and Business remains accessible','spa',async()=>{
            await login(page,'application');
            for (const retired of ['/workspaces','/organizations/new',`/workspaces/${fixture.b.businesses[0].business_public_id}`]) {
                assert.equal((await context.request.get(origin+retired,{maxRedirects:0})).status(),404);
            }
            for (const retired of ['/organizations','/workspaces']) {
                assert.equal((await post(page,retired,{name:'Retired parallel creation'})).status,404);
            }
            assert.ok((await list()).some(row=>row.business_public_id===fixture.a.businesses[0].business_public_id));
            return {retiredRoutes:404,businessAccessible:true};
        });
        await check('Business HTTP contracts preserve 200 201 403 409 419 422 and exact atomic creation replay','spa',async()=>{
            const before=await list(),body={command_id:crypto.randomUUID(),name:'Business API creation'};
            assert.equal((await post(page,'/api/businesses',{...body,name:'x'})).status,422);
            assert.equal((await raw('/api/businesses','POST',body,{'X-CSRF-TOKEN':'invalid'})).status,419);
            assert.equal((await raw('/api/businesses','POST',body,{'X-Builderad-Document':'invalid'})).status,409);
            const foreign=fixture.b.businesses[0];assert.equal((await get(page,`/api/businesses/${foreign.business_public_id}/surface?access=${foreign.grant_refs['business.context.read']}`)).status,403);
            assert.equal((await list()).length,before.length);
            const first=await post(page,'/api/businesses',body);assert.equal(first.status,201);validate('BusinessDraftResult',first.body);apiCreated=first.body.data;
            const replay=await post(page,'/api/businesses',body);assert.equal(replay.status,201);assert.deepEqual(replay.body,first.body);
            const after=await list();assert.equal(after.length,before.length+1);assert.equal(after.filter(row=>row.business_public_id===apiCreated.business_public_id).length,1);
            return{statuses:[200,201,403,409,419,422],createdBusinesses:1,exactAtomicReplay:true};
        });
        await check('Business creation draft survives locale and SPA traversal with narrow RTL and LTR layouts','spa',async()=>{
            await push('/businesses/new');await page.locator('#bf-create-type input[value]').first().check();
            for(const step of ['sector','practices','market','goal'])await page.locator(`[data-wizard-step="${step}"] [data-wizard-next]`).click();
            await page.locator('#bf-create-name').fill('Retained Business creation draft');
            await language('en');assert.equal(await page.locator('#bf-create-name').inputValue(),'Retained Business creation draft');
            await push('/businesses');await page.locator('[data-business-list]').waitFor();await push('/businesses/new');
            assert.equal(await page.locator('#bf-create-name').inputValue(),'Retained Business creation draft');
            await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
            assert.equal(await page.locator('html').getAttribute('dir'),'ltr');await language('ar');assert.equal(await page.locator('html').getAttribute('dir'),'rtl');assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
            return{retainedDraft:true,width:390,locales:['ar','en'],horizontalOverflow:false,screenshots:false};
        });
        await check('Business creation lost ACK and later denial retain exact original command without duplicate creation','spa',async()=>{
            const before=await list(),payloads=[];let receipt;
            await page.route('**/api/businesses',async route=>{
                if(route.request().method()!=='POST')return route.continue();payloads.push(route.request().postDataJSON());
                if(payloads.length===1){const committed=await route.fetch();assert.equal(committed.status(),201);receipt=(await committed.json()).data;return route.abort('failed');}
                if(payloads.length===2)return route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({error:'simulated_later_reconciliation_denial'})});
                return route.continue();
            });
            try{
                await page.locator('[data-wizard-step="review"] [data-wizard-next]').click();await page.locator('[data-business-unknown]').waitFor();assert.equal(payloads.length,1);
                assert.equal((await list()).filter(row=>row.business_public_id===receipt.business_public_id).length,1);
                const denied=page.waitForResponse(response=>new URL(response.url()).pathname==='/api/businesses'&&response.request().method()==='POST');await page.locator('[data-setup-reconcile]').click();assert.equal((await denied).status(),403);
                await page.locator('[data-business-unknown]').waitFor();assert.equal(await page.locator('#bf-create-name').count(),0,'An uncertain command cannot be replaced by new editable creation');assert.equal(payloads.length,2);assert.deepEqual(payloads[1],payloads[0]);
                await page.locator('[data-setup-reconcile]').click();await ready('[data-business-public]');created=receipt;
                assert.equal(payloads.length,3);assert.deepEqual(payloads[2],payloads[0]);assert.equal((await list()).length,before.length+1);assert.equal(await page.locator('[data-business-public]').getAttribute('data-business-public'),receipt.business_public_id);
                return{committedLostAck:true,laterDenial:'simulated403; no earlier-outcome inference',exactOriginalPayload:true,requests:3,businessesCreated:1,automaticRetry:0};
            }finally{await page.unroute('**/api/businesses');}
        });
        await check('Business edit uses qualified current context and stale CAS never overwrites accepted changes','spa',async()=>{
            await page.setViewportSize({width:1280,height:900});await push(`/businesses/${created.business_public_id}?access=${created.grant_refs['business.context.read']}&section=settings&settings=details`);
            await page.locator('[data-business-task="business-profile"]').click();await page.locator('#bf-name').fill('Business edited in active UI');
            const saved=page.waitForResponse(response=>new URL(response.url()).pathname===`/api/businesses/${created.business_public_id}/context`&&response.request().method()==='PUT');await page.locator('form').filter({has:page.locator('#bf-name')}).locator('button.bf-primary').click();
            assert.equal((await saved).status(),200);await page.locator('[data-business-saved]').waitFor();
            const surface=await get(page,`/api/businesses/${created.business_public_id}/surface?access=${created.grant_refs['business.context.read']}`);assert.equal(surface.status,200);validate('BusinessSurfaceResult',surface.body);assert.equal(surface.body.data.business.name,'Business edited in active UI');
            const stale={command_id:crypto.randomUUID(),name:'Stale replacement',business_type_id:surface.body.data.business.business_type_id,expected_context:surface.body.data.business.versions.context-1,authority_selection:{realm_kind:'direct',requirements:[{requirement_kind:'business',leaf_grant_id:Number(created.grant_refs['business.context.manage'])}]}};
            assert.equal((await raw(`/api/businesses/${created.business_public_id}/context`,'PUT',stale)).status,409);assert.equal((await get(page,`/api/businesses/${created.business_public_id}/surface?access=${created.grant_refs['business.context.read']}`)).body.data.business.name,'Business edited in active UI');
            return{realUIEdit:true,staleCAS:409,acceptedNamePreserved:true};
        });
        await check('revoked session cannot restore Business choices or consume historical JSON with its old receipt','spa',async()=>{
            await push('/businesses');const identity=await meta(page);command('revoke');await page.reload();await page.waitForURL(origin+'/login');
            for(const path of ['/api/businesses']){
                const response=await context.request.get(origin+path,{headers:{Accept:'application/json','X-Builderad-Document':identity.receipt},maxRedirects:0});assert.ok([401,409].includes(response.status()));
            }
            assert.equal(await page.locator('[data-business-list]').count(),0);assert.equal(await page.locator('[data-workspaces="index"]').count(),0);
            return{oldReceiptDenied:true,nativeRecovery:'/login',protectedListsAbsent:true};
        });
        assert.deepEqual(errors,[]);
    }finally{await context.close();}
}
