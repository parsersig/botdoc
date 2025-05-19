<?php
// =============================================
// 🚀 Telegram Bot Webhook Handler for Render.com
// =============================================

// Health check endpoint
if ($_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'time' => date('Y-m-d H:i:s'),
        'version' => '1.0.0'
    ]);
    exit;
}

// CLI mode for webhook setup (run during deploy)
if (php_sapi_name() === 'cli') {
    require __DIR__.'/setup_webhook.php';
    exit;
}

// Main webhook handler
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty(file_get_contents("php://input"))) {
    http_response_code(200);
    echo "Telegram Bot Webhook Endpoint";
    exit;
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/error.log');

// Register shutdown function for error handling
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && $error['type'] === E_ERROR) {
        file_put_contents('/tmp/error.log', "Fatal error: ".print_r($error, true)."\n", FILE_APPEND);
    }
});

// Constants
define('DB_FILE', '/tmp/bot_database.db');
define('CHANNEL_ID', '-1002543728373'); // Your channel ID
define('WEBHOOK_URL', 'https://'.($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Environment variables
$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$adminId = getenv('ADMIN_ID') ?: '';
$botUsername = getenv('BOT_USERNAME') ?: 'CRYPTOCAP_ROBOT';

// Validate config
foreach (['TELEGRAM_BOT_TOKEN'=>$botToken, 'ADMIN_ID'=>$adminId, 'BOT_USERNAME'=>$botUsername] as $key=>$value) {
    if (empty($value)) {
        file_put_contents('/tmp/error.log', "Missing $key config\n", FILE_APPEND);
        http_response_code(500);
        die("Configuration error");
    }
}

// Initialize database
try {
    if (!file_exists(DB_FILE)) {
        touch(DB_FILE);
        chmod(DB_FILE, 0666);
    }

    $db = new SQLite3(DB_FILE);
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        username TEXT,
        balance INTEGER DEFAULT 0,
        referrals INTEGER DEFAULT 0,
        ref_code TEXT,
        referred_by INTEGER,
        blocked BOOLEAN DEFAULT 0,
        last_earn INTEGER DEFAULT 0,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    file_put_contents('/tmp/error.log', "DB Error: ".$e->getMessage()."\n", FILE_APPEND);
    http_response_code(500);
    die("Database error");
}

// API URL without trailing slash
$apiUrl = "https://api.telegram.org/bot$botToken";

// -----------------------------
// 🛠️ Helper Functions
// -----------------------------
function logMessage($message) {
    file_put_contents('/tmp/request.log', "[".date('Y-m-d H:i:s')."] $message\n", FILE_APPEND);
}

function apiRequest($method, $params = [], $retries = 3) {
    global $apiUrl;

    $url = "$apiUrl/$method";
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data']
    ]);

    for ($i = 0; $i < $retries; $i++) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            curl_close($ch);
            return $result;
        }

        if ($i < $retries - 1) {
            sleep(1);
            continue;
        }

        logMessage("API Error ($method): HTTP $httpCode - ".curl_error($ch));
        curl_close($ch);
        return false;
    }
}

// ... [остальные функции остаются без изменений, как в вашем исходном коде]
// (getSubscriptionKeyboard, getMainKeyboard, handleStart, handleCallback и т.д.)

// -----------------------------
// 🚀 Main Webhook Handler
// -----------------------------
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    logMessage("Invalid JSON received");
    echo "OK";
    exit;
}

try {
    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
    } elseif (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;

        if (!$chatId) {
            logMessage("No chat ID in message");
            echo "OK";
            exit;
        }

        // Initialize user if not exists
        if (!$db->querySingle("SELECT 1 FROM users WHERE user_id=$chatId")) {
            $username = $message['from']['username'] ?? null;
            $refCode = substr(md5($chatId.time()), 0, 8);
            $db->exec("INSERT INTO users (user_id, username, ref_code) VALUES ($chatId, '$username', '$refCode')");
        }

        // Check if blocked
        if ($db->querySingle("SELECT blocked FROM users WHERE user_id=$chatId") == 1) {
            sendMessage($chatId, "🚫 Вы заблокированы администратором.");
            echo "OK";
            exit;
        }

        // Handle commands
        $text = trim($message['text'] ?? '');
        if (strpos($text, '/start') === 0) {
            handleStart($chatId, $text);
        } elseif (in_array($text, ['💰 Заработать', ' mtx', ' mtw', '🏆 Топ', '👥 Рефералы', '⚙️ Админ'])) {
            handleCommand($chatId, $text);
        }
    }
} catch (Exception $e) {
    logMessage("Error: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine());
    http_response_code(500);
}

echo "OK";
