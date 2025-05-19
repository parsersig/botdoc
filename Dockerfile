FROM php:8.2-apache

# Install curl for Telegram API requests
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy application files
COPY . /var/www/html

# Set permissions for /tmp (used for users.json, error.log, request.log)
RUN chown -R www-data:www-data /tmp \
    && chmod -R 775 /tmp

# Enable Apache rewrite module for .htaccess
RUN a2enmod rewrite

# Set ServerName to suppress Apache warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Expose port 80
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]
