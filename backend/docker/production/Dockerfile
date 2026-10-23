FROM php:8.5.12-fpm-bookworm

# PHP 8.5 official image already ships dom, mbstring, curl and OPcache.
# Rebuilding DOM causes a Lexbor header error and is unnecessary.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip git; \
    docker-php-ext-install -j2 pdo_mysql; \
    php -r 'foreach (["pdo_mysql", "mbstring", "dom", "curl", "Zend OPcache"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: {$ext}\n"); exit(1); } }'; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress \
    && composer audit --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev
RUN mkdir -p /app/var && chmod 755 /app/bin/*.php
COPY docker/production/php.ini /usr/local/etc/php/conf.d/chupacabra.ini
EXPOSE 9000
CMD ["php-fpm", "-F"]
