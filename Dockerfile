FROM php:8.2-cli

# Install system dependencies needed by common Laravel extensions
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first (better Docker layer caching)
COPY composer.json composer.lock ./

# Install PHP dependencies (production, no dev packages)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy the rest of the application code
COPY . .

# Generate optimized autoloader now that full app code is present
RUN composer dump-autoload --optimize

# Make sure storage and cache directories are writable
RUN mkdir -p storage/framework/{cache,sessions,views} \
    && mkdir -p storage/logs \
    && chmod -R 775 storage bootstrap/cache

# Cache Laravel config/routes/views for faster boot (safe to fail if env not ready yet)
RUN php artisan config:clear

EXPOSE 10000

# Render provides the $PORT env var at runtime; default to 10000 for local testing
CMD php artisan serve --host 0.0.0.0 --port ${PORT:-10000}
