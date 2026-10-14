#!/bin/sh
set -eu

# Use an explicit shell script so the host runs both release steps.
php artisan migrate --force
php artisan db:seed --force
