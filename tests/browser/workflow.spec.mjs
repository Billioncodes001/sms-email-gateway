import { test as base, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { createHmac, randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const test = base.extend({
  desk: async ({ page }, use, info) => {
    const directory = await mkdtemp(resolve(tmpdir(), 'postroom-browser-'));
    await mkdir(resolve(directory, 'sessions'));
    const port = info.project.name === 'mobile' ? 5308 : 5307;
    const origin = `http://127.0.0.1:${port}`;
    const password = randomBytes(24).toString('hex');
    const secret = randomBytes(32).toString('hex');
    let child; let log = '';
    const stop = async () => {
      if (!child || child.exitCode !== null) return;
      const ended = new Promise(resolve => child.once('exit', resolve));
      child.kill('SIGTERM'); await ended;
    };
    const start = async (newPassword = password) => {
      child = spawn('php', ['-d', `session.save_path=${directory}/sessions`, '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], {
        cwd: root,
        env: { ...process.env, DATABASE: `${directory}/isolated.sqlite`, APP_PASSWORD: newPassword, APP_ORIGIN: origin, LOCAL_CALLBACK_SECRET: secret, POSTROOM_PROVIDER: 'local-test', COOKIE_SECURE: 'false' },
        stdio: ['ignore', 'ignore', 'pipe'],
      });
      child.stderr.on('data', data => { log += data.toString(); });
      for (let tries = 0; tries < 100; tries++) {
        if (child.exitCode !== null) throw new Error(log);
        try { const response = await fetch(origin); if (response.status === 200) return; } catch {}
        await new Promise(resolve => setTimeout(resolve, 50));
      }
      throw new Error(`Server did not start: ${log}`);
    };
    // Every browser URL is restricted to this isolated loopback test server.
    await page.context().route('**/*', route => route.request().url().startsWith(origin + '/') ? route.continue() : route.abort());
    try {
      await start();
      await use({ origin, password, secret, restart: async (newPassword) => { await stop(); await start(newPassword); } });
      expect(log).not.toMatch(/PHP (Fatal error|Warning|Notice)/);
    } finally { await stop(); await rm(directory, { recursive: true, force: true }); }
  },
});

async function login(page, desk) {
  await page.goto(desk.origin);
  await page.getByLabel('Workspace password').fill(desk.password);
  await page.getByRole('button', { name: 'Open workspace' }).click();
  await expect(page.getByRole('heading', { name: 'Your test address book.' })).toBeVisible();
}
async function addPerson(page, { sms = true, emailConsent = true } = {}) {
  await page.getByRole('link', { name: 'Audience', exact: true }).click();
  await page.getByLabel('Synthetic recipient name').fill('Ada Test');
  await page.getByLabel('Test email', { exact: true }).fill('ada@example.test');
  if (emailConsent) {
    await page.getByLabel('Explicit opt-in for Email', { exact: true }).check();
    await page.getByLabel('Email opt-in evidence').fill('Synthetic workshop sign-up / Email only');
  }
  if (sms) await page.getByLabel('Fictional test number').fill('+12025550123');
  await page.getByRole('button', { name: 'Add test recipient' }).click();
  await expect(page.getByRole('heading', { name: 'Ada Test', exact: true })).toBeVisible();
}
async function draft(page, { channel = 'Email', scenario = 'fail_once', title = 'A note from the test desk' } = {}) {
  await page.getByRole('link', { name: 'Compose', exact: true }).click();
  await page.getByLabel('Test title').fill(title);
  await page.getByLabel('Test recipient', { exact: true }).selectOption({ label: 'Ada Test' });
  await page.getByLabel('Channel', { exact: true }).selectOption(channel);
  await page.getByLabel('Your test message').fill('Thanks for choosing to hear from us. This is a synthetic local test, not a delivered message.');
  await page.getByLabel('Deterministic LOCAL TEST scenario').selectOption(scenario);
  await page.getByRole('button', { name: 'Review test draft' }).click();
  await expect(page.locator('.review')).toContainText(title);
}
async function queueAndDispatch(page) {
  await page.getByRole('button', { name: 'Queue local test', exact: true }).click();
  const form = await page.getByRole('button', { name: 'Dispatch local test', exact: true }).evaluate(button => Object.fromEntries(new FormData(button.form)));
  await page.getByRole('button', { name: 'Dispatch local test', exact: true }).click();
  return form;
}
async function audit(page) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
  expect(result.violations).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}
async function capture(page, name, info) {
  if (process.env.UPDATE_SCREENSHOTS === '1') {
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: resolve(root, 'docs', `${name}-${info.project.name}.png`), fullPage: true });
  }
}

