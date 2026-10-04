import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { signIn, fixtures, http, persisted } from './helpers.ts';

// Explicit opt-in: this contacts a real public HTTP source. AI generation still uses the app's mock provider.
const source = process.env.E2E_PUBLIC_PRODUCT_URL;
test('public source generation, four approved section variations and failed/stale campaign recovery persist', {
  skip: source ? false : 'Set E2E_PUBLIC_PRODUCT_URL to an approved public HTML page; this test makes outbound source requests.',
  tags: ['public-source'], timeout: 240000,
}, async context => {
  const { app, screen, browser } = context;
  expect(fixtures().processingMode).toBe(process.env.E2E_PROCESSING_MODE === 'request' ? 'request' : 'queue');
  await signIn(context);
  await app.open('/campaign-packs/create');
  await screen.getByLabel('Brand name').fill('Public Source Browser Brand');
  await screen.getByRole('button', /Create brand/).click();
  await screen.getByLabel('Product page URL').fill(source!);
  await screen.getByRole('button', /Get product details/).click();
  await expect(screen.getByRole('button', /Use this product/)).toBeVisible({ timeout: 30000 });
  const name = await browser.locator('.product-import-preview h3').textContent();
  expect(name?.length).toBeGreaterThan(0);
  await screen.getByRole('button', /Use this product/).click();
  await expect(screen.getByRole('button', /Generate campaign pack/)).toBeVisible({ timeout: 30000 });
  await screen.getByRole('button', /Generate campaign pack/).click();
  await expect(browser).toHaveURL(/\/campaign-packs\/\d+$/, { timeout: 45000 });
  await expect(browser.locator('.approval-line strong')).toHaveText('Draft', { timeout: 45000 });
  await browser.reload();
  await expect(browser.locator('.approval-line strong')).toHaveText('Draft');
  const pack = Number((await browser.url()).split('/').at(-1));
  const startingCredits = Number(await browser.locator('.workspace-usage strong').textContent());
  const original = persisted('SELECT content FROM campaign_pack_versions WHERE campaign_pack_id = ? AND version = 1', [pack])[0].content;
  await screen.getByRole('button', 'Approve source truth').click();
  for (const [index, section] of ['positioning', 'ranked_angles', 'creative_routes', 'offers'].entries()) {
    await screen.getByRole('button', 'Approve & lock').click();
    await expect(browser.locator('.approval-line strong')).toHaveText('Approved');
    const previous = JSON.parse(persisted('SELECT content FROM campaign_pack_versions WHERE campaign_pack_id = ? AND version = ?', [pack, index + 1])[0].content);
    await screen.getByLabel('Regenerate', { exact: true }).selectOption({ value: section });
    await screen.getByRole('button', /Generate variation/).click();
    await expect(browser.locator('.breadcrumbs strong')).toHaveText(`Campaign Pack v${index + 2}`, { timeout: 45000 });
    await expect(browser.locator('.approval-line strong')).toHaveText('Draft');
    await browser.reload();
    await expect(browser.locator('.breadcrumbs strong')).toHaveText(`Campaign Pack v${index + 2}`);
    const current = JSON.parse(persisted('SELECT content FROM campaign_pack_versions WHERE campaign_pack_id = ? AND version = ?', [pack, index + 2])[0].content);
    expect(JSON.stringify(current[section]) === JSON.stringify(previous[section])).toBe(false);
    for (const key of Object.keys(previous).filter(key => key !== section)) expect(current[key]).toEqual(previous[key]);
    expect(Number(await browser.locator('.workspace-usage strong').textContent())).toBe(startingCredits - (index === 3 ? 1 : 0));
    const job = persisted('SELECT status, section, credit_cost, base_version FROM campaign_generation_jobs WHERE campaign_pack_id = ? ORDER BY id DESC LIMIT 1', [pack])[0];
    expect(job).toEqual({ status: 'completed', section, credit_cost: index === 3 ? 1 : 0, base_version: index + 1 });
  }
  await browser.locator('.version-history').getByRole('button', 'v1', { exact: true }).click();
  await expect(browser.locator('.breadcrumbs strong')).toHaveText('Campaign Pack v1');
  await expect(browser.locator('.approval-line strong')).toHaveText('Approved');
  expect(persisted('SELECT content FROM campaign_pack_versions WHERE campaign_pack_id = ? AND version = 1', [pack])[0].content).toBe(original);
  await browser.locator('.version-history').getByRole('button', 'v5', { exact: true }).click();
  await expect(browser.locator('.approval-line strong')).toHaveText('Draft');

  // Retry the actual exhausted-pack UI; its original failed job stays terminal.
  const failed = fixtures().failedRetry;
  await app.open(`/campaign-packs/${failed.pack}`);
  await expect(screen.getByRole('heading', 'Generation needs attention.')).toBeVisible();
  const beforeRetry = Number(await browser.locator('.workspace-usage strong').textContent());
  await screen.getByRole('button', /Retry generation/).click();
  await expect(browser.locator('.approval-line strong')).toHaveText('Draft', { timeout: 45000 });
  await browser.reload();
  await expect(browser.locator('.approval-line strong')).toHaveText('Draft');
  expect(Number(await browser.locator('.workspace-usage strong').textContent())).toBe(beforeRetry - 1);
  expect(persisted('SELECT status FROM campaign_generation_jobs WHERE id = ?', [failed.job])[0].status).toBe('failed');
  expect(persisted('SELECT status, attempts FROM campaign_generation_jobs WHERE campaign_pack_id = ? ORDER BY id DESC LIMIT 1', [failed.pack])[0]).toEqual({ status: 'completed', attempts: 1 });

  expect(persisted('SELECT status FROM campaign_generation_jobs WHERE id = ?', [fixtures().cronRetry.job])[0].status).toBe('retrying');
  expect(persisted('SELECT status FROM campaign_generation_jobs WHERE id = ?', [fixtures().cronStale.job])[0].status).toBe('processing');
  for (const kind of ['cronRetry', 'cronStale']) {
    const fixture = fixtures()[kind];
    // One scheduler call marks every stale job retryable, then runs the oldest pending job.
    expect(persisted('SELECT status FROM campaign_generation_jobs WHERE id = ?', [fixture.job])[0].status).toBe('retrying');
    const result = await http(browser, '/internal/campaign-jobs/recover', { Authorization: 'Bearer e2e-fixture-cron-token' });
    expect(result.status).toBe(200);
    expect(JSON.parse(result.body)).toEqual({ status: 'completed' });
    expect(persisted('SELECT status, attempts FROM campaign_generation_jobs WHERE id = ?', [fixture.job])[0]).toEqual({ status: 'completed', attempts: 2 });
    await app.open(`/campaign-packs/${fixture.pack}`);
    await expect(browser.locator('.approval-line strong')).toHaveText('Draft');
    await browser.reload();
    await expect(browser.locator('.breadcrumbs strong')).toHaveText('Campaign Pack v1');
  }
  const idle = await http(browser, '/internal/campaign-jobs/recover', { Authorization: 'Bearer e2e-fixture-cron-token' });
  expect(idle.status).toBe(200);
  expect(JSON.parse(idle.body)).toEqual({ status: 'idle' });
  expect(Number(await browser.locator('.workspace-usage strong').textContent())).toBe(beforeRetry - 1);
  await app.open('/usage');
  await expect(screen.getByText('Standard campaign pack generation')).toBeVisible();
  // The premium UI reserves three credits instead of the standard one.
  // Another synthetic owner stays within the real six campaign-process requests/minute limit.
  await screen.getByRole('button', 'Sign out').click();
  await signIn(context, 'concierge');
  const beforeDeep = Number(await browser.locator('.workspace-usage strong').textContent());
  await app.open('/campaign-packs/create');
  await screen.getByLabel('Brand name').fill('Premium Browser Brand');
  await screen.getByRole('button', /Create brand/).click();
  await screen.getByLabel('Product page URL').fill(source!);
  await screen.getByRole('button', /Get product details/).click();
  await expect(screen.getByRole('button', /Use this product/)).toBeVisible({ timeout: 30000 });
  await screen.getByRole('button', /Use this product/).click();
  await screen.getByRole('radio', /Premium deep analysis/).check();
  await screen.getByRole('button', /Generate campaign pack/).click();
  await expect(browser).toHaveURL(/\/campaign-packs\/\d+$/, { timeout: 45000 });
  await expect(browser.locator('.approval-line strong')).toHaveText('Draft', { timeout: 45000 });
  await browser.reload();
  expect(Number(await browser.locator('.workspace-usage strong').textContent())).toBe(beforeDeep - 3);
  const premiumPack = Number((await browser.url()).split('/').at(-1));
  expect(persisted('SELECT analysis_mode, credit_cost FROM campaign_packs WHERE id = ?', [premiumPack])[0]).toEqual({ analysis_mode: 'deep', credit_cost: 3 });
  await app.open('/usage');
  await expect(screen.getByText('Deep campaign pack generation')).toBeVisible();
});
