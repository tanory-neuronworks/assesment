# Base image dengan tag versi yang jelas (PHP 8.2 + Apache)
FROM php:8.2-apache

# Install dependency OS & ekstensi PHP, lalu bersihkan cache apt agar image kecil
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# pcov (code coverage untuk SonarQube), default mati; lihat docker/pcov.ini
COPY docker/pcov.ini /usr/local/etc/php/conf.d/zz-pcov.ini

# Konfigurasi Apache: DocumentRoot ke /public + rewrite ke index.php
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Composer diambil dari image resmi (multi-stage copy), versi major dipin
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Layer dependency: hanya berubah kalau composer.json/lock berubah (cache efisien)
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress

# Salin source code (di-filter .dockerignore), lalu generate autoloader
COPY . .
RUN composer dump-autoload --optimize --no-interaction \
    && mkdir -p public/uploads \
    && chown -R www-data:www-data /var/www/html

# Port yang dipakai Apache di dalam container (di-map ke host lewat compose.yaml)
EXPOSE 80

# Cek kesehatan: halaman /login harus bisa dibuka, kalau tidak container ditandai unhealthy
# start-period memberi waktu Apache/PHP menyala sebelum pengecekan dihitung gagal
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://localhost/login') === false ? 1 : 0);"
