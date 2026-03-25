FROM php:8.5-fpm-alpine

# Keep runtime libraries installed after extension compilation.
RUN apk add --no-cache \
      icu-libs \
      libzip \
      oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
      icu-dev \
      libzip-dev \
      oniguruma-dev \
      openssl-dev \
      zlib-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql intl mbstring zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN composer install --no-interaction --no-dev --no-scripts --optimize-autoloader --prefer-dist

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
