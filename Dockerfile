# IMathAS on PHP 8.2 + Apache, for Railway (fork-only file; upstream has no Dockerfile).
# config.php is generated at container start from environment variables by docker/entrypoint.sh,
# so the image holds no credentials. Writable directories live on one volume at /data.
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev libonig-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mbstring pdo_mysql zip intl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Railway terminates TLS; trust its X-Forwarded-Proto so IMathAS builds https URLs.
RUN printf 'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on\n' > /etc/apache2/conf-available/forwarded-proto.conf \
    && a2enconf forwarded-proto \
    && printf 'upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=256M\nmax_execution_time=120\n' > /usr/local/etc/php/conf.d/imathas.ini

COPY . /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/imathas-entrypoint
RUN chmod +x /usr/local/bin/imathas-entrypoint \
    && rm -f /var/www/html/config.php \
    && chown -R www-data:www-data /var/www/html

# Apache listens on $PORT (Railway sets it); default 80 for local runs.
ENV PORT=80
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

ENTRYPOINT ["imathas-entrypoint"]
CMD ["apache2-foreground"]
