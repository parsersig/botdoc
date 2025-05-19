<?php
// =============================================
// 🚀 Telegram Bot Webhook Handler for Render.com
// =============================================
// Version 1.3.0: Configurable paths, improved callback handling

// --- Configuration ---
// Эти пути будут использоваться, если переменные окружения не установлены.
// Для Render.com, установите DB_FILE_PATH и ERROR_LOG_PATH через переменные окружения
// чтобы указывать на ваш Render Disk (например, /mnt/disk/bot_database.db)

define('DEFAULT_DB_FILE', '/tmp/bot_database.db');
define('DEFAULT_ERROR_LOG_FILE', '/tmp/error.log'); // Лог ошибок PHP и бота

$dbFilePath = getenv('DB_FILE_PATH') ?: DEFAULT_DB_FILE;
$errorLogPath = getenv('ERROR_LOG_PATH') ?: DEFAULT_ERROR_LOG_FILE;

// Устанавливаем error_log для PHP
ini_set('error_log', $errorLogPath);
ini_set('log_errors', 1);
ini_set('display_errors', 0); // Не отображать ошибки пользователям
error_reporting(E_ALL);

// Health check endpoint
if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'time' => date('Y-m-d H:i:s'),
        'version' => '1.3.0'
    ]);
    exit;
}

// Register shutdown function for fatal errors
register_shutdown_function(function() use ($errorLogPath) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        $logMessage = sprintf(
            "[%s] Fatal Error: %s in %s on line %d\n",
            date('Y-m-d H:i:s'),
            $error['message'],
            $error['file'],
            $error['line']
        );
        file_put_contents($errorLogPath, $logMessage, FILE_APPEND);
    }
});


// Constants from Environment Variables
$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$adminId = getenv('ADMIN_ID') ?: '';
$botUsername = getenv('BOT_USERNAME') ?: 'MyTestBot'; // Default fallback
$channelId = getenv('CHANNEL_ID') ?: ''; // Может быть не установлен, проверяйте использование
$webhookBaseUrl = getenv('WEBHOOK_BASE_URL') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Validate essential config
if (empty($botToken) || empty($adminId)) {
    file_put_contents($errorLogPath, "[".date('Y-m-d H:i:s')."] Missing critical environment variables: TELEGRAM_BOT_TOKEN or ADMIN_ID\n", FILE_APPEND);
    http_response_code(500);
    die("Configuration error: Missing TELEGRAM_BOT_TOKEN or ADMIN_ID");
}


// API URL
$apiUrl = "https://api.telegram.org/bot$botToken";

// Initialize database
try {
    $dataDir = dirname($dbFilePath);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true); // Attempt to create data directory
    }
    if (!file_exists($dbFilePath) && is_writable($dataDir)) {
        touch($dbFilePath); // Create file if it doesn't exist to ensure correct permissions later
    }
    if (file_exists($dbFilePath) && !is_writable($dbFilePath)) {
         chmod($dbFilePath, 0666); // Ensure writable if exists
    }


    $db = new SQLite3($dbFilePath);
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        username TEXT,
        balance INTEGER DEFAULT 0,
        referrals INTEGER DEFAULT 0,
        ref_code TEXT UNIQUE,
        referred_by INTEGER,
        blocked BOOLEAN DEFAULT 0,
        last_earn INTEGER DEFAULT 0,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    file_put_contents($errorLogPath, "[".date('Y-m-d H:i:s')."] DB Error: ".$e->getMessage()."\n", FILE_APPEND);
    http_response_code(500);
    die("Database error: " . $e->getMessage());
}

// Webhook auto-setup
if (isset($_GET['setwebhook']) && $_GET['setwebhook'] === '1') {
    $scriptPath = $_SERVER['PHP_SELF']; // e.g. /index.php
    $webhookUrlToSet = rtrim($webhookBaseUrl, '/') . $scriptPath;
    $setWebhookUrl = "$apiUrl/setWebhook?url=" . urlencode($webhookUrlToSet);
    $result = @file_get_contents($setWebhookUrl); // Use @ to suppress errors if any, log them instead
    $logEntry = "[".date('Y-m-d H:i:s')."] Webhook setup attempt to $webhookUrlToSet. Result: $result\n";
    file_put_contents($errorLogPath, $logEntry, FILE_APPEND); // Log to error_log for visibility
    echo "Webhook setup attempt. Result: " . htmlspecialchars($result);
    exit;
}
if (isset($_GET['deletewebhook']) && $_GET['deletewebhook'] === '1') {
    $result = @file_get_contents("$apiUrl/deleteWebhook");
    $logEntry = "[".date('Y-m-d H:i:s')."] Webhook delete attempt. Result: $result\n";
    file_put_contents($errorLogPath, $logEntry, FILE_APPEND);
    echo "Webhook delete attempt. Result: " . htmlspecialchars($result);
    exit;
}


