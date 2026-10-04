import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { signIn } from './helpers.ts';

// Explicit opt-in: this contacts a real public HTTP source. AI generation still uses the app's mock provider.
const source = process.env.E2E_PUBLIC_PRODUCT_URL;
test('real public source ingestion and mock campaign generation persist', {
  skip: source ? false : 'Set E2E_PUBLIC_PRODUCT_URL to an approved public HTML page; this test makes outbound source requests.',
  tags: ['public-source'], timeout: 120000,
}, async context => {
  const { app, screen, browser } = context;
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
  await app.open('/usage');
  await expect(screen.getByText('Standard campaign pack generation')).toBeVisible();
});
