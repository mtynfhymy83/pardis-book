# syntax=docker/dockerfile:1.6
# Build with Iranian mirrors (local / restricted networks):
#   docker build -f Dockerfile.iran -t backend-api .

FROM docker.arvancloud.ir/composer:latest AS composer-bin

FROM docker.arvancloud.ir/php:8.2-cli-alpine3.18 AS ext-builder

RUN printf 'https://linux-mirror.liara.ir/repository/alpine/v3.18/main\nhttps://linux-mirror.liara.ir/repository/alpine/v3.18/community\n' \
    > /etc/apk/repositories

RUN apk add --no-cache \
        autoconf g++ make linux-headers \
        postgresql-dev libzip-dev freetype-dev libjpeg-turbo-dev libpng-dev openssl-dev \
        php82-pecl-swoole php82-pecl-redis php82-pecl-igbinary php82-pecl-msgpack

RUN docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql pdo_mysql sockets pcntl zip opcache \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd

RUN PHP_EXT_DIR="$(php -r 'echo ini_get("extension_dir");')" \
    && cp /usr/lib/php82/modules/swoole.so   "$PHP_EXT_DIR/swoole.so" \
    && cp /usr/lib/php82/modules/redis.so    "$PHP_EXT_DIR/redis.so" \
    && cp /usr/lib/php82/modules/igbinary.so "$PHP_EXT_DIR/igbinary.so" \
    && cp /usr/lib/php82/modules/msgpack.so  "$PHP_EXT_DIR/msgpack.so"

FROM docker.arvancloud.ir/php:8.2-cli-alpine3.18

RUN printf 'https://linux-mirror.liara.ir/repository/alpine/v3.18/main\nhttps://linux-mirror.liara.ir/repository/alpine/v3.18/community\n' \
    > /etc/apk/repositories

RUN apk add --no-cache \
        git unzip curl bash openssl tzdata \
        libpq libzip freetype libjpeg-turbo libpng \
        lz4-libs c-ares brotli-libs libstdc++

COPY --from=ext-builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=ext-builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

RUN docker-php-ext-enable igbinary msgpack swoole redis

RUN { \
    echo "memory_limit=512M"; \
    echo "opcache.enable=1"; \
    echo "opcache.enable_cli=1"; \
    echo "date.timezone=Asia/Tehran"; \
    } > /usr/local/etc/php/conf.d/99-opcache.ini \
    && cp /usr/share/zoneinfo/Asia/Tehran /etc/localtime

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
RUN composer config -g repos.packagist composer https://package-mirror.liara.ir/repository/composer/

RUN addgroup -g 1000 app && adduser -D -u 1000 -G app app
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/logs storage/cache \
    && chown -R app:app storage \
    && chmod -R 775 storage

USER app
EXPOSE 9502
CMD ["php", "server.php"]
