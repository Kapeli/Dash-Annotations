#!/bin/sh

cat > .env  <<-EOF
APP_KEY=${APP_KEY:-SomeRandomKey!}
APP_ENV=${APP_ENV:-local}
APP_DEBUG=${APP_ENV:-true}
APP_LOCALE=${APP_LOCALE:-en}
APP_FALLBACK_LOCALE=${APP_FALLBACK_LOCALE:-en}

DB_CONNECTION=${DB_CONNECTION:-mysql}
DB_HOST=${DB_PORT_3306_TCP_ADDR:-localhost}
DB_DATABASE=${DB_DATABASE:-annotations}
DB_USERNAME=${DB_USER:-mysql_username}
DB_PASSWORD=${DB_PASSWORD:-mysql_password}

CACHE_DRIVER=${CACHE_DRIVER:-file}
SESSION_DRIVER=${SESSION_DRIVER:-file}
QUEUE_DRIVER=${QUEUE_DRIVER:-sync}
EOF
composer config -g github-oauth.github.com ${TOKEN:-}
composer install
php artisan cache:clear
chmod -R 777 public
chmod -R 777 storage
php artisan migrate --force
exec php-fpm
