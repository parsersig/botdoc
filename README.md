# Telegram Investment Bot

A Telegram bot for managing user balances, referrals, and mock investment plans.
Built with PHP and SQLite, designed to run with Docker.

## Prerequisites

*   PHP (primarily for understanding the code, as execution is within Docker)
*   Docker
*   Docker Compose

## Setup & Local Launch

1.  **Clone the Repository**:
    ```bash
    git clone <repository_url>
    cd <repository_directory>
    ```

2.  **Configure Environment Variables**:
    Create a `.env` file in the project root. This file is used by Docker Compose to set environment variables for the bot.
    ```env
    # .env file content

    # --- Telegram Bot Configuration ---
    # Your Telegram Bot Token from BotFather
    TELEGRAM_BOT_TOKEN=YOUR_TELEGRAM_BOT_TOKEN

    # Your numeric Telegram User ID - you will be the admin
    ADMIN_ID=YOUR_ADMIN_ID

    # (Optional) Your bot's username without @ (e.g., MyCoolBot)
    # Defaults to CRYPTOCAP_ROBOT in index.php if not set
    BOT_USERNAME=YOUR_BOT_USERNAME

    # (Optional) Channel username (e.g., otch1) for mandatory subscription.
    # Bot will check if users are subscribed to this channel.
    # Leave empty or comment out if no channel subscription is required.
    # Defaults to @otch1 in index.php if not set.
    CHANNEL_ID=@your_channel_username

    # --- Webhook Configuration ---
    # Base URL where your bot is accessible from the internet (e.g., https://yourdomain.com or your ngrok URL)
    # Docker container exposes port 8080 locally.
    WEBHOOK_BASE_URL=https://your-publicly-accessible-domain.com 

    # --- Security ---
    # A secret token for accessing GET-based administrative endpoints (logs, set/delete webhook etc.)
    # Change this to a strong random string.
    ADMIN_SECRET_TOKEN=YOUR_STRONG_DEFAULT_TOKEN_PLEASE_CHANGE_ME

    # --- Database ---
    # Path inside the container where the SQLite database file will be stored.
    # This is configured to use a Docker volume for persistence.
    DB_FILE_PATH=/app/data/bot_database.db

    # (Optional) Path for PHP error logs inside the container.
    # Defaults to /tmp/error.log in index.php and send_stats.php
    ERROR_LOG_PATH=/app/data/php_error.log # Example: store logs in the persistent volume too

    # --- Cron Job for Statistics ---
    # Cron schedule for sending bot statistics (via send_stats.php).
    # Default is every 6 hours. Format: minute hour day_of_month month day_of_week
    CRON_SCHEDULE=0 */6 * * *
    ```
    **Important**: Replace placeholder values (like `YOUR_TELEGRAM_BOT_TOKEN`, `YOUR_ADMIN_ID`, `https://your-publicly-accessible-domain.com`, `YOUR_STRONG_DEFAULT_TOKEN_PLEASE_CHANGE_ME`) with your actual data.

3.  **Build and Run with Docker Compose**:
    Open a terminal in the project root and run:
    ```bash
    docker-compose up --build -d
    ```
    This command will build the Docker image (if it's the first time or Dockerfile changed) and start the bot service in detached mode. The bot will be accessible locally on port `8080` (e.g., `http://localhost:8080`).

4.  **Set the Telegram Webhook**:
    Once the bot is running and accessible externally (e.g., via ngrok or a deployed URL which is `WEBHOOK_BASE_URL`), you need to tell Telegram where to send updates.
    Open your browser and go to:
    `{WEBHOOK_BASE_URL}/index.php?setwebhook=1&admin_token={ADMIN_SECRET_TOKEN}`
    
    Replace `{WEBHOOK_BASE_URL}` with your actual public URL and `{ADMIN_SECRET_TOKEN}` with the value you set in your `.env` file.
    You should see a confirmation message from Telegram.

    *Example using ngrok (if your `WEBHOOK_BASE_URL` is `https://abcdef12345.ngrok.io` and `ADMIN_SECRET_TOKEN` is `mysecret`):*
    `https://abcdef12345.ngrok.io/index.php?setwebhook=1&admin_token=mysecret`

5.  **Interact with Your Bot**:
    Open Telegram and find your bot to start interacting with it.

## Administrative GET Endpoints

Certain GET endpoints in `index.php` are protected by the `ADMIN_SECRET_TOKEN`. To use them, append `&admin_token={ADMIN_SECRET_TOKEN}` to the URL.
Examples:
*   **Webhook Info**: `{WEBHOOK_BASE_URL}/index.php?webhook_info=1&admin_token={ADMIN_SECRET_TOKEN}`
*   **View Logs**: `{WEBHOOK_BASE_URL}/index.php?logs=1&admin_token={ADMIN_SECRET_TOKEN}` (Note: PHP error logs are configured by `ERROR_LOG_PATH`)
*   **Delete Webhook**: `{WEBHOOK_BASE_URL}/index.php?deletewebhook=1&admin_token={ADMIN_SECRET_TOKEN}`
*   **Check Investments Manually**: `{WEBHOOK_BASE_URL}/index.php?check_investments=1&admin_token={ADMIN_SECRET_TOKEN}`

## Statistics Script (`send_stats.php`)

The `send_stats.php` script is configured to run periodically via cron inside the Docker container.
*   **Schedule**: Controlled by the `CRON_SCHEDULE` environment variable (default is "0 */6 * * *", meaning every 6 hours at minute 0).
*   **Functionality**: Fetches bot statistics (total users, active users, balances, top users) and can send them to predefined channels or the admin. (Currently, the `stat_channels` table and logic for sending to multiple channels is in `send_stats.php` but not fully managed via bot commands - admin would need to add channels to `stat_channels` table manually or it defaults to admin ID).
*   **Logging**: Cron job output (including `echo` statements from `send_stats.php`) is redirected to `/var/log/cron.log` inside the container. PHP errors from the script are logged based on `ERROR_LOG_PATH` and `error_log()` calls (typically to stderr/stdout, which Docker captures).

## Project Structure

*   `index.php`: Main Telegram webhook handler and bot logic.
*   `send_stats.php`: Script for sending bot statistics (run by cron).
*   `Dockerfile`: Defines the Docker image for the bot.
*   `docker-compose.yml`: Configures the Docker services, volumes, and environment.
*   `bot_cron`: Crontab file for scheduling `send_stats.php`.
*   `.htaccess`: Apache configuration for URL rewriting and file protection.
*   `/app/data/`: (Inside Docker container, mapped to `bot_db_data` volume) Stores the SQLite database (`bot_database.db`) and potentially logs.
*   `README.md`: This file.

## Development Notes

*   The bot uses an SQLite database stored in a Docker volume for data persistence.
*   Ensure `ADMIN_SECRET_TOKEN` is kept secure.
*   To view logs from the running container: `docker-compose logs -f web`
*   To run a command inside the container: `docker-compose exec web <command>` (e.g., `docker-compose exec web php /var/www/html/send_stats.php`)

```
