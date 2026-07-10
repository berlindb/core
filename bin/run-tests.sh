#!/usr/bin/env bash
# Run the BerlinDB PHPUnit test suite in Docker.
#
# Usage:
#   bin/run-tests.sh [options] [-- phpunit-args...]
#
# Options:
#   -p <version>   PHP version to use (default: 8.2)
#   -w <version>   WordPress version to use (default: latest)
#   -d <version>   MariaDB version to use (default: 10.2)
#   -h             Show this help text
#
# Examples:
#   bin/run-tests.sh
#   bin/run-tests.sh -p 8.1
#   bin/run-tests.sh -p 8.2 -w 6.4
#   bin/run-tests.sh -w 6.7.2
#   bin/run-tests.sh -d 11.8
#   bin/run-tests.sh -- --filter ColumnTest
#   bin/run-tests.sh -- --testdox

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"

TEST_PHP_VERSION="8.2"
WP_VERSION="latest"
MARIADB_VERSION="10.2"
PHPUNIT_ARGS=()

while [[ $# -gt 0 ]]; do
	case "$1" in
		-p)
			TEST_PHP_VERSION="$2"
			shift 2
			;;
		-w)
			WP_VERSION="$2"
			shift 2
			;;
		-d)
			MARIADB_VERSION="$2"
			shift 2
			;;
		-h|--help)
			sed -n '2,21p' "$0" | sed 's/^# \?//'
			exit 0
			;;
		--)
			shift
			PHPUNIT_ARGS=("$@")
			break
			;;
		*)
			echo "Unknown option: $1" >&2
			exit 1
			;;
	esac
done

export TEST_PHP_VERSION
export WP_VERSION
export MARIADB_VERSION
export COMPOSE_PROJECT_NAME="berlindb_tests_$(openssl rand -hex 4)"
export PHPUNIT_ARGS="${PHPUNIT_ARGS[*]}"

cd "$REPO_DIR"

cleanup() {
	# --rmi local also removes the image built for this run's unique project name,
	# which the random COMPOSE_PROJECT_NAME (above) would otherwise leak one of per
	# run. down() removes containers/volumes/networks but not images without it.
	docker compose -f docker-compose-phpunit.yml down --volumes --remove-orphans --rmi local 2>/dev/null || true
}
trap cleanup EXIT

docker compose -f docker-compose-phpunit.yml build php
docker compose -f docker-compose-phpunit.yml run --rm -e PHPUNIT_ARGS php
