<?php

// === Конфигурация ===
$botUsername = 'your_bot_username'; // Замените на ваше имя бота без @
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE');
define('ADMIN_ID', getenv('ADMIN_ID') ?: 0);
define('CHANNEL_ID', getenv('CHANNEL_ID') ?: ''); // Может быть '@username' или '-100123456789'
define('DB_PATH', __DIR__ . '/tmp/bot_database.db');
define('ERROR_LOG_PATH', __DIR__ . '/tmp/bot_errors.log');

$apiUrl = "https://api.telegram.org/bot " . TELEGRAM_BOT_TOKEN;
$errorLogPath = ERROR_LOG_PATH;

// === Логирование ===
function bot_log($message, $level = "INFO") {
    $logEntry = "[" . date("Y-m-d H:i:s") . "] [$level] $message\n";
    file_put_contents(ERROR_LOG_PATH, $logEntry, FILE_APPEND);
}

// === Подключение к базе данных ===
$db = new SQLite3(DB_PATH);
if (!$db) {
    bot_log("Не удалось открыть базу данных", "FATAL");
    exit("Database error");
}
$stmt = $db->exec("CREATE TABLE IF NOT EXISTS users (
    user_id INTEGER PRIMARY KEY,
    username TEXT,
    balance INTEGER DEFAULT 0,
    referrals INTEGER DEFAULT 0,
    ref_code TEXT UNIQUE,
    joined_at INTEGER,
    last_earn INTEGER,
    blocked INTEGER DEFAULT 0
)");
if (!$stmt) {
    bot_log("Ошибка создания таблицы: " . $db->lastErrorMsg(), "FATAL");
}

// === Функция API Telegram ===
function apiRequest($method, $params = [], $retries = 3) {
    global $apiUrl, $errorLogPath;
    $url = "$apiUrl/$method";
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($params),
            'timeout' => 10,
        ],
    ];
    $context = stream_context_create($options);
    for ($i = 0; $i < $retries; $i++) {
        $response = @file_get_contents($url, false, $context);
        if ($response !== false) {
            return json_decode($response, true);
        }
        usleep(500000); // 0.5 секунды между попытками
    }
    bot_log("API Request failed after $retries attempts to $url", "WARNING");
    return ['ok' => false, 'error_code' => 500, 'description' => 'Internal Server Error'];
}

function sendMessage($chatId, $text, $replyMarkup = null, $threadId = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup);
    if ($threadId) $params['message_thread_id'] = $threadId;
    return apiRequest('sendMessage', $params);
}

function editMessage($chatId, $msgId, $text, $replyMarkup = null) {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $msgId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup);
    return apiRequest('editMessageText', $params);
}

function answerCallbackQuery($callbackQueryId, $text = null, $showAlert = false) {
    $params = [
        'callback_query_id' => $callbackQueryId,
        'text' => $text,
        'show_alert' => $showAlert,
    ];
    return apiRequest('answerCallbackQuery', $params);
}

// === Проверка подписки ===
function isSubscribed($userId) {
    global $db, $channelId;
    if (empty($channelId)) return true;
    $result = apiRequest('getChatMember', [
        'chat_id' => $channelId,
        'user_id' => $userId
    ]);
    return isset($result['result']) && in_array($result['result']['status'], ['member', 'administrator', 'creator']);
}

// === Генерация клавиатур ===
function getSubscriptionKeyboard() {
    global $channelId;
    if (empty($channelId)) return null;
    $channelUrl = '';
    if (strpos((string)$channelId, "-100") === 0) {
        $channelIdForLink = substr((string)$channelId, 4);
        $channelUrl = 'https://t.me/c/ ' . $channelIdForLink;
    } elseif ($channelId[0] === '@') {
        $channelUrl = 'https://t.me/ ' . ltrim($channelId, '@');
    } else {
        $channelUrl = 'https://t.me/ ' . $channelId;
    }

    return [
        'inline_keyboard' => [
            [['text' => 'Перейти в канал', 'url' => $channelUrl]],
            [['text' => 'Проверить подписку', 'callback_data' => 'check_subscription']]
        ]
    ];
}

function getMainMenuInlineKeyboard($isAdmin) {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => 'Заработать', 'callback_data' => 'earn_money']],
            [['text' => 'Рефералы', 'callback_data' => 'show_referrals_info']],
            [['text' => 'Статистика', 'callback_data' => 'show_stats']],
        ]
    ];
    if ($isAdmin) {
        $keyboard['inline_keyboard'][] = [['text' => '⚙️ Админ-панель', 'callback_data' => 'admin_panel_show']];
    }
    return $keyboard;
}

function getAdminPanelKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '📊 Статистика', 'callback_data' => 'admin_stats_show']],
            [['text' => '👥 Пользователи', 'callback_data' => 'admin_users_list']],
            [['text' => '🔙 Назад', 'callback_data' => 'main_menu_show']],
        ]
    ];
}

function getUserActionsKeyboard($targetUserId, $isBlocked = false) {
    return [
        'inline_keyboard' => [
            [['text' => '⬅️ Назад', 'callback_data' => 'main_menu_show']]
        ]
    ];
}

function getBackToAdminPanelKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '🔙 Назад', 'callback_data' => 'admin_panel_show']]
        ]
    ];
}

function getBotStatsText() {
    global $db;
    $totalUsers = $db->querySingle("SELECT COUNT(*) FROM users");
    $activeUsers = $db->querySingle("SELECT COUNT(*) FROM users WHERE blocked = 0");
    $blockedUsers = $db->querySingle("SELECT COUNT(*) FROM users WHERE blocked = 1");
    $totalBalance = $db->querySingle("SELECT SUM(balance) FROM users");

    return "📊 <b>Статистика бота</b>\n".
        "Всего пользователей: <b>$totalUsers</b>\n".
        "Активные: <b>$activeUsers</b>, Заблокированные: <b>$blockedUsers</b>\n".
        "Общий баланс: <b>$totalBalance</b>";
}

// === Обработчики команд ===
function handleStart($chatId, $userId, $text) {
    global $db, $botUsername, $adminId, $channelId;

    $refCode = '';
    if (strpos($text, ' ') !== false) {
        $parts = explode(' ', $text, 2);
        $refCode = trim($parts[1]);
    }

    // Проверяем, существует ли пользователь
    $stmt = $db->prepare("SELECT 1 FROM users WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    if (!$stmt->execute()->fetchArray(SQLITE3_ASSOC)) {
        // Создаем нового пользователя
        $refCodeNew = substr(bin2hex(random_bytes(4)), 0, 8);
        $stmt = $db->prepare("INSERT INTO users (user_id, username, ref_code, joined_at) VALUES (:user_id, :username, :ref_code, :joined_at)");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':username', null, SQLITE3_NULL);
        $stmt->bindValue(':ref_code', $refCodeNew, SQLITE3_TEXT);
        $stmt->bindValue(':joined_at', time(), SQLITE3_INTEGER);
        $stmt->execute();

        // Если есть реферальный код — увеличиваем счетчик у пригласившего
        if (!empty($refCode)) {
            $stmt = $db->prepare("SELECT user_id FROM users WHERE ref_code = :ref_code LIMIT 1");
            $stmt->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
            $referrer = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($referrer) {
                $stmt = $db->prepare("UPDATE users SET referrals = referrals + 1 WHERE user_id = :user_id");
                $stmt->bindValue(':user_id', $referrer['user_id'], SQLITE3_INTEGER);
                $stmt->execute();
            }
        }
    }

    // Проверяем подписку
    if (!empty($channelId) && !isSubscribed($userId) && $userId != $adminId) {
        $message = "👋 Добро пожаловать в @$botUsername!\n";
        $message .= "Для начала, пожалуйста, <b>подпишитесь на наш канал</b>. Это обязательное условие для использования бота.\n";
        $message .= "После подписки нажмите кнопку «Я подписался».";

        $subKeyboard = getSubscriptionKeyboard();
        if ($subKeyboard) {
            sendMessage($chatId, $message, $subKeyboard);
        } else {
            sendMessage($chatId, $message . "\nНе удалось сформировать ссылку на канал. Обратитесь к администратору.");
        }
    } else {
        // Пользователь уже подписан — показываем главное меню
        $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
        $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $refLink = "https://t.me/ $botUsername?start=" . ($user['ref_code'] ?? '');

        $message = "👋 Добро пожаловать в @$botUsername!\n";
        $message .= "💰 Зарабатывайте баллы и выводите их.\n";
        $message .= "👥 Приглашайте друзей: <code>$refLink</code>\n";
        $message .= "👇 Используйте меню ниже для навигации.";

        sendMessage($chatId, $message, getMainMenuInlineKeyboard($userId == $adminId));
    }
}

