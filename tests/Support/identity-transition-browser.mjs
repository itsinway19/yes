// Disposable P2-A delivery proof; not an application client or a P2-B cookie policy.
import { createRequire } from 'node:module';
import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';

if (process.env.P2A_BROWSER_FIXTURE !== 'enabled') throw new Error('Explicit disposable fixture authorization required');
const require = createRequire(process.env.P2A_PLAYWRIGHT_PACKAGE);
const { chromium } = require('playwright');
const evidenceRoot = path.resolve(process.env.P2A_EVIDENCE_ROOT);
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const held = new Map();
const wire = [];
const proxy = http.createServer((req, res) => {
  const outgoing = http.request({ hostname: 'localhost', port: 8128, method: req.method, path: req.url, headers: req.headers }, source => {
    const chunks = [];
    source.on('data', chunk => chunks.push(chunk));
    source.on('end', () => {
      const body = Buffer.concat(chunks);
      const row = { completed: Date.now(), status: source.statusCode, bodyHash: hash(body), cookieNames: (source.headers['set-cookie'] ?? []).map(value => value.split('=')[0]) };
      wire.push(row);
      const deliver = () => {
        row.delivered = Date.now();
        if (!res.destroyed) { res.writeHead(source.statusCode, source.headers); res.end(body); }
      };
      if (req.headers['x-p2a-hold']) held.set(req.headers['x-p2a-hold'], { deliver, row });
      else deliver();
    });
  });
  outgoing.on('error', () => { if (!res.destroyed) { res.writeHead(502); res.end(); } });
  req.pipe(outgoing);
});
await new Promise(resolve => proxy.listen(8132, 'localhost', resolve));
const browser = await chromium.launch({ headless: true, executablePath: process.env.P2A_CHROMIUM_EXECUTABLE });
const results = { browser: browser.version(), cases: [], wire };
const nonce = () => crypto.randomBytes(32).toString('hex');
async function waitHeld(key) {
  const deadline = Date.now() + 15000;
  while (!held.has(key)) { if (Date.now() > deadline) throw new Error('Original response did not complete at the hold'); await new Promise(resolve => setTimeout(resolve, 10)); }
}
async function post(page, data, hold) {
  return page.evaluate(async ({ data, hold }) => {
    const csrf = decodeURIComponent(document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN=')).split('=').slice(1).join('='));
    const response = await fetch('/p2a/login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf, ...(hold ? { 'X-P2A-Hold': hold } : {}) }, body: JSON.stringify(data) });
    return { status: response.status, body: await response.json() };
  }, { data, hold });
}
async function get(page, url) {
  return page.evaluate(async url => { const response = await fetch(url); return { status: response.status, body: await response.json() }; }, url);
}
async function sessionHash(context) {
  const cookie = (await context.cookies()).find(value => value.name === 'builderad-session');
  assert.ok(cookie, 'The actual session cookie must exist');
  return hash(cookie.value); // Raw credentials never enter evidence.
}
try {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto('http://localhost:8132/p2a');
  const bootstrap = (await get(page, '/p2a/bootstrap')).body;
  const firstNonce = nonce();
  const pendingA = post(page, { lineage: bootstrap.lineage, epoch: 0, nonce: firstNonce, email: 'p2a-a@example.test' }, 'login-a');
  await waitHeld('login-a');
  const aCommitted = await get(page, `/p2a/resolve?lineage=${bootstrap.lineage}&nonce=${firstNonce}`);
  assert.equal(aCommitted.body.epoch, 1);
  const stale = await post(page, { lineage: bootstrap.lineage, epoch: 0, nonce: nonce(), email: 'p2a-b@example.test' });
  assert.equal(stale.status, 409);
  const current = await post(page, { lineage: bootstrap.lineage, epoch: 1, nonce: nonce(), email: 'p2a-b@example.test' });
  assert.equal(current.status, 200);
  assert.equal(current.body.epoch, 2);
  const bCookie = await sessionHash(context);
  const before = await get(page, `/p2a/protected?lineage=${bootstrap.lineage}`);
  assert.equal(before.status, 200);
  held.get('login-a').deliver();
  const old = await pendingA;
  assert.equal(old.status, 200);
  const afterCookie = await sessionHash(context);
  assert.notEqual(bCookie, afterCookie);
  const after = await get(page, `/p2a/protected?lineage=${bootstrap.lineage}`);
  assert.equal(after.status, 409);
  const stillCurrent = await get(page, `/p2a/resolve?lineage=${bootstrap.lineage}&nonce=${current.body.nonce}`);
  assert.equal(stillCurrent.body.epoch, 2);
  results.cases.push({ name: 'late-login-cookie', pass: true, staleStatus: stale.status, beforePrincipal: before.body.principal, afterAdmission: after.status, epoch: 2, cookieBeforeHash: bCookie, cookieAfterHash: afterCookie, held: held.get('login-a').row });
  await context.close();

  const lossContext = await browser.newContext();
  const owner = await lossContext.newPage();
  await owner.goto('http://localhost:8132/p2a');
  const loss = (await get(owner, '/p2a/bootstrap')).body;
  const lossNonce = nonce();
  const initialCookie = await sessionHash(lossContext);
  const abandoned = post(owner, { lineage: loss.lineage, epoch: 0, nonce: lossNonce, email: 'p2a-a@example.test' }, 'closed-tab').catch(() => null);
  await waitHeld('closed-tab');
  await owner.close();
  held.get('closed-tab').deliver();
  await abandoned;
  assert.equal(await sessionHash(lossContext), initialCookie);
  const recovery = await lossContext.newPage();
  await recovery.goto('http://localhost:8132/p2a');
  const committed = await get(recovery, `/p2a/resolve?lineage=${loss.lineage}&nonce=${lossNonce}`);
  assert.equal(committed.body.epoch, 1);
  const denied = await get(recovery, `/p2a/protected?lineage=${loss.lineage}`);
  assert.equal(denied.status, 409);
  results.cases.push({ name: 'committed-without-cookie-tab-closed', pass: true, epoch: 1, admission: 409, cookieDeliveryAbsent: true, held: held.get('closed-tab').row });
  await lossContext.close();
  await fs.writeFile(path.join(evidenceRoot, 'browser-evidence.json'), JSON.stringify(results, null, 2));
  console.log(JSON.stringify({ pass: true, browser: results.browser, cases: results.cases.map(value => value.name) }));
} finally {
  for (const value of held.values()) value.deliver();
  await browser.close();
  proxy.closeAllConnections();
  await new Promise(resolve => proxy.close(resolve));
}
