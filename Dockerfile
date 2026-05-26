# syntax=docker/dockerfile:1.7
#
# Dockerfile
#
# QUOI : Image PHP 8.4-FPM multi-stage (`base`, `vendor`, `dev`, `prod`).
#
# COMMENT : Extensions en `base` ; cache Composer en `vendor` ; `prod` fige code + OPcache preload + healthcheck FPM.
#
# OÙ : Contexte racine — `dev` dans compose.yaml, `prod` dans compose.prod.yaml.
#
# POURQUOI : Une recette unique pour dev reproductible et image prod immutable.
#
# Build prod : docker build --target prod -t opale-news:prod .
# Build dev  : docker build --target dev  -t opale-news:dev  .

ARG PHP_VERSION=8.4

############################
# Stage 1 : base PHP-FPM   #
############################
FROM php:${PHP_VERSION}-fpm-bookworm AS base

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    PHP_INI_DIR=/usr/local/etc/php

RUN apt-get update && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        git \
        unzip \
        acl \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libfcgi-bin \
        postgresql-client \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        opcache \
        pdo_pgsql \
        zip \
        gd \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

############################
# Stage 2 : vendor (cache) #
############################
FROM base AS vendor

COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install \
        --prefer-dist \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-progress \
        --optimize-autoloader

############################
# Stage 3 : dev            #
############################
FROM base AS dev

# Xdebug optionnel : décommenter si besoin de debug pas-à-pas
# RUN pecl install xdebug && docker-php-ext-enable xdebug

COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-symfony.ini"

CMD ["php-fpm"]

############################
# Stage 4 : prod           #
############################
FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0

COPY docker/php/php.prod.ini "$PHP_INI_DIR/conf.d/zz-symfony.ini"
COPY docker/php/www.prod.conf /usr/local/etc/php-fpm.d/zz-www.conf

# 1) Copie des dépendances pré-installées (couche cache).
COPY --from=vendor /var/www/html/vendor ./vendor

# 2) Copie du code applicatif (filtré par .dockerignore).
COPY --chown=www-data:www-data . .

# 3) Optimisations Symfony + permissions.
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && mkdir -p var/cache var/log var/share public/uploads \
    && chown -R www-data:www-data var public/uploads \
    && chmod -R u=rwX,g=rX,o= var public/uploads \
    && rm -rf /root/.composer

# Healthcheck FPM (le pool ping est exposé sur /ping via www.prod.conf).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

USER www-data

EXPOSE 9000
CMD ["php-fpm", "--nodaemonize"]
