<?php
// =============================================
// 🚀 Telegram Bot Webhook Handler for Render.com
// =============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty(file_get_contents("php://input"))) {
    http_response_code(200);
    echo "This is a Telegram webhook endpoint.";
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/error.log');

// Константы
define('DB_FILE', '/tmp/bot_database.db');
define('CHANNEL_ID', '-1002543728373'); // Ваш приватный канал
define('WEBHOOK_URL', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Переменные окружения
$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$adminId = getenv('ADMIN_ID') ?: '';
$botUsername = getenv('BOT_USERNAME') ?: 'CRYPTOCAP_ROBOT';

foreach (['TELEGRAM_BOT_TOKEN'=>$botToken, 'ADMIN_ID'=>$adminId, 'BOT_USERNAME'=>$botUsername] as $key=>$value) {
    if (empty($value)) {
        logMessage("Critical: Missing or empty $key environment variable");
        http_response_code(500);
        die("Configuration error");
    }
}

$apiUrl = "https://api.telegram.org/bot $botToken/";

// Инициализация SQLite
try {
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
    logMessage("Database error: " . $e->getMessage());
    http_response_code(500);
    die("Database error");
}

// -----------------------------
// 🛠️ Вспомогательные функции
// -----------------------------
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents('/tmp/request.log', "[$timestamp] $message\n", FILE_APPEND);
}

function apiRequest($method, $params = [], $retries = 3) {
    global $apiUrl;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl.$method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data'],
        CURLOPT_FOLLOWLOCATION => true
    ]);

    for ($i=0; $i<$retries; $i++) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode != 200) {
            if ($i < $retries - 1) {
                sleep(2);
                continue;
            }
            logMessage("API Error: HTTP $httpCode - ".curl_error($ch));
            curl_close($ch);
            return false;
        }

        $result = json_decode($response, true);
        curl_close($ch);
        return $result;
    }

    curl_close($ch);
    return false;
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
    $response = @file_get_contents("https://api.telegram.org/bot $botToken/getChatMember?chat_id=" . CHANNEL_ID . "&user_id=$userId");
    $data = json_decode($response, true);
    return in_array($data['result']['status'] ?? '', ['member', 'administrator', 'creator']);
}

