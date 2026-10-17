# Shopify landing pages

The Shopify adapter publishes the existing Product → Pages draft as a Shopify online-store page. It reuses approval blockers, frozen content snapshots, owner-only publishing, public version verification and rollback. It does not change product templates, themes, carts or checkout. Shopify product import and order reporting are not part of this connector.

## App setup for a development-store pilot

1. Create a **standalone/non-embedded app** in Shopify's Dev Dashboard. Use a development store while testing. Choose custom distribution only for a single-merchant pilot; public distribution needs App Store review before unrelated merchants can install. Distribution methods cannot be changed on an existing app, so use separate app registrations for a custom pilot and a public release.
2. Set the app's redirect URI to `https://<MarketingOwl-host>/integrations/shopify/callback`. It must exactly match the URL Laravel generates. For local OAuth, use a temporary HTTPS tunnel and set `APP_URL` to that URL; never use production credentials or production data in a public local preview.
3. Request `write_online_store_pages,write_files`. No theme, customer, order, checkout or product-writing scopes are requested.
4. Set `SHOPIFY_CLIENT_ID` and `SHOPIFY_CLIENT_SECRET` in the target environment's secret settings. Leave them blank until the app registration is ready. Refresh the application's configuration/deployment after adding them. Do not put values in Git or send them through chat.
5. In MarketingOwl, sign in as the workspace owner. Open Settings → Stores → Connect a store → Shopify, enter its original `*.myshopify.com` address, then Continue to Shopify. Approve access there. Credentials are stored in the existing encrypted `store_connections.secret` field; no database migration is needed.
6. Open a product's Pages tab, create a draft, bind approved content, choose this store and publish. Confirm the resulting `/pages/<handle>` in the actual storefront, then test an edit and rollback.

The connector requests expiring offline tokens, reads their expiry from Shopify, and refreshes them under a database row lock. Missing/revoked permission requires reconnecting. Reconnect preserves remote page IDs. A store is connected once across the application; reconnect the existing record rather than making duplicate installations. Removing a connection in MarketingOwl leaves published Shopify pages intact and does not uninstall the app in Shopify.

Images are uploaded through Shopify's staged-upload and Files APIs. Pending file IDs are retained so retrying after asynchronous processing reuses the upload. A taken page handle is rejected rather than overwriting another page. If a page-create answer is lost, retry searches the requested handle and reclaims a page only when its HTML carries this MarketingOwl page's marker.

## Verification boundary

A successful Admin API write does not mean a page is verified live. MarketingOwl fetches the public URL without an Admin API token and checks the page/version markers. A password-protected development storefront, missing page rendering in the theme, or stripped markers will remain unverified. Inspect the page in Shopify and the storefront; do not disable verification to make a development-store test appear live.

Automated tests fake Shopify's network responses; they prove local integration behavior, not Shopify account configuration or theme rendering. A real OAuth installation, upload, public render and rollback are required before customer rollout.

## Public-release requirements still outstanding

This implementation is a development/custom-pilot connector, not an approved public Shopify app. Before broad release, complete App Store registration/review, install-entry routing from the listing, required privacy webhooks and uninstall handling, Shopify app billing or an explicit Shopify exception, and the app listing/support requirements. Generic permission checks detect revoked access but are not a substitute for uninstall webhooks. Do not enable public distribution solely by adding credentials.

Deployment adds two optional secret variables and no schema changes. Existing WooCommerce and sandbox connections continue through their current adapters. Roll back by reverting the feature commit; leave existing published store pages alone. Reconnecting after a rollback is unsupported until this adapter is deployed again.

## Checks

```sh
php artisan test
vendor/bin/pint --test
npm run build
```

Also check the Settings connection form at desktop and mobile widths, its unavailable-configuration state, authorization success/failure, cross-workspace access, token refresh, image-processing retries, duplicate-address recovery, stale/public password-page verification and rollback.

## Primary references

- [Standalone OAuth](https://shopify.dev/docs/apps/build/authentication-authorization/authenticate-standalone-apps)
- [Access tokens](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens)
- [pageCreate](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/pageCreate) and [pageUpdate](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/pageUpdate)
- [Staged uploads](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/stagedUploadsCreate) and [fileCreate](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/fileCreate)
- [Distribution](https://shopify.dev/docs/apps/launch/distribution) and [App Store requirements](https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements)
