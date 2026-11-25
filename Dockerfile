FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libonig-dev ca-certificates \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && sed -i 's/\r$//' docker/start.sh \
    && chmod +x docker/start.sh

EXPOSE 10000

CMD ["./docker/start.sh"]
