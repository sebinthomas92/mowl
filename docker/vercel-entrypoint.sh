#!/bin/sh
set -eu

mkdir -p \
    /tmp/marketing-owl-storage/app/private \
    /tmp/marketing-owl-storage/framework/cache \
    /tmp/marketing-owl-storage/framework/sessions \
    /tmp/marketing-owl-storage/framework/views \
    /tmp/marketing-owl-storage/logs \
    /tmp/frankenphp-config \
    /tmp/frankenphp-data

exec docker-php-entrypoint "$@"
