#!/usr/bin/env bash

# TO DO - document this file

CONFIG_FILE="testing"

while getopts ":f:" opt
   do
     # shellcheck disable=SC2220
     case $opt in
        f ) CONFIG_FILE=$OPTARG;;
     esac
done

ENV_FILE="--env=${CONFIG_FILE}"

echo "@ Running clear.sh with options: $ENV_FILE"

# cleaning laravel
php artisan clear-compiled $ENV_FILE        # Remove the compiled class file
#php artisan auth:clear-resets $ENV_FILE    # Flush expired password reset tokens @todo reactivate as soon as we have tokens working
php artisan cache:clear $ENV_FILE           # Flush the application cache
php artisan config:clear $ENV_FILE          # Remove the configuration cache file
php artisan event:clear $ENV_FILE           # Clear all cached events and listeners
php artisan optimize:clear $ENV_FILE        # Remove the cached bootstrap files
#php artisan queue:clear $ENV_FILE          # Delete all of the jobs from the specified queue
php artisan route:clear $ENV_FILE           # Remove the route cache file
php artisan schedule:clear-cache $ENV_FILE  #   Delete the cached mutex files created by scheduler
php artisan view:clear $ENV_FILE            # Clear all compiled view files

# if any file is left, this will remove it
rm -rf bootstrap/cache/*.php

