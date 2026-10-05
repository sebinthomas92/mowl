import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { readFileSync } from 'node:fs';
import { signIn, fixtures, http, downloadText, downloadPath, persisted } from './helpers.ts';

test.beforeEach(async ({ browser }) => {
  expect(fixtures().processingMode).toBe(process.env.E2E_PROCESSING_MODE === 'request' ? 'request' : 'queue');
  await browser.addInitScript(() => {
    const record = message => {
      const errors = JSON.parse(sessionStorage.getItem('e2e-errors') || '[]');
      errors.push(typeof message === 'object' && message !== null ? JSON.stringify(message, Object.getOwnPropertyNames(message)) : String(message));
      sessionStorage.setItem('e2e-errors', JSON.stringify(errors));
    };
    window.addEventListener('error', event => { if (event.message) record(event.message); });
    window.addEventListener('unhandledrejection', event => record(event.reason?.message || event.reason));
    const original = console.error;
    console.error = (...args) => { for (const arg of args) record(arg); original.apply(console, args); };
  });
});
test.afterEach(async ({ browser }) => {
  expect(await browser.evaluate(() => JSON.parse(sessionStorage.getItem('e2e-errors') || '[]'))).toEqual([]);
});

test('guest redirects, invalid login, registration, persistence and sign out', async ({ app, browser, screen }) => {
  await app.open('/settings');
  await expect(browser).toHaveURL('/login');
  await screen.getByLabel(/^Email address/).fill('unknown@example.test');
  await screen.getByLabel('Password').fill('wrong-password');
  await screen.getByRole('button', /Sign in/).click();
  await expect(screen.getByText('These credentials do not match our records.')).toBeVisible();
  await screen.getByRole('link', 'Create your workspace').click();
  await expect(browser).toHaveURL('/register');
  await screen.getByLabel('Your name').fill('New Browser User');
  await screen.getByLabel('Workspace name').fill('New Browser Workspace');
  await screen.getByLabel(/^Email address/).fill('new-user@example.test');
  await screen.getByLabel('Password').fill('Browser-test-123!');
  await screen.getByLabel('Confirm password').fill('mismatch-password');
  await screen.getByRole('button', /Create workspace/).click();
  await expect(screen.getByText('The password field confirmation does not match.')).toBeVisible();
  await screen.getByLabel('Confirm password').fill('Browser-test-123!');
  await screen.getByRole('button', /Create workspace/).click();
  await expect(browser).toHaveURL('/campaign-packs');
  await app.open('/settings');
  await expect(screen.getByLabel('Workspace name')).toHaveValue('New Browser Workspace');
  await browser.reload();
  await expect(screen.getByLabel('Workspace name')).toHaveValue('New Browser Workspace');
  await screen.getByRole('button', 'Sign out').click();
  await expect(browser).toHaveURL('/login');
  await app.open('/settings');
  await expect(browser).toHaveURL('/login');
});

test('all sidebar routes, pin persistence and workspace selection', async context => {
  const { browser, screen, app } = context;
  await signIn(context);
  for (const [label, path, title] of [['Brands', '/brands', 'Brands'], ['Products', '/products', 'Products'], ['Campaign packs', '/campaign-packs', 'Campaign packs'], ['Team', '/team', 'Team'], ['Usage and cost', '/usage', 'Usage & cost'], ['Settings', '/settings', 'Settings']]) {
    await screen.getByRole('link', label, { exact: true }).click();
    await expect(browser).toHaveURL(path);
    await expect(screen.getByRole('heading', title, { level: 1 })).toBeVisible();
  }
  await browser.mouse.move(30, 120);
  await screen.getByRole('button', 'Pin navigation').click();
  await expect(screen.getByRole('button', 'Unpin navigation')).toHaveAttribute('aria-pressed', 'true');
  await browser.reload();
  await expect(screen.getByRole('button', 'Unpin navigation')).toHaveAttribute('aria-pressed', 'true');
  await browser.mouse.move(30, 120);
  await screen.getByRole('button', 'Unpin navigation').click();
  await browser.locator('.workspace-switcher summary').click();
  await screen.getByRole('button', 'Other Agency').click();
  await expect(screen.getByRole('heading', 'Owner action')).not.toBeVisible();
  await expect(screen.getByText('Only Outsider Browser can change the workspace name.')).toBeVisible();
  await app.open('/campaign-packs');
  await expect(screen.getByText('Your campaign library is empty')).toBeVisible();
  await browser.locator('.workspace-switcher summary').click();
  await screen.getByRole('button', 'Browser Agency').click();
  await expect(screen.getByText('Review Browser Product')).toBeVisible();
});

