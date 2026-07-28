# syntax=docker/dockerfile:1

FROM php:8.4-fpm-alpine AS base

# install-php-extensions resolves the correct build deps for each extension,
# which is otherwise a large amount of hand-maintained apk/docker-php-ext glue.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# pcntl is the reason this container exists: Horizon requires it and Windows
# cannot provide it. redis is the native client (faster than predis).
RUN install-php-extensions \
        pdo_mysql \
        bcmath \
        gd \
        intl \
        zip \
        exif \
        pcntl \
        redis \
        opcache \
    && apk add --no-cache git mysql-client icu-data-full

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# Match the host user so bind-mounted files stay writable from both sides.
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} -S app 2>/dev/null || true \
    && adduser -u ${UID} -S app -G app 2>/dev/null || true \
    && chown -R ${UID}:${GID} /var/www/html

USER app