// Main webhook handler
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Allow GET for health check and webhook setup, otherwise only POST
    if (! (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/health') &&
        ! (isset($_GET['setwebhook']) && $_GET['setwebhook'] === '1') &&
        ! (isset($_GET['deletewebhook']) && $_GET['deletewebhook'] === '1') ) {
        http_response_code(405); // Method Not Allowed
        echo "Method Not Allowed. This endpoint expects POST requests from Telegram.";
    }
    exit;
}

$content = file_get_contents("php://input");
if (empty($content)) {
    http_response_code(200); // Telegram expects 200 even for empty POSTs sometimes (e.g. during webhook test)
    echo "Empty request body.";
    exit;
}

// -----------------------------
// 🛠️ Helper Functions
// -----------------------------
function bot_log($message, $level = "INFO") {
    global $errorLogPath; // Use the global error log path
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($errorLogPath, "[$timestamp] [$level] $message\n", FILE_APPEND);
}

function apiRequest($method, $params = [], $retries = 3) {
    global $apiUrl;
    $url = "$apiUrl/$method";

    if (isset($params['reply_markup']) && is_array($params['reply_markup'])) {
        $params['reply_markup'] = json_encode($params['reply_markup']);
    }

    $ch = curl_init();
    $curlOptions = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true, // Recommended for production
    ];
    curl_setopt_array($ch, $curlOptions);

    for ($i = 0; $i < $retries; $i++) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false) {
            bot_log("API Error ($method): cURL Error: $curlError. HTTP Code: $httpCode. URL: $url. Params: " . json_encode($params), "ERROR");
            if ($i < $retries - 1) {
                sleep(1 + $i); // Exponential backoff
                continue;
            }
            curl_close($ch);
            return false;
        }
        
        $result = json_decode($response, true);
        if ($httpCode === 200 && isset($result['ok']) && $result['ok'] === true) {
            curl_close($ch);
            return $result;
        }

        bot_log("API Error ($method): HTTP $httpCode - Response: $response. URL: $url. Params: " . json_encode($params), "ERROR");
        if ($httpCode >= 500 && $i < $retries - 1) { // Retry on server errors
            sleep(1 + $i); 
            continue;
        }
        
        curl_close($ch);
        return $result; // Return API error response for handling
    }
    return false; 
}

function sendMessage($chatId, $text, $keyboard = null, $message_thread_id = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    if ($keyboard) {
        $params['reply_markup'] = $keyboard;
    }
    if ($message_thread_id) {
        $params['message_thread_id'] = $message_thread_id;
    }
    return apiRequest('sendMessage', $params);
}

function editMessage($chatId, $msgId, $text, $keyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $msgId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    if ($keyboard) {
        $params['reply_markup'] = $keyboard;
    }
    return apiRequest('editMessageText', $params);
}

function answerCallbackQuery($callbackQueryId, $text = null, $showAlert = false) {
    $params = ['callback_query_id' => $callbackQueryId];
    if ($text !== null) { // Allow empty string for text
        $params['text'] = $text;
    }
    $params['show_alert'] = $showAlert;
    return apiRequest('answerCallbackQuery', $params);
}

function isSubscribed($userId) {
    global $botToken, $channelId;
    if (empty($channelId)) {
        bot_log("Channel ID not configured. Subscription check skipped for user $userId.", "WARNING");
        return true; // Skip check if channel ID is not set
    }
    
    $url = "https://api.telegram.org/bot$botToken/getChatMember?chat_id=" . $channelId . "&user_id=$userId";

    $response = @file_get_contents($url); 
    if ($response === false) {
        bot_log("isSubscribed: Failed to fetch from $url. User: $userId, Channel: $channelId", "ERROR");
        return false;
    }
    $data = json_decode($response, true);

    if (!isset($data['ok']) || $data['ok'] === false) {
        bot_log("isSubscribed: API error for user $userId, channel $channelId. Response: " . $response, "ERROR");
        // Specific error messages from Telegram can be useful here
        if (isset($data['description'])) {
            bot_log("Telegram API error description: " . $data['description'], "ERROR");
            if (strpos($data['description'], "not found") !== false || strpos($data['description'], "kicked") !== false) {
                 return false; // User definitely not in channel
            }
        }
        return false; // Default to not subscribed on API error
    }
    return isset($data['result']['status']) && in_array($data['result']['status'], ['member', 'administrator', 'creator']);
}

