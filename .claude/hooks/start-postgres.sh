#!/bin/bash
# Starts the cloud image's PostgreSQL cluster for the permission tests that CI
# runs against Postgres (ProductHubPermissionsTest, PagesPermissionsTest), and
# exports the same PRODUCT_HUB_TEST_DATABASE_URL that CI uses.
#
# Runs on every session start, resume, clear and compact, because a resumed
# session can land in a fresh container where Postgres is stopped. Each step is
# a no-op when already done. It never fails the session: without Postgres those
# tests skip themselves, as they do on a laptop.
set -uo pipefail

[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0

db=marketingowl_permissions_test

start_test_postgres() {
    command -v pg_lsclusters >/dev/null || return 0
    [ "$(id -u)" = 0 ] || return 0

    local version cluster port _
    read -r version cluster port _ < <(pg_lsclusters --no-header | head -n 1) || return 0
    [ -n "${port:-}" ] || return 0

    # Already running exits non-zero; readiness is checked below.
    pg_ctlcluster "$version" "$cluster" start >/dev/null 2>&1
    local tries=0
    until pg_isready -q -h 127.0.0.1 -p "$port"; do
        tries=$((tries + 1))
        [ "$tries" -lt 30 ] || return 1
        sleep 1
    done

    # Local, disposable cluster: same credentials as the CI service container.
    runuser -u postgres -- psql -q -p "$port" -c "ALTER USER postgres PASSWORD 'postgres'" || return 1
    if ! runuser -u postgres -- psql -p "$port" -tAc "SELECT 1 FROM pg_database WHERE datname = '$db'" | grep -q 1; then
        runuser -u postgres -- createdb -p "$port" "$db" || return 1
    fi

    local line="export PRODUCT_HUB_TEST_DATABASE_URL=postgresql://postgres:postgres@127.0.0.1:${port}/${db}"
    if [ -n "${CLAUDE_ENV_FILE:-}" ] && ! grep -qxF "$line" "$CLAUDE_ENV_FILE" 2>/dev/null; then
        echo "$line" >> "$CLAUDE_ENV_FILE"
    fi
}

start_test_postgres || echo "start-postgres: PostgreSQL did not start; the PostgreSQL permission tests will be skipped." >&2
exit 0
