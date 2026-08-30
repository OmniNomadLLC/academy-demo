# Academy Suite demo — single-container FrankenPHP + SQLite.
FROM dunglas/frankenphp:1-php8.3

RUN install-php-extensions intl zip gd pcntl

WORKDIR /app
ENV SERVER_NAME=:8080

COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --no-interaction --no-scripts --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs database \
    && chmod -R 775 storage bootstrap/cache

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":8080"]
