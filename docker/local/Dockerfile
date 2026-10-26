FROM node:24-bookworm-slim AS node
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql pdo_sqlite intl mbstring zip pcntl bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node /usr/local/ /usr/local/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    NPM_CONFIG_CACHE=/tmp/npm

WORKDIR /app
CMD ["php", "artisan", "serve", "--host=0.0.0.0"]
