git #!/usr/bin/env bash

# TODO - document this file
#rm -f ./database/database.sqlite
#touch ./database/database.sqlite

CLEAR_CACHE=no
DELETE_DB=yes
SEEDING=no
TEST_MIGRATIONS=no

while getopts ":c:d:s:t:" opt
   do
     # shellcheck disable=SC2220
     case $opt in
        c ) CLEAR_CACHE=$OPTARG;;
        d ) DELETE_DB=$OPTARG;;
        s ) SEEDING=$OPTARG;;
        t ) TEST_MIGRATIONS=$OPTARG;;
     esac
done

echo "@ Running refresh.sh with options: CLEAR_CACHE=${CLEAR_CACHE} (-c), DELETE_DB=${DELETE_DB} (-d), SEEDING=${SEEDING} (-s)  TEST_MIGRATIONS=${TEST_MIGRATIONS} (-t)"

# clearing cache?
if [ ${CLEAR_CACHE} = 'no' ] && [ ${TEST_MIGRATIONS} = 'no' ]
then
    echo "Cache NOT cleared (-c no default)"
else
    echo "Clearing cache (-c yes)"
    ./bash/clear.sh
fi

# deleting old database?
if [ ${DELETE_DB} = 'no' ] && [ ${TEST_MIGRATIONS} = 'no' ]
then
    echo "Database NOT deleted (-d no)"
    php artisan migrate:reset
else
    echo "Deleting database (-d yes default)"
    rm -f ./database/database.sqlite
    touch ./database/database.sqlite
fi

# running migrations
php artisan migrate

# running test migrations?
if [ ${TEST_MIGRATIONS} = 'no' ]
then
    echo "Test migrations NOT ran (-t no default)"
else
    echo "Running testing migrations (-t yes)"
    php artisan migrate --path /database/migrations/test
fi

# seeding database?
if [ ${SEEDING} = 'no' ]
then
    echo "Database NOT seeded (-s no default)"
else
    echo "Seeding database (-s yes)"
    php artisan db:seed --class=TestTableSeeder
fi
