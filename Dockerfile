FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev postgresql-client \
 && docker-php-ext-install pdo_pgsql opcache \
 && a2enmod headers \
 && rm -rf /var/lib/apt/lists/*

RUN { echo 'opcache.enable=1'; echo 'opcache.validate_timestamps=0'; } > /usr/local/etc/php/conf.d/opcache.ini

ENV PORT=80
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY . /var/www/app

RUN mkdir -p /var/cache/tiles && chown www-data:www-data /var/cache/tiles
ENV TILE_CACHE_DIR=/var/cache/tiles

RUN chmod +x /var/www/app/docker/entrypoint.sh
ENTRYPOINT ["/var/www/app/docker/entrypoint.sh"]
CMD ["apache2-foreground"]

EXPOSE 80