test('owner rename validates and persists while members have no edit control', async context => {
  const { app, screen, browser } = context;
  await signIn(context);
  await app.open('/settings');
  await screen.getByLabel('Workspace name').fill('');
  await screen.getByRole('button', /Save workspace name/).click();
  await expect(screen.getByText('The name field is required.')).toBeVisible();
  await screen.getByLabel('Workspace name').fill('Renamed Browser Agency');
  await screen.getByRole('button', /Save workspace name/).click();
  await expect(screen.getByRole('status')).toContainText('Workspace settings saved.');
  await browser.reload();
  await expect(screen.getByLabel('Workspace name')).toHaveValue('Renamed Browser Agency');
  await screen.getByLabel('Workspace name').fill('Browser Agency');
  await screen.getByRole('button', /Save workspace name/).click();
  await expect(screen.getByRole('status')).toContainText('Workspace settings saved.');
  await screen.getByRole('button', 'Sign out').click();
  await signIn(context, 'member');
  await app.open('/settings');
  await expect(screen.getByLabel('Workspace name')).toHaveCount(0);
  await expect(screen.getByText('Only Owner Browser can change the workspace name.')).toBeVisible();
});

test('invite duplicate validation, create, revoke and revoked token rejection', async context => {
  const { app, screen, browser } = context;
  await signIn(context);
  await app.open('/team');
  await screen.getByLabel(/^Email address/).fill('owner@example.test');
  await screen.getByRole('button', /Create invite link/).click();
  await expect(screen.getByText('This person is already a workspace member.')).toBeVisible();
  await screen.getByLabel(/^Email address/).fill('revoked@example.test');
  await screen.getByRole('button', /Create invite link/).click();
  await expect(screen.getByRole('status')).toContainText('Invite created for revoked@example.test.');
  const invite = await browser.locator('.invite-link span').textContent();
  await screen.getByRole('button', 'Revoke').click();
  await expect(screen.getByRole('status')).toContainText('Invitation revoked.');
  expect((await http(browser, new URL(invite!).pathname)).status).toBe(404);
});

test('invited registration uses reserved email, member permissions and owner removal', async context => {
  const { app, screen, browser } = context;
  await signIn(context);
  await app.open('/team');
  await screen.getByLabel(/^Email address/).fill('invited@example.test');
  await screen.getByRole('button', /Create invite link/).click();
  await expect(screen.getByRole('status')).toContainText('Invite created for invited@example.test.');
  const invite = await browser.locator('.invite-link span').textContent();
  await screen.getByRole('button', 'Sign out').click();
  await app.open(invite!);
  await expect(screen.getByLabel(/^Email address/)).toHaveAttribute('readonly', '');
  await expect(screen.getByLabel(/^Email address/)).toHaveValue('invited@example.test');
  await screen.getByLabel('Your name').fill('Invited Buyer');
  await screen.getByLabel('Password').fill('Browser-test-123!');
  await screen.getByLabel('Confirm password').fill('Browser-test-123!');
  await screen.getByRole('button', /Join workspace/).click();
  await expect(browser).toHaveURL('/campaign-packs');
  await app.open('/team');
  await expect(screen.getByRole('button', /Create invite link/)).toHaveCount(0);
  await expect(browser.locator('.member-row strong').filter({ hasText: 'Invited Buyer' })).toBeVisible();
  await screen.getByRole('button', 'Sign out').click();
  await signIn(context);
  await app.open('/team');
  await browser.onDialog(dialog => dialog.accept());
  await browser.locator('.member-row').filter({ hasText: 'Invited Buyer' }).getByRole('button', 'Remove').click();
  await expect(screen.getByRole('status')).toContainText('Invited Buyer was removed from the workspace.');
  await browser.reload();
  await expect(browser.locator('.member-row strong').filter({ hasText: 'Invited Buyer' })).toHaveCount(0);
});

