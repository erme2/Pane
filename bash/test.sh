#!/usr/bin/env bash


./bash/clear.sh

# creating the test tables to run tests
php artisan migrate --path /database/migrations/test

php artisan test

# removing the test tables once the tests are done
php artisan migrate:rollback --path /database/migrations/test
