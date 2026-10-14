FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build
FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libonig-dev libicu-dev libcurl4-openssl-dev unzip supervisor \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql mbstring intl zip gd opcache curl pcntl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf 'memory_limit=512M\nupload_max_filesize=32M\npost_max_size=34M\n' > /usr/local/etc/php/conf.d/sushi.ini \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=frontend /app/public/build ./public/build
COPY deploy/start.sh /usr/local/bin/sushi-start
COPY deploy/supervisord.conf /etc/supervisor/conf.d/sushi.conf
RUN chmod +x /usr/local/bin/sushi-start
ENTRYPOINT ["/usr/local/bin/sushi-start"]
CMD ["sushi-server"]
EXPOSE 80