// -----------------------------
// ⌨️ Keyboards (All Inline)
// -----------------------------
function getSubscriptionKeyboard() {
    global $channelId;
    if (empty($channelId)) return null;

    $channelUrl = '';
    if (strpos((string)$channelId, "-100") === 0) { // Private channel/supergroup
        $channelIdForLink = substr((string)$channelId, 4);
        $channelUrl = 'https://t.me/c/' . $channelIdForLink;
    } elseif ($channelId[0] === '@') { // Public channel username
        $channelUrl = 'https://t.me/' . ltrim($channelId, '@');
    } else {
        // Potentially a public channel ID, linking directly might not work well.
        // Or an invalid ID. For simplicity, we assume private or username.
        bot_log("Cannot generate channel URL for Channel ID: $channelId", "WARNING");
        return null; // Or provide a generic message without URL
    }

    return [
        'inline_keyboard' => [[
            ['text' => '📢 Наш канал', 'url' => $channelUrl],
            ['text' => '✅ Я подписался', 'callback_data' => 'check_subscription']
        ]]
    ];
}

function getMainMenuInlineKeyboard($isAdmin = false) {
    $inline_keyboard = [
        [
            ['text' => '💰 Заработать', 'callback_data' => 'earn_money'],
            ['text' => '💳 Баланс', 'callback_data' => 'show_balance']
        ],
        [
            ['text' => '🏆 Топ', 'callback_data' => 'show_top_users'],
            ['text' => '👥 Рефералы', 'callback_data' => 'show_referrals_info']
        ],
        [
            ['text' => '💸 Вывод', 'callback_data' => 'initiate_withdraw'],
            ['text' => 'ℹ️ Помощь', 'callback_data' => 'show_help_info']
        ]
    ];
    if ($isAdmin) {
        $inline_keyboard[] = [['text' => '⚙️ Админ-панель', 'callback_data' => 'admin_panel_show']];
    }
    return ['inline_keyboard' => $inline_keyboard];
}

function getBackToMainMenuKeyboard() {
    return ['inline_keyboard' => [[['text' => '⬅️ Назад в меню', 'callback_data' => 'main_menu_show']]]];
}

function getAdminPanelKeyboard() {
    return ['inline_keyboard' => [
        [['text' => '📊 Статистика', 'callback_data' => 'admin_stats_show']],
        [['text' => '👤 Участники', 'callback_data' => 'admin_users_list']],
        [['text' => '⬅️ В главное меню', 'callback_data' => 'main_menu_show']]
    ]];
}

function getBackToAdminPanelKeyboard() {
     return ['inline_keyboard' => [[['text' => '⬅️ Назад в админ-панель', 'callback_data' => 'admin_panel_show']]]];
}

function getWithdrawKeyboard($targetUserId) { 
    return ['inline_keyboard' => [[
        ['text' => '✅ Одобрить', 'callback_data' => "approve_withdraw_$targetUserId"],
        ['text' => '❌ Отклонить', 'callback_data' => "reject_withdraw_$targetUserId"]
    ]]];
}

function getUserActionsKeyboard($targetUserId, $isBlocked) {
    $blockButtonText = $isBlocked ? '✅ Разблокировать' : '🚫 Заблокировать';
    $blockCallbackData = $isBlocked ? "unblock_user_$targetUserId" : "block_user_$targetUserId";
    return ['inline_keyboard' => [
        [
            ['text' => $blockButtonText, 'callback_data' => $blockCallbackData]
        ],
        [
            ['text' => '⬅️ К списку участников', 'callback_data' => 'admin_users_list'] 
        ]
    ]];
}

// -----------------------------
// 📊 Bot Stats & Info
// -----------------------------
function getBotStatsText() {
    global $db;
    $stats = ['total' => 0, 'active' => 0, 'balance' => 0, 'referrals' => 0];
    $topUsers = [];

    $result = $db->query("SELECT user_id, username, balance, referrals, blocked FROM users ORDER BY balance DESC");
    if (!$result) {
        bot_log("Error fetching users for stats: " . $db->lastErrorMsg(), "ERROR");
        return "Ошибка при получении статистики.";
    }

    while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
        $stats['total']++;
        $stats['balance'] += $user['balance'];
        $stats['referrals'] += $user['referrals'];
        if (!$user['blocked']) $stats['active']++;
        if (count($topUsers) < 5) { 
            $topUsers[] = $user;
        }
    }

    $message = "📊 <b>Статистика бота</b>\n";
    $message .= "👥 Всего пользователей: <b>{$stats['total']}</b>\n";
    $message .= "🟢 Активных (не заблокированных): <b>{$stats['active']}</b>\n";
    $message .= "💰 Общий баланс пользователей: <b>{$stats['balance']}</b>\n";
    $message .= "🔗 Всего привлечено рефералов: <b>{$stats['referrals']}</b>\n\n";
    $message .= "🏆 <b>Топ-5 пользователей по балансу</b>:\n";
    if (empty($topUsers)) {
        $message .= "Пока нет пользователей в топе.\n";
    } else {
        foreach ($topUsers as $i => $user) {
            $status = $user['blocked'] ? '🚫' : '✅';
            $usernameDisplay = $user['username'] ? htmlspecialchars("@".$user['username']) : "ID: ".$user['user_id'];
            $message .= ($i+1) . ". $usernameDisplay - <b>{$user['balance']}</b> баллов (Реф: {$user['referrals']}) $status\n";
        }
    }
    $message .= "\n⏱ Обновлено: " . date('d.m.Y H:i:s');
    return $message;
}

