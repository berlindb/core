#!/usr/bin/env bash
# Container-side test runner. Called by docker-compose-phpunit.yml.
# Do not run this script directly on your host machine.

set -ex

DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-berlindb_tests}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
WP_VERSION="${WP_VERSION:-latest}"

# Use a path distinct from /tmp/wordpress so the install script's
# unzip+mv doesn't collide with the mkdir it creates first.
export WP_CORE_DIR=/tmp/wp-core

composer install --no-interaction --prefer-dist

bin/install-wp-tests.sh "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST" "$WP_VERSION"

if [[ -n "$PHPUNIT_ARGS" ]]; then
	# shellcheck disable=SC2086
	vendor/bin/phpunit $PHPUNIT_ARGS
else
	vendor/bin/phpunit
fi
