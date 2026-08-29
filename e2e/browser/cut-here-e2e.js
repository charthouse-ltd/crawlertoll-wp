// CrawlerToll "✂ Cut here" cursor-split e2e (Playwright).
// Usage: node cut-here-e2e.js [postId]
// Flow: click mid-paragraph → toolbar button → block splits at cursor,
// cut meta lands between the halves → save → RAW front-end HTML contains
// the free half but NEVER the sealed half (the money proof).
const { chromium } = require('playwright');

const BASE = 'https://qa2.85.10.200.55.nip.io';
const WP_USER = 'crawlertoll';
const WP_PASS = '0I2KRfgSqSrGmBMPcqpzktxx';
const POST_ID = process.argv[2] || '22';
// Offset of CUTGAMMA inside block 2's text:
// "CUTBETA freehalf before the split point " = 40 chars.
const OFFSET = 40;

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
    viewport: { width: 1400, height: 1600 },
  });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log('[pageerror]', String(e).slice(0, 500)));
  page.on('console', (m) => { if (m.type() === 'error') console.log('[console-err]', m.text().slice(0, 300)); });

  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', WP_USER);
  await page.fill('#user_pass', WP_PASS);
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');
  await page.goto(BASE + '/wp-admin/post.php?post=' + POST_ID + '&action=edit', { waitUntil: 'domcontentloaded' });

  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  await canvas.locator('[data-block]').first().waitFor({ timeout: 30000 });
  await page.keyboard.press('Escape');
  await page.waitForTimeout(1500);

  const blockCount = () => page.evaluate(() =>
    window.wp.data.select('core/block-editor').getBlocks().length);
  const getCut = () => page.evaluate(() =>
    window.wp.data.select('core/editor').getEditedPostAttribute('meta')._crawlertoll_cut);

  ck((await blockCount()) === 3, 'starts with 3 blocks (got ' + (await blockCount()) + ')');

  // Click into block 2, then place the cursor DETERMINISTICALLY through the
  // editor store (rapid synthetic ArrowRight presses get lost under React
  // re-renders; the store selection is what the split code actually reads).
  const block2 = canvas.locator('.is-root-container > *:nth-child(2)');
  await block2.click();
  await page.waitForTimeout(500);
  await page.evaluate((off) => {
    const be = window.wp.data.select('core/block-editor');
    const id = be.getSelectedBlockClientId();
    window.wp.data.dispatch('core/block-editor').selectionChange(id, 'content', off, off);
  }, OFFSET);
  await page.waitForTimeout(400);

  const sel = await page.evaluate(() => {
    const s = window.wp.data.select('core/block-editor').getSelectionStart();
    return { clientId: s && s.clientId, offset: s && s.offset };
  });
  console.log('selection before split:', JSON.stringify(sel));
  ck(sel.offset === OFFSET, 'cursor sits at offset ' + OFFSET + ' (got ' + sel.offset + ')');

  // Toolbar button — fixed toolbar renders in the parent doc; floating in the iframe.
  const btnSel = 'button[aria-label="Place paywall cut at cursor"]';
  let btn = page.locator(btnSel);
  try {
    await btn.waitFor({ timeout: 5000 });
  } catch (e) {
    btn = canvas.locator(btnSel);
    await btn.waitFor({ timeout: 5000 }).catch(() => {});
  }
  ck((await btn.count()) === 1, 'cut-here toolbar button visible for selected paragraph');
  await btn.click();
  await page.waitForTimeout(900);

  ck((await blockCount()) === 4, 'block split at cursor: 3 → 4 blocks (got ' + (await blockCount()) + ')');
  ck((await getCut()) === 2, 'cut meta = 2 (after the free half; got ' + (await getCut()) + ')');

  const texts = await page.evaluate(() =>
    window.wp.data.select('core/block-editor').getBlocks().map((b) => {
      const c = b.attributes.content;
      const s = typeof c === 'string' ? c : (c && c.text) || '';
      return s.replace(/<[^>]+>/g, '');
    }));
  console.log('blocks now:', JSON.stringify(texts));
  ck(/CUTBETA/.test(texts[1] || '') && !/CUTGAMMA/.test(texts[1] || ''), 'free half keeps CUTBETA, loses CUTGAMMA');
  ck(/CUTGAMMA/.test(texts[2] || ''), 'sealed half starts at CUTGAMMA');

  // Save and verify the RAW front-end HTML (no JS) — sealed text must be absent.
  await page.click('.editor-post-publish-button__button');
  await page.waitForTimeout(3000);
  // Fetch ANONYMOUSLY (fresh context, basic auth only): the admin context
  // bypasses the paywall and would see everything.
  const anon = await browser.newContext({
    ignoreHTTPSErrors: true,
    httpCredentials: { username: 'ct-qa', password: 'IWq8wzBR4jFfee5NTv98' },
  });
  const res = await anon.request.get(BASE + '/?p=' + POST_ID);
  const html = await res.text();
  await anon.close();
  ck(/CUTALPHA/.test(html), 'front end serves free paragraph 1');
  ck(/CUTBETA/.test(html), 'front end serves the free half (preview ends mid-sentence)');
  ck(!/CUTGAMMA/.test(html), 'MONEY PROOF: sealed half (CUTGAMMA) never in served HTML');
  ck(!/CUTDELTA/.test(html), 'MONEY PROOF: sealed paragraph (CUTDELTA) never in served HTML');

  await browser.close();
  console.log(failures === 0 ? 'ALL GREEN' : failures + ' FAILURES');
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('E2E ERROR:', e); process.exit(2); });
