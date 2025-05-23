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

# Create data directory and set permissions
RUN mkdir -p /app/data && chown www-data:www-data /app/data

# Make send_stats.php executable
RUN chmod +x /var/www/html/send_stats.php

# Create and set permissions for cron log file
RUN touch /var/log/cron.log && chmod 0666 /var/log/cron.log

# Подавление предупреждения ServerName
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Создание стартового скрипта для поддержки веб-сервера и cron-задач
RUN echo '#!/bin/bash\n\
# Default cron schedule if not set\n\
DEFAULT_CRON_SCHEDULE="0 */6 * * *"\n\
CRON_JOB_SCHEDULE=${CRON_SCHEDULE:-$DEFAULT_CRON_SCHEDULE}\n\
\n\
# Create cron job file\n\
echo "${CRON_JOB_SCHEDULE} php /var/www/html/send_stats.php >> /var/log/cron.log 2>&1" > /etc/cron.d/bot_cron\n\
# Add an empty line to the cron file, it'\''s sometimes required\n\
echo "" >> /etc/cron.d/bot_cron\n\
\n\
# Give execution rights on the cron job file\n\
chmod 0644 /etc/cron.d/bot_cron\n\
# Apply cron job\n\
crontab /etc/cron.d/bot_cron\n\
\n\
# Start cron daemon\n\
cron\n\
\n\
# Start Apache in foreground\n\
apache2-foreground\n\
' > /usr/local/bin/entrypoint.sh && \
    chmod +x /usr/local/bin/entrypoint.sh

HEALTHCHECK --interval=30s --timeout=3s \
    CMD curl -f http://localhost/health || exit 1

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
