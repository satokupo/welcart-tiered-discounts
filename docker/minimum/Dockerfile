ARG WP_CLI_IMAGE=wordpress:cli-2.12.0-php8.3
ARG PHP_IMAGE=php:7.4.33-apache

FROM ${WP_CLI_IMAGE} AS wpcli

FROM ${PHP_IMAGE}

ARG WORDPRESS_VERSION=5.6.19

ENV WORDPRESS_VERSION="${WORDPRESS_VERSION}"

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" curl gd mysqli mbstring pdo_mysql xml zip; \
    a2enmod rewrite; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    mkdir -p /usr/src/wordpress; \
    curl -fsSL "https://wordpress.org/wordpress-${WORDPRESS_VERSION}.tar.gz" \
        | tar -xz --strip-components=1 -C /usr/src/wordpress

RUN sed -i "s/define( 'WP_DEBUG', false );/define( 'WP_DEBUG', true );/" /usr/src/wordpress/wp-config-sample.php \
    && awk "/require_once ABSPATH/ && !inserted { print \"define( 'WP_DEBUG_LOG', true );\"; print \"define( 'WP_DEBUG_DISPLAY', false );\"; print \"define( 'WP_ENVIRONMENT_TYPE', 'local' );\"; print \"@ini_set( 'display_errors', 0 );\"; inserted=1 } { print }" /usr/src/wordpress/wp-config-sample.php > /tmp/wp-config-sample.php \
    && mv /tmp/wp-config-sample.php /usr/src/wordpress/wp-config-sample.php

COPY --from=wpcli /usr/local/bin/wp /usr/local/bin/wp

RUN chmod +x /usr/local/bin/wp

RUN set -eux; \
    install -d -o www-data -g www-data /var/www/html; \
    install -d -o www-data -g www-data /usr/src/wordpress/wp-content/uploads

RUN printf '%s\n' \
    '#!/bin/bash' \
    'set -Eeuo pipefail' \
    '' \
    'if [ "${1:-}" = "apache2-foreground" ]; then' \
    '    if [ ! -f /var/www/html/index.php ]; then' \
    '        cp -a /usr/src/wordpress/. /var/www/html/' \
    '    fi' \
    '    find /var/www/html -path /var/www/html/wp-content/plugins -prune -o -exec chown www-data:www-data {} + 2>/dev/null || true' \
    '' \
    '    if [ ! -f /var/www/html/wp-config.php ]; then' \
    '        cp /var/www/html/wp-config-sample.php /var/www/html/wp-config.php' \
    '        sed -i "s/database_name_here/${WORDPRESS_DB_NAME}/" /var/www/html/wp-config.php' \
    '        sed -i "s/username_here/${WORDPRESS_DB_USER}/" /var/www/html/wp-config.php' \
    '        sed -i "s/password_here/${WORDPRESS_DB_PASSWORD}/" /var/www/html/wp-config.php' \
    '        sed -i "s/localhost/${WORDPRESS_DB_HOST}/" /var/www/html/wp-config.php' \
    '        for _ in 1 2 3 4 5 6 7 8; do' \
    '            salt="$(od -An -N32 -tx1 /dev/urandom | tr -d " \\n")"' \
    '            sed -i "0,/put your unique phrase here/s//${salt}/" /var/www/html/wp-config.php' \
    '        done' \
    '    fi' \
    'fi' \
    '' \
    'exec docker-php-entrypoint "$@"' \
    > /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
