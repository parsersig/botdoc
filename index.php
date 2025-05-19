Вот полностью обновленный код для Telegram-бота с исправленными ошибками и полной функциональностью, готовый к деплою на Render.com:

### 1. Полный код `index.php`

```php
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

function sendMessage($chatId, $text, $keyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard);
    }

    return apiRequest('sendMessage', $params);
}

function editMessage($chatId, $msgId, $text, $keyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $msgId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard);
    }

    return apiRequest('editMessageText', $params);
}

function isSubscribed($userId) {
    global $botToken;
    $response = @file_get_contents("https://api.telegram.org/bot$botToken/getChatMember?chat_id=".CHANNEL_ID."&user_id=$userId");
    $data = json_decode($response, true);
    return in_array($data['result']['status'] ?? '', ['member', 'administrator', 'creator']);
}

// -----------------------------
// ⌨️ Keyboards
// -----------------------------
function getSubscriptionKeyboard() {
    return [
        'inline_keyboard' => [[
            ['text' => '📢 Наш канал', 'url' => 'https://t.me/c/'.ltrim(CHANNEL_ID, '-')],
            ['text' => '✅ Я подписался', 'callback_data' => 'check_subscription']
        ]]
    ];
}

function getMainKeyboard($isAdmin = false) {
    $keyboard = [
        ['💰 Заработать', '💳 Баланс'],
        ['🏆 Топ', '👥 Рефералы'],
        [' mtx', ' mtw']
    ];

    if ($isAdmin) {
        $keyboard[] = ['⚙️ Админ'];
    }

    return ['keyboard' => $keyboard, 'resize_keyboard' => true];
}

function getAdminKeyboard() {
    return ['inline_keyboard' => [
        [['text' => '📊 Статистика', 'callback_data' => 'admin_stats']],
        [['text' => '👤 Участники', 'callback_data' => 'admin_users']],
        [['text' => '⬅️ Назад', 'callback_data' => 'admin_back']]
    ]];
}

function getWithdrawKeyboard($userId) {
    return ['inline_keyboard' => [[
        ['text' => '✅ Одобрить', 'callback_data' => "approve_$userId"],
        ['text' => '❌ Отклонить', 'callback_data' => "reject_$userId"]
    ]]];
}

function getUserActionsKeyboard($userId) {
    return ['inline_keyboard' => [[
        ['text' => '✅ Разблокировать', 'callback_data' => "unblock_$userId"],
        ['text' => '🚫 Заблокировать', 'callback_data' => "block_$userId"]
    ],[
        ['text' => '⬅️ Назад', 'callback_data' => 'admin_users']
    ]]];
}

// -----------------------------
// 📊 Bot Statistics
// -----------------------------
function getBotStats() {
    global $db;
    $stats = [
        'total' => 0,
        'active' => 0,
        'balance' => 0,
        'referrals' => 0
    ];

    $topUsers = [];

    $result = $db->query("SELECT * FROM users ORDER BY balance DESC");
    while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
        $stats['total']++;
        $stats['balance'] += $user['balance'];
        $stats['referrals'] += $user['referrals'];
        if (!$user['blocked']) $stats['active']++;
        $topUsers[] = $user;
    }

    $topUsers = array_slice($topUsers, 0, 5);

    $message = "📊 <b>Статистика бота</b>\n";
    $message .= "👥 Всего пользователей: <b>{$stats['total']}</b>\n";
    $message .= "🟢 Активных: <b>{$stats['active']}</b>\n";
    $message .= "💰 Общий баланс: <b>{$stats['balance']}</b>\n";
    $message .= "👥 Всего рефералов: <b>{$stats['referrals']}</b>\n";
    $message .= "🏆 <b>Топ-5 пользователей</b>:\n";

    foreach ($topUsers as $i => $user) {
        $status = $user['blocked'] ? '🚫' : '✅';
        $message .= ($i+1) . ". ID {$user['user_id']}: <b>{$user['balance']}</b> (Реф: {$user['referrals']}) $status\n";
    }

    $message .= "\n⏱ Обновлено: " . date('d.m.Y H:i:s');
    return $message;
}

// -----------------------------
// 📨 Command Handlers
// -----------------------------
function handleStart($chatId, $text) {
    global $db, $botUsername;

    $refCode = trim(str_replace('/start', '', $text));

    if ($refCode && !$db->querySingle("SELECT referred_by FROM users WHERE user_id=$chatId")) {
        $referrer = $db->querySingle("SELECT user_id FROM users WHERE ref_code='$refCode'");
        if ($referrer && $referrer != $chatId) {
            $db->exec("UPDATE users SET 
                referrals=referrals+1, 
                balance=balance+50 
                WHERE user_id={$referrer}");
            sendMessage($referrer, "🎉 Новый реферал! +50 баллов.");
        }
    }

    if (!isSubscribed($chatId)) {
        $message = "👋 Добро пожаловать!\n";
        $message .= "📢 Подпишитесь на наш канал, чтобы продолжить:";
        sendMessage($chatId, $message, getSubscriptionKeyboard());
        return;
    }

    $user = $db->querySingle("SELECT * FROM users WHERE user_id=$chatId", true);
    $refLink = "https://t.me/$botUsername?start={$user['ref_code']}";

    $message = "👋 Добро пожаловать в @{$botUsername}!\n";
    $message .= "💰 Зарабатывайте баллы и выводите их\n";
    $message .= "👥 Приглашайте друзей по реферальной ссылке:\n<code>$refLink</code>";

    sendMessage($chatId, $message, getMainKeyboard($chatId == $GLOBALS['adminId']));
}

function handleCallback($callbackQuery) {
    global $db, $adminId;

    $chatId = $callbackQuery['message']['chat']['id'];
    $msgId = $callbackQuery['message']['message_id'];
    $data = $callbackQuery['data'];

    if ($data === 'check_subscription') {
        if (isSubscribed($chatId)) {
            $user = $db->querySingle("SELECT * FROM users WHERE user_id=$chatId", true);
            $refLink = "https://t.me/".$GLOBALS['botUsername']."?start={$user['ref_code']}";
            $message = "✅ Спасибо за подписку!\nТеперь вы можете пользоваться ботом.\nРеферальная ссылка: <code>$refLink</code>";
            sendMessage($chatId, $message, getMainKeyboard($chatId == $GLOBALS['adminId']));
        } else {
            sendMessage($chatId, "❌ Вы ещё не подписаны. Нажмите кнопку ещё раз после подписки.", getSubscriptionKeyboard());
        }
    }

    if (strpos($data, 'approve_') === 0) {
        $userId = str_replace('approve_', '', $data);
        $user = $db->querySingle("SELECT * FROM users WHERE user_id=$userId", true);

        if ($chatId == $adminId && $user) {
            $amount = $user['balance'];
            $db->exec("UPDATE users SET balance=0 WHERE user_id=$userId");

            $adminMsg = "✅ Заявка одобрена\n";
            $adminMsg .= "Сумма: $amount баллов\n";
            $adminMsg .= "Пользователь: $userId";

            editMessage($chatId, $msgId, $adminMsg);
            sendMessage($userId, "🎉 Ваша заявка на вывод $amount баллов одобрена!");
        }
    }

    if (strpos($data, 'reject_') === 0) {
        $userId = str_replace('reject_', '', $data);
        $user = $db->querySingle("SELECT * FROM users WHERE user_id=$userId", true);

        if ($chatId == $adminId && $user) {
            $amount = $user['balance'];
            $db->exec("UPDATE users SET balance=balance WHERE user_id=$userId");

            $adminMsg = "❌ Заявка отклонена\n";
            $adminMsg .= "Сумма: $amount баллов\n";
            $adminMsg .= "Пользователь: $userId";

            editMessage($chatId, $msgId, $adminMsg);
            sendMessage($userId, "⚠️ Ваша заявка на вывод $amount баллов отклонена. Средства возвращены.");
        }
    }

    if ($data === 'admin_stats') {
        sendMessage($chatId, getBotStats(), ['inline_keyboard' => [[
            ['text' => '⬅️ Назад', 'callback_data' => 'admin_back']
        ]]]);
    }

    if ($data === 'admin_users') {
        $result = $db->query("SELECT * FROM users ORDER BY balance DESC LIMIT 50");
        $keyboard = ['inline_keyboard' => []];

        while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
            $status = $user['blocked'] ? '🔒' : '🔓';
            $keyboard['inline_keyboard'][] = [[
                'text' => "ID: {$user['user_id']} | 💰: {$user['balance']}",
                'callback_data' => "user_{$user['user_id']}"
            ]];
        }

        $keyboard['inline_keyboard'][] = [['text' => '⬅️ Назад', 'callback_data' => 'admin_back']];
        sendMessage($chatId, "👥 <b>Список участников</b>", $keyboard);
    }

    if (strpos($data, 'user_') === 0) {
        $userId = str_replace('user_', '', $data);
        $user = $db->querySingle("SELECT * FROM users WHERE user_id=$userId", true);

        $message = "👤 <b>Профиль пользователя</b>\n";
        $message .= "ID: <b>{$user['user_id']}</b>\n";
        $message .= "Баланс: <b>{$user['balance']}</b>\n";
        $message .= "Рефералов: <b>{$user['referrals']}</b>\n";
        $message .= "Подписка: " . (isSubscribed($userId) ? '✅' : '❌') . "\n";
        $message .= "Статус: " . ($user['blocked'] ? '🚫 Заблокирован' : '✅ Активен');

        sendMessage($chatId, $message, getUserActionsKeyboard($userId));
    }

    if ($data === 'admin_back') {
        sendMessage($chatId, "⚙️ <b>Админ-панель</b>", getAdminKeyboard());
    }

    if (strpos($data, 'block_') === 0) {
        $userId = str_replace('block_', '', $data);
        if ($chatId == $adminId && $userId != $adminId) {
            $db->exec("UPDATE users SET blocked=1 WHERE user_id=$userId");
            sendMessage($userId, "🚫 Вы заблокированы администратором.");
            sendMessage($chatId, "✅ Пользователь заблокирован");
        }
    }

    if (strpos($data, 'unblock_') === 0) {
        $userId = str_replace('unblock_', '', $data);
        if ($chatId == $adminId) {
            $db->exec("UPDATE users SET blocked=0 WHERE user_id=$userId");
            sendMessage($userId, "🎉 Вы разблокированы!");
            sendMessage($chatId, "✅ Пользователь разблокирован");
        }
    }
}

function handleCommand($chatId, $text) {
    global $db, $adminId, $botUsername;

    switch ($text) {
        case '💰 Заработать':
            $cooldown = 60;
            $row = $db->querySingle("SELECT last_earn FROM users WHERE user_id=$chatId", true);
            $remaining = $cooldown - (time() - $row['last_ear
