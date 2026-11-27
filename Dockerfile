FROM php:8.2-apache

# Install required PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy the PHP website into Apache's web directory
COPY healthcare_platform/ /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Allow Apache to run on the required port
EXPOSE 80