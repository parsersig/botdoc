FROM php:8.2-apache

# Установка зависимостей и dev-пакетов для сборки расширений
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    sqlite3 \
    cron \
    && docker-php-ext-install pdo pdo_sqlite curl \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .

# Права для базы и /tmp
RUN chmod -R 777 /tmp && \
    touch /tmp/bot_database.db && \
    chmod 777 /tmp/bot_database.db && \
    chmod +x /var/www/html/send_stats.php

# Подавление предупреждения ServerName
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Создание стартового скрипта для поддержки веб-сервера и cron-задач
RUN echo '#!/bin/bash\n\
if [ "$1" = "cron" ]; then\n\
  php /var/www/html/send_stats.php\n\
  exit $?\n\
fi\n\
\n\
# Запуск Apache в foreground режиме\n\
apache2-foreground\n\
' > /usr/local/bin/entrypoint.sh && \
    chmod +x /usr/local/bin/entrypoint.sh

HEALTHCHECK --interval=30s --timeout=3s \
    CMD curl -f http://localhost/health || exit 1

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
