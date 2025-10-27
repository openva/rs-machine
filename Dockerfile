# Base PHP 8.4 runtime (RC until GA is published)
FROM php:8.4-rc-cli

# Install system packages and PHP extensions required by the application
RUN apt-get update && apt-get install -y \
        git \
        unzip \
        mariadb-client \
        libmemcached-dev \
        libzip-dev \
        zlib1g-dev \
        libssl-dev \
        libicu-dev \
        pkg-config \
        memcached \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        intl \
        mysqli \
        pdo_mysql \
        zip \
    && pecl install memcached \
    && docker-php-ext-enable memcached \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set the working directory
WORKDIR /app

# Copy only composer files first to leverage Docker layer caching
COPY composer.json composer.lock ./

# Install PHP dependencies (without running scripts/autoloader yet)
RUN composer install --prefer-dist --no-progress --no-scripts --no-autoloader

# Now copy the rest of the application code
COPY . .

# Generate optimized autoload files
RUN composer dump-autoload --optimize
