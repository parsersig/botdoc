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
        'version' => '1.1.0' // Version updated
    ]);
    exit;
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Errors are logged, not displayed
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/error.log'); // Ensure this path is writable on Render.com

// Register shutdown function for fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && $error['type'] === E_ERROR) {
        file_put_contents('/tmp/error.log', "Fatal error: ".print_r($error, true)."\n", FILE_APPEND);
    }
});

// Constants
define('DB_FILE', '/tmp/bot_database.db'); // Ensure this path is writable and persistent if needed, or use Render's disk feature
define('CHANNEL_ID', '-1002543728373');
define('WEBHOOK_URL', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Environment variables
$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$adminId = getenv('ADMIN_ID') ?: '';
// IMPORTANT: Set BOT_USERNAME environment variable to your bot's username *without* the '@'
// e.g., CRYPTOCAP_ROBOT
$botUsername = getenv('BOT_USERNAME') ?: 'CRYPTOCAP_ROBOT';


// Validate config
foreach (['TELEGRAM_BOT_TOKEN'=>$botToken, 'ADMIN_ID'=>$adminId, 'BOT_USERNAME'=>$botUsername] as $key=>$value) {
    if (empty($value)) {
        file_put_contents('/tmp/error.log', "Missing $key config\n", FILE_APPEND);
        http_response_code(500);
        die("Configuration error: Missing $key");
    }
}

// API URL
$apiUrl = "https://api.telegram.org/bot$botToken";

// Initialize database
try {
    if (!file_exists(DB_FILE)) {
        touch(DB_FILE);
        chmod(DB_FILE, 0666); // Check permissions on Render
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

// Webhook auto-setup (only on first run, not on every request)
if (isset($_GET['setwebhook']) && $_GET['setwebhook'] === '1') {
    $webhookUrlToSet = WEBHOOK_URL . $_SERVER['PHP_SELF']; // Ensure it points to this script
    $result = file_get_contents("$apiUrl/setWebhook?url=$webhookUrlToSet");
    file_put_contents('/tmp/request.log', "[".date('Y-m-d H:i:s')."] Webhook set to $webhookUrlToSet: $result\n", FILE_APPEND);
    echo "Webhook set result: " . $result;
    exit;
}

// Main webhook handler
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty(file_get_contents("php://input"))) {
    http_response_code(200);
    echo "Telegram Bot Webhook Endpoint. Setup with ?setwebhook=1 if needed.";
    exit;
}

// -----------------------------
// 🛠️ Helper Functions
// -----------------------------
function logMessage($message) {
    file_put_contents('/tmp/request.log', "[".date('Y-m-d H:i:s')."] $message\n", FILE_APPEND);
}

function apiRequest($method, $params = [], $retries = 3) {
    global $apiUrl;
    $url = "$apiUrl/$method";
    if (isset($params['reply_markup']) && is_array($params['reply_markup'])) {
        $params['reply_markup'] = json_encode($params['reply_markup']);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 10,
        //CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data'] // Only for file uploads
    ]);
    if (!empty($params['photo']) || !empty($params['document'])) { // Example condition for multipart
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
    }


    for ($i = 0; $i < $retries; $i++) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false) {
            logMessage("API Error ($method): cURL Error: $curlError. HTTP Code: $httpCode. URL: $url. Params: " . json_encode($params));
            if ($i < $retries - 1) {
                sleep(1);
                continue;
            }
            curl_close($ch);
            return false;
        }
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            curl_close($ch);
            return $result;
        }

        logMessage("API Error ($method): HTTP $httpCode - Response: $response. URL: $url. Params: " . json_encode($params));
        if ($i < $retries - 1) {
            sleep(1);
            continue;
        }
        
        curl_close($ch);
        return false;
    }
    return false; // Should not be reached if retries complete
}


function sendMessage($chatId, $text, $keyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    if ($keyboard) {
        $params['reply_markup'] = $keyboard; // Already an array, will be json_encoded by apiRequest
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
        $params['reply_markup'] = $keyboard; // Already an array, will be json_encoded by apiRequest
    }
    return apiRequest('editMessageText', $params);
}

function answerCallbackQuery($callbackQueryId, $text = null, $showAlert = false) {
    $params = ['callback_query_id' => $callbackQueryId];
    if ($text) {
        $params['text'] = $text;
    }
    $params['show_alert'] = $showAlert;
    return apiRequest('answerCallbackQuery', $params);
}

