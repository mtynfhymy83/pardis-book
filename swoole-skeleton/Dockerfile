# Official Swoole + PHP image (bundles the swoole extension).
FROM phpswoole/swoole:php8.2-alpine

RUN apk add --no-cache postgresql-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

WORKDIR /app

# Install dependencies first (better layer caching)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader || composer install --no-interaction

COPY . .

EXPOSE 9501

CMD ["php", "server.php"]
