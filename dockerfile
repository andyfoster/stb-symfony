# Dockerfile

FROM dunglas/frankenphp:php8.4-bookworm

ENV APP_ENV=prod
ENV DATABASE_URL="postgresql://dummy:dummy@localhost:5432/dummy?serverVersion=18&charset=utf8"

RUN install-php-extensions \
    pdo_pgsql \
    intl \
    opcache \
    zip \
    gd

# A TrueType font for the example data's stand-in photos (app:seed on the test site).
RUN apt-get update \
    && apt-get install -y --no-install-recommends fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*

# Listing photos: up to 4 x 10 MB per form, and room to resize a phone photo with GD.
RUN printf 'upload_max_filesize = 10M\npost_max_size = 45M\nmemory_limit = 256M\n' > "$PHP_INI_DIR/conf.d/app.ini"

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

RUN php bin/console importmap:install

# Bundle assets (EasyAdmin's CSS, JS and icons) into public/bundles. Composer's
# auto-scripts would do this, but they're skipped above with --no-scripts.
RUN php bin/console assets:install public

RUN php bin/console asset-map:compile

RUN php bin/console cache:clear --env=prod

COPY Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 80