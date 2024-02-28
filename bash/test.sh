#!/usr/bin/env bash

# TODO - document this file

# refreshing database and cache (laravel)
./bash/refresh.sh -d yes -c yes

UNDO_MIGRATIONS=yes

while getopts ":u:" opt
   do
     case $opt in
        u ) UNDO_MIGRATIONS=$OPTARG;;
     esac
done



# creating the test tables to run tests
php artisan migrate --path /database/migrations/test

# running the tests
php artisan test

if [ ${UNDO_MIGRATIONS} = 'no' ]
then
    echo "Test migrations were not undone"
else
    echo "Undoing testing migrations"
    php artisan migrate:rollback --path /database/migrations/test
fi
