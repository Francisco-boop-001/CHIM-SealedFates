'use strict';

const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const base = 'http://127.0.0.1:1';
const php = process.env.SF_TEST_PHP;
assert(php, 'SF_TEST_PHP must select an explicit local PHP binary');
const html = execFileSync(php, ['-n', path.join(__dirname, 'render_ui.php'), 'search'], { encoding: 'utf8' });
const csp = fs.readFileSync(path.join(root, 'server', 'index.php'), 'utf8').match(/header\("Content-Security-Policy: ([^"]+)"\)/)?.[1];
assert(csp, 'Production page must set CSP');

async function main() {
  const browser = await chromium.launch({ headless: true, channel: process.env.SF_TEST_BROWSER_CHANNEL });
  const unexpected = [];
  const assets = new Map([
    ['/assets/compose-search.js', 'text/javascript'],
    ['/assets/style.css', 'text/css'],
    ['/dashboard-art.webp', 'image/webp'],
  ]);
  async function open(javaScriptEnabled) {
    const page = await browser.newPage({ javaScriptEnabled });
    await page.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin === base && request.method() === 'GET') {
        if (url.pathname === '/compose') {
          return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', headers: { 'Content-Security-Policy': csp }, body: html });
        }
        if (assets.has(url.pathname)) {
          return route.fulfill({ status: 200, contentType: assets.get(url.pathname), path: path.join(root, 'server', url.pathname) });
        }
      }
      unexpected.push(request.method() + ' ' + request.url());
      return route.abort();
    });
    await page.goto(base + '/compose');
    return page;
  }

  try {
    const page = await open(true);
    const recipient = page.getByRole('combobox', { name: 'Recipient', exact: true });
    const search = page.getByRole('searchbox', { name: 'Search recipients', exact: true });
    await search.waitFor({ timeout: 1500 });
    await search.fill('altenborough');
    assert.deepEqual(await recipient.locator('option').allTextContents(), [
      'Dragonborn (player)', 'Alice Altenborough (npc:11)', 'Alice Altenborough (npc:12)',
    ]);
    assert.equal(await recipient.inputValue(), 'player', 'Filtering preserves the selection');
    await search.press('Enter');
    assert.equal(await recipient.evaluate(el => el === document.activeElement), true);
    await recipient.press('ArrowDown');
    assert.equal(await recipient.inputValue(), 'npc:11', 'Native keyboard selection works');
    await search.fill('npc:12');
    assert.deepEqual(await recipient.locator('option').allTextContents(), [
      'Alice Altenborough (npc:11)', 'Alice Altenborough (npc:12)',
    ]);
    await recipient.press('ArrowDown');
    assert.equal(await recipient.inputValue(), 'npc:12');
    await search.fill('not-in-this-catalog');
    assert.equal(await recipient.inputValue(), 'npc:12');
    assert.match(await page.locator('[role=status]').allTextContents().then(values => values.join(' ')), /no matching recipients/i);
    await search.fill('');
    assert.equal(await recipient.locator('option').count(), 6, 'Clearing restores all catalog options');

    for (const [field, label] of [['claimed_sender', 'Search signatures'], ['author', 'Search authors'], ['payer', 'Search payers']]) {
      await page.getByRole('searchbox', { name: label, exact: true }).fill('elodie');
      const select = page.locator('select[name=' + field + ']');
      assert.equal(await select.locator('option[value="npc:13"]').count(), 1, 'Search matches accented names');
      assert.equal(await select.locator('option').count(), 2, 'Each identity is filtered independently');
      assert.equal(await select.inputValue(), 'player');
    }
    assert.equal(await recipient.locator('option').count(), 6, 'Other identity filters leave the recipient list intact');

    const itemSearch = page.getByRole('searchbox', { name: 'Search attachments', exact: true });
    const item = page.locator('select[name="item[]"]').first();
    await itemSearch.fill('wine');
    assert.deepEqual(await item.locator('option').allTextContents(), [
      'No attachment', 'Wine — Skyrim.esm|0003133C', 'Wine — Other.esp|00000042',
    ]);
    await item.selectOption('Skyrim.esm|0003133C');
    await page.locator('input[name="quantity[]"]').first().fill('1');
    await itemSearch.fill('Other.esp|00000042');
    for (const other of await page.locator('select[name="item[]"]').all()) {
      assert.equal(await other.locator('option[value="Other.esp|00000042"]').count(), 1, 'All slots use the same attachment search');
      assert.equal(await other.locator('option[value=""]').count(), 1, 'The empty attachment is always available');
    }
    assert.equal(await item.inputValue(), 'Skyrim.esm|0003133C');
    assert.equal(await page.locator('input[name="quantity[]"]').first().inputValue(), '1');
    const payload = await recipient.evaluate(el => [...new FormData(el.form).entries()]);
    assert.deepEqual([...new Set(payload.map(([key]) => key))].sort(),
      ['action', 'author', 'body', 'claimed_sender', 'csrf', 'item[]', 'payer', 'quantity[]', 'recipient', 'request_id']);
    assert.equal(payload.find(([key]) => key === 'recipient')[1], 'npc:12');
    assert.equal(payload.find(([key]) => key === 'item[]')[1], 'Skyrim.esm|0003133C');
    assert.equal(payload.find(([key]) => key === 'quantity[]')[1], '1');
    const gift = page.getByRole('checkbox', { name: /personal gift/i });
    assert.equal(await gift.isChecked(), false, 'Credit requires explicit opt-in');
    assert(await gift.evaluate(el => el.getBoundingClientRect().width < 40), 'Gift checkbox stays compact');
    await gift.press('Space');
    assert.equal(await gift.isChecked(), true, 'Keyboard can declare the gift');
    assert.equal(await gift.evaluate(el => new FormData(el.form).get('gift')), '1');

    await page.close();
    const fallback = await open(false);
    assert.equal(await fallback.getByRole('searchbox').count(), 0);
    const fallbackRecipient = fallback.getByRole('combobox', { name: 'Recipient', exact: true });
    await fallbackRecipient.selectOption('npc:12');
    assert.equal(await fallbackRecipient.inputValue(), 'npc:12');
    assert.equal(await fallback.locator('select[name="item[]"]').first().locator('option').count(), 4);
    assert.deepEqual(unexpected, [], 'Search adds no requests or accidental submissions');
    console.log('PASS offline browser compose search: keyboard, duplicate names, accents, IDs, retained selections, POST fields and no-script fallback');
  } finally {
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
