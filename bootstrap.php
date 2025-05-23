<?php
// bootstrap.php

// --- Error Reporting (consistent across scripts) ---
ini_set('display_errors', 0); // Should be 0 in production
ini_set('log_errors', 1);
error_reporting(E_ALL);

// --- Environment Variables & Configuration ---
$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$adminId = getenv('ADMIN_ID') ?: '';
$botUsername = getenv('BOT_USERNAME') ?: 'CRYPTOCAP_ROBOT';
$channelId = getenv('CHANNEL_ID') ?: '@otch1'; // Default channel for subscription check
$channelUsername = ($channelId && $channelId[0] === '@') ? substr($channelId, 1) : $channelId;

$webhookBaseUrl = getenv('WEBHOOK_BASE_URL') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$adminSecretToken = getenv('ADMIN_SECRET_TOKEN') ?: 'YOUR_DEFAULT_SECRET_TOKEN_CHANGE_ME';

$dbFilePath = getenv('DB_FILE_PATH') ?: '/app/data/bot_database.db'; // Default path consistent with Docker volume
$errorLogPath = getenv('ERROR_LOG_PATH') ?: '/app/data/php_error.log'; // Default error log path

ini_set('error_log', $errorLogPath);

// --- Critical Configuration Checks ---
if (empty($botToken)) {
    error_log("CRITICAL: TELEGRAM_BOT_TOKEN is not set.");
    // In a web context, you might die(), but for bootstrap, just log.
    // Scripts including this should handle this if critical.
}
if (empty($dbFilePath)) {
    error_log("CRITICAL: DB_FILE_PATH is not set.");
}

// --- Database Initialization ---
$db = null;
try {
    $dataDir = dirname($dbFilePath);
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
             error_log("CRITICAL: Failed to create database directory: " . $dataDir);
             throw new RuntimeException(sprintf('Directory "%s" was not created', $dataDir));
        }
        // Attempt to chown only if mkdir was successful and we are not root (less relevant if www-data is consistent)
        // chown($dataDir, 'www-data'); // or get effective uid/gid
        // chmod($dataDir, 0775);
    }
     
    // Check writability of the directory for the database file
    if (!is_writable($dataDir)) {
        error_log("CRITICAL: Database directory is not writable: " . $dataDir);
        // throw new RuntimeException("Database directory is not writable: " . $dataDir); // Or handle gracefully
    }


    $db = new SQLite3($dbFilePath);
    $db->exec("PRAGMA foreign_keys = ON;");

    // --- Database Schema Initialization (Tables & Indexes) ---
    // Users Table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        username TEXT,
        balance INTEGER DEFAULT 0,
        referrals INTEGER DEFAULT 0,
        ref_code TEXT UNIQUE,
        referred_by INTEGER,
        blocked BOOLEAN DEFAULT 0,
        last_earn INTEGER DEFAULT 0, -- Consider if this field is actively used
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Investments Table
    $db->exec("CREATE TABLE IF NOT EXISTS investments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        plan_id INTEGER,
        amount INTEGER,
        start_date INTEGER,
        end_date INTEGER,
        status TEXT DEFAULT 'active', -- e.g., 'active', 'completed'
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE -- Added ON DELETE CASCADE
    )");
    
    // Stat Channels Table (used by send_stats.php)
    $db->exec("CREATE TABLE IF NOT EXISTS stat_channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_id TEXT NOT NULL UNIQUE,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // --- Indexes ---
    $db->exec("CREATE INDEX IF NOT EXISTS idx_investments_user_id ON investments (user_id);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_investments_status_end_date ON investments (status, end_date);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_referred_by ON users (referred_by);");
    // Primary keys and UNIQUE constraints (user_id, ref_code, investments.id, stat_channels.id, stat_channels.channel_id) are automatically indexed.

} catch (Exception $e) {
    error_log("DB CRITICAL ERROR: " . $e->getMessage());
    // In a web context, this might result in a 500 error.
    // For CLI (like send_stats), script might exit or fail.
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        die("Database initialization error. Check logs.");
    } else {
        die("CLI: Database initialization error. Check logs.\n");
    }
}

// --- Helper: Logging Function (can be used by other scripts) ---
// Moved from index.php to be globally available if bootstrap is included.
// Or, each script can define its own if preferred, but this promotes DRY.
if (!function_exists('bot_log')) {
    function bot_log($message, $level = "INFO") {
        global $errorLogPath; // Relies on $errorLogPath from this bootstrap file.
        $timestamp = date('Y-m-d H:i:s');
        // Ensure $errorLogPath is writable, though it might be too late here if it failed earlier.
        // It's better if the calling script ensures logging is possible or handles failure.
        if (!empty($errorLogPath) && is_writable(dirname($errorLogPath)) || (file_exists($errorLogPath) && is_writable($errorLogPath)) ) {
            file_put_contents($errorLogPath, "[$timestamp] [$level] $message\n", FILE_APPEND);
        } else {
            // Fallback to PHP's error_log if custom path isn't writable
            error_log("[$timestamp] [$level] $message");
            if (!empty($errorLogPath)) {
                error_log("Additionally, failed to write to configured error_log: $errorLogPath");
            }
        }
    }
}

?>
