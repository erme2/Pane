#!/usr/bin/env bash

# TODO - document this file

SHOW_OPTIONS=yes
TEST_SEEDER=yes
UNDO_MIGRATIONS=yes
VERBOSE=yes
REFRESH_DB=yes
STOP_ON_FAILURE=yes

while getopts ":f:o:s:u:v:" opt
   do
     case $opt in
        f ) STOP_ON_FAILURE=$OPTARG;;
        o ) SHOW_OPTIONS=$OPTARG;;
        r ) REFRESH_DB=$OPTARG;;
        s ) TEST_SEEDER=$OPTARG;;
        u ) UNDO_MIGRATIONS=$OPTARG;;
        v ) VERBOSE=$OPTARG;;
     esac
done

if [ ${SHOW_OPTIONS} = 'yes' ]
then
    echo "Running tests with seeder:"
    echo -e "\t show options: ${SHOW_OPTIONS} (-o)"
    echo -e "\t stop on failure: ${REFRESH_DB} (-f)"
    echo -e "\t refresh DB: ${REFRESH_DB} (-r)"
    echo -e "\t run Seeder: ${TEST_SEEDER} (-s)"
    echo -e "\t verbose: ${VERBOSE} (-v)"
    echo -e "\t undo (remove) test seeder: ${UNDO_MIGRATIONS} (-u) "

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
    ./bash/refresh.sh -c yes -t yes -v no -o yes
fi

# creating the test tables to run tests
php artisan migrate --path /database/migrations/test

if [ ${TEST_SEEDER} = 'yes' ]
then
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Seeding the test database (-s yes default)"
    fi
    php artisan db:seed --class=TestTableSeeder
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



vendor/bin/phpunit --testdox${SOF}
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
    php artisan migrate:rollback --path /database/migrations/test
fi
