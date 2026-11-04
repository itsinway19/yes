import assert from 'node:assert/strict';

/** Additional real HTTP/DB journeys; no authentication or application replacement. */
export async function runClosureCases(h){
 const {browser,check,login,get,post,meta,ready,target,command,until,held,wire,result,fixture,workspace,other,foreign,hash}=h;
 const cookies=async context=>(await context.cookies()).map(({name,value,path,expires,httpOnly,secure,sameSite})=>({name,hash:hash(value),path,expires,httpOnly,secure,sameSite}));
 for(const mode of process.env.GOALS_SPA_ONLY==='retention'?[]:['application']){
  for(const scenario of ['renew-reverse','revoked-pending','logout-pending','multiple-tabs']){
   const context=await browser.newContext();const page=await context.newPage();page.on('dialog',d=>d.accept());
   try{
    await login(page,mode);
    await check('closure '+scenario+' with actual browser cookies',mode,async()=>{
     const identity=await meta(page),before=command('inspect'),cookieBefore=await cookies(context);
     if(scenario==='renew-reverse'){
      const first=post(page,'/identity/renew',undefined,{'X-Proof-Hold':'first-'+mode});
      const second=post(page,'/identity/renew',undefined,{'X-Proof-Hold':'second-'+mode});await until(()=>held.has('first-'+mode)&&held.has('second-'+mode));
      held.get('second-'+mode).deliver();held.delete('second-'+mode);const newer=await second;assert.equal(newer.status,200);
      const cookieAfterNewer=await cookies(context);
      held.get('first-'+mode).deliver();held.delete('first-'+mode);assert.equal((await first).status,200);
      const next=await get(page,'/identity/context');assert.equal(next.status,200);assert.equal(next.body.identity.binding.principal,fixture.a.user);
      const old=before.sessions.find(s=>s.session_key_hash===identity.binding.credential),after=command('inspect').sessions.find(s=>s.session_key_hash===identity.binding.credential);
      assert.equal(after.idle_expires_at,old.idle_expires_at);assert.equal(after.absolute_expires_at,old.absolute_expires_at);assert.equal(after.revoked_at,null);
      assert.deepEqual(newer.body.identity.binding,identity.binding);
      return {cookieBefore,cookieAfterNewer,cookieAfter:await cookies(context),serverBefore:old,serverAfter:after,nextContext:next.status,principal:next.body.identity.binding.principal,note:'Same credential and CSRF; encrypted cookie bytes can differ. Does not prove longer lease after an arbitrarily delayed older response.'};
     }
     if(scenario==='multiple-tabs'){
      const second=await context.newPage();second.on('dialog',d=>d.accept());await second.goto('http://localhost:8138'+target(mode,other));await ready(second,mode);
      assert.equal((await meta(second)).binding.principal,fixture.a.user);
      const switched=await post(second,'/identity/switch',{email:'goals-b@example.test',password:'DisposableGoalsProof42!'});assert.equal(switched.status,200);
      // Recovery may natively replace the old tab between two evaluations.
      // Capture its actual signed receipt, then use the same browser context's
      // real cookie jar for requests which must not adopt the new identity.
      const oldHeaders={Accept:'application/json','X-Builderad-Document':identity.receipt,'X-CSRF-TOKEN':switched.body.identity.csrf};
      const oldRead=await context.request.get(`http://localhost:8138/workspaces/${workspace}/commercial-history`,{headers:oldHeaders});
      assert.equal(oldRead.status(),409);assert.equal(command('inspect').effects,before.effects);
      await second.goto('http://localhost:8138'+target(mode,foreign));await ready(second,mode);
      assert.equal((await meta(second)).binding.principal,fixture.b.user);assert.equal((await get(second,`/workspaces/${workspace}/commercial-history`)).status,403);
      return {oldRead:oldRead.status(),newPrincipal:(await meta(second)).binding.principal,cookieBefore,cookieAfter:await cookies(context),server:command('inspect').transitions.filter(t=>t.lineage_id===identity.binding.lineage),note:'Two real pages sharing browser cookies. Captured old receipt tested using that actual cookie jar; native recovery can replace the old tab. Not real mobile suspension evidence.'};
     }
     const key=scenario+'-'+mode,renewing=post(page,'/identity/renew',undefined,{'X-Proof-Hold':key});await until(()=>held.has(key));
     assert.equal(held.get(key).row.status,200);
     if(scenario==='revoked-pending')command('revoke');else assert.equal((await post(page,'/logout')).status,200);
     held.get(key).deliver();held.delete(key);assert.equal((await renewing).status,200,'Reply was produced before revocation; transport delivery does not authorize the next request');
     const next=await get(page,'/identity/context');assert.ok([401,409].includes(next.status));
     const denied=await post(page,'/identity/renew');assert.equal(denied.status,409);
     const after=command('inspect');assert.equal(after.effects,before.effects);assert.ok(after.sessions.find(s=>s.session_key_hash===identity.binding.credential).revoked_at);
     return {cookieBefore,cookieAfter:await cookies(context),renewalProduced:200,nextContext:next.status,oldDocumentRenewal:denied.status,serverAfter:after.sessions.find(s=>s.session_key_hash===identity.binding.credential),transitionAfter:after.transitions.find(t=>t.lineage_id===identity.binding.lineage)};
    });
   }finally{await context.close();}
  }
 }
 await check('closure renewed sessions survive original short cookie lifetime without cookie republication','both',async()=>{
  const entries=[];
  try{
   for(const mode of ['application']){
    const context=await browser.newContext(),page=await context.newPage();page.on('dialog',d=>d.accept());await login(page,mode);
    const identity=await meta(page),state=command('inspect'),session=state.sessions.find(s=>s.session_key_hash===identity.binding.credential),jar=await cookies(context);
    const credential=jar.find(c=>c.httpOnly&&c.name.endsWith('-session'));assert.ok(credential);
    const publication=wire.filter(row=>row.method==='POST'&&row.path==='/login'&&row.status===200).at(-1),issued=publication.cookies.find(c=>c.name===credential.name);
    assert.equal(Date.parse(issued.expires),Date.parse(session.absolute_expires_at),'Declared expiry must equal the canonical absolute bound; cookie-jar time includes browser Date/Max-Age clock adjustment');
    entries.push({mode,context,page,identity,session,jar,publication,started:Date.now()});
   }
   const shortLifetime=Number(process.env.SESSION_LIFETIME)*60000;assert.equal(shortLifetime,120000,'This bounded proof uses the configured two-minute transport lifetime');
   const finish=Math.max(...entries.map(e=>e.started))+shortLifetime+5000;let lastRenew=Date.now();
   while(Date.now()<finish){
    await new Promise(r=>setTimeout(r,500));
    if(Date.now()-lastRenew>=30000){
     for(const entry of entries){const response=await post(entry.page,'/identity/renew');assert.equal(response.status,200);assert.equal(wire.filter(r=>r.path==='/identity/renew').at(-1).cookies.length,0);}
     lastRenew=Date.now();console.log('CHECKPOINT real cookie-retention elapsed '+Math.round((Date.now()-entries[0].started)/1000)+'s');
    }
   }
   const details=[];
   for(const entry of entries){
    const afterRenew=command('inspect').sessions.find(s=>s.session_key_hash===entry.identity.binding.credential);
    assert.equal(afterRenew.idle_expires_at,entry.session.idle_expires_at);assert.equal(afterRenew.absolute_expires_at,entry.session.absolute_expires_at);
    const jar=await cookies(entry.context);assert.deepEqual(jar,entry.jar,'Read-only renewal must not replace cookies or extend their expiry');
    const next=await get(entry.page,`/workspaces/${workspace}/commercial-history`);assert.equal(next.status,200);
     await entry.page.bringToFront();await ready(entry.page,entry.mode);
    const credential=jar.find(c=>c.httpOnly&&c.name.endsWith('-session'));
    details.push({mode:entry.mode,elapsedMilliseconds:Date.now()-entry.started,configuredShortLifetime:shortLifetime,cookie:credential,publication:entry.publication.cookies,serverDate:entry.publication.serverDate,browserClockAdjustmentSeconds:credential.expires-Date.parse(entry.session.absolute_expires_at)/1000,canonicalBefore:entry.session,canonicalAfterRenewal:afterRenew,nextProtectedRequest:next.status});
   }
   return {details,note:'Real wall-clock wait beyond the former cookie lifetime with actual Redis renewal and unchanged cookie jar. Does not simulate waiting 24 hours or device shutdown.'};
  }finally{for(const entry of entries)await entry.context.close();}
 });
 result.closureJourneys=true;
}
