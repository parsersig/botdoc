<?php
// =============================================
// 🚀 Telegram Bot Webhook Handler for Render.com
// =============================================
// -----------------------------
// 🔧 Конфигурация и инициализация
// -----------------------------
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
define('CHANNEL_ID', -1002543728373); // ID канала
define('WEBHOOK_URL', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
define('MIN_WITHDRAW', 100); // Минимальная сумма вывода

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

// Инициализация БД
try {
    $db = new SQLite3(DB_FILE);
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        username TEXT,
        balance INTEGER DEFAULT 0,
        referrals INTEGER DEFAULT 0,
        ref_code TEXT,
        referred_by INTEGER,
        subscribed BOOLEAN DEFAULT 0,
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
    $response = file_get_contents("https://api.telegram.org/bot $botToken/getChatMember?chat_id=" . CHANNEL_ID . "&user_id=$userId");
    $data = json_decode($response, true);
    return in_array($data['result']['status'] ?? '', ['member', 'administrator', 'creator']);
}

// -----------------------------
// ⌨️ Клавиатуры
// -----------------------------
function getSubscriptionKeyboard() {
    global $adminId;
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
        ['🏧 Вывод', '❓ Помощь']
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
        [['text' => '✉️ Рассылка', 'callback_data' => 'admin_broadcast']],
        [['text' => '⬅️ Назад', 'callback_data' => 'admin_back']]
    ]];
}

function getUserActionsKeyboard($userId) {
    return ['inline_keyboard' => [[
        ['text' => '✅ Разблокировать', 'callback_data' => "unblock_$userId"],
        ['text' => '🚫 Заблокировать', 'callback_data' => "block_$userId"]
    ],[
        ['text' => '📨 Написать', 'callback_data' => "write_$userId"],
        ['text' => '🗑 Удалить', 'callback_data' => "delete_$userId"]
    ],[
        ['text' => '⬅️ Назад', 'callback_data' => 'admin_users']
    ]]];
}