test('review, durable failure/retry, restart, manual signed completion and screenshots', async ({ page, desk }, info) => {
  await login(page, desk); await addPerson(page); await audit(page); await capture(page, 'audience', info);
  await draft(page); await audit(page); await capture(page, 'review', info);
  const originalDispatch = await queueAndDispatch(page);
  await expect(page.locator('.state-failed')).toHaveText('Simulated failure');
  await page.getByRole('button', { name: 'Queue retry', exact: true }).click();
  const delayed = await page.request.post(`${desk.origin}/?tab=outbox`, { form:originalDispatch, headers:{ origin:desk.origin } });
  expect(delayed.status()).toBe(200);
  await page.reload();
  await expect(page.locator('.state-queued')).toBeVisible();
  await expect(page.locator('.outbox-card')).toContainText('1 of 5 attempts');
  await desk.restart(); await page.reload();
  await page.getByRole('button', { name: 'Dispatch local test', exact: true }).click();
  await expect(page.locator('.state-simulated')).toHaveText('Simulated success / not delivered');
  await expect(page.locator('.outbox-card')).toContainText('2 of 5 attempts');
  await page.reload(); await expect(page.locator('.outbox-card')).toContainText('2 of 5 attempts');
  await draft(page, { scenario: 'hold', title: 'A callback, at your own pace' });
  await queueAndDispatch(page);
  const held = page.locator('.outbox-card').filter({ hasText: 'A callback, at your own pace' });
  await held.getByRole('button', { name: 'Resume same attempt' }).click();
  await expect(held).toContainText('1 of 5 attempts');
  await audit(page); await capture(page, 'outbox', info);
  await held.getByRole('button', { name: 'Generate signed local callback' }).click();
  await expect(held.locator('.state-simulated')).toBeVisible();
  await expect(held).toContainText('1 of 5 attempts');
});

