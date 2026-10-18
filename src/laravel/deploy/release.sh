#!/bin/sh
set -eu

# Use an explicit shell script so every release step must succeed.
php artisan migrate --force
php artisan db:seed --force
php artisan sushi:retag-v3 --apply
php artisan sushi:backfill-community