test('brand creation and source URL errors protect the product builder', async context => {
  const { app, screen, browser } = context;
  await signIn(context);
  await app.open('/campaign-packs/create');
  await screen.getByRole('button', /Create brand/).click();
  await expect(screen.getByText('The brand name field is required.')).toBeVisible();
  await screen.getByLabel(/^Brand name/).fill('Browser Created Brand');
  await screen.getByRole('button', /Create brand/).click();
  await expect(screen.getByRole('heading', 'Add the product page')).toBeVisible();
  await screen.getByLabel('Product page URL').fill('http://127.0.0.1/private');
  await screen.getByRole('button', /Get product details/).click();
  await expect(screen.getByText('We could not read product details from that URL. Check that the page is public and try again.')).toBeVisible();
  await app.open('/brands');
  await expect(screen.getByRole('heading', 'Browser Created Brand')).toBeVisible();
  await app.open('/campaign-packs/create');
  await screen.getByLabel('Existing brand').selectOption({ label: 'Browser Created Brand' });
  await screen.getByRole('button', /Use this brand/).click();
  await expect(screen.getByRole('heading', 'Add the product page')).toBeVisible();
});

test('tenant and concierge access failures return exact HTTP status', async context => {
  const { app, browser, screen } = context;
  await signIn(context, 'outsider');
  for (const path of [`/campaign-packs/${fixtures().approved.pack}`, `/products/${fixtures().approved.product}`, '/concierge', '/invitations/not-a-valid-token', '/shared/products/not-a-valid-token', '/shared/campaign-packs/not-a-valid-token']) expect((await http(browser, path)).status).toBe(404);
  for (const path of ['/internal/campaign-jobs/recover', '/internal/banner-creatives/recover']) expect((await http(browser, path)).status).toBe(401);
  await app.open('/products');
  await expect(screen.getByText('No products yet')).toBeVisible();
});

test('owner review comments, approval, exports, public share and revocation', async context => {
  const { app, screen, browser } = context;
  await signIn(context);
  const fixture = fixtures().review;
  await app.open(`/campaign-packs/${fixture.pack}`);
  await screen.getByRole('button', 'Send to review').click();
  await expect(browser.locator('.approval-line strong')).toHaveText('Review');
  await screen.getByPlaceholder('Section (optional)').fill('Product Truth');
  await screen.getByPlaceholder('Leave a precise review note').fill('Browser review note persisted.');
  await screen.getByRole('button', 'Add note').click();
  await expect(browser.locator('.comments-panel article p')).toHaveText('Browser review note persisted.');
  await screen.getByRole('button', 'Approve source truth').click();
  await expect(screen.getByRole('heading', /Source approved/)).toBeVisible();
  await screen.getByLabel('Approval or rejection note').fill('Browser owner approval.');
  await screen.getByRole('button', 'Approve & lock').click();
  await expect(screen.getByRole('heading', 'This version is locked.')).toBeVisible();
  await browser.reload();
  await expect(browser.locator('.approval-line strong')).toHaveText('Approved');
  for (const [label, extension, expected] of [['Complete pack', '.pdf', '%PDF'], ['Voiceover sheet', '.csv', 'pace_wpm'], ['Timed captions', '.csv', 'caption'], ['Shot plan', '.csv', 'camera_framing']]) {
    const download = await browser.waitForDownload(() => browser.locator('.export-panel').getByRole('link', label).click());
    expect(download.suggestedFilename.endsWith(extension)).toBe(true);
    expect(downloadText(download)).toContain(expected);
  }
  await screen.getByRole('button', 'Create 7-day share link').click();
  await expect(browser.locator('.share-result a')).toBeVisible();
  const share = await browser.locator('.share-result a').getAttribute('href');
  expect((await http(browser, new URL(share!).pathname)).status).toBe(200);
  await screen.getByRole('button', 'Sign out').click();
  await app.open(share!);
  await expect(screen.getByRole('heading', 'Review Browser Product')).toBeVisible();
  await expect(screen.getByRole('button', 'Approve & lock')).toHaveCount(0);
  await signIn(context);
  await app.open(`/campaign-packs/${fixture.pack}`);
  await screen.getByRole('button', 'Revoke share').click();
  await expect(screen.getByRole('button', 'Revoke share')).toHaveCount(0);
  expect((await http(browser, new URL(share!).pathname)).status).toBe(404);
});

