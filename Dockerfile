# Use an official Ubuntu base image
FROM ubuntu:24.04

# Set environment variables
ENV DEBIAN_FRONTEND=noninteractive

# PHP is pinned to 8.4, the version CI and production run. Ubuntu 24.04's own packages are PHP
# 8.3, so it comes from packages.sury.org, added as its README (packages.sury.org/php/README.txt)
# says, as deploy/predeploy.sh does on the server. (It replaces ppa:ondrej/php, which is frozen.)
# Composer comes from its own image, because Ubuntu's composer package would install another PHP
# alongside.
RUN apt-get update && apt-get install -y lsb-release ca-certificates curl && \
    curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb && \
    dpkg -i /tmp/debsuryorg-archive-keyring.deb && rm /tmp/debsuryorg-archive-keyring.deb && \
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && apt-get install -y \
    php8.4-cli php8.4-curl php8.4-gd php8.4-mysql php8.4-mbstring php8.4-xml php8.4-zip \
    php8.4-memcached memcached unzip git curl mariadb-client && \
    apt-get clean
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set the working directory
WORKDIR /home/ubuntu/rs-machine/

# Copy the application code into the container
COPY . /home/ubuntu/rs-machine/

# Install PHP dependencies
RUN composer install --prefer-dist --no-progress
