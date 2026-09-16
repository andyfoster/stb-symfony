# Dockerfile

FROM dunglas/frankenphp:php8.4-bookworm

ENV APP_ENV=prod

RUN install-php-extensions \
    pdo_pgsql \
    intl \
    opcache \
    zip

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock symfony.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

COPY . .

RUN composer dump-autoload \
    --classmap-authoritative \
    --no-dev

RUN php bin/console cache:clear --env=prod

COPY Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 80