import { readFileSync, readdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { expect } from 'e2e';

export const fixtures = () => JSON.parse(readFileSync('.e2e/runtime/fixtures.json', 'utf8'));
export async function signIn({ app, screen, browser }, role = 'owner') {
  await app.open('/login');
  await screen.getByLabel('Email address').fill(`${role}@example.test`);
  await screen.getByLabel('Password').fill('Browser-test-123!');
  await screen.getByRole('button', /Sign in/).click();
  await expect(browser).toHaveURL('/campaign-packs');
}
export async function http(browser, path, headers = {}) {
  return browser.evaluate(async ({ path, headers }) => {
    const response = await fetch(path, { redirect: 'manual', headers });
    return { status: response.status, type: response.headers.get('content-type'), body: await response.text() };
  }, { path, headers });
}

// Read-only corroboration of UI/HTTP outcomes in the dedicated disposable SQLite store.
export function persisted(sql: string, params: Array<string | number> = []) {
  return JSON.parse(execFileSync('php', ['-r', '$db = new PDO("sqlite:".$argv[1]); $db->exec("PRAGMA query_only = ON"); $query = $db->prepare($argv[2]); $query->execute(json_decode($argv[3], true)); echo json_encode($query->fetchAll(PDO::FETCH_ASSOC));', '.e2e/runtime/database.sqlite', sql, JSON.stringify(params)], { encoding: 'utf8' }));
}

export function downloadPath(download) {
  const entry = readdirSync('.e2e/artifacts', { recursive: true }).find(path => String(path).endsWith('/' + download.path));
  if (!entry) throw new Error(`Download artifact missing: ${download.path}`);
  return `.e2e/artifacts/${entry}`;
}

export const downloadText = download => readFileSync(downloadPath(download), 'utf8');
