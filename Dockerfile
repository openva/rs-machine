# Use an official Ubuntu base image
FROM ubuntu:24.04

# Set environment variables
ENV DEBIAN_FRONTEND=noninteractive

# Install required packages including Composer
RUN apt-get update && apt-get install -y \
    php-cli php-curl php-mysql php-mbstring php-xml php-zip \
    php-memcached memcached unzip git curl mariadb-client composer && \
    apt-get clean

# Set the working directory
WORKDIR /home/ubuntu/rs-machine/

# Copy the application code into the container
COPY . /home/ubuntu/rs-machine/

# Install PHP dependencies
RUN composer install --prefer-dist --no-progress