function isSubscribed($userId) {
    global $botToken; // Ensure $botToken is accessible
    $channelIdNumeric = ltrim(CHANNEL_ID, '@'); // Handles both @channelusername and ID
    if (is_numeric(CHANNEL_ID) && CHANNEL_ID < 0) { // for private channels/supergroups by ID
         $url = "https://api.telegram.org/bot$botToken/getChatMember?chat_id=" . CHANNEL_ID . "&user_id=$userId";
    } else { // for public channels by @username
         $url = "https://api.telegram.org/bot$botToken/getChatMember?chat_id=@" . $channelIdNumeric . "&user_id=$userId";
    }

    $response = @file_get_contents($url); // Suppress errors for cleaner log
    if ($response === false) {
        logMessage("isSubscribed: Failed to fetch from $url");
        return false;
    }
    $data = json_decode($response, true);

    if (!isset($data['ok']) || $data['ok'] === false) {
        logMessage("isSubscribed: API error for user $userId, channel " . CHANNEL_ID . ". Response: " . $response);
        return false; // Could be bot not admin in channel, or user_id invalid, or channel_id invalid
    }
    return isset($data['result']['status']) && in_array($data['result']['status'], ['member', 'administrator', 'creator']);
}

// -----------------------------
// ⌨️ Keyboards (All Inline)
// -----------------------------
function getSubscriptionKeyboard() {
    // CHANNEL_ID for private channel should be like -100xxxxxxxxxx
    // For public channel, it could be @channelusername. isSubscribed handles this.
    // The link for private channel t.me/c/xxxxxxx (channel_id without -100)
    $channelLinkPart = ltrim(CHANNEL_ID, '-');
    if (strpos(CHANNEL_ID, "-100") === 0) {
         $channelUrl = 'https://t.me/c/' . substr(CHANNEL_ID, 4);
    } else {
         $channelUrl = 'https://t.me/' . ltrim(CHANNEL_ID, '@');
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

function getWithdrawKeyboard($userId) { // For admin to approve/reject
    return ['inline_keyboard' => [[
        ['text' => '✅ Одобрить', 'callback_data' => "approve_withdraw_$userId"],
        ['text' => '❌ Отклонить', 'callback_data' => "reject_withdraw_$userId"]
    ]]];
}

function getUserActionsKeyboard($userId, $isBlocked) {
    $blockButtonText = $isBlocked ? '✅ Разблокировать' : '🚫 Заблокировать';
    $blockCallbackData = $isBlocked ? "unblock_user_$userId" : "block_user_$userId";
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
    while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
        $stats['total']++;
        $stats['balance'] += $user['balance'];
        $stats['referrals'] += $user['referrals'];
        if (!$user['blocked']) $stats['active']++;
        if (count($topUsers) < 5) { // Only store top 5 for display
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
            $usernameDisplay = $user['username'] ? "@".$user['username'] : "ID: ".$user['user_id'];
            $message .= ($i+1) . ". $usernameDisplay - <b>{$user['balance']}</b> баллов (Реф: {$user['referrals']}) $status\n";
        }
    }
    $message .= "\n⏱ Обновлено: " . date('d.m.Y H:i:s');
    return $message;
}

// -----------------------------
// 📨 Command Handlers
// -----------------------------
function handleStart($chatId, $userId, $text) {
    global $db, $botUsername, $adminId;

    // Handle referral code if present
    $refCode = trim(str_replace('/start', '', $text));
    $userExists = $db->querySingle("SELECT 1 FROM users WHERE user_id=$userId");

    if ($userExists && $refCode) {
        $userReferralInfo = $db->querySingle("SELECT referred_by FROM users WHERE user_id=$userId", true);
        if (empty($userReferralInfo['referred_by'])) { // Can only be referred once
            $referrerQuery = $db->prepare("SELECT user_id FROM users WHERE ref_code = :ref_code AND user_id != :user_id");
            $referrerQuery->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
            $referrerQuery->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $referrerResult = $referrerQuery->execute();
            $referrer = $referrerResult->fetchArray(SQLITE3_ASSOC);

            if ($referrer && $referrer['user_id'] != $userId) {
                $db->exec("UPDATE users SET referrals = referrals + 1, balance = balance + 50 WHERE user_id = " . $referrer['user_id']);
                sendMessage($referrer['user_id'], "🎉 Новый реферал присоединился по вашей ссылке! +50 баллов на ваш счет.");
                $db->exec("UPDATE users SET referred_by = " . $referrer['user_id'] . " WHERE user_id = $userId");
            }
        }
    }
    
    if (!isSubscribed($userId)) {
        $message = "👋 Добро пожаловать в @$botUsername!\n\n";
        $message .= "Для начала, пожалуйста, <b>подпишитесь на наш канал</b>. Это обязательное условие для использования бота.\n\n";
        $message .= "После подписки нажмите кнопку «Я подписался».";
        sendMessage($chatId, $message, getSubscriptionKeyboard());
        return;
    }

    $user = $db->querySingle("SELECT * FROM users WHERE user_id=$userId", true);
    $refLink = "https://t.me/$botUsername?start={$user['ref_code']}";

    $message = "👋 Добро пожаловать обратно в @$botUsername!\n\n";
    $message .= "💰 Зарабатывайте баллы, выполняйте задания и выводите их.\n";
    $message .= "👥 Приглашайте друзей и получайте бонусы! Ваша реферальная ссылка:\n<code>$refLink</code>\n\n";
    $message .= "👇 Используйте меню ниже для навигации.";
    sendMessage($chatId, $message, getMainMenuInlineKeyboard($userId == $adminId));
}

function handleCallback($callbackQuery) {
    global $db, $adminId, $botUsername; // $botToken needed for isSubscribed

    $callbackQueryId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $msgId = $callbackQuery['message']['message_id'];
    $userId = $callbackQuery['from']['id']; // User who pressed the button
    $data = $callbackQuery['data'];

    // Always acknowledge the callback quickly
    // answerCallbackQuery($callbackQueryId); // Can be used if no specific message needed immediately

    if ($data === 'check_subscription') {
        if (isSubscribed($userId)) {
            $user = $db->querySingle("SELECT ref_code FROM users WHERE user_id=$userId", true);
            $refLink = "https://t.me/$botUsername?start={$user['ref_code']}";
            $message = "✅ Спасибо за подписку!\n\nТеперь вы можете пользоваться всеми функциями бота.\n\nВаша реферальная ссылка для приглашения друзей:\n<code>$refLink</code>\n\n";
            $message .= "👇 Вот главное меню:";
            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userId == $adminId));
        } else {
            answerCallbackQuery($callbackQueryId, "❌ Вы всё ещё не подписаны. Пожалуйста, подпишитесь на канал и нажмите кнопку ещё раз.", true);
            // Optionally, resend subscription message if current one is bad
            // editMessage($chatId, $msgId, $callbackQuery['message']['text'], getSubscriptionKeyboard());
        }
        return;
    }
    
    // Check subscription for all other actions if user is not admin
    // This is a basic gatekeeper. More granular checks might be needed.
    if ($userId != $adminId && !isSubscribed($userId) && $data !== 'main_menu_show') { // Allow main_menu_show to escape sub check loop
        $text = "Пожалуйста, подпишитесь на канал, чтобы продолжить.";
        editMessage($chatId, $msgId, $text, getSubscriptionKeyboard());
        answerCallbackQuery($callbackQueryId);
        return;
    }


    if ($data === 'main_menu_show') {
        $user = $db->querySingle("SELECT ref_code FROM users WHERE user_id=$userId", true);
        if (!$user) { // Should not happen if user passed start
            sendMessage($chatId, "Ошибка: пользователь не найден. Попробуйте /start");
            answerCallbackQuery($callbackQueryId);
            return;
        }
        $refLink = "https://t.me/$botUsername?start={$user['ref_code']}";
        $message = "👋 Главное меню @$botUsername!\n\n";
        $message .= "💰 Зарабатывайте баллы и выводите их.\n";
        $message .= "👥 Приглашайте друзей: <code>$refLink</code>\n\n";
        $message .= "👇 Используйте кнопки ниже:";
        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userId == $adminId));
        answerCallbackQuery($callbackQueryId);
        return;
    }

    // Main Menu Callbacks
    if ($data === 'earn_money') {
        $cooldown = 60; // 60 seconds
        $row = $db->querySingle("SELECT last_earn, balance FROM users WHERE user_id=$userId", true);
        $remaining = $cooldown - (time() - ($row['last_earn'] ?? 0));

        if ($remaining > 0) {
            answerCallbackQuery($callbackQueryId, "⏳ Подождите $remaining секунд перед следующим заработkom!", true);
        } else {
            $earnedAmount = 10;
            $db->exec("UPDATE users SET balance = balance + $earnedAmount, last_earn = " . time() . " WHERE user_id=$userId");
            $newBalance = $row['balance'] + $earnedAmount;
            answerCallbackQuery($callbackQueryId, "✅ +$earnedAmount баллов! Ваш баланс: $newBalance", false);
            // No message edit needed, alert is enough. Menu stays.
        }
        return;
    }

    if ($data === 'show_balance') {
        $balance = $db->querySingle("SELECT balance FROM users WHERE user_id=$userId");
        answerCallbackQuery($callbackQueryId, "💳 Ваш текущий баланс: $balance баллов.", false);
        return;
    }

    if ($data === 'show_top_users') {
        editMessage($chatId, $msgId, getBotStatsText(), getBackToMainMenuKeyboard());
        answerCallbackQuery($callbackQueryId);
        return;
    }

    if ($data === 'show_referrals_info') {
        $user = $db->querySingle("SELECT ref_code, referrals FROM users WHERE user_id=$userId", true);
        $refLink = "https://t.me/$botUsername?start={$user['ref_code']}";
        $msg = "👥 <b>Ваша реферальная система</b>\n\n";
        $msg .= "Ваш уникальный код приглашения: <code>{$user['ref_code']}</code>\n";
        $msg .= "Приглашено друзей: <b>{$user['referrals']}</b> чел.\n";
        $msg .= "Ваша ссылка для приглашений:\n<code>$refLink</code>\n\n";
        $msg .= "💰 Вы получаете <b>50 баллов</b> за каждого друга, который присоединится по вашей ссылке и подпишется на канал!";
        editMessage($chatId, $msgId, $msg, getBackToMainMenuKeyboard());
        answerCallbackQuery($callbackQueryId);
        return;
    }

    if ($data === 'initiate_withdraw') {
        $user = $db->querySingle("SELECT balance FROM users WHERE user_id=$userId", true);
        $balance = $user['balance'];
        $minWithdraw = 100;

        if ($balance < $minWithdraw) {
            $needed = $minWithdraw - $balance;
            answerCallbackQuery($callbackQueryId, "❌ Минимальная сумма для вывода: $minWithdraw баллов. Вам не хватает: $needed баллов.", true);
        } else {
            // $db->exec("UPDATE users SET balance = 0 WHERE user_id=$userId"); // Balance deduction on approval by admin

            $adminMsg = "🔔 Новый запрос на вывод средств!\n\n";
            $adminMsg .= "👤 Пользователь: $userId (Username: @".$callbackQuery['from']['username'].")\n";
            $adminMsg .= "💰 Сумма к выводу: $balance баллов\n";
            $adminMsg .= "⏱ Время запроса: " . date('d.m.Y H:i:s');

            sendMessage($adminId, $adminMsg, getWithdrawKeyboard($userId)); // Send to admin with approve/reject buttons
            editMessage($chatId, $msgId, "✅ Ваш запрос на вывод $balance баллов успешно отправлен администратору на рассмотрение. Ожидайте.", getMainMenuInlineKeyboard($userId == $adminId));
            answerCallbackQuery($callbackQueryId, "Запрос отправлен!", false);
        }
        return;
    }

    if ($data === 'show_help_info') {
        $msg = "ℹ️ <b>Информация и Помощь</b>\n\n";
        $msg .= "🤖 @$botUsername - это бот для заработка баллов.\n\n";
        $msg .= "💰 <b>Заработать</b> — Нажмите, чтобы получить баллы (есть небольшой кулдаун).\n";
        $msg .= "💳 <b>Баланс</b> — Узнать ваш текущий баланс.\n";
        $msg .= "🏆 <b>Топ</b> — Посмотреть рейтинг пользователей.\n";
        $msg .= "👥 <b>Рефералы</b> — Приглашайте друзей и получайте бонусы.\n";
        $msg .= "💸 <b>Вывод</b> — Запросить вывод накопленных баллов (минимальная сумма - 100 баллов).\n\n";
        $msg .= "📢 Не забудьте быть подписанным на наш основной канал для доступа ко всем функциям!\n\n";
        $msg .= "Если у вас возникли проблемы, свяжитесь с администрацией (контакты могут быть здесь).";
        editMessage($chatId, $msgId, $msg, getBackToMainMenuKeyboard());
        answerCallbackQuery($callbackQueryId);
        return;
    }

    // Admin Panel Callbacks
    if ($data === 'admin_panel_show') {
        if ($userId == $adminId) {
            editMessage($chatId, $msgId, "⚙️ <b>Админ-панель</b>\nВыберите действие:", getAdminPanelKeyboard());
        } else {
            answerCallbackQuery($callbackQueryId, "⛔ Доступ запрещен.", true);
        }
        return;
    }

    if ($data === 'admin_stats_show') {
        if ($userId == $adminId) {
            editMessage($chatId, $msgId, getBotStatsText(), getBackToAdminPanelKeyboard());
        } else {
            answerCallbackQuery($callbackQueryId, "⛔ Доступ запрещен.", true);
        }
        answerCallbackQuery($callbackQueryId);
        return;
    }

    if ($data === 'admin_users_list') {
        if ($userId != $adminId) {
            answerCallbackQuery($callbackQueryId, "⛔ Доступ запрещен.", true);
            return;
        }
        $result = $db->query("SELECT user_id, username, balance, blocked FROM users ORDER BY joined_at DESC LIMIT 20"); // Show recent users
        $usersKeyboard = ['inline_keyboard' => []];
        $userListText = "👥 <b>Список участников (последние 20)</b>:\n\n";
        $count = 0;
        while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
            $count++;
            $statusIcon = $user['blocked'] ? '🚫' : '✅';
            $usernameDisplay = $user['username'] ? "@".$user['username'] : "ID:".$user['user_id'];
            $usersKeyboard['inline_keyboard'][] = [[
                'text' => "$statusIcon $usernameDisplay | 💰: {$user['balance']}",
                'callback_data' => "admin_user_details_{$user['user_id']}"
            ]];
        }
        if ($count == 0) $userListText .= "Пока нет зарегистрированных пользователей.";

        $usersKeyboard['inline_keyboard'][] = [['text' => '⬅️ Назад в админ-панель', 'callback_data' => 'admin_panel_show']];
        editMessage($chatId, $msgId, $userListText, $usersKeyboard);
        answerCallbackQuery($callbackQueryId);
        return;
    }
    
    if (strpos($data, 'admin_user_details_') === 0) {
        if ($userId != $adminId) {
            answerCallbackQuery($callbackQueryId, "⛔ Доступ запрещен.", true);
            return;
        }
        $targetUserId = str_replace('admin_user_details_', '', $data);
        $user = $db->querySingle("SELECT * FROM users WHERE user_id=$targetUserId", true);

        if ($user) {
            $message = "👤 <b>Профиль пользователя</b>\n";
            $message .= "ID: <b>{$user['user_id']}</b>\n";
            $message .= "Username: " . ($user['username'] ? "@{$user['username']}" : "<i>не указан</i>") . "\n";
            $message .= "Баланс: <b>{$user['balance']}</b> баллов\n";
            $message .= "Рефералов: <b>{$user['referrals']}</b>\n";
            $message .= "Приглашен (ID): " . ($user['referred_by'] ?: "<i>нет</i>") . "\n";
            $message .= "Реф. код: <code>{$user['ref_code']}</code>\n";
            $message .= "Подписка на канал: " . (isSubscribed($targetUserId) ? '✅ Да' : '❌ Нет') . "\n";
            $message .= "Статус: " . ($user['blocked'] ? '🚫 <b>Заблокирован</b>' : '✅ Активен') . "\n";
            $message .= "Зарегистрирован: " . $user['joined_at'] . "\n";
            $message .= "Последний заработок (timestamp): " . ($user['last_earn'] ? date('Y-m-d H:i:s', $user['last_earn']) : "<i>не было</i>") . "\n";

            editMessage($chatId, $msgId, $message, getUserActionsKeyboard($targetUserId, $user['blocked']));
        } else {
            answerCallbackQuery($callbackQueryId, "Пользователь не найден.", true);
        }
        answerCallbackQuery($callbackQueryId);
        return;
    }


    // Approve/Reject Withdraw Callbacks (Admin actions)
    if (strpos($data, 'approve_withdraw_') === 0) {
        if ($userId != $adminId) {
            answerCallbackQuery($callbackQueryId, "⛔ Доступ запрещен.", true);
            return;
        }
        $targetUserId = str_replace('approve_withdraw_', '', $data);
        $user = $db->querySingle("SELECT balance, username FROM users WHERE user_id=$targetUserId", true);

        if ($user) {
            $amount = $user['balance']; // Assume full balance withdrawal requested earlier
            $db->exec("UPDATE users SET balance = 0 WHERE user_id=$targetUserId"); // Deduct balance

            $adminConfirmationMsg = "✅ Заявка на вывод для пользователя ID $targetUserId (@{$user['username']}) на сумму $amount баллов ОДОБРЕНА.\nБаланс пользователя обнулен.";
            editMessage($chatId, $msgId, $adminConfirmationMsg); // Edit admin's message
            sendMessage($targetUserId, "🎉 Ваша заявка на вывод $amount баллов ОДОБРЕНА администратором!");
        } else {
            editMessage($chatId, $msgId, "❌ Ошибка: Пользователь $targetUserId для одобрения вывода не найден.");
        }
        answerCallbackQuery($callbackQueryId);
        return;
    }

    if (strpos($data, 'reject_withdraw_') === 0) {
         if ($userId != $adminId) {
            answerCallbackQuery($callbackQueryId, "⛔ Доступ запрещен.", true);
            return;
        }
        $targetUserId = str_replace('reject_withdraw_', '', $data);
        $user = $db->querySingle("SELECT balance, username FROM users WHERE user_id=$targetUserId", true); // Get current balance

        if ($user) {
            $amount = $user['balance']; // This is the amount that was requested (current balance at time of request)
            // Balance is NOT changed upon rejection.
            $adminConfirmationMsg = "❌ Заявка на вывод для пользователя ID $targetUserId (@{$user['username']}) на сумму $amount баллов ОТКЛОНЕНА.\nБаланс пользователя НЕ изменен.";
            editMessage($chatId, $msgId, $adminConfirmationMsg); // Edit admin's message
            sendMessage($targetUserId, "⚠️ Ваша заявка на вывод $amount баллов была ОТКЛОНЕНА администратором. Средства остаются на вашем балансе.");
        } else {
            editMessage($chatId, $msgId, "❌ Ошибка: Пользователь $targetUserId для отклонения вывода не найден.");
        }
        answerCallbackQuery($callbackQueryId);
        return;
    }

    // Block/Unblock User Callbacks (Admin actions)
    if (strpos($data, 'block_user_') === 0) {
        if ($userId == $adminId) {
            $targetUserId = str_replace('block_user_', '', $data);
            if ($targetUserId != $adminId) { // Admin cannot block self through this
                $db->exec("UPDATE users SET blocked=1 WHERE user_id=$targetUserId");
                sendMessage($targetUserId, "🚫 Администратор заблокировал ваш доступ к боту.");
                answerCallbackQuery($callbackQueryId, "✅ Пользователь ID $targetUserId заблокирован.", false);
                // Refresh the user details message
                $user = $db->querySingle("SELECT * FROM users WHERE user_id=$targetUserId", true);
                // Re-call logic for admin_user_details to refresh view
                $data = "admin_user_details_".$targetUserId; // Simulate clicking user details again to refresh
                // Fall through to user_details logic will not work well here.
                // Explicitly call the display logic again or simplify.
                // For now, just an alert. Admin can go back and check.
                // To refresh:
                // $message = "👤 Пользователь ID $targetUserId теперь ЗАБЛОКИРОВАН.\nВыберите следующее действие или вернитесь к списку.";
                // editMessage($chatId, $msgId, $message, getUserActionsKeyboard($targetUserId, true));
            } else {
                answerCallbackQuery($callbackQueryId, "⛔ Нельзя заблокировать самого себя.", true);
            }
        } else { answerCallbackQuery($callbackQueryId, "⛔ Доступ запрещен.", true); }
        return;
    }

    if (strpos($data, 'unblock_user_') === 0) {
        if ($userId == $adminId) {
            $targetUserId = str_replace('unblock_user_', '', $data);
            $db->exec("UPDATE users SET blocked=0 WHERE user_id=$targetUserId");
            sendMessage($targetUserId, "🎉 Ваш доступ к боту восстановлен администратором!");
            answerCallbackQuery($callbackQueryId, "✅ Пользователь ID $targetUserId разблокирован.", false);
            // Refresh the user details message
            // $message = "👤 Пользователь ID $targetUserId теперь РАЗБЛОКИРОВАН.\nВыберите следующее действие или вернитесь к списку.";
            // editMessage($chatId, $msgId, $message, getUserActionsKeyboard($targetUserId, false));
        } else { answerCallbackQuery($callbackQueryId, "⛔ Доступ запрещен.", true); }
        return;
    }
    
    // If no other specific callback handled it, just acknowledge.
    // This helps prevent the "loading" icon on buttons.
    answerCallbackQuery($callbackQueryId);
}


