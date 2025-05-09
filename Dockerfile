# Use an official Ubuntu base image
FROM ubuntu:24.04

# Set environment variables
ENV DEBIAN_FRONTEND=noninteractive

# Install required packages
RUN apt-get update && apt-get install -y \
    php-cli php-curl php-mysql php-mbstring php-xml php-zip \
    php-memcached memcached unzip git curl mariadb-client && \
    apt-get clean

# Set the working directory
WORKDIR /home/ubuntu/rs-machine/

# Copy only composer files first to leverage Docker layer caching
COPY composer.json composer.lock ./

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install PHP dependencies (without running scripts/autoloader yet)
RUN composer install --prefer-dist --no-progress --no-scripts --no-autoloader

# Now copy the rest of the application code
COPY . .

# Generate optimized autoload files
RUN composer dump-autoload --optimize
