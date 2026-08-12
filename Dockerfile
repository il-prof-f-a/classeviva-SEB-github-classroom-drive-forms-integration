FROM composer:2.8 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-req=ext-gd \
    --ignore-platform-req=ext-pdo_mysql

FROM php:8.2-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

WORKDIR /var/www/html
COPY . /var/www/html
COPY --from=dependencies /app/vendor /var/www/html/vendor
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/uda-system.ini
COPY docker/entrypoint.sh /usr/local/bin/uda-entrypoint

RUN mkdir -p \
        /usr/local/share/uda-system \
        database/backup \
        database/export \
        storage/cache \
        storage/exports \
        storage/logs \
        storage/temp \
        storage/uda \
        storage/uploads \
    && cp storage/template_obiettivi.xlsx /usr/local/share/uda-system/template_obiettivi.xlsx \
    && chown -R www-data:www-data database storage \
    && chmod +x /usr/local/bin/uda-entrypoint

ENTRYPOINT ["uda-entrypoint"]
CMD ["apache2-foreground"]
