#!/bin/sh
# Restart the CLI after each pass: reconnect the database and reload settings.
# The persisted queue and flock locks survive worker/container restarts safely.
while :; do
  if [ -f /var/www/html/storage/.env ] && [ ! -f /var/www/html/storage/.maintenance ]; then
    su -s /bin/sh www-data -c 'cd /var/www/html && php bin/console images:work' || true
  fi
  sleep 2
done
