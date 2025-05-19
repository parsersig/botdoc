FROM php:8.2-apache

# Install required extensions
RUN docker-php-ext-install mysqli

# Enable necessary Apache modules
RUN a2enmod rewrite authz_core authz_host

# Set working directory
WORKDIR /var/www/html

# Copy files
COPY index.php config.php .htaccess /var/www/html/

# Create data files and set permissions
RUN touch users.json error.log && \
    chmod 666 users.json error.log && \
    chown -R www-data:www-data /var/www/html

EXPOSE 80
