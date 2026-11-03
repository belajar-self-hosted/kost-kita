# syntax=docker/dockerfile:1

# -------------------------------------------------------------
# Stage 1: Node.js binaries & tools
# -------------------------------------------------------------
FROM node:22-bookworm-slim AS node-source

# -------------------------------------------------------------
# Stage 2: Build dependencies & frontend assets
# -------------------------------------------------------------
FROM php:8.3-cli-bookworm AS builder

# Wayfinder generates client route URLs during the Vite build. Keep its origin
# aligned with production so links never point to the build container's localhost.
ARG APP_URL=https://kostkita.sequein.my.id
ENV APP_URL=${APP_URL}

# Install build dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        pcntl \
        posix \
        opcache \
        gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy Node & Composer
COPY --from=node-source /usr/local /usr/local
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP production dependencies first (caching layer)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Install NPM dependencies
COPY package.json package-lock.json ./
RUN npm ci

# Copy project source code for build
COPY . .

# Build frontend assets (Vite + Svelte 5 + Wayfinder)
RUN npm run build

# Finalize autoloader
RUN composer dump-autoload --optimize --no-dev

# -------------------------------------------------------------
# Stage 3: Production PHP-FPM Application Runtime
# -------------------------------------------------------------
FROM php:8.3-fpm-bookworm AS app

# Install production runtime libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        pcntl \
        posix \
        opcache \
        gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy composer for platform check & artisan commands
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure PHP & Opcache
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

# Copy application source & built assets from builder
COPY --from=builder /app /var/www/html

# Validate PHP platform requirements for production dependencies
RUN composer check-platform-reqs --no-dev

# Prepare entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set directory permissions for Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

EXPOSE 9000

# -------------------------------------------------------------
# Stage 4: Test image with dev dependencies for Acceptance Tests
# -------------------------------------------------------------
FROM app AS test

WORKDIR /var/www/html
RUN composer install --no-interaction --prefer-dist

# -------------------------------------------------------------
# Stage 5: Production Web Server (Nginx)
# -------------------------------------------------------------
FROM nginx:1.27-alpine AS web

# Install curl for container healthcheck
RUN apk add --no-cache curl

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

EXPOSE 80

HEALTHCHECK --interval=10s --timeout=3s --retries=3 \
  CMD curl -fsS http://127.0.0.1/up || exit 1
