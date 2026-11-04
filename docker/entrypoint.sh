#!/bin/sh
set -e

# The container runs as www-data. Never rewrite permissions on the persistent
# storage volume at startup: it may contain private user files and may be large.
for directory in \
    /var/www/html/storage/app/private \
    /var/www/html/storage/app/public \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache
do
    if [ ! -d "$directory" ] || [ ! -w "$directory" ]; then
        printf 'Required Laravel directory is missing or not writable by uid %s: %s\n' "$(id -u)" "$directory" >&2
        exit 1
    fi
done

exec "$@"
