// CrawlerToll cut-drag debug: inspect the iframed editor canvas DOM and
// run the panel's sibling-traversal logic against reality.
const { chromium } = require('playwright');

const BASE = 'https://qa2.85.10.200.55.nip.io';
const WP_USER = 'crawlertoll';
const WP_PASS = '0I2KRfgSqSrGmBMPcqpzktxx';

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({
    ignoreHTTPSErrors: true,
    httpCredentials: { username: 'ct-qa', password: 'IWq8wzBR4jFfee5NTv98' },
    viewport: { width: 1400, height: 900 },
  });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error') console.log('[page-err]', m.text().slice(0, 200)); });

  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', WP_USER);
  await page.fill('#user_pass', WP_PASS);
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');

  await page.goto(BASE + '/wp-admin/post.php?post=12&action=edit', { waitUntil: 'domcontentloaded' });
  // Dismiss the welcome guide if present
  await page.waitForTimeout(4000);
  try {
    const iframe = page.frameLocator('iframe[name="editor-canvas"]');
    await iframe.locator('[data-block]').first().waitFor({ timeout: 20000 });
  } catch (e) {
    console.log('NO IFRAME CANVAS — falling back to top-frame query');
  }

  // Close any modal (welcome guide)
  await page.keyboard.press('Escape');
  await page.waitForTimeout(1000);

  const report = await page.evaluate(() => {
    const ifr = document.querySelector('iframe[name="editor-canvas"]');
    const doc = ifr ? ifr.contentDocument : document;
    const blocks = Array.from(doc.querySelectorAll('[data-block]'));
    const layout = doc.querySelector('.is-root-container') || doc.querySelector('.block-editor-block-list__layout');
    const layoutKids = layout ? Array.from(layout.children).map((c) => ({
      tag: c.tagName, cls: (c.className || '').toString().slice(0, 60),
      dataBlock: c.hasAttribute('data-block'),
    })) : null;
    // Find our marker
    const all = Array.from(doc.querySelectorAll('div'));
    const marker = all.find((d) => (d.textContent || '').includes('drag to move') && d.children.length <= 4);
    let markerInfo = null;
    if (marker) {
      const own = marker.closest('[data-block]');
      const parent = own && own.parentElement;
      markerInfo = {
        found: true,
        ownDataBlock: own ? own.getAttribute('data-block') : null,
        parentTag: parent ? parent.tagName : null,
        parentCls: parent ? (parent.className || '').toString().slice(0, 80) : null,
        siblingCount: parent ? Array.from(parent.children).filter((c) => c.hasAttribute('data-block')).length : -1,
        ownIndex: parent ? Array.from(parent.children).indexOf(own) : -1,
        totalChildren: parent ? parent.children.length : -1,
      };
    } else {
      markerInfo = { found: false };
    }
    // meta cut from the editor store (parent frame wp)
    let meta = null;
    try {
      meta = window.wp.data.select('core/editor').getEditedPostAttribute('meta');
    } catch (e) { meta = 'err: ' + e.message; }
    return {
      iframed: !!ifr,
      blockCount: blocks.length,
      blockParents: [...new Set(blocks.map((b) => (b.parentElement.className || '').toString().slice(0, 60)))],
      layoutKids,
      markerInfo,
      meta,
    };
  });
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
