// CrawlerToll in-canvas cut-drag e2e (Playwright, real mouse events).
// Drives the actual marker drag on demo post 12 and asserts:
//   1. the ghost snaps to block boundaries while dragging
//   2. a drop lands the meta EXACTLY on the boundary the ghost showed
//   3. cut=total renders the "nothing sealed" marker below the last block
//   4. the marker can be dragged again afterwards (no stuck state)
// Editor state only — never saves; the DB meta stays untouched.
const { chromium } = require('playwright');

const BASE = 'https://qa2.85.10.200.55.nip.io';
const WP_USER = 'crawlertoll';
const WP_PASS = '0I2KRfgSqSrGmBMPcqpzktxx';

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
    viewport: { width: 1400, height: 900 },
  });
  const page = await ctx.newPage();

  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', WP_USER);
  await page.fill('#user_pass', WP_PASS);
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');
  await page.goto(BASE + '/wp-admin/post.php?post=12&action=edit', { waitUntil: 'domcontentloaded' });

  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  await canvas.locator('[data-block]').first().waitFor({ timeout: 30000 });
  await page.keyboard.press('Escape'); // welcome guide, if any
  await page.waitForTimeout(1500);

  const getCut = () => page.evaluate(() =>
    window.wp.data.select('core/editor').getEditedPostAttribute('meta')._crawlertoll_cut);

  // Top-level layout children that are/contain blocks (mirrors topLevelUnits).
  const unitBoxes = async () => {
    const units = canvas.locator('.is-root-container > *:has([data-block]), .is-root-container > [data-block]');
    const n = await units.count();
    const boxes = [];
    for (let i = 0; i < n; i++) boxes.push(await units.nth(i).boundingBox());
    return boxes;
  };

  const marker = canvas.locator('div[title="Drag to move the paywall cut"]');
  await marker.waitFor({ timeout: 10000 });
  const cutBefore = await getCut();
  console.log('initial cut meta:', cutBefore);
  ck(await marker.count() >= 1, 'marker rendered in canvas');

  // ── Drag 1: from current position to BELOW the last block (cut=3) ──
  const m1 = await marker.boundingBox();
  const boxes1 = await unitBoxes();
  ck(boxes1.length === 3, 'three top-level units found (got ' + boxes1.length + ')');
  const dropY = boxes1[2].y + boxes1[2].height - 4; // lower half of last block
  await page.mouse.move(m1.x + m1.width / 2, m1.y + m1.height / 2);
  await page.mouse.down();
  await page.mouse.move(m1.x + m1.width / 2, dropY, { steps: 12 });
  await page.waitForTimeout(300);
  const ghost = canvas.locator('div:has-text("cut after block")');
  ck(await ghost.count() === 1, 'ghost line visible while dragging');
  const ghostText = await ghost.textContent();
  ck(/cut after block\s*3/.test(ghostText), 'ghost snapped to boundary 3 at lower-half of last block (got "' + ghostText.trim().slice(0, 60) + '")');
  const g1 = await ghost.boundingBox();
  ck(Math.abs(g1.y + g1.height / 2 - (boxes1[2].y + boxes1[2].height)) < 14,
    'ghost sits AT the bottom boundary (delta ' + Math.round(g1.y + g1.height / 2 - (boxes1[2].y + boxes1[2].height)) + 'px)');
  await page.mouse.up();
  await page.waitForTimeout(500);
  ck((await getCut()) === 3, 'drop below last block persists cut=3 (got ' + (await getCut()) + ')');
  const markerAfter = canvas.locator('div[title="Drag to move the paywall cut"]');
  ck((await markerAfter.count()) === 1 && /Nothing sealed/.test(await markerAfter.textContent()),
    'cut=total renders "nothing sealed" marker below last block');

  // ── Drag 2: back up between block 1 and 2 (cut=1) — proves re-drag works ──
  const m2 = await markerAfter.boundingBox();
  const boxes2 = await unitBoxes();
  const targetY2 = boxes2[1].y + 4; // just inside the top of block 2 → idx 1
  await page.mouse.move(m2.x + m2.width / 2, m2.y + m2.height / 2);
  await page.mouse.down();
  await page.mouse.move(m2.x + m2.width / 2, targetY2, { steps: 12 });
  await page.waitForTimeout(300);
  const ghost2Text = await canvas.locator('div:has-text("cut after block")').textContent();
  await page.mouse.up();
  await page.waitForTimeout(500);
  ck(/cut after block\s*1/.test(ghost2Text), 'second drag: ghost snapped to boundary 1 (got "' + ghost2Text.trim().slice(0, 60) + '")');
  ck((await getCut()) === 1, 'second drag persists cut=1 — marker not stuck (got ' + (await getCut()) + ')');

  // ── Drag 3: between block 2 and 3 (cut=2); ghost Y ≈ final marker Y ──
  const m3 = await canvas.locator('div[title="Drag to move the paywall cut"]').boundingBox();
  const boxes3 = await unitBoxes();
  const targetY3 = boxes3[2].y + 4; // top of block 3 → idx 2
  await page.mouse.move(m3.x + m3.width / 2, m3.y + m3.height / 2);
  await page.mouse.down();
  await page.mouse.move(m3.x + m3.width / 2, targetY3, { steps: 10 });
  await page.waitForTimeout(300);
  const g3 = await canvas.locator('div:has-text("cut after block")').boundingBox();
  await page.mouse.up();
  await page.waitForTimeout(500);
  ck((await getCut()) === 2, 'third drag persists cut=2 (got ' + (await getCut()) + ')');
  const m3after = await canvas.locator('div[title="Drag to move the paywall cut"]').boundingBox();
  const delta = Math.abs((g3.y + g3.height / 2) - (m3after.y + m3after.height / 2));
  ck(delta < 20, 'final marker lands where the ghost showed (delta ' + Math.round(delta) + 'px)');

  console.log(failures === 0 ? 'ALL GREEN' : failures + ' FAILURES');
  await browser.close();
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
