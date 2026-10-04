import { readFileSync, readdirSync } from 'node:fs';
import { expect } from 'e2e';

export const fixtures = () => JSON.parse(readFileSync('.e2e/runtime/fixtures.json', 'utf8'));
export async function signIn({ app, screen, browser }, role = 'owner') {
  await app.open('/login');
  await screen.getByLabel('Email address').fill(`${role}@example.test`);
  await screen.getByLabel('Password').fill('Browser-test-123!');
  await screen.getByRole('button', /Sign in/).click();
  await expect(browser).toHaveURL('/campaign-packs');
}
export async function http(browser, path) {
  return browser.evaluate(async path => {
    const response = await fetch(path, { redirect: 'manual' });
    return { status: response.status, type: response.headers.get('content-type'), body: await response.text() };
  }, path);
}

export function downloadPath(download) {
  const entry = readdirSync('.e2e/artifacts', { recursive: true }).find(path => String(path).endsWith('/' + download.path));
  if (!entry) throw new Error(`Download artifact missing: ${download.path}`);
  return `.e2e/artifacts/${entry}`;
}

export const downloadText = download => readFileSync(downloadPath(download), 'utf8');
