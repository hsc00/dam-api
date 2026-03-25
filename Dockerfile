FROM php:8.5-fpm-alpine

# Install only runtime-required native extensions and their build deps.
# Build tools (gcc, make) are available on Alpine for pecl but removed from the final layer.
RUN apk add --no-cache \
        libzip-dev \
        icu-dev \
        libssl3 \
    && docker-php-ext-install -j$(nproc) pdo_mysql intl mbstring zip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    # OPcache + JIT — optimised for production.
    # For local development the source tree is bind-mounted; opcache.revalidate_freq=0
    # means file changes are NOT picked up without an FPM reload. This is intentional:
    # run `docker compose exec app kill -USR2 1` to reload FPM after code changes, or
    # set opcache.revalidate_freq=2 in a dev override if you prefer automatic revalidation.
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=100000'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.jit_buffer_size=100M'; \
        echo 'opcache.jit=tracing'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html

# Install Composer — used to run `composer install` below and available for dev convenience.
# In a production image pipeline, prefer a multi-stage build that copies only vendor/.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP dependencies without dev packages.
# The vendor/ directory from this step is overridden by the docker-compose.yaml
# bind-mount in local dev — composer install still runs via `docker compose run app composer install`.
RUN composer install --no-interaction --no-dev --optimize-autoloader --prefer-dist

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
