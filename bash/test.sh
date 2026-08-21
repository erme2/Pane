#!/usr/bin/env bash

CONF_FILE="testing"
REFRESH_DB=yes
SHOW_OPTIONS=yes
STOP_ON_FAILURE=yes
TEST_SEEDER=yes
UNDO_MIGRATIONS=yes
VERBOSE=yes

while getopts ":c:f:o:r:s:u:v:" opt
   do
     # shellcheck disable=SC2220
     case $opt in
        c ) CONF_FILE=$OPTARG;;
        f ) STOP_ON_FAILURE=$OPTARG;;
        o ) SHOW_OPTIONS=$OPTARG;;
        r ) REFRESH_DB=$OPTARG;;
        s ) TEST_SEEDER=$OPTARG;;
        u ) UNDO_MIGRATIONS=$OPTARG;;
        v ) VERBOSE=$OPTARG;;
     esac
done

LOAD_CONFIG_FILE="--env=${CONF_FILE}"

if [ -z "${APP_KEY:-}" ]; then
    APP_KEY=$(php -r 'echo implode("", ["base", "64"]).":".base64_encode(random_bytes(32));')
    export APP_KEY
fi

if [ ${VERBOSE} = 'yes' ]
then
    echo "Running tests with seeder:"
    echo "\t show options: ${SHOW_OPTIONS} (-o)"
    echo "\t conf file: ${CONF_FILE} / ${LOAD_CONFIG_FILE} (-c)"
    echo "\t stop on failure: ${STOP_ON_FAILURE} (-f)"
    echo "\t refresh DB: ${REFRESH_DB} (-r)"
    echo "\t run Seeder: ${TEST_SEEDER} (-s)"
    echo "\t verbose: ${VERBOSE} (-v)"
    echo "\t undo (remove) test seeder: ${UNDO_MIGRATIONS} (-u) "
fi

if [ ${SHOW_OPTIONS} = 'yes' ]
then
    read -p "Do you want to run this script? (y/n): " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        echo "Exiting without running the script."
        exit 0
    fi
fi

if [ ${REFRESH_DB} = 'yes' ]
then
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Refreshing the database (-r yes default)"
    fi
    ./bash/refresh.sh -c yes -t yes -v ${VERBOSE} -o ${SHOW_OPTIONS} -f ${CONF_FILE} -s ${TEST_SEEDER}
else
    echo "Database NOT refreshed ${REFRESH_DB} (-r no)"
fi

# running the tests
if [ ${STOP_ON_FAILURE} = 'no' ]
then
    echo "Tests will NOT stop on failure (-f no default)"
    SOF=""
else
    echo "Tests will stop on failure (-f yes)"
    SOF=" --stop-on-failure"
fi


#vendor/bin/phpunit --testdox${SOF}
php artisan test ${LOAD_CONFIG_FILE} --testdox${SOF}
TEST_EXIT_CODE=$?

if [ $TEST_EXIT_CODE -ne 0 ]; then
    echo "Tests failed with exit code $TEST_EXIT_CODE (Test migrations NOT rolled back)"
    exit $TEST_EXIT_CODE
fi

if [ ${UNDO_MIGRATIONS} = 'no' ]
then
    echo "Test migrations NOT rolled back (-u no default)"
else
    echo "Rolling back test migrations (-u yes)"
    php artisan migrate:rollback ${LOAD_CONFIG_FILE} --path /database/migrations/test
fi
