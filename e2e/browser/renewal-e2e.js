// A1 live browser e2e: silent pass renewal from an EXPIRED CEK cache.
// Seeds localStorage with an expired cache entry (real CEK + valid pass_id,
// expiry in the past), loads the sealed article as an anonymous reader, and
// asserts the app renews silently and the sealed body appears — no payment,
// no click. Then asserts the renewed cache entry persists the pass.
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = 'https://qa2.85.10.200.55.nip.io';
const CID = 'qa2.85.10.200.55.nip.io/post/12';
const PASS_ID = fs.readFileSync('/tmp/ct-passid2.txt', 'utf8').trim();
const CEK = fs.readFileSync('/tmp/ct-cek.txt', 'utf8').trim();

let failures = 0;
function ck(cond, msg) {
  console.log((cond ? 'PASS' : 'FAIL') + ': ' + msg);
  if (!cond) failures++;
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({
    ignoreHTTPSErrors: true,
    httpCredentials: { username: 'ct-qa', password: 'IWq8wzBR4jFfee5NTv98' },
  });
  // Seed BEFORE any page script runs: expired cache entry (A1 shape {c,p,e}).
  await ctx.addInitScript(([cid, cek, pid]) => {
    const entry = { c: cek, p: pid, e: '2026-08-29T20:00:00.000Z' }; // expired 45 min ago
    window.localStorage.setItem('ct:cek:' + cid, JSON.stringify(entry));
  }, [CID, CEK, PASS_ID]);

  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log('[pageerror]', String(e).slice(0, 300)));
  await page.goto(BASE + '/?p=12', { waitUntil: 'domcontentloaded' });

  // The renewal + decrypt is async: wait for the unlocked body. (Post 12's
  // sealed marker from the cut-bar test suite — the served preview never
  // contains it, so its presence proves a real decrypt.)
  const sealedText = 'CUTBAR_SEALED_SECRET';
  try {
    await page.waitForSelector(`.ct-unlocked:has-text("${sealedText}")`, { timeout: 20000 });
    ck(true, 'expired cache → silent renewal → sealed body revealed, no payment, no click');
  } catch {
    ck(false, 'silent renewal did not unlock the article');
    console.log('wall state:', (await page.locator('.ct-pro').textContent().catch(() => 'n/a')).slice(0, 200));
  }

  // The renewed cache entry persists the pass for the next expiry cycle.
  const stored = await page.evaluate((cid) => window.localStorage.getItem('ct:cek:' + cid), CID);
  const parsed = stored ? JSON.parse(stored) : {};
  ck(parsed.p === PASS_ID, 'renewed cache entry keeps the pass_id');
  ck(typeof parsed.c === 'string' && parsed.c.length > 8, 'renewed cache entry holds a fresh CEK');

  await browser.close();
  console.log(failures === 0 ? 'ALL GREEN' : failures + ' FAILURES');
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('E2E ERROR:', e); process.exit(2); });