test('review rejection requires a note and persists', async context => {
  const { app, screen, browser } = context;
  await signIn(context);
  await app.open(`/campaign-packs/${fixtures().reject.pack}`);
  await screen.getByRole('button', 'Reject', { exact: true }).click();
  await expect(screen.getByRole('alert')).toContainText('The review note field is required.');
  await screen.getByLabel('Approval or rejection note').fill('Fix the product evidence first.');
  await screen.getByRole('button', 'Reject', { exact: true }).click();
  await expect(browser.locator('.approval-line strong')).toHaveText('Rejected');
  await browser.reload();
  await expect(browser.locator('.approval-line strong')).toHaveText('Rejected');
});

test('member may comment but cannot approve, share, or export drafts', async context => {
  const { app, screen, browser } = context;
  await signIn(context, 'member');
  const fixture = fixtures().variation;
  await app.open(`/campaign-packs/${fixture.pack}`);
  await expect(screen.getByRole('button', 'Approve & lock')).toHaveCount(0);
  await expect(screen.getByRole('button', 'Create 7-day share link')).toHaveCount(0);
  await screen.getByPlaceholder('Leave a precise review note').fill('Member browser comment.');
  await screen.getByRole('button', 'Add note').click();
  await expect(browser.locator('.comments-panel article p')).toHaveText('Member browser comment.');
  expect((await http(browser, `/campaign-packs/${fixture.pack}/versions/${fixture.version}/export/pdf`)).status).toBe(422);
});

test('product hub sections, Google Ads variants and real CSV downloads', async context => {
  const { app, screen, browser } = context;
  await signIn(context);
  await app.open(`/products/${fixtures().approved.product}`);
  await expect(screen.getByRole('heading', 'Google Ads copy')).toBeVisible();
  for (const [tab, slug] of [['Search campaign', 'search'], ['Performance Max', 'performance_max'], ['Display', 'display']]) {
    await screen.getByRole('tab', tab).click();
    await expect(screen.getByRole('tab', tab)).toHaveAttribute('aria-selected', 'true');
    const download = await browser.waitForDownload(() => browser.locator(`.google-section a[href$="/${slug}.csv"]`).click());
    expect(download.suggestedFilename).toContain(slug);
    expect(downloadText(download)).toContain('Approved Browser Product');
  }
  for (const [label, heading] of [['Overview', 'Your product marketing, ready to use.'], ['Product details', 'The source-linked product truth.'], ['Key messaging', 'The words that hold the story together.'], ['Meta Ads', 'Copy built for the feed.'], ['Email & SMS', 'Messages ready for the inbox.'], ['Organic social', 'Keep the product story moving.'], ['Asset links', 'Every approved resource, one click away.'], ['Campaign history', 'What has been approved and when.']]) {
    await browser.locator('.product-rail nav').getByRole('button', label).click();
    await expect(screen.getByRole('heading', heading)).toBeVisible();
  }
});

