#!/usr/bin/env bash

#
# TYPO3 core test runner based on docker or podman.
#
if [ "${CI}" != "true" ]; then
    trap 'echo "runTests.sh SIGINT signal emitted";cleanUp;exit 2' SIGINT
fi

printSummary() {
    cleanUp

    echo "" >&2
    echo "###########################################################################" >&2
    echo "Result of ${TEST_SUITE}" >&2
    echo "Container runtime: ${CONTAINER_BIN}" >&2
    echo "Container suffix: ${SUFFIX}"
    echo "PHP: ${PHP_VERSION}" >&2
    if [[ ${SUITE_EXIT_CODE} -eq 0 ]]; then
        echo "SUCCESS" >&2
    else
        echo "FAILURE" >&2
    fi
    echo "###########################################################################" >&2
    echo "" >&2
    exit ${SUITE_EXIT_CODE}
}

waitFor() {
    local HOST=${1}
    local PORT=${2}
    local TESTCOMMAND="
        COUNT=0;
        while ! nc -z ${HOST} ${PORT}; do
            if [ \"\${COUNT}\" -gt 30 ]; then
              echo \"Can not connect to ${HOST} port ${PORT}. Aborting.\";
              exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
    "
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name wait-for-${SUFFIX} ${IMAGE_ALPINE} /bin/sh -c "${TESTCOMMAND}"
    if [[ $? -gt 0 ]]; then
        kill -SIGINT -$$
    fi
}

cleanUp() {
    echo "Remove container for network \"${NETWORK}\""
    ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps --filter network=${NETWORK} --format='{{.Names}}')
    for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
        ${CONTAINER_BIN} kill ${ATTACHED_CONTAINER} >/dev/null
    done
    if [ ${CONTAINER_BIN} = "docker" ]; then
        ${CONTAINER_BIN} network rm ${NETWORK} >/dev/null
    else
        ${CONTAINER_BIN} network rm -f ${NETWORK} >/dev/null
    fi
}

loadHelp() {
    # Load help text into $HELP
    read -r -d '' HELP <<EOF
EXT:s3_driver test runner. Execute unit tests and other test suites in a
container based test environment. Handles execution of single test files, sending
xdebug information to a local IDE and more.

Usage: $0 [options] [file]

Options:
    -s <...>
        Specifies the test suite to run
            - cgl: test and fix all php files
            - composer: "composer" command dispatcher, to execute various composer commands
            - composerInstall: install the dependencies of the TYPO3 version given with -t
            - composerValidate: "composer validate"
            - functional: functional tests against an S3 API server (adobe/s3mock)
            - lint: PHP linting
            - phpstan: phpstan tests
            - phpstanGenerateBaseline: regenerate phpstan baseline, handy after phpstan updates
            - unit (default): PHP unit tests

    -b <docker|podman>
        Container environment:
            - podman (default)
            - docker

    -p <8.5>
        Specifies the PHP minor version to be used
            - 8.5 (default): use PHP 8.5, required by TYPO3 v15

    -t <15>
        Specifies the TYPO3 Core version to be used - Only with -s composerInstall|composerValidate|phpstan
            - 15 (default): Use TYPO3 v15.0 (development version)

    -x
        Only with -s functional|unit
        Send information to host instance for test or system under test break points. This is especially
        useful if a local PhpStorm instance is listening on default xdebug port 9003. A different port
        can be selected with -y

    -y <port>
        Send xdebug information to a different port than default 9003 if an IDE like PhpStorm
        is not listening on default port.

    -n
        Only with -s cgl
        Activate dry-run in CGL check that does not actively change files and only prints broken ones.

    -u
        Update existing typo3/core-testing-*:latest container images and remove dangling local volumes.
        New images are published once in a while and only the latest ones are supported by core testing.
        Use this if weird test errors occur. Also removes obsolete image versions of typo3/core-testing-*.

    -h
        Show this help.

Examples:
    # Install TYPO3 v15 and run all unit tests
    ./Build/Scripts/runTests.sh -s composerInstall
    ./Build/Scripts/runTests.sh -s unit

    # Run the functional tests against a MinIO container
    ./Build/Scripts/runTests.sh -s functional

    # Run phpstan
    ./Build/Scripts/runTests.sh -s phpstan
EOF
}


