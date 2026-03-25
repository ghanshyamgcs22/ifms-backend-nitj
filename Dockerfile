FROM php:8.3-apache

# Install required tools and MongoDB extension dependencies
RUN apt-get update && apt-get install -y \
    libssl-dev \
    git \
    unzip \
    && pecl install mongodb-1.17.0 \
    && docker-php-ext-enable mongodb

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable necessary Apache modules for routing (.htaccess) and CORS headers
RUN a2enmod rewrite headers

# Set Apache Document Root to the inner backend directory
WORKDIR /var/www/html

# Copy the inner backend directory contents to the container
COPY backend/ /var/www/html/

# Install PHP dependencies
RUN composer update --no-dev --optimize-autoloader --ignore-platform-req=ext-mongodb

# Tell Apache to allow .htaccess overrides in the document root
RUN echo "<Directory /var/www/html/>\n\
    AllowOverride All\n\
    Require all granted\n\
    </Directory>" > /etc/apache2/conf-available/custom.conf \
    && a2enconf custom

# Fix permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
