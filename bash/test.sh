#!/usr/bin/env bash

# TODO - document this file

CONF_FILE="testing"
REFRESH_DB=yes
SHOW_OPTIONS=yes
STOP_ON_FAILURE=yes
TEST_SEEDER=yes
UNDO_MIGRATIONS=yes
VERBOSE=yes

while getopts ":c:f:o:s:u:v:" opt
   do
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

if [ ${VERBOSE} = 'yes' ]
then
    echo "Running tests with seeder:"
    echo -e "\t show options: ${SHOW_OPTIONS} (-o)"
    echo -e "\t conf file: ${CONF_FILE} / ${LOAD_CONFIG_FILE} (-c)"
    echo -e "\t stop on failure: ${STOP_ON_FAILURE} (-f)"
    echo -e "\t refresh DB: ${REFRESH_DB} (-r)"
    echo -e "\t run Seeder: ${TEST_SEEDER} (-s)"
    echo -e "\t verbose: ${VERBOSE} (-v)"
    echo -e "\t undo (remove) test seeder: ${UNDO_MIGRATIONS} (-u) "
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
    ./bash/refresh.sh -c yes -t yes -v ${VERBOSE} -o ${SHOW_OPTIONS} -f ${CONF_FILE}
else
    echo "Database NOT refreshed ${REFRESH_DB} (-r no)"
fi

# creating the test tables to run tests
php artisan migrate --path /database/migrations/test ${LOAD_CONFIG_FILE}

if [ ${TEST_SEEDER} = 'yes' ]
then
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Seeding the test database (-s yes default)"
    fi
    php artisan db:seed --class=TestTableSeeder ${LOAD_CONFIG_FILE}
else
    echo "Test seeder NOT run (-s no)"
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
php artisan test --testdox${SOF} ${LOAD_CONFIG_FILE}
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
    php artisan migrate:rollback --path /database/migrations/test ${LOAD_CONFIG_FILE}
fi