# Test if docker exists, else exit out with error
if ! type "docker" >/dev/null 2>&1 && ! type "podman" >/dev/null 2>&1; then
    echo "This script relies on docker or podman. Please install" >&2
    exit 1
fi

# Go to the directory this script is located, so everything else is relative
# to this dir, no matter from where this script is called, then go up two dirs.
THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "$THIS_SCRIPT_DIR" || exit 1
cd ../../ || exit 1
CORE_ROOT="${PWD}"

# Option defaults
TEST_SUITE="help"
COMPOSER_ROOT_VERSION="0.1.0"
PHP_VERSION="8.5"
PHP_XDEBUG_ON=0
PHP_XDEBUG_PORT=9003
TYPO3="15"
CGLCHECK_DRY_RUN=""
CONTAINER_BIN=""
CI_PARAMS="${CI_PARAMS:-}"
CONTAINER_INTERACTIVE="-it --init"
CONTAINER_HOST="host.docker.internal"
HOST_UID=$(id -u)
HOST_PID=$(id -g)
USERSET=""
CI_JOB_ID=${CI_JOB_ID:-}
SUFFIX=$(echo $RANDOM)
if [ ${CI_JOB_ID} ]; then
    SUFFIX="${CI_JOB_ID}-${SUFFIX}"
fi
NETWORK="b13-s3-driver-${SUFFIX}"

# Option parsing updates above default vars
# Reset in case getopts has been used previously in the shell
OPTIND=1
# Array for invalid options
INVALID_OPTIONS=()
# Simple option parsing based on getopts (! not getopt)
while getopts "b:s:t:p:xy:nhu" OPT; do
    case ${OPT} in
        s)
            TEST_SUITE=${OPTARG}
            ;;
        b)
            if ! [[ ${OPTARG} =~ ^(docker|podman)$ ]]; then
                INVALID_OPTIONS+=("${OPTARG}")
            fi
            CONTAINER_BIN=${OPTARG}
            ;;
        t)
            TYPO3=${OPTARG}
            if ! [[ ${TYPO3} =~ ^(15)$ ]]; then
                INVALID_OPTIONS+=("${OPTARG}")
            fi
          ;;
        p)
            PHP_VERSION=${OPTARG}
            if ! [[ ${PHP_VERSION} =~ ^(8.5)$ ]]; then
                INVALID_OPTIONS+=("${OPTARG}")
            fi
            ;;
        x)
            PHP_XDEBUG_ON=1
            ;;
        y)
            PHP_XDEBUG_PORT=${OPTARG}
            ;;
        n)
            CGLCHECK_DRY_RUN="-n"
            ;;
        h)
            TEST_SUITE="help"
            ;;
        u)
            TEST_SUITE=update
            ;;
        \?)
            INVALID_OPTIONS+=("${OPTARG}")
            ;;
        :)
            INVALID_OPTIONS+=("${OPTARG}")
            ;;
    esac
done

