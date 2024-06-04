#!/usr/bin/env bash

# TODO - document this file
#rm -f ./database/database.sqlite
#touch ./database/database.sqlite

CLEAR_CACHE=no
DELETE_DB=yes
TEST_MIGRATIONS=no

while getopts ":c:d:t:" opt
   do
     case $opt in
        c ) CLEAR_CACHE=$OPTARG;;
        d ) DELETE_DB=$OPTARG;;
        t ) TEST_MIGRATIONS=$OPTARG;;
     esac
done

# clearing cache?
if [ ${CLEAR_CACHE} = 'no' ] && [ ${TEST_MIGRATIONS} = 'no' ]
then
    echo "Cache was not cleared"
else
    echo "Clearing cache"
    ./bash/clear.sh
fi

# deleting old database?
if [ ${DELETE_DB} = 'no' ] && [ ${TEST_MIGRATIONS} = 'no' ]
then
    echo "Database was not deleted"
    php artisan migrate:reset
else
    echo "Deleting database"
    rm -f ./database/database.sqlite
    touch ./database/database.sqlite
fi

# running migrations
php artisan migrate

# running test migrations?
if [ ${TEST_MIGRATIONS} = 'no' ]
then
    echo "Test migrations were not ran"
else
    echo "Running testing migrations"
    php artisan migrate --path /database/migrations/test
fi