test('resource link create, update, delete and product share refresh/revoke persist', async context => {
  const { app, screen, browser } = context;
  await signIn(context);
  await app.open(`/products/${fixtures().approved.product}`);
  await browser.locator('.product-rail nav').getByRole('button', 'Asset links').click();
  await screen.getByLabel('Brand guide').fill('https://example.com/brand-guide');
  await screen.getByRole('button', 'Save links').click();
  await expect(screen.getByText('Resource links saved.')).toBeVisible();
  await browser.reload();
  await browser.locator('.product-rail nav').getByRole('button', 'Asset links').click();
  await expect(screen.getByLabel('Brand guide')).toHaveValue('https://example.com/brand-guide');
  await screen.getByLabel('Brand guide').fill('https://example.com/updated-guide');
  await screen.getByRole('button', 'Save links').click();
  await expect(browser.locator('.resource-grid a')).toHaveAttribute('href', 'https://example.com/updated-guide');
  await screen.getByLabel('Brand guide').fill('');
  await screen.getByRole('button', 'Save links').click();
  await expect(browser.locator('.resource-grid a')).toHaveCount(0);
  await screen.getByRole('button', 'Share with client').click();
  await expect(screen.getByRole('button', 'Revoke link')).toBeVisible();
  const sharePath = await browser.evaluate(() => {
    const component = Object.values(window.Livewire.all())[0];
    return new URL(component.$wire.shareUrl).pathname;
  });
  expect((await http(browser, sharePath)).status).toBe(200);
  await screen.getByRole('button', 'Sign out').click();
  await app.open(sharePath);
  await expect(screen.getByRole('heading', 'Google Ads copy')).toBeVisible();
  await browser.locator('.product-rail nav').getByRole('button', 'Meta Ads').click();
  await expect(screen.getByRole('heading', 'Copy built for the feed.')).toBeVisible();
  await expect(screen.getByRole('button', 'Share with client')).toHaveCount(0);
  await signIn(context);
  await app.open(`/products/${fixtures().approved.product}`);
  await screen.getByRole('button', 'Revoke link').click();
  await expect(screen.getByRole('button', 'Share with client')).toBeVisible();
  expect((await http(browser, sharePath)).status).toBe(404);
});

test('concierge lookup, support notes, onboarding and credit audit persist', async context => {
  const { app, screen, browser } = context;
  await signIn(context, 'concierge');
  await app.open('/concierge');
  await screen.getByLabel('Account lookup').fill('Browser Agency');
  await browser.locator('.concierge-lookup button').filter({ hasText: 'Browser Agency' }).click();
  await screen.getByLabel('Credits (+/-)').fill('0');
  await screen.getByRole('button', /Record adjustment/).click();
  await expect(screen.getByText('The selected adjustment amount is invalid.')).toBeVisible();
  await screen.getByLabel('Credits (+/-)').fill('2');
  await screen.getByLabel('Required reason').fill('Disposable browser support adjustment.');
  await screen.getByRole('button', /Record adjustment/).click();
  await expect(screen.getByRole('status')).toContainText('Credit adjustment recorded.');
  await screen.getByPlaceholder('Add an internal support note').fill('Disposable browser support history.');
  await screen.getByRole('button', 'Add note').click();
  await expect(screen.getByRole('status')).toContainText('Support note added.');
  await screen.getByRole('button', 'Confirm agency goals and campaign cadence').click();
  await expect(browser.locator('.onboarding-list button').first()).toHaveAttribute('class', 'done');
  await browser.reload();
  await browser.locator('.concierge-lookup button').filter({ hasText: 'Browser Agency' }).click();
  await expect(browser.locator('.concierge-notes').first()).toContainText('Disposable browser support history.');
  await expect(browser.locator('.onboarding-list button').first()).toHaveAttribute('class', 'done');
});

