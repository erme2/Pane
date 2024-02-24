#!/usr/bin/env bash

# TO DO - document this file

./bash/clear.sh
./bash/refresh.sh -d yes -c yes

STOP_ON_FAIL=no

while getopts ":s:" opt
   do
     case $opt in
        s ) STOP_ON_FAIL='yes';;
     esac
done

# creating the test tables to run tests
php artisan migrate --path /database/migrations/test

if [ ${STOP_ON_FAIL} = 'no' ]
then
    php artisan test
else
    php artisan test --stop-on-failure
fi


# removing the test tables once the tests are done
php artisan migrate:rollback --path /database/migrations/test
