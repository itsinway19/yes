import assert from 'node:assert/strict';

export async function runRestorationAcceptance({browser,check,login,get,post,target,fixture,foreign,command,wire,before,until,result}) {
 const origin='http://localhost:8138';
 const hidden=page=>page.locator('[data-spa-private]').evaluateAll(elements=>elements.every(element=>!element.getClientRects().length||getComputedStyle(element).visibility==='hidden'));
 const context=await browser.newContext();
 context.on('response',response=>{if(response.url().includes('/build/')&&response.status()===200)result.assets.push({url:response.url(),status:response.status()});});
 const page=await context.newPage();page.on('dialog',dialog=>dialog.accept());
 try {
  await login(page,'spa');
  await check('production artifact and script-free shell expose no Goals projection','spa',async()=>{
   assert.equal(await page.evaluate(()=>typeof window.__goalsSpa),'undefined');
   const workspaceName=await page.locator('.goals-business strong').innerText();assert.ok(workspaceName);
   const shellContext=await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState()});
   try {
    const shell=await shellContext.newPage();
    const response=await shell.goto(origin+target('spa'));
    assert.equal(response.status(),200);assert.match(response.headers()['cache-control'],/no-store/);
    assert.equal(await shell.locator('#goals-spa').innerHTML(),'');
    const html=await response.text();
    assert.ok(!html.includes(workspaceName));
    const bootstrap=JSON.parse(await shell.locator('#goals-bootstrap').textContent());
    assert.deepEqual(Object.keys(bootstrap).sort(),['identity','locale']);
    assert.equal(await shell.locator('[data-spa-private], [data-goals="form"]').count(),0);
    return {status:response.status(),cacheControl:response.headers()['cache-control'],scope:'Fresh HTML only; identity bootstrap remains present; not cached compositor frames'};
   } finally {await shellContext.close();}
  });
  await check('reload keeps private presentation closed until authoritative witness completes','spa',async()=>{
   let intercepted=false;
   await page.route('**/spa/api/workspaces/*/goals',async route=>{
    const request=route.request();
    if(!intercepted&&request.headers()['x-goals-witness']==='1'){
     intercepted=true;await route.continue({headers:{...request.headers(),'X-Proof-Before':'restoration-witness'}});
    }else await route.continue();
   });
   try {
    await page.reload({waitUntil:'domcontentloaded'});await until(()=>before.has('restoration-witness'));
    assert.equal(await hidden(page),true);
    before.get('restoration-witness')();before.delete('restoration-witness');
    await page.waitForSelector('#goals-spa[data-state="ready"] [data-goals="form"]');
    return {scope:'Real reload, real HTTP witness held before server admission; no proof globals'};
   } finally {await page.unroute('**/spa/api/workspaces/*/goals');}
  });
 } finally {await context.close();}
 for(const transition of ['expire','revoke','switch','deny-view']) {
  const active=await browser.newContext();const document=await active.newPage();document.on('dialog',dialog=>dialog.accept());
  try {
   await login(document,'spa');
   await check('script-free fresh document rejects old workspace after '+transition,'spa',async()=>{
    if(transition==='switch')assert.equal((await post(document,'/identity/switch',{email:'goals-b@example.test',password:'DisposableGoalsProof42!'})).status,200);
    else command(transition);
    const fresh=await browser.newContext({javaScriptEnabled:false,storageState:await active.storageState()});
    try {
     const destination=await fresh.newPage();const mark=wire.length;
     const response=await destination.goto(origin+target('spa'));
     const request=wire.slice(mark).find(row=>row.path===target('spa')&&row.method==='GET');
     assert.ok(request);assert.ok([302,401,403,409].includes(request.status),JSON.stringify(request));
     assert.equal(await destination.locator('[data-spa-private], [data-goals="form"]').count(),0);
     return {requestStatus:request.status,finalStatus:response.status(),scope:'Forced fresh HTTP document with JavaScript disabled; does not establish native BFCache'};
    } finally {await fresh.close();}
   });
  } finally {if(transition==='deny-view')command('restore-view');await active.close();}
 }
 const isolated=await browser.newContext();const outsider=await isolated.newPage();outsider.on('dialog',dialog=>dialog.accept());
 try {
  await login(outsider,'spa');
  await check('production foreign workspace shell and projection both deny access','spa',async()=>{
   const read=await get(outsider,`/spa/api/workspaces/${foreign}/goals`);assert.equal(read.status,403);
   const response=await outsider.goto(origin+target('spa',foreign));assert.equal(response.status(),403);
   assert.equal(await outsider.locator('[data-spa-private]').count(),0);
   return {shell:response.status(),projection:read.status,scope:'Foreign shell and projection with current cookie and valid document receipt'};
  });
 } finally {await isolated.close();}
}
