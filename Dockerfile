# Use official PHP Apache image
FROM php:8.2-apache

# Install required PHP extensions
RUN docker-php-ext-install mysqli

# Enable Apache mod_rewrite for .htaccess
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy files
COPY index.php config.php .htaccess /var/www/html/

# Create data files and set permissions
RUN touch users.json error.log && \
    chmod 666 users.json error.log && \
    chown -R www-data:www-data /var/www/html

# Expose port 80 (Render will map to its own port)
EXPOSE 80