test('mock banner generation saves real PNGs and charges only confirmed additional credits', async context => {
  const { app, screen, browser } = context;
  await signIn(context);
  const fixture = fixtures().approved;
  await app.open(`/campaign-packs/${fixture.pack}`);
  const media = await http(browser, `/products/${fixture.product}/media/${fixture.media}`);
  expect(media.status).toBe(200);
  expect(media.type).toBe('image/png');
  await screen.getByLabel(/^Logo/).setInputFiles(fixture.imageFile);
  await screen.getByLabel('Primary color').fill('#234567');
  await screen.getByRole('button', 'Save branding').click();
  await expect(screen.getByText('Logo saved', { exact: true })).toBeVisible();
  await browser.reload();
  await expect(screen.getByText('Logo saved', { exact: true })).toBeVisible();
  await expect(screen.getByLabel('Primary color')).toHaveValue('#234567');
  const branding = persisted('SELECT banner_logo_path, primary_color FROM brands WHERE id = (SELECT brand_id FROM products WHERE id = ?)', [fixture.product])[0];
  expect(branding.primary_color).toBe('#234567');
  expect(branding.banner_logo_path.length).toBeGreaterThan(0);
  await screen.getByRole('button', /Generate 3 banners/).click();
  await expect(browser.locator('.banner-gallery img')).toHaveCount(3, { timeout: 30000 });
  const download = await browser.waitForDownload(() => browser.locator('.banner-gallery a').first().click());
  expect(download.suggestedFilename.endsWith('.png')).toBe(true);
  // Read binary directly: PNG's IHDR stores the output dimensions as big-endian integers.
  const file = readFileSync(downloadPath(download));
  expect(file.subarray(1, 4).toString()).toBe('PNG');
  expect(file.readUInt32BE(16)).toBe(1080);
  expect(file.readUInt32BE(20)).toBe(1350);
  const before = await browser.locator('.workspace-usage strong').textContent();
  await screen.getByRole('button', 'Generate another · 1 credit').click();
  await expect(screen.getByRole('dialog')).toBeVisible();
  await screen.getByRole('button', 'Cancel', { exact: true }).click();
  await expect(screen.getByRole('dialog')).not.toBeVisible();
  await expect(browser.locator('.workspace-usage strong')).toHaveText(before!);
  await screen.getByRole('button', 'Generate another · 1 credit').click();
  await screen.getByRole('button', 'Confirm · 1 credit').click();
  await expect(browser.locator('.banner-gallery img')).toHaveCount(4, { timeout: 30000 });
  await browser.reload();
  await expect(browser.locator('.banner-gallery img')).toHaveCount(4);
  expect(Number(await browser.locator('.workspace-usage strong').textContent())).toBe(Number(before) - 1);
});

