FROM php:8.2-apache

# Установка зависимостей
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_sqlite curl \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Копирование файлов
WORKDIR /var/www/html
COPY . .

# Установка прав доступа для /tmp и базы
RUN chmod -R 777 /tmp && \
    touch /tmp/bot_database.db && \
    chmod 777 /tmp/bot_database.db

# Подавление предупреждения ServerName
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Включение healthcheck (опционально, Render сам проверяет)
HEALTHCHECK --interval=30s --timeout=3s \
    CMD curl -f http://localhost/health || exit 1

EXPOSE 80
