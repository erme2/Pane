#!/usr/bin/env bash

rm -f ./database/database.sqlite

./bash/clear.sh

php artisan migrate

