# Используем официальный образ PHP с Apache
FROM php:8.2-apache

# Устанавливаем необходимые расширения
RUN docker-php-ext-install mysqli && \
    docker-php-ext-enable mysqli

# Включаем модули Apache
RUN a2enmod rewrite headers

# Настраиваем виртуальный хост
COPY ./docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Устанавливаем рабочую директорию
WORKDIR /var/www/html

# Копируем файлы
COPY . .

# Настраиваем права
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    touch users.json error.log && \
    chmod 666 users.json error.log

# Открываем порт 80
EXPOSE 80

# Запускаем Apache в foreground режиме
CMD ["apache2-foreground"]
