import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { randomBytes } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';

// No service credentials or application .env are inherited. Everything belongs to this run.
const root = resolve('.e2e/runtime');
// Database, sessions and rate-limit caches must start together as disposable state.
rmSync(`${root}/storage`, { recursive: true, force: true });
for (const dir of ['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/app/private', 'storage/app/public']) mkdirSync(`${root}/${dir}`, { recursive: true });
const database = `${root}/database.sqlite`;
writeFileSync(database, '');
const env = {
  PATH: process.env.PATH, HOME: process.env.HOME, TMPDIR: process.env.TMPDIR,
  APP_NAME: 'Marketing Owl', APP_ENV: 'e2e', APP_DEBUG: 'false',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`, APP_URL: `http://127.0.0.1:${process.env.E2E_PORT || '3103'}`,
  DB_CONNECTION: 'sqlite', DB_DATABASE: database,
  SESSION_DRIVER: 'file', CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'log',
  CAMPAIGN_GENERATOR: 'mock', BANNER_GENERATOR: 'mock', CAMPAIGN_PROCESSING_MODE: process.env.E2E_PROCESSING_MODE === 'request' ? 'request' : 'queue',
  CAMPAIGN_CONCIERGE_EMAILS: 'concierge@example.test', E2E_STORAGE: `${root}/storage`, LARAVEL_STORAGE_PATH: `${root}/storage`,
  CRON_SECRET: 'e2e-fixture-cron-token', E2E_PUBLIC_PRODUCT_URL: process.env.E2E_PUBLIC_PRODUCT_URL || 'https://example.com/',
};
for (const args of [['scripts/e2e/bootstrap.php', 'migrate'], ['scripts/e2e/bootstrap.php', 'seed']]) {
  const result = spawnSync('php', args, { env, stdio: 'inherit' });
  if (result.status !== 0) process.exit(result.status || 1);
}
const server = spawn('php', ['-S', `127.0.0.1:${process.env.E2E_PORT || '3103'}`, '-t', 'public', 'scripts/e2e/router.php'], { env, stdio: 'inherit' });
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => server.kill(signal));
server.on('exit', code => process.exit(code || 0));