test('authenticated scheduler recovers stale banners and failed included slots retry free', async context => {
  const { app, screen, browser } = context;
  await app.open('/login');
  const stale = fixtures().bannerStale;
  expect(persisted('SELECT status, attempts FROM banner_creatives WHERE banner_generation_batch_id = ?', [stale.batch])).toEqual(Array(3).fill({ status: 'processing', attempts: 1 }));
  expect((await http(browser, '/internal/banner-creatives/recover', { Authorization: 'Bearer incorrect-fixture-token' })).status).toBe(401);
  for (let index = 0; index < 3; index++) {
    const result = await http(browser, '/internal/banner-creatives/recover', { Authorization: 'Bearer e2e-fixture-cron-token' });
    expect(result.status).toBe(200);
    expect(JSON.parse(result.body)).toEqual({ status: 'completed' });
  }
  const idle = await http(browser, '/internal/banner-creatives/recover', { Authorization: 'Bearer e2e-fixture-cron-token' });
  expect(idle.status).toBe(200);
  expect(JSON.parse(idle.body)).toEqual({ status: 'idle' });
  expect(persisted('SELECT status, attempts FROM banner_creatives WHERE banner_generation_batch_id = ?', [stale.batch])).toEqual(Array(3).fill({ status: 'completed', attempts: 2 }));
  await signIn(context);
  await app.open(`/campaign-packs/${stale.pack}`);
  await expect(browser.locator('.banner-gallery img')).toHaveCount(3);
  const staleImage = await http(browser, await browser.locator('.banner-gallery img').first().getAttribute('src'));
  expect(staleImage.status).toBe(200);
  expect(staleImage.type).toBe('image/png');

  const failed = fixtures().bannerRetry;
  await app.open(`/campaign-packs/${failed.pack}`);
  await expect(browser.locator('.banner-failed')).toHaveCount(3);
  const before = await browser.locator('.workspace-usage strong').textContent();
  await screen.getByRole('button', 'Retry 3 included slots — free').click();
  await expect(browser.locator('.banner-gallery img')).toHaveCount(3, { timeout: 30000 });
  await browser.reload();
  await expect(browser.locator('.banner-gallery img')).toHaveCount(3);
  await expect(browser.locator('.workspace-usage strong')).toHaveText(before!);
  expect(persisted('SELECT status, attempts FROM banner_creatives WHERE banner_generation_batch_id = ?', [failed.batch])).toEqual(Array(3).fill({ status: 'completed', attempts: 1 }));
  expect(persisted('SELECT status FROM banner_generation_batches WHERE id = ?', [failed.batch])[0].status).toBe('completed');
  const download = await browser.waitForDownload(() => browser.locator('.banner-gallery a').first().click());
  const file = readFileSync(downloadPath(download));
  expect(file.subarray(1, 4).toString()).toBe('PNG');
  expect(file.readUInt32BE(16)).toBe(1080);
  expect(file.readUInt32BE(20)).toBe(1350);
  await app.open(`/products/${failed.product}`);
  await expect(browser.locator('.google-layout:visible .creative-thumbnails img')).toHaveCount(3);
  const authenticatedCreative = await browser.locator('.creative-thumbnails img').first().getAttribute('src');
  expect((await http(browser, authenticatedCreative)).status).toBe(200);
  await screen.getByRole('button', 'Share with client').click();
  await expect(screen.getByRole('button', 'Revoke link')).toBeVisible();
  const sharePath = await browser.evaluate(() => new URL(Object.values(window.Livewire.all())[0].$wire.shareUrl).pathname);
  await screen.getByRole('button', 'Sign out').click();
  await app.open(sharePath);
  await expect(browser.locator('.google-layout:visible .creative-thumbnails img')).toHaveCount(3);
  const sharedCreative = await browser.locator('.creative-thumbnails img').first().getAttribute('src');
  const sharedMedia = `${sharePath}/media/${failed.media}`;
  for (const path of [sharedCreative, sharedMedia]) {
    const response = await http(browser, path);
    expect(response.status).toBe(200);
    expect(response.type).toBe('image/png');
  }
  const sharedCsv = await browser.waitForDownload(() => browser.locator('.google-section a[href$="/search.csv"]').click());
  expect(downloadText(sharedCsv)).toContain('BannerRetry Browser Product');
  // Revocation protects the page and every public delivery endpoint, including already-known URLs.
  await signIn(context);
  await app.open(`/products/${failed.product}`);
  await screen.getByRole('button', 'Revoke link').click();
  await expect(screen.getByRole('button', 'Share with client')).toBeVisible();
  for (const path of [sharePath, sharedCreative, sharedMedia, `${sharePath}/google-ads/search.csv`]) expect((await http(browser, path)).status).toBe(404);
});

test('responsive routes, keyboard navigation, screenshots and accessibility', async context => {
  const { app, screen, browser } = context;
  await signIn(context);
  for (const width of [320, 390, 768, 1024, 1440]) {
    await browser.setViewport({ width, height: 900 });
    for (const path of ['/settings', '/team', '/products', `/products/${fixtures().approved.product}`, `/campaign-packs/${fixtures().approved.pack}`]) {
      await app.open(path);
      expect(await browser.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      await app.screenshot(`${width}-${path.replaceAll('/', '-')}`);
    }
  }
  await browser.setViewport({ width: 390, height: 844 });
  await app.open('/settings');
  await screen.getByRole('button', 'Toggle navigation').click();
  await expect(screen.getByRole('button', 'Toggle navigation')).toHaveAttribute('aria-expanded', 'true');
  await screen.getByRole('link', 'Team', { exact: true }).click();
  await expect(browser).toHaveURL('/team');
  await browser.setViewport({ width: 1440, height: 1000 });
  await app.open('/settings');
  await browser.keyboard.press('Tab');
  expect(await browser.evaluate(() => document.activeElement?.textContent?.trim())).toBe('Skip to workspace settings');
  await browser.keyboard.press('Enter');
  await expect(browser).toHaveURL('/settings#main-content');
  await browser.evaluate(source => { (0, eval)(source); return null; }, readFileSync('node_modules/axe-core/axe.min.js', 'utf8'));
  const accessibility = await browser.evaluate(async () => {
    const result = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } });
    return result.violations.map(issue => ({ id: issue.id, impact: issue.impact, targets: issue.nodes.map(node => node.target) }));
  });
  expect(accessibility).toEqual([]);
});
