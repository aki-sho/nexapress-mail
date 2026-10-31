const { chromium } = require('playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');

(async () => {
  const root = path.resolve(__dirname, '..');
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'npm-ui-'));
  const listener = net.createServer();
  await new Promise((resolve) => listener.listen(0, '127.0.0.1', resolve));
  const port = listener.address().port;
  await new Promise((resolve) => listener.close(resolve));
  const server = spawn(process.env.NPM_PHP_BINARY || 'php', ['-d', 'display_errors=stderr', '-d', `upload_tmp_dir=${directory}`, '-S', `127.0.0.1:${port}`, 'tests/ui-router.php'],
    { cwd: root, windowsHide: true, env: { ...process.env, NPM_UI_TEST_DIR: directory }, stdio: ['ignore', 'pipe', 'pipe'] });
  let serverErrors = '';
  server.stderr.on('data', (data) => { serverErrors += data.toString(); });
  server.stdout.resume();
  let browser;
  const origin = `http://127.0.0.1:${port}`;
  const base = `${origin}/admin/extensions/nexapress-mail`;
  try {
    let ready = false;
    for (let i = 0; i < 100; i++) {
      try { const response = await fetch(`${base}/compose`); if (response.ok) { ready = true; break; } } catch {}
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    assert(ready, serverErrors);
    const edge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
    const executablePath = process.env.NPM_BROWSER_EXECUTABLE || (fs.existsSync(edge) ? edge : undefined);
    browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    const context = await browser.newContext();
    const errors = [];
    context.on('page', (page) => { page.on('pageerror', (error) => { errors.push(error.message); console.error('Browser error:', error.message); }); page.on('dialog', (dialog) => dialog.accept()); });
    const page = await context.newPage();
    await page.goto(`${base}/compose`);
    await page.locator('[name=to]').fill('person@example.com');
    await page.locator('[name=body]').fill('Draft with attachment');
    await page.locator('input[type=file]').setInputFiles({ name: 'memo.txt', mimeType: 'text/plain', buffer: Buffer.from('fixture attachment') });
    await page.waitForFunction(() => document.querySelector('[data-save-status]')?.textContent.includes('下書きを保存しました'));
    const draftUrl = page.url(); const id = new URL(draftUrl).searchParams.get('message');
    assert(id);
    assert.equal(await page.locator('[data-draft-files] label').count(), 1);
    assert.equal(await page.locator('input[type=file]').inputValue(), '');
    await page.reload();
    assert.equal(await page.locator('[name=body]').inputValue(), 'Draft with attachment');
    assert.equal(await page.locator('[data-draft-files] label').count(), 1);
    console.log('[PASS] browser autosave and attachment persistence');

    await page.locator('[name=body]').fill('Latest input before reload');
    await page.reload();
    await page.locator('[data-restore-compose]').waitFor({ state: 'visible' });
    await page.locator('[data-restore-compose]').click();
    assert.equal(await page.locator('[name=body]').inputValue(), 'Latest input before reload');
    await page.waitForFunction(() => document.querySelector('[data-save-status]')?.textContent.includes('下書きを保存しました'));
    console.log('[PASS] browser restores input not yet saved to the server');

    await page.getByRole('button', { name: '送信', exact: true }).click();
    await page.waitForURL(/compose\?message=/);
    await page.getByRole('alert').filter({ hasText: 'SMTP simulated offline' }).waitFor();
    assert.equal(await page.locator('[name=body]').inputValue(), 'Latest input before reload');
    assert.equal(await page.locator('[data-draft-files] label').count(), 1);
    console.log('[PASS] browser preserves body and attachments after SMTP failure');

    const second = await context.newPage(); await second.goto(page.url());
    await page.locator('[name=body]').fill('Newer version');
    await page.waitForFunction(() => document.querySelector('[data-save-status]')?.textContent.includes('下書きを保存しました'));
    await second.locator('[name=body]').fill('Stale tab version');
    await second.waitForFunction(() => document.querySelector('[data-save-status]')?.textContent.includes('別の画面'));
    const saved = await (await context.request.get(`${origin}/fixture/draft?id=${id}`)).json();
    assert.equal(saved.body_text, 'Newer version');
    assert.equal(await second.locator('[name=body]').inputValue(), 'Stale tab version');
    await second.close();
    console.log('[PASS] browser refuses to overwrite a newer revision');

    await page.goto(`${base}/accounts`);
    await page.locator('[data-auth-type]').selectOption('oauth2');
    assert(await page.locator('[data-oauth-settings]').isVisible());
    assert(await page.locator('[name=smtp_password]').isDisabled());
    await page.locator('[data-oauth-provider]').selectOption('microsoft');
    await page.locator('[data-provider-defaults]').click();
    assert.equal(await page.locator('[name=imap_host]').inputValue(), 'outlook.office365.com');
    await page.locator('[data-auth-type]').selectOption('password');
    assert(await page.locator('[data-oauth-settings]').isHidden());
    assert(await page.locator('[name=oauth_redirect_uri]').isDisabled());
    console.log('[PASS] browser OAuth settings and provider defaults');

    await page.goto(`${base}/dashboard`);
    assert.equal(await page.locator('input[type=date]').count(), 2);
    assert.equal(await page.locator('[data-sync-status-url]').count(), 1);
    assert.deepEqual(errors, []);
    console.log('[PASS] browser history controls and synchronization status');

    const recoveryPage = await context.newPage(); await recoveryPage.goto(`${base}/compose`);
    let acceptedDraft = null;
    await recoveryPage.route('**/draft/autosave', async (route) => {
      const response = await route.fetch(); acceptedDraft = await response.json(); await route.abort('failed');
    }, { times: 1 });
    await recoveryPage.locator('[name=body]').fill('Response lost after saving');
    await recoveryPage.waitForFunction(() => document.querySelector('[data-save-status]')?.textContent.includes('入力内容はこのタブ'));
    assert(acceptedDraft?.id);
    await recoveryPage.reload(); await recoveryPage.locator('[data-restore-compose]').click();
    await recoveryPage.waitForFunction(() => document.querySelector('[data-save-status]')?.textContent.includes('下書きを保存しました'));
    assert.equal(new URL(recoveryPage.url()).searchParams.get('message'), String(acceptedDraft.id));
    assert.equal(await recoveryPage.locator('[name=body]').inputValue(), 'Response lost after saving');
    assert.deepEqual(errors, []);
    console.log('[PASS] browser recovers the same draft after a lost save response');
    console.log('7 browser scenarios passed; no real mail was sent');
  } catch (error) {
    console.error(serverErrors);
    if (browser) {
      for (const context of browser.contexts()) for (const page of context.pages()) {
        console.error(await page.evaluate(() => ({ url: location.href, status: document.querySelector('[data-save-status]')?.textContent,
          body: document.body.textContent.slice(-1500) })).catch(() => ({})));
      }
    }
    throw error;
  } finally {
    if (browser) await browser.close();
    server.kill();
    if (server.exitCode === null) await Promise.race([new Promise((resolve) => server.once('exit', resolve)), new Promise((resolve) => setTimeout(resolve, 3000))]);
    const resolved = path.resolve(directory);
    assert.equal(path.dirname(resolved), path.resolve(os.tmpdir()));
    assert(path.basename(resolved).startsWith('npm-ui-'));
    fs.rmSync(resolved, { recursive: true, force: true });
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
