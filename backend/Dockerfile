# Etapa 1: dependencias de PHP
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-req=ext-mongodb
COPY . .
RUN composer dump-autoload --no-dev --optimize

# Etapa 2: imagen final
FROM php:8.4-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql opcache \
 && pecl install mongodb \
 && docker-php-ext-enable mongodb \
 && a2enmod rewrite \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && sed -i 's/^expose_php.*/expose_php = Off/' "$PHP_INI_DIR/php.ini" \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri -e 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -ri -e 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

RUN printf '<IfModule mpm_prefork_module>\n  StartServers 2\n  MinSpareServers 2\n  MaxSpareServers 4\n  MaxRequestWorkers 10\n</IfModule>\n' > /etc/apache2/conf-available/mpm-ligero.conf \
 && a2enconf mpm-ligero \
 && chown -R 33:33 /var/run/apache2 /var/lock/apache2 /var/log/apache2

RUN printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/seguridad-extra.conf \
 && a2enconf seguridad-extra

WORKDIR /var/www/html
COPY --from=vendor --chown=33:33 /app /var/www/html

USER 33:33
EXPOSE 8080