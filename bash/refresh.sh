#!/usr/bin/env bash

#rm -f ./database/database.sqlite
#touch ./database/database.sqlite

CLEAR_CACHE=no
DELETE_DB=no

while getopts ":c:d:" opt
   do
     case $opt in
        c ) CLEAR_CACHE=$OPTARG;; # update in in vars
        d ) DELETE_DB=$OPTARG;; # update in in vars
     esac
done

if [ ${CLEAR_CACHE} = 'no' ]
then
    echo "Cache was not cleared"
else
    echo "Clearing cache"
    ./bash/clear.sh
fi

if [ ${DELETE_DB} = 'no' ]
then
    echo "Database was not deleted"
    php artisan migrate:reset
else
    echo "Deleting database"
    rm -f ./database/database.sqlite
    touch ./database/database.sqlite
fi

php artisan migrate
