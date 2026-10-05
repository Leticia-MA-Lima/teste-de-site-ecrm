const { chromium, webkit } = require(process.env.PLAYWRIGHT_PACKAGE || 'playwright');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const file = path.resolve(root, '.' + (url.pathname === '/' ? '/index.html' : url.pathname));
  if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404).end(); return; }
  const mime = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml' };
  res.setHeader('Content-Type', mime[path.extname(file)] || 'application/octet-stream');
  fs.createReadStream(file).pipe(res);
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const testUrl = process.env.ECRM_TEST_URL || `http://127.0.0.1:${server.address().port}/`;
  const allowedHost = new URL(testUrl).host;
  const browser = process.env.BROWSER_ENGINE === 'webkit' ? await webkit.launch({ headless: true }) : await chromium.launch({ channel: process.env.BROWSER_CHANNEL || 'msedge', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const events = []; let submissions = 0;
    const failedIds = []; let failureMode = 'transient';
    await page.route('**/tracking/collect.php', async route => {
      const event = route.request().postDataJSON(); events.push(event);
      if (event.kind === 'form_submit') {
        if (failureMode === 'transient') {
          failedIds.push(event.id); failureMode = null;
          await route.fulfill({ status: 502, contentType: 'text/html', body: '<html>Temporary gateway failure</html>' }); return;
        }
        if (failureMode === 'permanent') {
          failedIds.push(event.id);
          await route.fulfill({ status: 413, body: '' }); return;
        }
        submissions++;
      }
      await route.fulfill({ json: { accepted: true, score: 27, qualified: event.kind === 'form_submit', crm_state: event.kind === 'form_submit' ? 'pending' : null } });
    });
    // Não contatar analytics, WordPress original ou serviços externos durante QA.
    await page.route(url => /^https?:$/.test(url.protocol) && url.host !== allowedHost && !url.pathname.endsWith('/tracking/collect.php'), route => route.abort());
    await page.goto(testUrl);
    await page.waitForFunction(() => !!document.querySelector('.ecrm-whatsapp'));
    await page.locator('.ecrm-whatsapp > button').click();
    await page.locator('#ecrm-wa-panel input[name=nome]').fill('Teste WhatsApp');
    await page.locator('#ecrm-wa-panel input[name=email]').fill('qa@example.invalid');
    await page.locator('#ecrm-wa-panel input[name=whatsapp]').fill('11997831059');
    await page.locator('#ecrm-wa-panel [type=submit]').click();
    await page.locator('#ecrm-wa-panel [data-ecrm-status]').getByText('Contato recebido!', { exact: false }).waitFor();
    assert.match(await page.locator('#ecrm-wa-panel a').getAttribute('href'), /wa\.me\/5511997831059/);
    await page.locator('.ecrm-wa-close').click();
    const form = page.locator('.wpcf7-form');
    assert.equal(await form.locator('[name=nome]').getAttribute('required'), '');
    await form.locator('[name=nome]').fill('Teste Contato');
    await form.locator('[name=email]').fill('contato@example.invalid');
    await form.locator('[name=nome]').fill('Teste Contato Alterado');
    await form.locator('[type=submit]').click();
    await form.locator('.wpcf7-response-output').getByText('Contato recebido!', { exact: false }).waitFor();
    assert.equal(submissions, 2);
    assert.equal(events.filter(e => e.id === failedIds[0]).length, 2, 'A resposta HTML transitória deve repetir o mesmo ID, sem duplicar o contato.');
    assert.equal(events.filter(e => e.kind === 'field_filled' && e.target === 'contact:nome').length, 1);
    assert(events.some(e => e.kind === 'visit'));
    assert(events.every(e => e.kind === 'form_submit' || !e.contact));
    assert(!events.some(e => /corebos|authorization/.test(JSON.stringify(e))));
    await page.locator('.ecrm-whatsapp > button').click();
    await page.screenshot({ path: path.join(root, 'tests', 'browser-desktop.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(root, 'tests', 'browser-mobile.png') });
    assert.equal(await page.locator('#ecrm-wa-panel').evaluate(el => el.getBoundingClientRect().width <= innerWidth), true);
    await page.locator('.ecrm-wa-close').click();
    failureMode = 'permanent';
    await form.locator('[name=nome]').fill('Teste Resposta Inválida');
    await form.locator('[type=submit]').click();
    await form.locator('.wpcf7-response-output').getByText('resposta inválida', { exact: false }).waitFor();
    assert.equal(await form.locator('[type=submit]').isEnabled(), true);
    const retryId = failedIds[1]; failureMode = null;
    await form.locator('[type=submit]').click();
    await form.locator('.wpcf7-response-output').getByText('Contato recebido!', { exact: false }).waitFor();
    assert.equal(events.filter(e => e.id === retryId).length, 2, 'Nova tentativa manual deve preservar o ID do envio.');
    console.log(`OK: ${events.length} eventos, dois formulários, sem PII antes do envio, WhatsApp correto e layout móvel.`);
  } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
