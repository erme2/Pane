#!/usr/bin/env bash

# TODO - document this file

# refreshing database and cache (laravel)
./bash/refresh.sh -c yes -t yes

TEST_SEEDER=yes
UNDO_MIGRATIONS=yes

while getopts ":s:u:" opt
   do
     case $opt in
        s ) TEST_SEEDER=$OPTARG;;
        u ) UNDO_MIGRATIONS=$OPTARG;;
     esac
done



# creating the test tables to run tests
php artisan migrate --path /database/migrations/test

if [ ${TEST_SEEDER} = 'yes' ]
then
    echo "Seeding the test database"
    php artisan db:seed --class=TestSeeder
else
    echo "Test seeder was not run"
fi

# running the tests
php artisan test

if [ ${UNDO_MIGRATIONS} = 'no' ]
then
    echo "Test migrations were not undone"
else
    echo "Undoing testing migrations"
    php artisan migrate:rollback --path /database/migrations/test
fi
