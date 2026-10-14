#!/bin/bash
# Prepares a Claude Code cloud session to run the app locally:
# PHP + JS dependencies, a sqlite database with demo data, and built assets.
# Sign in at http://127.0.0.1:8000 as demo@marketingowl.test / password
# after `php artisan serve`.
set -euo pipefail

[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0
cd "$CLAUDE_PROJECT_DIR"

# composer.lock targets PHP 8.4; cloud images may ship 8.3. Resolve a
# compatible vendor tree locally and keep the committed lock untouched.
if ! composer install --no-interaction --prefer-dist --no-progress; then
    composer update -W --no-interaction --prefer-dist --no-progress
    git checkout -- composer.lock
fi

npm ci --no-audit --no-fund

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=.\+' .env || php artisan key:generate --no-interaction
for bin in ffmpeg ffprobe; do
    key=$(echo "$bin" | tr '[:lower:]' '[:upper:]')_PATH
    path=$(command -v "$bin" || true)
    if [ -n "$path" ]; then sed -i "s#^${key}=.*#${key}=${path}#" .env; fi
done

touch database/database.sqlite
# Pin Artisan to the local sqlite file so an inherited DB_* setting can never
# point these commands at a shared database.
export DB_CONNECTION=sqlite DB_DATABASE="$CLAUDE_PROJECT_DIR/database/database.sqlite"
php artisan migrate --force --no-interaction
php artisan db:seed --class=DemoSeeder --force --no-interaction

npm run build
