import type { E2EConfig } from 'e2e';
import { web } from '@e2e-dev/web';

export const engine = web({ viewport: { width: 1440, height: 1000 } });
export default {
  tests: 'tests/e2e/**/*.e2e.ts',
  targets: [{ name: 'chromium', engine, app: {
    url: 'http://127.0.0.1:3103', environment: 'test',
    command: { executable: 'node', args: ['scripts/e2e/serve.mjs'], log: '.e2e/logs/app.log', startupTimeout: 120000 },
  } }],
  workers: 1, retries: 0, timeout: 90000,
  reporters: ['list', 'junit'], trace: 'retain-on-failure', cache: 'off',
} satisfies E2EConfig;