// -----------------------------
// 📨 Command Handlers & Callback Logic
// -----------------------------
function handleStart($chatId, $userId, $text) {
    global $db, $botUsername, $adminId, $channelId;

    $refCode = '';
    if (strpos($text, ' ') !== false) {
        $parts = explode(' ', $text, 2);
        if (count($parts) > 1) {
            $refCode = trim($parts[1]);
        }
    }

    $userExistsStmt = $db->prepare("SELECT 1 FROM users WHERE user_id = :user_id");
    $userExistsStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $userExistsResult = $userExistsStmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($userExistsResult && !empty($refCode)) {
        $userReferralInfoStmt = $db->prepare("SELECT referred_by FROM users WHERE user_id = :user_id");
        $userReferralInfoStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userReferralInfo = $userReferralInfoStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($userReferralInfo && empty($userReferralInfo['referred_by'])) { 
            $referrerQuery = $db->prepare("SELECT user_id FROM users WHERE ref_code = :ref_code AND user_id != :user_id");
            $referrerQuery->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
            $referrerQuery->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $referrerResult = $referrerQuery->execute();
            $referrer = $referrerResult->fetchArray(SQLITE3_ASSOC);

            if ($referrer && $referrer['user_id'] != $userId) {
                $updateReferrerStmt = $db->prepare("UPDATE users SET referrals = referrals + 1, balance = balance + 50 WHERE user_id = :referrer_id");
                $updateReferrerStmt->bindValue(':referrer_id', $referrer['user_id'], SQLITE3_INTEGER);
                $updateReferrerStmt->execute();
                sendMessage($referrer['user_id'], "🎉 Новый реферал присоединился по вашей ссылке! +50 баллов на ваш счет.");
                
                $updateUserStmt = $db->prepare("UPDATE users SET referred_by = :referrer_id WHERE user_id = :user_id");
                $updateUserStmt->bindValue(':referrer_id', $referrer['user_id'], SQLITE3_INTEGER);
                $updateUserStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $updateUserStmt->execute();
            }
        }
    }
    
    if (!empty($channelId) && !isSubscribed($userId)) {
        $message = "👋 Добро пожаловать в @$botUsername!\n\n";
        $message .= "Для начала, пожалуйста, <b>подпишитесь на наш канал</b>. Это обязательное условие для использования бота.\n\n";
        $message .= "После подписки нажмите кнопку «Я подписался».";
        $subKeyboard = getSubscriptionKeyboard();
        if ($subKeyboard) {
            sendMessage($chatId, $message, $subKeyboard);
        } else {
            sendMessage($chatId, $message . "\nНе удалось сформировать ссылку на канал. Обратитесь к администратору.");
        }
        return;
    }

    $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
    $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');

    $message = "👋 Добро пожаловать в @$botUsername!\n\n";
    $message .= "💰 Зарабатывайте баллы, выполняйте задания и выводите их.\n";
    $message .= "👥 Приглашайте друзей и получайте бонусы! Ваша реферальная ссылка:\n<code>$refLink</code>\n\n";
    $message .= "👇 Используйте меню ниже для навигации.";
    sendMessage($chatId, $message, getMainMenuInlineKeyboard($userId == $adminId));
}