function getWithdrawKeyboard($userId) {
    return ['inline_keyboard' => [[
        ['text' => '✅ Одобрить', 'callback_data' => "approve_$userId"],
        ['text' => '❌ Отклонить', 'callback_data' => "reject_$userId"]
    ]]];
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
function handleStart($chatId, $text, &$db) {
    // Обработка реферальной ссылки
    $refCode = trim(str_replace('/start', '', $text));
    
    if ($refCode && !getUser($chatId)['referred_by']) {
        $referrer = $db->querySingle("SELECT user_id FROM users WHERE ref_code='$refCode'", true);
        
        if ($referrer && $referrer != $chatId) {
            $db->exec("UPDATE users SET 
                referrals=referrals+1, 
                balance=balance+50 
                WHERE user_id={$referrer}");
                
            sendMessage($referrer, "🎉 Новый реферал! +50 баллов.");
        }
    }
    
    // Проверяем подписку
    if (!isSubscribed($chatId)) {
        $message = "👋 Добро пожаловать!\n";
        $message .= "📢 Подпишитесь на наш канал, чтобы продолжить:";
        sendMessage($chatId, $message, getSubscriptionKeyboard());
        return;
    }
    
    // Основное меню
    $user = getUser($chatId);
    $refLink = "https://t.me/ $GLOBALS[botUsername]?start={$user['ref_code']}";
    
    $message = "👋 Добро пожаловать в @{$GLOBALS['botUsername']}!\n";
    $message .= "💰 Зарабатывайте баллы и выводите их\n";
    $message .= "👥 Приглашайте друзей по реферальной ссылке:\n";
    $message .= "<code>$refLink</code>\n";
    $message .= "Используйте кнопки ниже для навигации!";
    
    sendMessage($chatId, $message, getMainKeyboard($chatId == $GLOBALS['adminId']));
}

function handleEarn($chatId, &$db) {
    $cooldown = 60; // 1 минута
    $reward = 10; // 10 баллов
    
    $user = getUser($chatId);
    $lastEarn = $user['last_earn'];
    $remaining = $cooldown - (time() - $lastEarn);
    
    if ($remaining > 0) {
        sendMessage($chatId, "⏳ Подождите $remaining секунд перед следующим заработком!");
        return;
    }
    
    $db->exec("UPDATE users SET 
        balance=balance+10, 
        last_earn=" . time() . " 
        WHERE user_id=$chatId");
    
    $newBalance = getUser($chatId)['balance'];
    sendMessage($chatId, "✅ +10 баллов! Текущий баланс: $newBalance");
}

function handleWithdraw($chatId, &$db) {
    global $adminId;
    
    $user = getUser($chatId);
    $balance = $user['balance'];
    
    if ($balance < MIN_WITHDRAW) {
        $needed = MIN_WITHDRAW - $balance;
        sendMessage($chatId, "❌ Минимальная сумма вывода: " . MIN_WITHDRAW . " баллов\nВам не хватает: $needed баллов");
        return;
    }
    
    $db->exec("UPDATE users SET balance=0, withdraw_status='pending' WHERE user_id=$chatId");
    
    $adminMsg = "🔔 Новый запрос на вывод\n";
    $adminMsg .= "👤 Пользователь: $chatId\n";
    $adminMsg .= "💰 Сумма: $balance баллов\n";
    $adminMsg .= "⏱ Время: " . date('d.m.Y H:i:s');
    
    sendMessage($adminId, $adminMsg, getWithdrawKeyboard($chatId));
    sendMessage($chatId, "✅ Запрос на вывод $balance баллов отправлен администратору.");
}

function handleCallback($callbackQuery) {
    global $db, $adminId;
    
    $chatId = $callbackQuery['message']['chat']['id'];
    $msgId = $callbackQuery['message']['message_id'];
    $data = $callbackQuery['data'];
    
    if ($data === 'check_subscription') {
        if (isSubscribed($chatId)) {
            $user = getUser($chatId);
            $refLink = "https://t.me/ $GLOBALS[botUsername]?start={$user['ref_code']}";
            
            $message = "✅ Спасибо за подписку!\n";
            $message .= "Теперь вы можете пользоваться ботом.\n";
            $message .= "Реферальная ссылка: <code>$refLink</code>";
            
            sendMessage($chatId, $message, getMainKeyboard($chatId == $adminId));
        } else {
            sendMessage($chatId, "❌ Вы ещё не подписаны. Нажмите кнопку ещё раз после подписки.", getSubscriptionKeyboard());
        }
    }
    
    if (strpos($data, 'approve_') === 0) {
        $userId = str_replace('approve_', '', $data);
        $user = getUser($userId);
        
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
        $user = getUser($userId);
        
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
        $user = getUser($userId);
        
        $keyboard = getUserActionsKeyboard($userId);
        
        $message = "👤 <b>Профиль пользователя</b>\n";
        $message .= "ID: <b>{$user['user_id']}</b>\n";
        $message .= "Баланс: <b>{$user['balance']}</b>\n";
        $message .= "Рефералов: <b>{$user['referrals']}</b>\n";
        $message .= "Подписка: " . (isSubscribed($user['user_id']) ? '✅' : '❌') . "\n";
        $message .= "Статус: " . ($user['blocked'] ? '🚫 Заблокирован' : '✅ Активен');
        
        editMessage($chatId, $msgId, $message, $keyboard);
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

// -----------------------------
// 🚀 Основной обработчик
// -----------------------------
$content = file_get_contents("php://input");
logMessage("Incoming update: $content");
$update = json_decode($content, true);

if (!$update) {
    logMessage("Invalid JSON received");
    echo "OK";
    exit;
}

try {
    // Обработка callback-запросов
    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
        echo "OK";
        exit;
    }
    
    // Обработка сообщений
    if (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;
        
        if (!$chatId) {
            logMessage("Error: No chat ID in message");
            echo "OK";
            exit;
        }
        
        // Инициализация пользователя
        if (!getUser($chatId)) {
            $username = $message['from']['username'] ?? null;
            $refCode = substr(md5($chatId . time()), 0, 8);
            
            $db->exec("INSERT INTO users (
                user_id, username, balance, referrals, ref_code, referred_by, subscribed, blocked, last_earn
            ) VALUES (
                $chatId, '$username', 0, 0, '$refCode', NULL, 0, 0, 0
            )");
        }
        
        // Проверка блокировки
        if (getUser($chatId)['blocked']) {
            sendMessage($chatId, "🚫 Вы заблокированы администратором.");
            echo "OK";
            exit;
        }
        
        // Обработка команд
        $text = trim($message['text'] ?? '');
        
        if (strpos($text, '/start') === 0) {
            handleStart($chatId, $text, $db);
        } elseif ($text === '💰 Заработать') {
            handleEarn($chatId, $db);
        } elseif ($text === '💳 Баланс') {
            $user = getUser($chatId);
            $msg = "💰 Ваш баланс: <b>{$user['balance']}</b> баллов\n";
            $msg .= "👥 Рефералов: <b>{$user['referrals']}</b>";
            sendMessage($chatId, $msg);
        } elseif ($text === '🏆 Топ') {
            sendMessage($chatId, getBotStats());
        } elseif ($text === '👥 Рефералы') {
            $user = getUser($chatId);
            $refLink = "https://t.me/ $botUsername?start={$user['ref_code']}";
            
            $msg = "👥 <b>Реферальная система</b>\n";
            $msg .= "Ваш код: <code>{$user['ref_code']}</code>\n";
            $msg .= "Приглашено: <b>{$user['referrals']}</b>\n";
            $msg .= "Ссылка для приглашения:\n<code>$refLink</code>\n";
            $msg .= "💵 50 баллов за каждого друга!";
            
            sendMessage($chatId, $msg);
        } elseif ($text === ' mtx') {
            handleWithdraw($chatId, $db);
        } elseif ($text === ' mtw') {
            $msg = "ℹ️ <b>Помощь</b>\n";
            $msg .= "💰 <b>Заработать</b> - получайте 10 баллов каждую минуту\n";
            $msg .= "👥 <b>Рефералы</b> - приглашайте друзей и получайте бонусы\n";
            $msg .= "mtx <b>Вывод</b> - минимальная сумма 100 баллов\n";
            $msg .= "Используйте кнопки меню для навигации!";
            sendMessage($chatId, $msg);
        } elseif ($text === '⚙️ Админ' && $chatId == $adminId) {
            sendMessage($chatId, "⚙️ <b>Админ-панель</b>", getAdminKeyboard());
        }
    }
} catch (Exception $e) {
    logMessage("Error: ".$e->getMessage()." in ".$e->getFile()." on line ".$e->getLine());
    http_response_code(500);
}

echo "OK";

// Вспомогательные функции работы с БД
function getUser($userId) {
    global $db;
    return $db->querySingle("SELECT * FROM users WHERE user_id=$userId", true);
}
