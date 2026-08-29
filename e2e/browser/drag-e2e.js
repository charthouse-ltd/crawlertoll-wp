// CrawlerToll in-canvas cut-drag e2e (Playwright, real mouse events).
// Usage: node drag-e2e.js [postId] [expectedUnits]
//   node drag-e2e.js          → post 12 (3 paragraph blocks)
//   node drag-e2e.js 20 8     → longform post with an image block
// Asserts: ghost snaps to boundaries, drops land where shown, cut=total
// keeps the marker, re-drag works, final delta < 20px.
// Editor state only — never saves; the DB meta stays untouched.
const { chromium } = require('playwright');

const BASE = 'https://qa2.85.10.200.55.nip.io';
const WP_USER = 'crawlertoll';
const WP_PASS = '0I2KRfgSqSrGmBMPcqpzktxx';
const POST_ID = process.argv[2] || '12';
const EXPECT_UNITS = parseInt(process.argv[3] || '3', 10);

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
    viewport: { width: 1400, height: 1600 }, // tall: longform post must fit without scrolling
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

  const getCut = () => page.evaluate(() =>
    window.wp.data.select('core/editor').getEditedPostAttribute('meta')._crawlertoll_cut);

  const unitBoxes = async () => {
    const units = canvas.locator('.is-root-container > *:has([data-block]), .is-root-container > [data-block]');
    const n = await units.count();
    const boxes = [];
    for (let i = 0; i < n; i++) boxes.push(await units.nth(i).boundingBox());
    return boxes;
  };
  const markerSel = 'div[title="Drag to move the paywall cut"]';
  const ghostSel = 'span:has-text("cut after block")';

  const marker = canvas.locator(markerSel);
  await marker.waitFor({ timeout: 10000 });
  console.log('post', POST_ID, '| initial cut meta:', await getCut());
  ck((await marker.count()) >= 1, 'marker rendered in canvas');

  const N = (await unitBoxes()).length;
  ck(N === EXPECT_UNITS, EXPECT_UNITS + ' top-level units found (got ' + N + ')');
  const midIdx = N >= 4 ? 3 : 2; // drop target for drag 3: top of unit (midIdx+1)

  // ── Drag 1: to BELOW the last block (cut=N, nothing sealed) ──
  await marker.scrollIntoViewIfNeeded();
  const m1 = await marker.boundingBox();
  const boxes1 = await unitBoxes();
  const last = boxes1[N - 1];
  await page.mouse.move(m1.x + m1.width / 2, m1.y + m1.height / 2);
  await page.mouse.down();
  await page.mouse.move(m1.x + m1.width / 2, last.y + last.height - 4, { steps: 14 });
  await page.waitForTimeout(300);
  const ghost = canvas.locator(ghostSel);
  ck((await ghost.count()) === 1, 'ghost line visible while dragging');
  const ghostText = (await ghost.textContent()).trim();
  ck(new RegExp('cut after block\\s*' + N).test(ghostText), 'ghost snapped to boundary ' + N + ' (got "' + ghostText.slice(0, 60) + '")');
  const g1 = await ghost.boundingBox();
  ck(Math.abs(g1.y + g1.height / 2 - (last.y + last.height)) < 14,
    'ghost sits AT the bottom boundary (delta ' + Math.round(g1.y + g1.height / 2 - (last.y + last.height)) + 'px)');
  await page.mouse.up();
  await page.waitForTimeout(600);
  ck((await getCut()) === N, 'drop below last block persists cut=' + N + ' (got ' + (await getCut()) + ')');
  const markerAfter = canvas.locator(markerSel);
  ck((await markerAfter.count()) === 1 && /Nothing sealed/.test(await markerAfter.textContent()),
    'cut=total renders "nothing sealed" marker below last block');

  // ── Drag 2: back up between unit 1 and 2 (cut=1) — re-drag works ──
  await markerAfter.scrollIntoViewIfNeeded();
  const m2 = await markerAfter.boundingBox();
  const boxes2 = await unitBoxes();
  await page.mouse.move(m2.x + m2.width / 2, m2.y + m2.height / 2);
  await page.mouse.down();
  await page.mouse.move(m2.x + m2.width / 2, boxes2[1].y + 4, { steps: 14 });
  await page.waitForTimeout(300);
  const ghost2Text = (await canvas.locator(ghostSel).textContent()).trim();
  await page.mouse.up();
  await page.waitForTimeout(600);
  ck(/cut after block\s*1/.test(ghost2Text), 'second drag: ghost snapped to boundary 1 (got "' + ghost2Text.slice(0, 60) + '")');
  ck((await getCut()) === 1, 'second drag persists cut=1 — marker not stuck (got ' + (await getCut()) + ')');

  // ── Drag 3: to top of unit (midIdx+1); ghost Y ≈ final marker Y ──
  await canvas.locator(markerSel).scrollIntoViewIfNeeded();
  const m3 = await canvas.locator(markerSel).boundingBox();
  const boxes3 = await unitBoxes();
  await page.mouse.move(m3.x + m3.width / 2, m3.y + m3.height / 2);
  await page.mouse.down();
  await page.mouse.move(m3.x + m3.width / 2, boxes3[midIdx].y + 4, { steps: 12 });
  await page.waitForTimeout(300);
  const g3 = await canvas.locator(ghostSel).boundingBox();
  await page.mouse.up();
  await page.waitForTimeout(600);
  ck((await getCut()) === midIdx, 'third drag persists cut=' + midIdx + ' (got ' + (await getCut()) + ')');
  const m3after = await canvas.locator(markerSel).boundingBox();
  const delta = Math.abs((g3.y + g3.height / 2) - (m3after.y + m3after.height / 2));
  ck(delta < 20, 'final marker lands where the ghost showed (delta ' + Math.round(delta) + 'px)');

  console.log(failures === 0 ? 'ALL GREEN' : failures + ' FAILURES');
  await browser.close();
  process.exit(failures === 0 ? 0 : 1);
})().catch(async (e) => {
  console.error('FATAL', e);
  try {
    const { chromium } = require('playwright');
  } catch {}
  process.exit(1);
});
