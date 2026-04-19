#!/usr/bin/env bash

# TODO - document this file
#rm -f ./database/database.sqlite
#touch ./database/database.sqlite

CLEAR_CACHE=no
DELETE_DB=yes
SEEDING=no
TEST_MIGRATIONS=no
VERBOSE=yes
SHOW_OPTIONS=yes
LOAD_CONFIG_FILE="testing"

if [ ! -f .env ]; then
    echo "Error: .env file not found."
    exit 1
fi

while getopts ":c:d:f:o:s:t:v:" opt
   do
     # shellcheck disable=SC2220
     case $opt in
        c ) CLEAR_CACHE=$OPTARG;;
        d ) DELETE_DB=$OPTARG;;
        f ) LOAD_CONFIG_FILE=$OPTARG;;
        o ) SHOW_OPTIONS=$$OPTARG;;
        s ) SEEDING=$OPTARG;;
        t ) TEST_MIGRATIONS=$OPTARG;;
        v ) VERBOSE=$OPTARG;;
     esac
done

if [ ${VERBOSE} = 'yes' ]
then
    echo "@ Running refresh.sh with options:"
    echo -e "\t CLEAR_CACHE=${CLEAR_CACHE} (-c)"
    echo -e "\t DELETE_DB=${DELETE_DB} (-d)"
    echo -e "\t LOAD_CONFIG_FILE=${LOAD_CONFIG_FILE} (-f)"
    echo -e "\t SEEDING=${SEEDING} (-s)"
    echo -e "\t TEST_MIGRATIONS=${TEST_MIGRATIONS} (-t)"
    echo -e "\t VERBOSE=${VERBOSE} (-v)"
fi

CONFIG_FILE=".env.${LOAD_CONFIG_FILE}"
ENV_FILE="--env=${LOAD_CONFIG_FILE}"

source ${CONFIG_FILE}

if [ ${SHOW_OPTIONS} = 'yes' ]
then
    read -p "Do you want to run this script? (y/n): " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        echo "Exiting without running the script."
        exit 0
    fi
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
    ./bash/clear.sh -f ${LOAD_CONFIG_FILE}
fi

# deleting old database?
if [ ${DELETE_DB} = 'no' ] && [ ${TEST_MIGRATIONS} = 'no' ]
then
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Database NOT deleted (-d no)"
    fi
    php artisan migrate:reset ${ENV_FILE}
else
    if [ ${VERBOSE} = 'yes' ]
    then
        echo "Deleting database (-d yes default)"
    fi

    if [ "$DB_CONNECTION" = "sqlite" ]; then
        if [ ${VERBOSE} = 'yes' ]; then
            echo "Deleting sqlite"
        fi
        dirname="./database"
        db_file="${DB_DATABASE:-${dirname}/database.sqlite}"
        mkdir -p "$(dirname "$db_file")"
        rm -f "$dirname/${db_file}"
        touch "$dirname/${db_file}"
    elif [ "$DB_CONNECTION" = "mariadb" ]; then
        safe_db_name="${DB_DATABASE//\`/\`\`}"
        mariadb --skip-ssl \
          -u "$DB_USERNAME" \
          --password="$DB_PASSWORD" \
          -h "$DB_HOST" \
          -P "${DB_PORT:-3306}" \
          -e "DROP DATABASE IF EXISTS \`$safe_db_name\`; CREATE DATABASE \`$safe_db_name\`;"
    elif [ "$DB_CONNECTION" = "mysql" ]; then
        safe_db_name="${DB_DATABASE//\`/\`\`}"
        mysql --skip-ssl \
          -u "$DB_USERNAME" \
          --password="$DB_PASSWORD" \
          -h "$DB_HOST" \
          -P "${DB_PORT:-3306}" \
          -e "DROP DATABASE IF EXISTS \`$safe_db_name\`; CREATE DATABASE \`$safe_db_name\`;"
    else
        echo "Unsupported database connection: $DB_CONNECTION - Database will not be deleted and recreated."
        exit 1
    fi
fi

# running migrations
php artisan migrate ${ENV_FILE}

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
    php artisan migrate ${ENV_FILE} --path /database/migrations/test
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
    php artisan db:seed ${ENV_FILE} --class=TestTableSeeder
fi