test('independent SMS consent, unsubscribe after queue and global suppression', async ({ page, desk }) => {
  await login(page, desk); await addPerson(page);
  await draft(page, { channel: 'SMS', scenario: 'success', title: 'SMS permission check' });
  await page.getByRole('button', { name: 'Queue local test', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('explicit channel consent');
  const previewURL = page.url();
  await page.getByRole('link', { name: 'Audience', exact: true }).click();
  await page.getByText('Record SMS opt-in', { exact: true }).click();
  const permission = page.locator('details').filter({ has: page.getByText('Record SMS opt-in', { exact: true }) });
  await permission.getByLabel('Evidence / reason').fill('Synthetic SMS-specific opt-in');
  await permission.getByLabel('Explicit opt-in for SMS', { exact: true }).check();
  await permission.getByRole('button', { name: 'Save channel opt-in' }).click();
  await page.goto(previewURL); await page.getByRole('button', { name: 'Queue local test', exact: true }).click();
  await page.getByRole('link', { name: 'Audience', exact: true }).click();
  await page.getByText('Unsubscribe SMS', { exact: true }).click();
  const unsubscribe = page.locator('details').filter({ has: page.getByText('Unsubscribe SMS', { exact: true }) });
  await unsubscribe.getByLabel('Evidence / reason').fill('Synthetic withdrawal before dispatch');
  await unsubscribe.getByRole('button', { name: 'Confirm channel unsubscribe' }).click();
  await page.getByRole('link', { name: 'Test outbox', exact: true }).click();
  await expect(page.locator('.state-suppressed')).toBeVisible();
  await expect(page.locator('.outbox-card')).toContainText('0 of 5 attempts');
  await draft(page, { scenario: 'success', title: 'Global stop check' });
  await page.getByRole('button', { name: 'Queue local test', exact: true }).click();
  await page.getByRole('link', { name: 'Audience', exact: true }).click();
  await page.getByText('Suppress all channels', { exact: true }).click();
  await page.getByLabel('Reason for global suppression').fill('Synthetic global stop request');
  await page.getByRole('button', { name: 'Confirm global suppression' }).click();
  await expect(page.getByText('Globally suppressed', { exact: true })).toBeVisible();
  await page.getByText('Consent history', { exact: true }).click();
  await expect(page.locator('.timeline')).toContainText('Synthetic global stop request');
  await audit(page);
  await page.getByRole('link', { name: 'Test outbox', exact: true }).click();
  await expect(page.locator('.state-suppressed')).toHaveCount(2);
  await expect(page.getByRole('button', { name: 'Dispatch local test' })).toHaveCount(0);
});

test('HTTP callback rejects spoofing, replay conflicts and out-of-order reversal', async ({ page, desk }) => {
  await login(page, desk); await addPerson(page); await draft(page, { scenario: 'hold' }); await queueAndDispatch(page);
  const attemptID = (await page.locator('.mono').textContent()).match(/[a-f0-9]{32}/)[0];
  const event = { event_id: 'browser-terminal', attempt_id: attemptID, sequence: 2, state: 'simulated' };
  const post = async (payload, { forged = false, offset = 0 } = {}) => {
    const body = JSON.stringify(payload); const timestamp = String(Math.floor(Date.now()/1000) + offset);
    const signature = forged ? '0'.repeat(64) : createHmac('sha256', desk.secret).update(`${timestamp}.${body}`).digest('hex');
    return page.request.post(`${desk.origin}/callbacks/local-test`, { data: body, headers: { 'content-type':'application/json','x-local-timestamp':timestamp,'x-local-signature':signature } });
  };
  expect((await post(event, { forged: true })).status()).toBe(400);
  expect((await post(event, { offset: -301 })).status()).toBe(400);
  await page.reload(); await expect(page.locator('.state-submitted')).toBeVisible();
  expect((await (await post(event)).json()).result).toBe('applied');
  expect((await (await post(event)).json()).result).toBe('duplicate');
  expect((await post({ ...event, state: 'failed' })).status()).toBe(400);
  expect((await (await post({ ...event, event_id: 'late-accepted', sequence: 1, state: 'accepted' })).json()).result).toBe('ignored_out_of_order');
  await page.reload(); await expect(page.locator('.state-simulated')).toBeVisible();
  await expect(page.locator('.timeline')).toContainText('ignored_out_of_order');
});

test('auth, CSRF, host/origin, private paths, logout and credential rotation', async ({ page, desk }) => {
  await page.goto(`${desk.origin}/?tab=legacy`);
  await expect(page.getByLabel('Workspace password')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Before the durable test desk.' })).toHaveCount(0);
  expect((await page.request.post(desk.origin, { form: { action:'person', csrf:'forged' }, headers: { origin:desk.origin } })).status()).toBe(403);
  expect((await page.request.post(desk.origin, { form: { action:'login' }, headers: { origin:'http://attacker.test' } })).status()).toBe(403);
  expect((await page.request.get(desk.origin, { headers: { host:'attacker.test' } })).status()).toBe(403);
  expect((await page.request.get(`${desk.origin}/src/outbox.php`)).status()).toBe(404);
  expect((await page.request.get(`${desk.origin}/data/app.sqlite`)).status()).toBe(404);
  await login(page, desk); await addPerson(page);
  const cookie = (await page.context().cookies()).map(c=>`${c.name}=${c.value}`).join('; ');
  await page.getByRole('button', { name:'Sign out' }).click();
  const oldSession = await page.request.get(desk.origin, { headers: { cookie } });
  expect(await oldSession.text()).not.toContain('ada@example.test');
  await login(page, desk);
  await desk.restart(randomBytes(24).toString('hex')); await page.reload();
  await expect(page.getByLabel('Workspace password')).toBeVisible();
  await expect(page.getByText('ada@example.test', { exact:true })).toHaveCount(0);
});

test('validation retains draft input and two-tab stale consent cannot override suppression', async ({ page, desk }) => {
  await login(page, desk); await addPerson(page);
  await page.getByRole('link', { name:'Compose', exact:true }).click();
  await page.getByLabel('Test title').fill('Keep my draft');
  await page.getByLabel('Test recipient', { exact:true }).selectOption({ label:'Ada Test' });
  await page.getByLabel('Channel', { exact:true }).selectOption('SMS');
  await page.getByLabel('Your test message').fill('é'.repeat(241));
  await page.getByRole('button', { name:'Review test draft' }).click();
  await expect(page.getByRole('alert')).toContainText('Invalid body');
  await expect(page.getByLabel('Test title')).toHaveValue('Keep my draft');
  await expect(page.getByLabel('Your test message')).toHaveValue('é'.repeat(241));
  await page.getByRole('link', { name:'Audience', exact:true }).click();
  const stale = await page.context().newPage(); await stale.goto(page.url());
  await stale.getByText('Record SMS opt-in', { exact:true }).click();
  const form = stale.locator('details').filter({ has:stale.getByText('Record SMS opt-in', { exact:true }) });
  await form.getByLabel('Evidence / reason').fill('Old page opt-in');
  await form.getByLabel('Explicit opt-in for SMS', { exact:true }).check();
  await page.getByText('Suppress all channels', { exact:true }).click();
  await page.getByLabel('Reason for global suppression').fill('Newer suppression');
  await page.getByRole('button', { name:'Confirm global suppression' }).click();
  await form.getByRole('button', { name:'Save channel opt-in' }).click();
  await expect(stale.getByRole('alert')).toContainText('changed in another page');
  await expect(stale.getByText('Globally suppressed', { exact:true })).toBeVisible();
  await stale.close();
});
