#!/usr/bin/env bash
# Install the WordPress test suite and create a test database.
#
# Usage:
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
#
# Example:
#   bin/install-wp-tests.sh berlindb_tests root '' localhost latest

if [ $# -lt 3 ]; then
	echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
	if command -v curl >/dev/null 2>&1; then
		if ! curl -fsSL "$1" > "$2"; then
			if ! curl -fsSL --noproxy '*' "$1" > "$2"; then
				if [[ "$1" == https://* ]]; then
					curl -fsSL --noproxy '*' "${1/https:\/\//http://}" > "$2"
				else
					return 1
				fi
			fi
		fi
	elif command -v wget >/dev/null 2>&1; then
		if ! wget -q -O "$2" "$1"; then
			if ! wget --no-proxy -q -O "$2" "$1"; then
				if [[ "$1" == https://* ]]; then
					wget --no-proxy -q -O "$2" "${1/https:\/\//http://}"
				else
					return 1
				fi
			fi
		fi
	else
		echo "Could not find curl or wget; install one of them to continue"
		exit 1
	fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		# version x.x.0 is not in the release archive
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	# https://api.wordpress.org/core/version-check/1.7/
	download https://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	LATEST_VERSION=$(grep -o '"version":"[^"]*"' /tmp/wp-latest.json | sed 's/"version":"//;s/"//' | head -n 1)
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be found"
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

set -e

install_wp() {
	if [ -d $WP_CORE_DIR ]; then
		return
	fi

	mkdir -p $WP_CORE_DIR

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		mkdir -p $TMPDIR/wordpress-trunk
		if [ ! -d $TMPDIR/wordpress-trunk/tests/phpunit ]; then
			svn export --quiet https://develop.svn.wordpress.org/trunk/ $TMPDIR/wordpress-trunk
		fi
		cd $TMPDIR/wordpress-trunk
		if [ ! -e wp-config.php ]; then
			cp wp-config-sample.php wp-config.php
		fi
	fi

	if [ $WP_VERSION == 'latest' ]; then
		local ARCHIVE_NAME='latest'
	elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
		# https://wordpress.org/wordpress-3.7.zip
		local ARCHIVE_NAME="wordpress-$WP_VERSION"
	fi

	download https://wordpress.org/${ARCHIVE_NAME}.zip $TMPDIR/wordpress.zip
	unzip -q $TMPDIR/wordpress.zip -d $TMPDIR
	mv $TMPDIR/wordpress/* $WP_CORE_DIR
}

install_test_suite() {
	# portable in-place argument for both BSD and GNU sed
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	# set up testing suite if it doesn't yet exist or is incomplete
	if [ ! -f "$WP_TESTS_DIR/includes/functions.php" ]; then
		mkdir -p $WP_TESTS_DIR

		# Install test suite files from WordPress develop
		rm -rf $WP_TESTS_DIR/{includes,data}
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/ $WP_TESTS_DIR/includes
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/ $WP_TESTS_DIR/data

		download https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
		# remove leading slash from WordPress path so it works on Windows
		sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
	fi
}

install_db() {
	if [ ${SKIP_DB_CREATE} = "true" ]; then
		return
	fi

	# parse DB_HOST for port or socket references
	local PARTS=(${DB_HOST//\:/ })
	local DB_HOSTNAME=${PARTS[0]}
	local DB_SOCK_OR_PORT=${PARTS[1]}
	local EXTRA=""

	if ! [ -z $DB_SOCK_OR_PORT ]; then
		if [ $(echo $DB_SOCK_OR_PORT | grep -e '^[0-9]\{1,\}$') ]; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		else
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		fi
	elif ! [ -z $DB_HOSTNAME ]; then
		EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
	fi

	# create database
	mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS"$EXTRA --ssl=FALSE
}

install_wp
install_test_suite
install_db
