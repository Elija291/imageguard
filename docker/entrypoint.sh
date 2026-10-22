#!/bin/bash
set -Eeuo pipefail
umask 027
: "${APP_PASSWORD:?Set APP_PASSWORD in your private .env file (at least 16 characters).}"
if [ "${#APP_PASSWORD}" -lt 16 ]; then echo 'APP_PASSWORD must be at least 16 characters.' >&2; exit 1; fi
if [ "$APP_PASSWORD" = 'CHANGE-THIS-TO-A-LONG-RANDOM-PASSWORD' ]; then echo 'Replace the example APP_PASSWORD first.' >&2; exit 1; fi
install -d -o mysql -g mysql -m 750 /run/mysqld /var/lib/mysql
chmod 755 /run/mysqld
install -d -o root -g www-data -m 750 /run/imageguard
install -d -o www-data -g www-data -m 700 /var/lib/imageguard/sessions /var/lib/imageguard/tmp
chown -R mysql:mysql /var/lib/mysql
if [ ! -d /var/lib/mysql/mysql ]; then mysqld --initialize-insecure --user=mysql; fi
mysqld --user=mysql --daemonize
trap 'mysqladmin --protocol=socket shutdown >/dev/null 2>&1 || true' EXIT
ready=0
for i in {1..60}; do
  if mysqladmin --protocol=socket ping --silent; then ready=1; break; fi
  sleep 1
done
[ "$ready" = 1 ] || { echo 'MySQL did not become ready.' >&2; exit 1; }
php /opt/imageguard/init.php
mysqladmin --protocol=socket shutdown
trap - EXIT
unset APP_PASSWORD SIGHTENGINE_API_USER SIGHTENGINE_API_SECRET
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
