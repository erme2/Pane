#!/usr/bin/env bash

#rm -f ./database/database.sqlite
#touch ./database/database.sqlite

./bash/clear.sh

php artisan migrate:reset
php artisan migrate
