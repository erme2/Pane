#!/usr/bin/env bash

# TODO - document this file

# refreshing database and cache (laravel)
./bash/refresh.sh -d yes -c yes

# creating the test tables to run tests
php artisan migrate --path /database/migrations/test

# running the tests
php artisan test

# removing the test tables once the tests are done
php artisan migrate:rollback --path /database/migrations/test