// -----------------------------
// 🚀 Main Webhook Handler
// -----------------------------
$content = file_get_contents("php://input");
if (!$content) {
    logMessage("No content received in POST body.");
    echo "OK"; // Acknowledge Telegram, even if no content
    exit;
}

$update = json_decode($content, true);

if (!$update) {
    logMessage("Invalid JSON received: " . $content);
    echo "OK"; // Acknowledge Telegram
    exit;
}

logMessage("Received update: " . json_encode($update)); // Log incoming update

try {
    if (isset($update['callback_query'])) {
        $userId = $update['callback_query']['from']['id'];
        $username = $update['callback_query']['from']['username'] ?? null;
        // Ensure user exists for callback queries too, especially if they somehow skip /start
        if (!$db->querySingle("SELECT 1 FROM users WHERE user_id=$userId")) {
            $refCode = substr(md5($userId.time()), 0, 8); // Generate a new ref code
            $stmt = $db->prepare("INSERT OR IGNORE INTO users (user_id, username, ref_code) VALUES (:user_id, :username, :ref_code)");
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
            $stmt->execute();
        }
        handleCallback($update['callback_query']);

    } elseif (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;
        $userId = $message['from']['id'] ?? null; // Crucial for user identification

        if (!$chatId || !$userId) {
            logMessage("No chat_id or user_id in message: " . json_encode($message));
            echo "OK";
            exit;
        }

        // Initialize user if not exists
        if (!$db->querySingle("SELECT 1 FROM users WHERE user_id=$userId")) {
            $username = $message['from']['username'] ?? null;
            $refCode = substr(md5($userId.time()), 0, 8); // Generate a new ref code

            $stmt = $db->prepare("INSERT INTO users (user_id, username, ref_code) VALUES (:user_id, :username, :ref_code)");
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
            $result = $stmt->execute();
            if(!$result) {
                 logMessage("Failed to insert new user $userId. DB Error: " . $db->lastErrorMsg());
            } else {
                 logMessage("New user $userId ($username) initialized with ref_code $refCode.");
            }
        }

        // Check if user is blocked (unless it's the admin themself)
        if ($userId != $adminId && $db->querySingle("SELECT blocked FROM users WHERE user_id=$userId") == 1) {
            sendMessage($chatId, "🚫 Вы заблокированы администратором и не можете использовать этого бота.");
            echo "OK";
            exit;
        }

        $text = trim($message['text'] ?? '');
        if (strpos($text, '/start') === 0) {
            handleStart($chatId, $userId, $text);
        } else {
            // For any other text message, you might want to guide them to the menu
            // or ignore. For now, let's suggest /start or show menu if subscribed.
            if (isSubscribed($userId) || $userId == $adminId) {
                 sendMessage($chatId, "Пожалуйста, используйте кнопки меню для взаимодействия. Если меню не видно, попробуйте команду /start", getMainMenuInlineKeyboard($userId == $adminId));
            } else {
                 sendMessage($chatId, "Привет! Пожалуйста, начни с команды /start, чтобы увидеть меню и подписаться на канал.", getSubscriptionKeyboard());
            }
        }
    }
} catch (Exception $e) {
    logMessage("!!! Uncaught Exception: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()."\nStack trace:\n".$e->getTraceAsString());
    // Avoid dying here if possible, let Telegram get an "OK"
    // http_response_code(500); // This might cause Telegram to retry if not careful
}

echo "OK"; // Always respond with OK to Telegram to acknowledge receipt of the update
?>
