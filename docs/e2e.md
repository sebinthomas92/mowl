# Browser regression coverage

Marketing Owl uses `tester-army/e2e` with pinned `e2e@0.17.0`, `@e2e-dev/web@0.12.0`, and `axe-core@4.11.4`. Node 22.23.2, PHP 8.3+ with GD/SQLite, Composer dependencies and built Vite assets are required. The installed runner docs under `node_modules/e2e/docs` define the supported API; upstream GitHub docs can describe newer releases.

## Run

```sh
composer install --no-interaction --prefer-dist
npm ci
npm run build
npx @e2e-dev/web install chromium
npm run test:e2e
```

The runner starts its own Laravel HTTP server at `http://127.0.0.1:3103`, creates and seeds `.e2e/runtime/database.sqlite`, and stops the process when the run ends. Port 3103 must be available. One worker, no retries, no AI model keys and disabled telemetry keep runs bounded and failures visible. A failure retains a Playwright trace; `.e2e/report.json`, `.e2e/junit.xml` and `.e2e/artifacts/` contain results, downloaded files and screenshots. These files and disposable data are ignored by Git.

The existing CI quality job builds once, runs PHP checks and then runs browser tests with seven-day artifact retention. It performs no deployment and needs no provider secrets.

The test server inherits only OS runtime variables. It creates a fresh app encryption key, uses isolated storage, SQLite, file sessions/cache, log mail and the application's built-in mock generation providers. Its router and bootstrap ignore the project's `.env`. It has no production database access, does not send email, and never invokes paid AI generation. The bootstrap refuses any database outside its dedicated path. Synthetic `.test` accounts and fixture passwords are test data only.

## Actual route and journey inventory

| Surface | Real browser checks |
| --- | --- |
| `/`, `/login`, `/register`, `/logout` | Protected-route redirect, wrong credentials, password confirmation validation, successful registration, session persistence and logout |
| `/brands`, `/campaign-packs/create` | Empty/read states, create brand, existing-brand selection, product-page analysis, private-network source rejection |
| `/products`, `/products/{product}` | Workspace isolation, all nine product-hub sections, Google Ads Search/Performance Max/Display selection and actual CSV downloads |
| `/workspaces/{workspace}/select`, `/settings` | Switch workspace, persisted navigation pin, owner rename validation/persistence, member restrictions |
| `/team`, `/invitations/{token}` | Duplicate-member error, create/revoke invitation, revoked-token rejection, invited registration with fixed email, owner removal, member restrictions |
| `/campaign-packs`, `/campaign-packs/{pack}` | Library navigation, comments, source approval, review/approve/lock/reject, reload persistence, member approval/share restrictions |
| `/campaign-packs/{pack}/versions/{version}/export/{format}` | PDF, voiceover, caption and shot-plan downloads with content assertions; draft-export refusal |
| `/shared/campaign-packs/{token}` | Public share creation and revoke enforcement |
| `/products/{product}` resource links | Create, update, reload and delete links through real Livewire/SQLite persistence |
| `/shared/products/{token}` | Hub share creation/revocation; public invalid token rejection |
| Product media and banner image/download routes | Real local fixture PNG delivery, built-in mock banner generation, actual 1080×1350 PNG downloads, included/additional banner persistence and confirmation/credit behavior |
| `/usage`, `/concierge` | Usage rendering, allow-listed concierge lookup, invalid credit adjustment, valid adjustment, support-note/onboarding persistence; denied access for other users |
| `/internal/campaign-jobs/recover`, `/internal/banner-creatives/recover` | Unauthenticated scheduler requests rejected with 401 |
| Responsive and keyboard | Settings, team, products, hub and campaign routes at 320/390/768/1024/1440px; horizontal-overflow assertion and screenshots; mobile navigation, keyboard skip link and WCAG axe check |

Workspaces are created by registration and renamed in settings. The current UI provides no workspace-delete, brand-edit/delete, or product-edit/delete control. Tests cover existing capabilities rather than inventing endpoints.

Seeded draft and approved campaigns provide repeatable review and export coverage. Their messaging and seeded product image are fixtures; they do not establish real AI quality, product-image discovery, or live provider delivery.

## Public-source ingestion

This opt-in journey contacts a real public HTML page and runs brand → source analysis → product persistence → campaign generation → usage in the browser:

```sh
E2E_PUBLIC_PRODUCT_URL=https://example.com/ npm run test:e2e -- tests/e2e/public-source.e2e.ts --output .e2e/public-source-proof
```

