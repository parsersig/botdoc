FROM php:8.2-apache

# Установка зависимостей
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Копирование файлов
COPY . /var/www/html

# Установка прав доступа
RUN chown -R www-data:www-data /var/www/html /tmp \
    && chmod -R 775 /var/www/html /tmp

# Включение модуля rewrite
RUN a2enmod rewrite

# Подавление предупреждения ServerName
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Проверка работоспособности
HEALTHCHECK --interval=30s --timeout=3s \
    CMD curl -f http://localhost/ || exit 1

# Открытие порта
EXPOSE 80

# Запуск Apache
CMD ["apache2-foreground"]