# Exit on invalid options
if [ ${#INVALID_OPTIONS[@]} -ne 0 ]; then
    echo "Invalid option(s):" >&2
    for I in "${INVALID_OPTIONS[@]}"; do
        echo "-"${I} >&2
    done
    echo >&2
    echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
    exit 1
fi

# Dependencies of the TYPO3 version to test against, see -s composerInstall
case ${TYPO3} in
    15)
        CORE_CONSTRAINT="15.0.x-dev"
        TESTING_FRAMEWORK_CONSTRAINT="dev-main"
        ;;
esac
COMPOSER_INSTALL_COMMAND="composer update --no-progress --no-interaction --with-all-dependencies"
COMPOSER_INSTALL_COMMAND="${COMPOSER_INSTALL_COMMAND} --with typo3/cms-core:${CORE_CONSTRAINT}"
COMPOSER_INSTALL_COMMAND="${COMPOSER_INSTALL_COMMAND} --with typo3/testing-framework:${TESTING_FRAMEWORK_CONSTRAINT}"

if [ "${CI}" == "true" ]; then
    # ENV var "CI" is set by gitlab-ci. Use it to force some CI details.
    CONTAINER_INTERACTIVE=""
elif [ ! -t 0 ] || [ ! -t 1 ]; then
    # If stdin or stdout is not a TTY (e.g. a script runner, pipe, or non-interactive shell),
    # drop the interactive "-it" flags automatically to avoid podman warning "The input device
    # is not a TTY." and docker failure, and to keep redirected output free of TTY control characters.
    # Keep "--init" so the PID 1 init process still forwards signals (e.g. ctrl-c) to the test process.
    CONTAINER_INTERACTIVE="--init"
fi

# determine default container binary to use: 1. podman 2. docker
if [[ -z "${CONTAINER_BIN}" ]]; then
    if type "podman" >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    elif type "docker" >/dev/null 2>&1; then
        CONTAINER_BIN="docker"
    fi
fi

if [ $(uname) != "Darwin" ] && [ ${CONTAINER_BIN} = "docker" ]; then
    # Run docker jobs as current user to prevent permission issues. Not needed with podman.
    USERSET="--user $HOST_UID"
fi

if ! type ${CONTAINER_BIN} >/dev/null 2>&1; then
    echo "Selected container environment \"${CONTAINER_BIN}\" not found. Please install or use -b option to select one." >&2
    exit 1
fi

IMAGE_PHP="ghcr.io/typo3/core-testing-$(echo "php${PHP_VERSION}" | sed -e 's/\.//'):latest"
IMAGE_ALPINE="docker.io/alpine:3.20"
IMAGE_S3MOCK="docker.io/adobe/s3mock:5"

# Remove handled options and leaving the rest in the line, so it can be passed raw to commands
shift $((OPTIND - 1))

# Create .cache dir: composer and various npm jobs need this.
mkdir -p .cache
mkdir -p .Build/Web/typo3temp/var/tests

${CONTAINER_BIN} network create ${NETWORK} >/dev/null

if [ ${CONTAINER_BIN} = "docker" ]; then
    # docker needs the add-host for xdebug remote debugging. podman has host.container.internal built in
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host "${CONTAINER_HOST}:host-gateway" ${USERSET} -v ${CORE_ROOT}:${CORE_ROOT} -w ${CORE_ROOT}"
    TMPFS_MOUNT_OPTIONS="rw,noexec,nosuid,uid=${HOST_UID},gid=${HOST_PID}"
else
    # podman
    CONTAINER_HOST="host.containers.internal"
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm --network ${NETWORK} -v ${CORE_ROOT}:${CORE_ROOT} -w ${CORE_ROOT}"
    TMPFS_MOUNT_OPTIONS="rw,noexec,nosuid"
fi

if [[ "${CI}" == "true" ]]; then
    CONTAINER_COMMON_PARAMS="${CONTAINER_COMMON_PARAMS} ${CONTAINER_COMMON_PARAMS_CI:-}"
fi

if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
    XDEBUG_MODE="-e XDEBUG_MODE=off"
    XDEBUG_CONFIG=" "
    PHP_FPM_OPTIONS="-d xdebug.mode=off"
else
    XDEBUG_MODE="-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=foo"
    XDEBUG_CONFIG="client_port=${PHP_XDEBUG_PORT} client_host=${CONTAINER_HOST}"
    PHP_FPM_OPTIONS="-d xdebug.mode=debug -d xdebug.start_with_request=yes -d xdebug.client_host=${CONTAINER_HOST} -d xdebug.client_port=${PHP_XDEBUG_PORT} -d memory_limit=256M"
fi

# Suite execution
case ${TEST_SUITE} in
    cgl)
          # Active dry-run for cgl needs not "-n" but specific options
          if [ -n "${CGLCHECK_DRY_RUN}" ]; then
              CGLCHECK_DRY_RUN="--dry-run --diff"
          fi
          COMMAND="php -dxdebug.mode=off .Build/vendor/friendsofphp/php-cs-fixer/php-cs-fixer fix -v ${CGLCHECK_DRY_RUN} --config=Build/php-cs-fixer.php --using-cache=no"
          ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-${SUFFIX} ${IMAGE_PHP} ${COMMAND}
          SUITE_EXIT_CODE=$?
        ;;
    composer)
          COMMAND=(composer "$@")
          ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
          SUITE_EXIT_CODE=$?
        ;;
    composerInstall)
          rm -rf .Build/vendor .Build/bin composer.lock
          ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-install-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "
            php -v | grep '^PHP';
            ${COMPOSER_INSTALL_COMMAND}
          "
          SUITE_EXIT_CODE=$?
        ;;
    composerValidate)
          ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-validate-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "
            php -v | grep '^PHP';
            composer validate
          "
          SUITE_EXIT_CODE=$?
        ;;
    functional)
        # An S3 API server for the tests; the file index of TYPO3 is fine with sqlite
        ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name s3-func-${SUFFIX} --network ${NETWORK} -d ${IMAGE_S3MOCK} >/dev/null
        waitFor s3-func-${SUFFIX} 9090
        rm -rf "${CORE_ROOT}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs"
        mkdir -p "${CORE_ROOT}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/"
        CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite --tmpfs ${CORE_ROOT}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/:${TMPFS_MOUNT_OPTIONS}"
        CONTAINERPARAMS="${CONTAINERPARAMS} -e S3_TEST_ENDPOINT=http://s3-func-${SUFFIX}:9090"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} .Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml "$@"
        SUITE_EXIT_CODE=$?
        ;;
    help)
            loadHelp
            echo "${HELP}"
            exit 0
        ;;
    lint)
        COMMAND='php -v | grep '^PHP'; find . -name \\*.php ! -path "./.Build/\\*" -print0 | xargs -0 -n1 -P4 php -dxdebug.mode=off -l >/dev/null'
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-php-${SUFFIX} ${IMAGE_PHP} bash -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    phpstan)
        COMMAND=(php -dxdebug.mode=off .Build/bin/phpstan analyse -c Build/phpstan${TYPO3}.neon --no-progress --no-interaction --memory-limit 4G "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-${SUFFIX} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    phpstanGenerateBaseline)
        COMMAND=(php -dxdebug.mode=off .Build/bin/phpstan analyse -c Build/phpstan${TYPO3}.neon --no-progress --no-interaction --memory-limit 4G --generate-baseline=Build/phpstan-baseline-${TYPO3}.neon "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-baseline-${SUFFIX} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    unit)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} .Build/bin/phpunit -c Build/phpunit/UnitTests.xml "$@"
        SUITE_EXIT_CODE=$?
        ;;
    update)
        # prune unused, dangling local volumes
        echo "> prune unused, dangling local volumes"
        ${CONTAINER_BIN} volume ls -q -f driver=local -f dangling=true | awk '$0 ~ /^[0-9a-f]{64}$/ { print }' | xargs -I {} ${CONTAINER_BIN} volume rm {}
        echo ""
        # pull typo3/core-testing-*:latest versions of those ones that exist locally
        echo "> pull ${TYPO3_IMAGE_PREFIX}typo3/core-testing-*:latest versions of those ones that exist locally"
        ${CONTAINER_BIN} images ${TYPO3_IMAGE_PREFIX}typo3/core-testing-*:latest --format "{{.Repository}}:latest" | xargs -I {} ${CONTAINER_BIN} pull {}
        echo ""
        # remove "dangling" typo3/core-testing-* images (those tagged as <none>)
        echo "> remove \"dangling\" ${TYPO3_IMAGE_PREFIX}typo3/core-testing-* images (those tagged as <none>)"
        ${CONTAINER_BIN} images ${TYPO3_IMAGE_PREFIX}typo3/core-testing-* --filter "dangling=true" --format "{{.ID}}" | xargs -I {} ${CONTAINER_BIN} rmi -f {}
        echo ""
        ;;
    *)
        loadHelp
        echo "Invalid -s option argument ${TEST_SUITE}" >&2
        echo >&2
        echo "${HELP}" >&2
        exit 1
        ;;
esac

# Cleanup, print summary && exit with exitcode
printSummary
