# syntax=docker/dockerfile:1

# ---- dependências PHP (sem pacotes de desenvolvimento) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# ---- aplicação: FrankenPHP (Caddy + PHP) servindo /app/public ----
FROM dunglas/frankenphp:1-php8.4 AS app

# poppler-utils fornece o pdftotext usado para ler os PDFs da Energisa
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions intl zip gd bcmath pdo_sqlite pdo_mysql opcache

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache

ENV SERVER_NAME=":80" \
    APP_ENV=production \
    APP_DEBUG=false \
    PDFTOTEXT_BINARY=/usr/bin/pdftotext

EXPOSE 80
VOLUME ["/app/storage"]

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