function handleCallback($callbackQuery) {
    global $db, $adminId, $botUsername, $channelId;
    $callbackQueryId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $msgId = $callbackQuery['message']['message_id'];
    $userId = $callbackQuery['from']['id'];
    $data = $callbackQuery['data'];
    $userIsAdmin = ($userId == $adminId);
    $callbackAnswered = false;

    // Проверка подписки
    if ($data === 'check_subscription') {
        if (!empty($channelId) && isSubscribed($userId)) {
            $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
            $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
            $refLink = "https://t.me/ $botUsername?start=" . ($user['ref_code'] ?? '');

            $message = "✅ <b>Спасибо за подписку!</b>\n";
            $message .= "Теперь вы можете пользоваться всеми функциями бота.\n";
            $message .= "Ваша реферальная ссылка для приглашения друзей:\n<code>$refLink</code>\n";
            $message .= "👇 Вот главное меню:";

            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
            $callbackAnswered = true;
        } else if (empty($channelId)) {
            $message = "✅ Проверка подписки не требуется, так как канал не настроен.";
            $message .= "👇 Вот главное меню:";
            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
            $callbackAnswered = true;
        } else {
            answerCallbackQuery($callbackQueryId, "❌ Вы всё ещё не подписаны. Пожалуйста, подпишитесь и нажмите кнопку ещё раз.", true);
            $callbackAnswered = true;
        }
    }

    // Главное меню
    if ($data === 'main_menu_show') {
        $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
        $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $refLink = "https://t.me/ $botUsername?start=" . ($user['ref_code'] ?? '');

        $message = "👋 <b>Главное меню</b> @$botUsername!";
        $message .= "💰 Зарабатывайте баллы и выводите их.";
        $message .= "👥 Приглашайте друзей: <code>$refLink</code>";
        $message .= "👇 Используйте кнопки ниже:";

        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        $callbackAnswered = true;
    }

    // Заработать
    if ($data === 'earn_money') {
        $cooldown = 60;
        $stmt = $db->prepare("SELECT last_earn, balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $remaining = $cooldown - (time() - ($row['last_earn'] ?? 0));

        if ($remaining <= 0) {
            $newBalance = ($row['balance'] ?? 0) + 1;
            $stmt = $db->prepare("UPDATE users SET balance = :balance, last_earn = :last_earn WHERE user_id = :user_id");
            $stmt->bindValue(':balance', $newBalance, SQLITE3_INTEGER);
            $stmt->bindValue(':last_earn', time(), SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->execute();

            $message = "🎉 Вы заработали 1 балл!\n";
            $message .= "Текущий баланс: <b>$newBalance</b>";

            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        } else {
            $message = "⏳ Попробуйте заработать снова через $remaining секунд.";
            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        }
        $callbackAnswered = true;
    }

    // Рефералы
    if ($data === 'show_referrals_info') {
        $stmt = $db->prepare("SELECT ref_code, referrals FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $refLink = "https://t.me/ $botUsername?start=" . ($user['ref_code'] ?? '');

        $msg = "👥 <b>Ваша реферальная система</b>";
        $msg .= "Ваш уникальный код приглашения: <code>" . ($user['ref_code'] ?? 'N/A') . "</code>";
        $msg .= "Вы пригласили: <b>{$user['referrals']}</b> человек";

        editMessage($chatId, $msgId, $msg, getMainMenuInlineKeyboard($userIsAdmin));
        $callbackAnswered = true;
    }

    // Статистика
    if ($data === 'show_stats') {
        $stmt = $db->prepare("SELECT balance, referrals FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        $message = "📈 <b>Ваша статистика</b>";
        $message .= "Баланс: <b>{$user['balance']}</b>";
        $message .= "Рефералов: <b>{$user['referrals']}</b>";

        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        $callbackAnswered = true;
    }

    // Админ-панель
    if ($data === 'admin_panel_show' && $userIsAdmin) {
        editMessage($chatId, $msgId, "⚙️ <b>Админ-панель</b>", getAdminPanelKeyboard());
        $callbackAnswered = true;
    }

    if ($data === 'admin_stats_show' && $userIsAdmin) {
        editMessage($chatId, $msgId, getBotStatsText(), getBackToAdminPanelKeyboard());
        $callbackAnswered = true;
    }

    if ($data === 'admin_users_list' && $userIsAdmin) {
        $result = $db->query("SELECT user_id, username, balance, blocked FROM users ORDER BY joined_at DESC LIMIT 20");
        $usersKeyboard = ['inline_keyboard' => []];
        $userListText = "👥 <b>Список участников (последние 20)</b>:";

        if ($result) {
            while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
                $usersKeyboard['inline_keyboard'][] = [
                    ['text' => "👤 {$user['user_id']}", 'callback_data' => 'admin_user_details_' . $user['user_id']]
                ];
            }
            $usersKeyboard['inline_keyboard'][] = [['text' => '🔙 Назад', 'callback_data' => 'admin_panel_show']];
        }

        editMessage($chatId, $msgId, $userListText, $usersKeyboard);
        $callbackAnswered = true;
    }

    if (strpos($data, 'admin_user_details_') === 0 && $userIsAdmin) {
        $targetUserId = (int)str_replace('admin_user_details_', '', $data);
        $stmt = $db->prepare("SELECT * FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($user) {
            $message = "👤 <b>Профиль пользователя</b>";
            $message .= "ID: <b>{$user['user_id']}</b>";
            $message .= "Username: " . ($user['username'] ? htmlspecialchars("@{$user['username']}") : "<i>не указан</i>") . "";
            $message .= "Баланс: <b>{$user['balance']}</b> баллов";
            $message .= "Рефералов: <b>{$user['referrals']}</b>";
            $message .= "Статус: " . ($user['blocked'] ? '🚫 <b>Заблокирован</b>' : '✅ Активен') . "";

            $actionsKeyboard = getUserActionsKeyboard($targetUserId, $user['blocked']);
            editMessage($chatId, $msgId, $message, $actionsKeyboard);
        } else {
            answerCallbackQuery($callbackQueryId, "Пользователь не найден.", true);
        }
        $callbackAnswered = true;
    }

    if (strpos($data, 'block_user_') === 0 && $userIsAdmin) {
        $targetUserId = (int)str_replace('block_user_', '', $data);
        if ($targetUserId != $adminId) {
            $stmt = $db->prepare("UPDATE users SET blocked=1 WHERE user_id = :user_id");
            $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
            $stmt->execute();
            sendMessage($targetUserId, "🚫 Администратор заблокировал ваш доступ к боту.");

            $stmt = $db->prepare("SELECT * FROM users WHERE user_id = :user_id");
            $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
            $updatedUser = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            $message = "👤 <b>Профиль пользователя</b>";
            $message .= "ID: <b>{$updatedUser['user_id']}</b>";
            $message .= "Username: " . ($updatedUser['username'] ? htmlspecialchars("@{$updatedUser['username']}") : "<i>не указан</i>") . "";
            $message .= "Баланс: <b>{$updatedUser['balance']}</b> баллов";
            $message .= "Рефералов: <b>{$updatedUser['referrals']}</b>";
            $message .= "Статус: 🚫 <b>Заблокирован</b>";

            editMessage($chatId, $msgId, $message, getUserActionsKeyboard($targetUserId, true));
        } else {
            answerCallbackQuery($callbackQueryId, "⛔ Нельзя заблокировать самого себя.", true);
        }
        $callbackAnswered = true;
    }

    if (strpos($data, 'unblock_user_') === 0 && $userIsAdmin) {
        $targetUserId = (int)str_replace('unblock_user_', '', $data);
        $stmt = $db->prepare("UPDATE users SET blocked=0 WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
        $stmt->execute();
        sendMessage($targetUserId, "🎉 Ваш доступ к боту восстановлен.");

        $stmt = $db->prepare("SELECT * FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
        $updatedUser = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        $message = "👤 <b>Профиль пользователя</b>";
        $message .= "ID: <b>{$updatedUser['user_id']}</b>";
        $message .= "Username: " . ($updatedUser['username'] ? htmlspecialchars("@{$updatedUser['username']}") : "<i>не указан</i>") . "";
        $message .= "Баланс: <b>{$updatedUser['balance']}</b> баллов";
        $message .= "Рефералов: <b>{$updatedUser['referrals']}</b>";
        $message .= "Статус: ✅ <b>Активен</b>";

        editMessage($chatId, $msgId, $message, getUserActionsKeyboard($targetUserId, false));
        $callbackAnswered = true;
    }

    // Fallback для необработанных callback-запросов
    if (!$callbackAnswered) {
        bot_log("Unhandled callback_data: $data by user $userId", "WARNING");
        answerCallbackQuery($callbackQueryId, "⚠️ Неизвестная команда", true);
    }
}

// === Обработка входящих запросов ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!(isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/health') &&
        !(isset($_GET['setwebhook']) && $_GET['setwebhook'] === '1') &&
        !(isset($_GET['deletewebhook']) && $_GET['deletewebhook'] === '1') &&
        !(isset($_GET['webhook_info']) && $_GET['webhook_info'] === '1') &&
        !(isset($_GET['logs']) && $_GET['logs'] === '1')) {
        http_response_code(405);
        echo "Method Not Allowed. This endpoint expects POST requests from Telegram.";
        exit;
    }
}

$content = file_get_contents("php://input");
if (empty($content)) {
    http_response_code(200);
    echo "Empty request body.";
    exit;
}

// Логируем входящие данные
bot_log("Received update: " . $content, "INFO");

$update = json_decode($content, true);

try {
    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
    } elseif (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;
        $userId = $message['from']['id'] ?? null;
        $messageThreadId = $message['message_thread_id'] ?? null;

        if (!$chatId || !$userId) {
            bot_log("No chat_id or user_id in message: " . json_encode($message), "WARNING");
            echo "OK";
            exit;
        }

        $stmt = $db->prepare("SELECT 1 FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        if (!$stmt->execute()->fetchArray(SQLITE3_ASSOC)) {
            handleStart($chatId, $userId, '/start');
        } else {
            $userIsAdmin = ($userId == ADMIN_ID);
            $isSubscribed = empty(CHANNEL_ID) || isSubscribed($userId);

            if ($isSubscribed || $userIsAdmin) {
                sendMessage($chatId, "Пожалуйста, используйте кнопки меню. Если меню не видно, используйте команду /start.", getMainMenuInlineKeyboard($userIsAdmin), $messageThreadId);
            } else {
                $subKeyboard = getSubscriptionKeyboard();
                $subMessage = "Привет! Пожалуйста, подпишитесь на наш канал для доступа к боту.";
                if ($subKeyboard) {
                    sendMessage($chatId, $subMessage, $subKeyboard, $messageThreadId);
                } else {
                    sendMessage($chatId, $subMessage . "Не удалось сформировать ссылку на канал. Пожалуйста, начните с команды /start или обратитесь к администратору.", null, $messageThreadId);
                }
            }
        }
    }
} catch (Throwable $e) {
    bot_log("!!! Uncaught Throwable: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "Stack trace:" . $e->getTraceAsString(), "FATAL");
    if (!empty(ADMIN_ID)) {
        sendMessage(ADMIN_ID, "❌ Критическая ошибка в боте: " . $e->getMessage());
    }
}

// === Эндпоинты ===
if (isset($_GET['setwebhook']) && $_GET['setwebhook'] === '1') {
    $url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $url = str_replace('?setwebhook=1', '', $url);

    $deleteResult = apiRequest('deleteWebhook', ['drop_pending_updates' => true]);
    $setResult = apiRequest('setWebhook', [
        'url' => $url,
        'max_connections' => 40,
        'allowed_updates' => json_encode(['message', 'callback_query'])
    ]);

    echo "<pre>DELETE Webhook result: " . json_encode($deleteResult, JSON_PRETTY_PRINT) . "</pre>";
    echo "<pre>SET Webhook result: " . json_encode($setResult, JSON_PRETTY_PRINT) . "</pre>";
    exit;
}

if (isset($_GET['deletewebhook']) && $_GET['deletewebhook'] === '1') {
    $result = apiRequest('deleteWebhook', ['drop_pending_updates' => true]);
    echo "<pre>Delete Webhook result: " . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
    exit;
}

if (isset($_GET['webhook_info']) && $_GET['webhook_info'] === '1') {
    $result = apiRequest('getWebhookInfo');
    echo "<pre>Webhook Info: " . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
    exit;
}

if (isset($_GET['logs']) && $_GET['logs'] === '1') {
    if (file_exists(ERROR_LOG_PATH)) {
        $logs = file_get_contents(ERROR_LOG_PATH);
        echo "<pre>" . htmlspecialchars($logs) . "</pre>";
    } else {
        echo "Log file not found at: " . ERROR_LOG_PATH;
    }
    exit;
}

// === Ответ Telegram ===
http_response_code(200);
echo "OK";