// -----------------------------
// ⌨️ Клавиатуры
// -----------------------------
function getSubscriptionKeyboard() {
    return [
        'inline_keyboard' => [[
            ['text' => '📢 Наш канал', 'url' => 'https://t.me/c/ ' . ltrim(CHANNEL_ID, '-')],
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

// -----------------------------
// 📊 Статистика
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
// 📨 Обработка команд
// -----------------------------
function handleStart($chatId, $text) {
    global $db, $botUsername;

    $refCode = trim(str_replace('/start', '', $text));

    if ($refCode && !$db->querySingle("SELECT referred_by FROM users WHERE user_id=$chatId")) {
        $referrer = $db->querySingle("SELECT user_id FROM users WHERE ref_code='$refCode'");
        if ($referrer && $referrer != $chatId) {
            $db->exec("UPDATE users SET referrals=referrals+1, balance=balance+50 WHERE user_id={$referrer}");
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
    $refLink = "https://t.me/ $botUsername?start={$user['ref_code']}";

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
            $refLink = "https://t.me/ " . $GLOBALS['botUsername'] . "?start={$user['ref_code']}";
            $message = "✅ Спасибо за подписку!\nТеперь вы можете пользоваться ботом.\nРеферальная ссылка: <code>$refLink</code>";
            sendMessage($chatId, $message, getMainKeyboard($chatId == $adminId));
        } else {
            sendMessage($chatId, "❌ Вы ещё не подписаны. Нажмите кнопку ещё раз после подписки.", getSubscriptionKeyboard());
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

        $keyboard = ['inline_keyboard' => [[
            ['text' => '✅ Разблокировать', 'callback_data' => "unblock_$userId"],
            ['text' => '🚫 Заблокировать', 'callback_data' => "block_$userId"]
        ],[
            ['text' => '⬅️ Назад', 'callback_data' => 'admin_users']
        ]];

        editMessage($chatId, $msgId, $message, $keyboard);
    }

    if ($data === 'admin_back') {
        sendMessage($chatId, "⚙️ <b>Админ-панель</b>", getAdminKeyboard());
    }

    if (strpos($data, 'block_') === 0) {
        $userId = str_replace('block_', '', $data);
        $db->exec("UPDATE users SET blocked=1 WHERE user_id=$userId");
        sendMessage($userId, "🚫 Вы заблокированы администратором.");
        sendMessage($chatId, "✅ Пользователь заблокирован");
    }

    if (strpos($data, 'unblock_') === 0) {
        $userId = str_replace('unblock_', '', $data);
        $db->exec("UPDATE users SET blocked=0 WHERE user_id=$userId");
        sendMessage($userId, "🎉 Вы разблокированы!");
        sendMessage($chatId, "✅ Пользователь разблокирован");
    }
}

function handleCommand($chatId, $text) {
    global $db, $adminId;

    switch ($text) {
        case '💰 Заработать':
            $cooldown = 60;
            $row = $db->querySingle("SELECT last_earn FROM users WHERE user_id=$chatId", true);
            $remaining = $cooldown - (time() - $row['last_earn']);

            if ($remaining > 0) {
                sendMessage($chatId, "⏳ Подождите $remaining секунд перед следующим заработком!");
                break;
            }

            $db->exec("UPDATE users SET 
                balance=balance+10, 
                last_earn=" . time() . " 
                WHERE user_id=$chatId");

            $newBalance = $db->querySingle("SELECT balance FROM users WHERE user_id=$chatId");
            sendMessage($chatId, "✅ +10 баллов! Текущий баланс: $newBalance");
            break;

        case ' mtx':
            $user = $db->querySingle("SELECT * FROM users WHERE user_id=$chatId", true);
            $balance = $user['balance'];

            if ($balance < 100) {
                sendMessage($chatId, "❌ Минимальная сумма вывода: 100 баллов");
                break;
            }

            $db->exec("UPDATE users SET balance=0 WHERE user_id=$chatId");

            $adminMsg = "🔔 Новый запрос на вывод\n";
            $adminMsg .= "👤 Пользователь: $chatId\n";
            $adminMsg .= "💰 Сумма: $balance баллов\n";
            $adminMsg .= "⏱ Время: " . date('d.m.Y H:i:s');

            sendMessage($adminId, $adminMsg, ['inline_keyboard' => [[
                ['text' => '✅ Одобрить', 'callback_data' => "approve_$chatId"],
                ['text' => '❌ Отклонить', 'callback_data' => "reject_$chatId"]
            ]]]);

            sendMessage($chatId, "✅ Запрос на вывод $balance баллов отправлен администратору.");
            break;

        case ' mtw':
            $msg = "ℹ️ <b>Помощь</b>\n";
            $msg .= "💰 <b>Заработать</b> — получайте 10 баллов каждую минуту\n";
            $msg .= " mtx <b>Вывод</b> — минимальная сумма 100 баллов\n";
            $msg .= "Используйте кнопки меню для навигации!";
            sendMessage($chatId, $msg);
            break;

        case '🏆 Топ':
            sendMessage($chatId, getBotStats());
            break;

        case '👥 Рефералы':
            $user = $db->querySingle("SELECT * FROM users WHERE user_id=$chatId", true);
            $refLink = "https://t.me/ " . $GLOBALS['botUsername'] . "?start={$user['ref_code']}";
            $msg = "👥 <b>Реферальная система</b>\n";
            $msg .= "Ваш код: <code>{$user['ref_code']}</code>\n";
            $msg .= "Приглашено: <b>{$user['referrals']}</b>\n";
            $msg .= "Ссылка для приглашений:\n<code>$refLink</code>\n";
            $msg .= "💵 50 баллов за каждого друга!";
            sendMessage($chatId, $msg);
            break;

        case '⚙️ Админ':
            if ($chatId == $adminId) {
                sendMessage($chatId, "⚙️ <b>Админ-панель</b>", getAdminKeyboard());
            }
            break;
    }
}

// -----------------------------
// 🚀 Основной обработчик
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
        echo "OK";
        exit;
    }

    if (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;

        if (!$chatId) {
            logMessage("Error: No chat ID in message");
            echo "OK";
            exit;
        }

        if (!$db->querySingle("SELECT 1 FROM users WHERE user_id=$chatId")) {
            $username = $message['from']['username'] ?? null;
            $refCode = substr(md5($chatId . time()), 0, 8);
            $db->exec("INSERT INTO users (
                user_id, username, balance, referrals, ref_code, referred_by, subscribed, blocked, last_earn
            ) VALUES (
                $chatId, '$username', 0, 0, '$refCode', NULL, 0, 0, 0
            )");
        }

        if ($db->querySingle("SELECT blocked FROM users WHERE user_id=$chatId") == 1) {
            sendMessage($chatId, "🚫 Вы заблокированы администратором.");
            echo "OK";
            exit;
        }

        $text = trim($message['text'] ?? '');

        if (strpos($text, '/start') === 0) {
            handleStart($chatId, $text);
        } elseif ($text === ' mtx') {
            handleCommand($chatId, $text);
        } elseif ($text === ' mtw') {
            handleCommand($chatId, $text);
        } elseif ($text === '🏆 Топ') {
            handleCommand($chatId, $text);
        } elseif ($text === '👥 Рефералы') {
            handleCommand($chatId, $text);
        } elseif ($text === '⚙️ Админ') {
            handleCommand($chatId, $text);
        }
} catch (Exception $e) {
    logMessage("Error: ".$e->getMessage()." in ".$e->getFile()." on line ".$e->getLine());
    http_response_code(500);
}

echo "OK";
