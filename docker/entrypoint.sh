#!/bin/sh
set -eu

key_file="/var/www/html/storage/.encryption_key"
objective_template="/var/www/html/storage/template_obiettivi.xlsx"

if [ ! -f "$objective_template" ]; then
    cp /usr/local/share/uda-system/template_obiettivi.xlsx "$objective_template"
    chown www-data:www-data "$objective_template"
fi

if [ -z "${ENCRYPTION_KEY:-}" ]; then
    if [ ! -s "$key_file" ]; then
        umask 077
        php -r 'echo bin2hex(random_bytes(32));' > "$key_file"
        chown www-data:www-data "$key_file"
    fi
    ENCRYPTION_KEY="$(cat "$key_file")"
    export ENCRYPTION_KEY
fi

php /var/www/html/scripts/setup_database.php --wait=90

exec "$@"
