FROM composer:2.10.2 AS composer

FROM php:8.3.33-cli

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        git \
        libcurl4-openssl-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        unzip; \
    docker-php-ext-install -j"$(nproc)" curl dom mbstring zip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN mkdir -p /workspace /tmp/project-home /tmp/composer; \
    chmod 0777 /workspace /tmp/project-home /tmp/composer

ENV HOME=/tmp/project-home \
    COMPOSER_HOME=/tmp/composer

WORKDIR /workspace

CMD ["tail", "-f", "/dev/null"]
