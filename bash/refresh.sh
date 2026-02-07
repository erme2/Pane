git #!/usr/bin/env bash

# TODO - document this file
#rm -f ./database/database.sqlite
#touch ./database/database.sqlite

CLEAR_CACHE=no
DELETE_DB=yes
SEEDING=no
TEST_MIGRATIONS=no
VERBOSE=yes
SHOW_OPTIONS=no

while getopts ":c:d:o:s:t:v:" opt
   do
     # shellcheck disable=SC2220
     case $opt in
        c ) CLEAR_CACHE=$OPTARG;;
        d ) DELETE_DB=$OPTARG;;
        o ) SHOW_OPTIONS='yes';;
        s ) SEEDING=$OPTARG;;
        t ) TEST_MIGRATIONS=$OPTARG;;
        v ) VERBOSE=$OPTARG;;
     esac
done

if [ ${SHOW_OPTIONS} = 'yes' ]
then
    echo "@ Running refresh.sh with options:"
    echo -e "\t CLEAR_CACHE=${CLEAR_CACHE} (-c),"
    echo -e "\t DELETE_DB=${DELETE_DB} (-d),"
    echo -e "\t SEEDING=${SEEDING} (-s),"
    echo -e "\t TEST_MIGRATIONS=${TEST_MIGRATIONS} (-t)"
    exit 0
fi

# clearing cache?
if [ ${CLEAR_CACHE} = 'no' ] && [ ${TEST_MIGRATIONS} = 'no' ]
then
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Cache NOT cleared (-c no default)"
    fi
else
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Clearing cache (-c yes)"
    fi

    ./bash/clear.sh -v ${VERBOSE}
fi

# deleting old database?
if [ ${DELETE_DB} = 'no' ] && [ ${TEST_MIGRATIONS} = 'no' ]
then
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Database NOT deleted (-d no)"
    fi
    php artisan migrate:reset
else
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Deleting database (-d yes default)"
    fi
    rm -f ./database/database.sqlite
    touch ./database/database.sqlite
fi

# running migrations
php artisan migrate

# running test migrations?
if [ ${TEST_MIGRATIONS} = 'no' ]
then
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Test migrations NOT ran (-t no default)"
    fi
else
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Running testing migrations (-t yes)"
    fi
    php artisan migrate --path /database/migrations/test
fi

# seeding database?
if [ ${SEEDING} = 'no' ]
then
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Database NOT seeded (-s no default)"
    fi
else
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Seeding database (-s yes)"
    fi
    php artisan db:seed --class=TestTableSeeder
fi
