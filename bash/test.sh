#!/usr/bin/env bash

# TODO - document this file

# refreshing database and cache (laravel)
./bash/refresh.sh -c yes -t yes

TEST_SEEDER=yes
UNDO_MIGRATIONS=yes

echo "Running tests with seeder: ${TEST_SEEDER} (-s) and undo migrations: ${UNDO_MIGRATIONS} (-u)"

while getopts ":s:u:" opt
   do
     # shellcheck disable=SC2220
     case $opt in
        s ) TEST_SEEDER=$OPTARG;;
        u ) UNDO_MIGRATIONS=$OPTARG;;
     esac
done



# creating the test tables to run tests
php artisan migrate --path /database/migrations/test

if [ ${TEST_SEEDER} = 'yes' ]
then
    echo "Seeding the test database (-s yes default)"
    php artisan db:seed --class=TestTableSeeder
else
    echo "Test seeder NOT run (-s no)"
fi

# running the tests
php artisan test

if [ ${UNDO_MIGRATIONS} = 'no' ]
then
    echo "Test migrations NOT rolled back (-u no default)"
else
    echo "Rolling back test migrations (-u yes)"
    php artisan migrate:rollback --path /database/migrations/test
fi
