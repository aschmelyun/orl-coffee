FROM php:8.3-fpm-alpine

RUN docker-php-ext-install mysqli pdo pdo_mysql

RUN apk add --update --no-cache --virtual .build-dependencies $PHPIZE_DEPS \
        && pecl install apcu \
        && docker-php-ext-enable apcu \
        && pecl clear-cache \
        && apk del .build-dependencies