function handleCallback($callbackQuery) {
    global $db, $adminId, $botUsername, $channelId;

    $callbackQueryId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $msgId = $callbackQuery['message']['message_id'];
    $userId = $callbackQuery['from']['id']; 
    $data = $callbackQuery['data'];
    $userIsAdmin = ($userId == $adminId);

    // Always try to answer callback query to remove loading state from button
    // We will call it specifically in each branch or at the end.
    // For now, let's make sure it's called if a specific handler doesn't.
    $callbackAnswered = false;


    if ($data === 'check_subscription') {
        if (!empty($channelId) && isSubscribed($userId)) {
            $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
            $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
            $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');
            $message = "✅ Спасибо за подписку!\n\nТеперь вы можете пользоваться всеми функциями бота.\n\nВаша реферальная ссылка для приглашения друзей:\n<code>$refLink</code>\n\n";
            $message .= "👇 Вот главное меню:";
            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        } else if (empty($channelId)) {
             $message = "✅ Проверка подписки не требуется, так как канал не настроен.\n\n";
             $message .= "👇 Вот главное меню:";
             editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        }else {
            answerCallbackQuery($callbackQueryId, "❌ Вы всё ещё не подписаны. Пожалуйста, подпишитесь и нажмите кнопку ещё раз.", true);
            $callbackAnswered = true;
        }
        if (!$callbackAnswered) answerCallbackQuery($callbackQueryId);
        return;
    }
    
    if (!$userIsAdmin && !empty($channelId) && !isSubscribed($userId)) {
        $text = "Пожалуйста, подпишитесь на наш канал, чтобы продолжить.";
        $subKeyboard = getSubscriptionKeyboard();
        if ($subKeyboard) {
            editMessage($chatId, $msgId, $text, $subKeyboard);
        } else {
            editMessage($chatId, $msgId, $text . "\nНе удалось сформировать ссылку на канал. Обратитесь к администратору.");
        }
        answerCallbackQuery($callbackQueryId);
        return;
    }

    // --- Main Menu Callbacks ---
    if ($data === 'main_menu_show') {
        $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
        $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');
        $message = "👋 Главное меню @$botUsername!\n\n";
        $message .= "💰 Зарабатывайте баллы и выводите их.\n";
        $message .= "👥 Приглашайте друзей: <code>$refLink</code>\n\n";
        $message .= "👇 Используйте кнопки ниже:";
        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        answerCallbackQuery($callbackQueryId); $callbackAnswered = true;
    }

    else if ($data === 'earn_money') {
        $cooldown = 60; 
        $stmt = $db->prepare("SELECT last_earn, balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $remaining = $cooldown - (time() - ($row['last_earn'] ?? 0));

        if ($remaining > 0) {
            answerCallbackQuery($callbackQueryId, "⏳ Подождите $remaining секунд перед следующим заработком!", true); $callbackAnswered = true;
        } else {
            $earnedAmount = 10;
            $updateStmt = $db->prepare("UPDATE users SET balance = balance + :amount, last_earn = :time WHERE user_id = :user_id");
            $updateStmt->bindValue(':amount', $earnedAmount, SQLITE3_INTEGER);
            $updateStmt->bindValue(':time', time(), SQLITE3_INTEGER);
            $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $updateStmt->execute();
            $newBalance = ($row['balance'] ?? 0) + $earnedAmount;
            answerCallbackQuery($callbackQueryId, "✅ +$earnedAmount баллов! Ваш баланс: $newBalance", false); $callbackAnswered = true;
        }
    }

    else if ($data === 'show_balance') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        answerCallbackQuery($callbackQueryId, "💳 Ваш текущий баланс: " . $balance . " баллов.", false); $callbackAnswered = true;
    }

    else if ($data === 'show_top_users') {
        editMessage($chatId, $msgId, getBotStatsText(), getBackToMainMenuKeyboard());
        answerCallbackQuery($callbackQueryId); $callbackAnswered = true;
    }

    else if ($data === 'show_referrals_info') {
        $stmt = $db->prepare("SELECT ref_code, referrals FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');
        $msg = "👥 <b>Ваша реферальная система</b>\n\n";
        $msg .= "Ваш уникальный код приглашения: <code>" . ($user['ref_code'] ?? 'N/A'). "</code>\n";
        $msg .= "Приглашено друзей: <b>" . ($user['referrals'] ?? 0) . "</b> чел.\n";
        $msg .= "Ваша ссылка для приглашений:\n<code>$refLink</code>\n\n";
        $msg .= "💰 Вы получаете <b>50 баллов</b> за каждого друга!";
        editMessage($chatId, $msgId, $msg, getBackToMainMenuKeyboard());
        answerCallbackQuery($callbackQueryId); $callbackAnswered = true;
    }

    else if ($data === 'initiate_withdraw') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $balance = $userRow['balance'] ?? 0;
        $minWithdraw = 100;

        if ($balance < $minWithdraw) {
            $needed = $minWithdraw - $balance;
            answerCallbackQuery($callbackQueryId, "❌ Мин. сумма: $minWithdraw баллов. Вам не хватает: $needed.", true); $callbackAnswered = true;
        } else {
            $userFrom = $callbackQuery['from'];
            $usernameFrom = isset($userFrom['username']) ? "@".htmlspecialchars($userFrom['username']) : "ID: ".$userId;

            $adminMsg = "🔔 Новый запрос на вывод средств!\n\n";
            $adminMsg .= "👤 Пользователь: $usernameFrom (ID: $userId)\n";
            $adminMsg .= "💰 Сумма к выводу: $balance баллов\n";
            $adminMsg .= "⏱ Время запроса: " . date('d.m.Y H:i:s');

            sendMessage($adminId, $adminMsg, getWithdrawKeyboard($userId));
            $userConfirmationMsg = "✅ Ваш запрос на вывод $balance баллов отправлен администратору. Ожидайте.";
            // Не редактируем сообщение, а отвечаем через answerCallbackQuery и, возможно, отправляем новое с главным меню
            answerCallbackQuery($callbackQueryId, $userConfirmationMsg, false); $callbackAnswered = true;
            // sendMessage($chatId, $userConfirmationMsg . "\n\n👇 Выберите следующее действие:", getMainMenuInlineKeyboard($userIsAdmin));
        }
    }

    else if ($data === 'show_help_info') {
        $msg = "ℹ️ <b>Информация и Помощь</b>\n\n";
        $msg .= "🤖 @$botUsername - это бот для заработка баллов.\n\n";
        $msg .= "💰 <b>Заработать</b> — Нажмите, чтобы получить баллы (кулдаун 60 сек).\n";
        $msg .= "💳 <b>Баланс</b> — Узнать ваш текущий баланс.\n";
        $msg .= "🏆 <b>Топ</b> — Посмотреть рейтинг пользователей.\n";
        $msg .= "👥 <b>Рефералы</b> — Приглашайте друзей и получайте бонусы.\n";
        $msg .= "💸 <b>Вывод</b> — Запросить вывод (мин. 100 баллов).\n\n";
        if (!empty($channelId)) $msg .= "📢 Не забудьте быть подписанным на наш основной канал!\n\n";
        $msg .= "При возникновении проблем обращайтесь к администрации.";
        editMessage($chatId, $msgId, $msg, getBackToMainMenuKeyboard());
        answerCallbackQuery($callbackQueryId); $callbackAnswered = true;
    }

    // --- Admin Panel Callbacks ---
    else if ($userIsAdmin) { // Group all admin actions here
        if ($data === 'admin_panel_show') {
            editMessage($chatId, $msgId, "⚙️ <b>Админ-панель</b>\nВыберите действие:", getAdminPanelKeyboard());
            answerCallbackQuery($callbackQueryId); $callbackAnswered = true;
        }
        else if ($data === 'admin_stats_show') {
            editMessage($chatId, $msgId, getBotStatsText(), getBackToAdminPanelKeyboard());
            answerCallbackQuery($callbackQueryId); $callbackAnswered = true;
        }
        else if ($data === 'admin_users_list') {
            $result = $db->query("SELECT user_id, username, balance, blocked FROM users ORDER BY joined_at DESC LIMIT 20"); 
            $usersKeyboard = ['inline_keyboard' => []];
            $userListText = "👥 <b>Список участников (последние 20)</b>:\n\n";
            $count = 0;
            if ($result) {
                while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
                    $count++;
                    $statusIcon = $user['blocked'] ? '🚫' : '✅';
                    $usernameDisplay = $user['username'] ? htmlspecialchars("@".$user['username']) : "ID:".$user['user_id'];
                    $usersKeyboard['inline_keyboard'][] = [[
                        'text' => "$statusIcon $usernameDisplay | 💰: {$user['balance']}",
                        'callback_data' => "admin_user_details_{$user['user_id']}"
                    ]];
                }
            }
            if ($count == 0) $userListText .= "Пока нет зарегистрированных пользователей.";

            $usersKeyboard['inline_keyboard'][] = [['text' => '⬅️ Назад в админ-панель', 'callback_data' => 'admin_panel_show']];
            editMessage($chatId, $msgId, $userListText, $usersKeyboard);
            answerCallbackQuery($callbackQueryId); $callbackAnswered = true;
        }
        else if (strpos($data, 'admin_user_details_') === 0) {
            $targetUserId = (int)str_replace('admin_user_details_', '', $data);
            $stmt = $db->prepare("SELECT * FROM users WHERE user_id = :user_id");
            $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
            $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if ($user) {
                $message = "👤 <b>Профиль пользователя</b>\n";
                $message .= "ID: <b>{$user['user_id']}</b>\n";
                $message .= "Username: " . ($user['username'] ? htmlspecialchars("@{$user['username']}") : "<i>не указан</i>") . "\n";
                $message .= "Баланс: <b>{$user['balance']}</b> баллов\n";
                $message .= "Рефералов: <b>{$user['referrals']}</b>\n";
                $message .= "Приглашен (ID): " . ($user['referred_by'] ?: "<i>нет</i>") . "\n";
                $message .= "Реф. код: <code>{$user['ref_code']}</code>\n";
                $message .= "Подписка на канал: " . (!empty($channelId) && isSubscribed($targetUserId) ? '✅ Да' : (empty($channelId) ? 'Н/Д' : '❌ Нет')) . "\n";
                $message .= "Статус: " . ($user['blocked'] ? '🚫 <b>Заблокирован</b>' : '✅ Активен') . "\n";
                $message .= "Зарегистрирован: " . $user['joined_at'] . "\n";
                $message .= "Последний заработок: " . ($user['last_earn'] ? date('Y-m-d H:i:s', $user['last_earn']) : "<i>не было</i>") . "\n";

                editMessage($chatId, $msgId, $message, getUserActionsKeyboard($targetUserId, $user['blocked']));
            } else {
                answerCallbackQuery($callbackQueryId, "Пользователь не найден.", true); $callbackAnswered = true;
            }
            if (!$callbackAnswered) answerCallbackQuery($callbackQueryId); $callbackAnswered = true;
        }
        else if (strpos($data, 'approve_withdraw_') === 0) {
            $targetUserId = (int)str_replace('approve_withdraw_', '', $data);
            $stmt = $db->prepare("SELECT balance, username FROM users WHERE user_id = :user_id");
            $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
            $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if ($user) {
                $amount = $user['balance']; 
                $updateStmt = $db->prepare("UPDATE users SET balance = 0 WHERE user_id = :user_id");
                $updateStmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
                $updateStmt->execute();

                $adminConfirmationMsg = "✅ Заявка на вывод для ID $targetUserId (" . ($user['username'] ? "@".htmlspecialchars($user['username']) : '') . ") на $amount баллов ОДОБРЕНА.\nБаланс обнулен.";
                editMessage($chatId, $msgId, $adminConfirmationMsg); 
                sendMessage($targetUserId, "🎉 Ваша заявка на вывод $amount баллов ОДОБРЕНА!");
            } else {
                editMessage($chatId, $msgId, "❌ Ошибка: Пользователь $targetUserId для одобрения не найден.");
            }
            answerCallbackQuery($callbackQueryId); $callbackAnswered = true;
        }
        else if (strpos($data, 'reject_withdraw_') === 0) {
            $targetUserId = (int)str_replace('reject_withdraw_', '', $data);
            $stmt = $db->prepare("SELECT balance, username FROM users WHERE user_id = :user_id");
            $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
            $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if ($user) {
                $amount = $user['balance']; 
                $adminConfirmationMsg = "❌ Заявка на вывод для ID $targetUserId (" . ($user['username'] ? "@".htmlspecialchars($user['username']) : '') . ") на $amount баллов ОТКЛОНЕНА.\nБаланс НЕ изменен.";
                editMessage($chatId, $msgId, $adminConfirmationMsg); 
                sendMessage($targetUserId, "⚠️ Ваша заявка на вывод $amount баллов ОТКЛОНЕНА. Средства на балансе.");
            } else {
                editMessage($chatId, $msgId, "❌ Ошибка: Пользователь $targetUserId для отклонения не найден.");
            }
            answerCallbackQuery($callbackQueryId); $callbackAnswered = true;
        }
        else if (strpos($data, 'block_user_') === 0) {
            $targetUserId = (int)str_replace('block_user_', '', $data);
            if ($targetUserId != $adminId) { 
                $stmt = $db->prepare("UPDATE users SET blocked=1 WHERE user_id = :user_id");
                $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
                $stmt->execute();
                sendMessage($targetUserId, "🚫 Администратор заблокировал ваш доступ к боту.");
                answerCallbackQuery($callbackQueryId, "✅ Пользователь ID $targetUserId заблокирован.", false); $callbackAnswered = true;
                 // To refresh admin view:
                $userStmt = $db->prepare("SELECT * FROM users WHERE user_id = :user_id");
                $userStmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
                $updatedUser = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
                if ($updatedUser) {
                     $message = "👤 <b>Профиль пользователя (обновлено)</b>\n";
                     $message .= "ID: <b>{$updatedUser['user_id']}</b> ... Статус: 🚫 <b>Заблокирован</b>"; // Сокращенно для примера
                     // Полное обновление сообщения с актуальной клавиатурой
                     // editMessage($chatId, $msgId, $полный_текст_профиля, getUserActionsKeyboard($targetUserId, true));
                }
            } else {
                answerCallbackQuery($callbackQueryId, "⛔ Нельзя заблокировать самого себя.", true); $callbackAnswered = true;
            }
        }
        else if (strpos($data, 'unblock_user_') === 0) {
            $targetUserId = (int)str_replace('unblock_user_', '', $data);
            $stmt = $db->prepare("UPDATE users SET blocked=0 WHERE user_id = :user_id");
            $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
            $stmt->execute();
            sendMessage($targetUserId, "🎉 Ваш доступ к боту восстановлен!");
            answerCallbackQuery($callbackQueryId, "✅ Пользователь ID $targetUserId разблокирован.", false); $callbackAnswered = true;
            // Optionally refresh admin view
        }
    } else if (!$userIsAdmin && 
               (strpos($data, 'admin_') === 0 || 
                strpos($data, 'approve_') === 0 || 
                strpos($data, 'reject_') === 0 || 
                strpos($data, 'block_') === 0 || 
                strpos($data, 'unblock_') === 0)
              ) {
        answerCallbackQuery($callbackQueryId, "⛔ У вас нет доступа к этой функции.", true); $callbackAnswered = true;
    }


    // Fallback if no specific callback handled it and answerCallbackQuery was not called
    if (!$callbackAnswered) {
        bot_log("Unhandled callback_data: $data by user $userId", "WARNING");
        answerCallbackQuery($callbackQueryId, "Неизвестная команда или действие.", true);
    }
}