Without that variable, the report includes the exact skipped prerequisite. The public example page is a source-fetch sanity check; it is not an ecommerce product or proof of every merchant's structured-data markup. Campaign generation continues to use the app's mock provider. No browser request interception, fake backend, or provider response stub is used.

## Boundaries and remaining prerequisites

- Live OpenAI/Vertex campaign generation, transcription and Vertex banners require authorized provider credentials, configured project/identity and explicit spending authorization. They have not been run by this suite.
- Production/staging provider storage, real customer records, live deployment behavior and billing collection remain unverified. Settings currently displays concierge billing setup pending; there is no checkout flow to exercise.
- Real product-image discovery requires a selected accessible product URL with usable JPEG/PNG/WebP images. Banner tests use a declared local product-image fixture.
- Cron recovery success requires a configured `CRON_SECRET` and scheduler. Only rejection is checked here; existing PHP tests cover request-mode signatures, queue failure/retry and recovery contracts.
- Real email delivery is not an application invitation action: the current UI creates a shareable invite link. Browser tests use that real link and log mail configuration.
- Dependency advisories are checked against the installed lockfile; see the dependency triage below. An npm finding alone does not establish a reachable deployed PHP application vulnerability.

The first browser run reproduced campaign evidence-row overflow at 768px. Evidence now stacks within the available tablet column; the five-width regression checks that behavior.

Guest-browser coverage also reproduced an inert public product hub: the standalone share view did not load the existing Livewire/Alpine runtime. The share view now includes those existing assets, and the regression switches public channel sections while logged out.

## Dependency advisory triage

A fresh audit on October 5, 2026 reported 11 high-severity package entries in the initial lockfile. Every listed vulnerable version was also present in `origin/main` before the e2e runner was added. The earlier attribution to the e2e runner was incorrect: these paths come through existing icon tooling, Vite and concurrently.

| Package | Previous → patched resolution | Exact dependency/call path |
| --- | --- | --- |
| `@xmldom/xmldom` | 0.9.10 → 0.9.12 | icons-webfont → svgtofont → svg2ttf → XML font parsing |
| `brace-expansion` | 5.0.7 → 5.0.12; 2.1.2 → 2.1.7 | icons-webfont → svgtofont → svgicons2svgfont → glob → minimatch; also native-install cache → glob → minimatch |
| `concurrently` | 9.2.3 → 9.2.4 | Composer's existing development command orchestration; advisory propagated from shell-quote |
| `http-cache-semantics` | 4.2.0 → 4.3.0 | icons-webfont → svgtofont → ttf2woff2 → node-gyp → make-fetch-happen → cache policy |
| `ip-address` | 10.2.0 → 10.7.3 | Same native-install path → make-fetch-happen → @npmcli/agent → socks-proxy-agent → socks → address parsing |
| `nanoid` | 3.3.15 → 3.3.19 | Vite → PostCSS input ID generation; its caller uses a fixed positive size of six |
| `postcss` | 8.5.16 → 8.5.28 | Vite production/dev CSS transformation → previous-map source-map loading |
| `shell-quote` | 1.8.4 → 1.9.0 | concurrently argument expansion; compatible concurrently patch updates its pinned child |
| `svgo` | 3.3.4 → 3.3.5 | icons-webfont → svgtofont → generate.js SVG optimization |
| `tar` | 7.5.20 → 7.5.22 | svgtofont → ttf2woff2 → node-gyp archive handling; also make-fetch-happen → cacache |
| `undici` | 6.27.0 → 6.29.0 | icons-webfont → svgtofont → cheerio → public fromURL/stream fetching |

The application imports prebuilt Tabler CSS/fonts in `resources/css/app.css`; it does not invoke the upstream font generator. Vite transforms repository CSS, and Composer's dev command supplies fixed repository command strings to concurrently. These observations bound the confirmed call paths; they are not a proof that every vulnerable library API is exploitable in production. The safe compatible updates still remove the vulnerable installed resolutions.

Targeted `npm update` changed only `package-lock.json`: no new package declarations, major upgrades or overrides; the pinned e2e and browser-engine versions remain unchanged. A clean `npm ci` followed by `npm audit` reported zero vulnerabilities. Production build, PHP tests, Pint and all 16 opted-in browser journeys were rerun after the updates.

Primary advisory examples: [PostCSS source-map loading](https://github.com/advisories/GHSA-fxqj-rqcc-2cmp), [cache policy handling](https://github.com/advisories/GHSA-ch52-4w7c-c8xp), and [Undici retry interceptor](https://github.com/advisories/GHSA-r53p-7pc4-xj5r). The live npm audit covers the full installed graph; future newly disclosed advisories may require further updates.
