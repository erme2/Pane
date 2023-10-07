#!/usr/bin/env bash

# cleaning laravel
php artisan clear-compiled          # Remove the compiled class file
#php artisan auth:clear-resets       # Flush expired password reset tokens @todo reactivate as soon as we have tokens working
php artisan cache:clear             # Flush the application cache
php artisan config:clear            # Remove the configuration cache file
php artisan event:clear             # Clear all cached events and listeners
php artisan optimize:clear          # Remove the cached bootstrap files
#php artisan queue:clear             # Delete all of the jobs from the specified queue
php artisan route:clear             # Remove the route cache file
php artisan schedule:clear-cache    #   Delete the cached mutex files created by scheduler
php artisan view:clear              # Clear all compiled view files

# if any file is left, this will remove it
rm -rf bootstrap/cache/*.php