// -----------------------------
// 🚀 Main Webhook Logic
// -----------------------------
$update = json_decode($content, true);

if (!$update) {
    bot_log("Invalid JSON received: " . $content, "ERROR");
    http_response_code(400); // Bad Request
    echo "Invalid JSON.";
    exit;
}

bot_log("Received update: " . json_encode($update), "DEBUG"); 

try {
    $message_thread_id = $update['message']['message_thread_id'] ?? ($update['callback_query']['message']['message_thread_id'] ?? null);

    if (isset($update['callback_query'])) {
        $userId = $update['callback_query']['from']['id'];
        $username = $update['callback_query']['from']['username'] ?? null;
        
        $stmt = $db->prepare("SELECT 1 FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        if (!$stmt->execute()->fetchArray(SQLITE3_ASSOC)) {
            $refCode = substr(bin2hex(random_bytes(4)), 0, 8); 
            $insertStmt = $db->prepare("INSERT OR IGNORE INTO users (user_id, username, ref_code) VALUES (:user_id, :username, :ref_code)");
            $insertStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $insertStmt->bindValue(':username', $username ? substr($username, 0, 255) : null, SQLITE3_TEXT);
            $insertStmt->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
            if (!$insertStmt->execute()) {
                bot_log("Failed to insert user $userId ($username) on callback. DB Error: " . $db->lastErrorMsg(), "ERROR");
            }
        }
        handleCallback($update['callback_query']);

    } elseif (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;
        $userId = $message['from']['id'] ?? null; 

        if (!$chatId || !$userId) {
            bot_log("No chat_id or user_id in message: " . json_encode($message), "WARNING");
            echo "OK"; // Acknowledge Telegram
            exit;
        }

        $stmt = $db->prepare("SELECT 1 FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        if (!$stmt->execute()->fetchArray(SQLITE3_ASSOC)) {
            $username = $message['from']['username'] ?? null;
            $refCode = substr(bin2hex(random_bytes(4)), 0, 8); 

            $insertStmt = $db->prepare("INSERT INTO users (user_id, username, ref_code) VALUES (:user_id, :username, :ref_code)");
            $insertStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $insertStmt->bindValue(':username', $username ? substr($username, 0, 255) : null, SQLITE3_TEXT);
            $insertStmt->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
            if(!$insertStmt->execute()) {
                 bot_log("Failed to insert new user $userId ($username). DB Error: " . $db->lastErrorMsg(), "ERROR");
            } else {
                 bot_log("New user $userId ($username) initialized with ref_code $refCode.", "INFO");
            }
        }

        $userBlockedStmt = $db->prepare("SELECT blocked FROM users WHERE user_id = :user_id");
        $userBlockedStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userStatus = $userBlockedStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($userId != $adminId && isset($userStatus['blocked']) && $userStatus['blocked'] == 1) {
            sendMessage($chatId, "🚫 Вы заблокированы администратором и не можете использовать этого бота.", null, $message_thread_id);
            echo "OK";
            exit;
        }

        $text = trim($message['text'] ?? '');
        if (strpos($text, '/start') === 0) {
            handleStart($chatId, $userId, $text);
        } else {
            $userIsAdmin = ($userId == $adminId);
            $is_subscribed = empty($channelId) || isSubscribed($userId);

            if ($is_subscribed || $userIsAdmin) {
                 sendMessage($chatId, "Пожалуйста, используйте кнопки меню. Если меню не видно, используйте команду /start.", getMainMenuInlineKeyboard($userIsAdmin), $message_thread_id);
            } else {
                 $subKeyboard = getSubscriptionKeyboard();
                 $subMessage = "Привет! Пожалуйста, подпишитесь на наш канал для доступа к боту.";
                 if ($subKeyboard) {
                    sendMessage($chatId, $subMessage, $subKeyboard, $message_thread_id);
                 } else {
                    sendMessage($chatId, $subMessage . "\nНе удалось сформировать ссылку на канал. Пожалуйста, начните с команды /start или обратитесь к администратору.", null, $message_thread_id);
                 }
            }
        }
    }
} catch (Throwable $e) { // Catch all throwables (PHP 7+)
    bot_log("!!! Uncaught Throwable: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()."\nStack trace:\n".$e->getTraceAsString(), "FATAL");
    // Avoid sending HTTP 500 to Telegram if possible, as it might cause retries.
    // Telegram expects a 200 OK.
}

// Always respond with OK to Telegram to acknowledge receipt of the update
http_response_code(200);
echo "OK"; 
?>
