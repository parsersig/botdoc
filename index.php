<?php
// =============================================
// 🚀 Telegram Bot Webhook Handler for Render.com
// =============================================
// Version 1.6.0: Добавлены инвестиционные планы

// --- Configuration ---
define('DEFAULT_DB_FILE', '/tmp/bot_database.db');
define('DEFAULT_ERROR_LOG_FILE', '/tmp/error.log');

$dbFilePath = getenv('DB_FILE_PATH') ?: DEFAULT_DB_FILE;
$errorLogPath = getenv('ERROR_LOG_PATH') ?: DEFAULT_ERROR_LOG_FILE;

// Устанавливаем error_log для PHP
ini_set('error_log', $errorLogPath);
ini_set('log_errors', 1);
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Health check endpoint
if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'time' => date('Y-m-d H:i:s'),
        'version' => '1.6.0'
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
$botUsername = getenv('BOT_USERNAME') ?: 'MyTestBot';
$channelId = getenv('CHANNEL_ID') ?: '';
$webhookBaseUrl = getenv('WEBHOOK_BASE_URL') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Validate essential config
if (empty($botToken) || empty($adminId)) {
    file_put_contents($errorLogPath, "[".date('Y-m-d H:i:s')."] Missing critical environment variables: TELEGRAM_BOT_TOKEN or ADMIN_ID\n", FILE_APPEND);
    http_response_code(500);
    die("Configuration error: Missing TELEGRAM_BOT_TOKEN or ADMIN_ID");
}

// API URL
$apiUrl = "https://api.telegram.org/bot$botToken";

// -----------------------------
// 🛠️ Helper Functions
// -----------------------------
function bot_log($message, $level = "INFO") {
    global $errorLogPath;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($errorLogPath, "[$timestamp] [$level] $message\n", FILE_APPEND);
}

// Initialize database
try {
    $dataDir = dirname($dbFilePath);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }
    if (!file_exists($dbFilePath) && is_writable($dataDir)) {
        touch($dbFilePath);
    }
    if (file_exists($dbFilePath) && !is_writable($dbFilePath)) {
        chmod($dbFilePath, 0666);
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
    
    // Добавляем таблицу для инвестиционных планов
    $db->exec("CREATE TABLE IF NOT EXISTS investments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        plan_id INTEGER,
        amount INTEGER,
        start_date INTEGER,
        end_date INTEGER,
        status TEXT DEFAULT 'active',
        FOREIGN KEY (user_id) REFERENCES users(user_id)
    )");
} catch (Exception $e) {
    bot_log("DB Error: ".$e->getMessage(), "ERROR");
    http_response_code(500);
    die("Database error: " . $e->getMessage());
}

// Webhook auto-setup
if (isset($_GET['setwebhook']) && $_GET['setwebhook'] === '1') {
    $scriptPath = $_SERVER['PHP_SELF'];
    $webhookUrlToSet = rtrim($webhookBaseUrl, '/') . $scriptPath;
    
    // Добавляем логирование для отладки
    bot_log("Attempting to set webhook to: $webhookUrlToSet", "INFO");
    
    // Сначала удаляем текущий вебхук, чтобы избежать конфликтов
    $deleteResult = @file_get_contents("$apiUrl/deleteWebhook");
    bot_log("Delete webhook result: $deleteResult", "INFO");
    
    // Используем более надежный метод для установки вебхука
    $setWebhookParams = [
        'url' => $webhookUrlToSet,
        'max_connections' => 40,
        'allowed_updates' => json_encode(['message', 'callback_query'])
    ];
    
    $ch = curl_init("$apiUrl/setWebhook");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $setWebhookParams);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $logEntry = "[".date('Y-m-d H:i:s')."] Webhook setup attempt to $webhookUrlToSet. Result: $result, HTTP Code: $httpCode\n";
    file_put_contents($errorLogPath, $logEntry, FILE_APPEND);
    
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

// Добавляем просмотр информации о вебхуке
if (isset($_GET['webhook_info']) && $_GET['webhook_info'] === '1') {
    $result = @file_get_contents("$apiUrl/getWebhookInfo");
    echo "<pre>Webhook Info: " . htmlspecialchars($result) . "</pre>";
    exit;
}

// Добавляем просмотр логов
if (isset($_GET['logs']) && $_GET['logs'] === '1') {
    if (file_exists($errorLogPath)) {
        $logs = file_get_contents($errorLogPath);
        $logs = htmlspecialchars($logs);
        echo "<pre>$logs</pre>";
    } else {
        echo "Log file not found at: $errorLogPath";
    }
    exit;
}

// Простой тест для отправки сообщения админу
if (isset($_GET['test_message']) && $_GET['test_message'] === '1') {
    $testFunctionResult = testFormatting($adminId);
    echo "Test message sent to admin. Result: " . json_encode($testFunctionResult);
    exit;
}

// Запуск проверки инвестиций
if (isset($_GET['check_investments']) && $_GET['check_investments'] === '1') {
    checkCompletedInvestments();
    echo "Investment check completed.";
    exit;
}

// Main webhook handler
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (! (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/health') &&
        ! (isset($_GET['setwebhook']) && $_GET['setwebhook'] === '1') &&
        ! (isset($_GET['deletewebhook']) && $_GET['deletewebhook'] === '1') &&
        ! (isset($_GET['webhook_info']) && $_GET['webhook_info'] === '1') &&
        ! (isset($_GET['logs']) && $_GET['logs'] === '1') &&
        ! (isset($_GET['test_message']) && $_GET['test_message'] === '1') &&
        ! (isset($_GET['check_investments']) && $_GET['check_investments'] === '1')) {
        http_response_code(405);
        echo "Method Not Allowed. This endpoint expects POST requests from Telegram.";
    }
    exit;
}

$content = file_get_contents("php://input");
if (empty($content)) {
    http_response_code(200);
    echo "Empty request body.";
    exit;
}

// Логируем входящие данные
bot_log("Received update: " . $content, "INFO");

function apiRequest($method, $params = [], $retries = 3) {
    global $apiUrl, $errorLogPath;
    $url = "$apiUrl/$method";

    // Важное исправление: правильно кодируем reply_markup как JSON
    if (isset($params['reply_markup']) && is_array($params['reply_markup'])) {
        $params['reply_markup'] = json_encode($params['reply_markup'], 
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init();
    $curlOptions = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    curl_setopt_array($ch, $curlOptions);

    for ($i = 0; $i < $retries; $i++) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false) {
            file_put_contents($errorLogPath, "[".date('Y-m-d H:i:s')."] API Error ($method): cURL Error: $curlError. HTTP Code: $httpCode\n", FILE_APPEND);
            if ($i < $retries - 1) {
                sleep(1 + $i);
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

        file_put_contents($errorLogPath, "[".date('Y-m-d H:i:s')."] API Error ($method): HTTP $httpCode - Response: $response\n", FILE_APPEND);
        if ($httpCode >= 500 && $i < $retries - 1) {
            sleep(1 + $i); 
            continue;
        }
        
        curl_close($ch);
        return $result;
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
    if ($text !== null) {
        $params['text'] = $text;
    }
    $params['show_alert'] = $showAlert;
    return apiRequest('answerCallbackQuery', $params);
}

function isSubscribed($userId) {
    global $botToken, $channelId;
    if (empty($channelId)) {
        return true;
    }
    
    $url = "https://api.telegram.org/bot$botToken/getChatMember?chat_id=" . $channelId . "&user_id=$userId";
    $response = @file_get_contents($url); 
    if ($response === false) {
        bot_log("isSubscribed: Failed to fetch from $url. User: $userId", "ERROR");
        return false;
    }
    $data = json_decode($response, true);

    if (!isset($data['ok']) || $data['ok'] === false) {
        if (isset($data['description'])) {
            if (strpos($data['description'], "not found") !== false || 
                strpos($data['description'], "kicked") !== false) {
                return false;
            }
        }
        return false;
    }
    return isset($data['result']['status']) && in_array($data['result']['status'], ['member', 'administrator', 'creator']);
}

// Test function for formatting
function testFormatting($adminId) {
    return sendMessage($adminId, 
        "<b>Тест жирного</b>\n" .
        "<i>Тест курсива</i>\n" .
        "<code>Тест моноширинного</code>\n" .
        "<pre>Тест блока кода</pre>\n" .
        "<a href='https://t.me/'>Тест ссылки</a>"
    );
}

// -----------------------------
// 💰 Инвестиционные планы
// -----------------------------
$investmentPlans = [
    1 => [
        'name' => 'Базовый',
        'min_amount' => 100,
        'days' => 10,
        'percent' => 20, // 2% в день = 20% за весь период
        'description' => 'Инвестиционный план на 10 дней с доходностью 20%'
    ],
    2 => [
        'name' => 'Стандарт',
        'min_amount' => 500,
        'days' => 20,
        'percent' => 50, // 2.5% в день = 50% за весь период
        'description' => 'Инвестиционный план на 20 дней с доходностью 50%'
    ],
    3 => [
        'name' => 'Премиум',
        'min_amount' => 1000,
        'days' => 30,
        'percent' => 90, // 3% в день = 90% за весь период
        'description' => 'Инвестиционный план на 30 дней с доходностью 90%'
    ]
];

function createInvestment($userId, $planId, $amount) {
    global $db, $investmentPlans;
    
    if (!isset($investmentPlans[$planId])) {
        return false;
    }
    
    $plan = $investmentPlans[$planId];
    $startDate = time();
    $endDate = $startDate + ($plan['days'] * 86400); // days * seconds in a day
    
    $stmt = $db->prepare("INSERT INTO investments (user_id, plan_id, amount, start_date, end_date, status) 
                         VALUES (:user_id, :plan_id, :amount, :start_date, :end_date, 'active')");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':plan_id', $planId, SQLITE3_INTEGER);
    $stmt->bindValue(':amount', $amount, SQLITE3_INTEGER);
    $stmt->bindValue(':start_date', $startDate, SQLITE3_INTEGER);
    $stmt->bindValue(':end_date', $endDate, SQLITE3_INTEGER);
    
    if ($stmt->execute()) {
        // Вычитаем сумму инвестиции из баланса пользователя
        $updateBalanceStmt = $db->prepare("UPDATE users SET balance = balance - :amount WHERE user_id = :user_id");
        $updateBalanceStmt->bindValue(':amount', $amount, SQLITE3_INTEGER);
        $updateBalanceStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateBalanceStmt->execute();
        return true;
    }
    
    return false;
}

function getUserActiveInvestments($userId) {
    global $db, $investmentPlans;
    
    $stmt = $db->prepare("SELECT * FROM investments WHERE user_id = :user_id AND status = 'active'");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $investments = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $planId = $row['plan_id'];
        $plan = $investmentPlans[$planId] ?? ['name' => 'Неизвестный план'];
        
        $row['plan_name'] = $plan['name'];
        $row['percent'] = $plan['percent'];
        $row['days'] = $plan['days'];
        $row['profit'] = round($row['amount'] * ($plan['percent'] / 100));
        $row['total'] = $row['amount'] + $row['profit'];
        $row['days_left'] = max(0, ceil(($row['end_date'] - time()) / 86400));
        
        $investments[] = $row;
    }
    
    return $investments;
}

function checkCompletedInvestments() {
    global $db, $investmentPlans;
    
    $now = time();
    $stmt = $db->prepare("SELECT * FROM investments WHERE status = 'active' AND end_date <= :now");
    $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    while ($investment = $result->fetchArray(SQLITE3_ASSOC)) {
        $userId = $investment['user_id'];
        $planId = $investment['plan_id'];
        $amount = $investment['amount'];
        
        if (!isset($investmentPlans[$planId])) {
            continue;
        }
        
        $plan = $investmentPlans[$planId];
        $profit = round($amount * ($plan['percent'] / 100));
        $total = $amount + $profit;
        
        // Обновляем статус инвестиции
        $updateInvStmt = $db->prepare("UPDATE investments SET status = 'completed' WHERE id = :id");
        $updateInvStmt->bindValue(':id', $investment['id'], SQLITE3_INTEGER);
        $updateInvStmt->execute();
        
        // Начисляем прибыль на баланс пользователя
        $updateBalanceStmt = $db->prepare("UPDATE users SET balance = balance + :total WHERE user_id = :user_id");
        $updateBalanceStmt->bindValue(':total', $total, SQLITE3_INTEGER);
        $updateBalanceStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateBalanceStmt->execute();
        
        // Получаем текущий баланс пользователя
        $balanceStmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $balanceStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balanceResult = $balanceStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $currentBalance = $balanceResult['balance'];
        
        // Отправляем уведомление пользователю
        $message = "🚀 <b>Поздравляем!</b>\n\n";
        $message .= "Ваш инвестиционный план <b>{$plan['name']}</b> на {$plan['days']} дней отработал и принёс отличные результаты. ";
        $message .= "За это время Ваш капитал вырос на {$plan['percent']}% относительно начальной суммы.\n\n";
        $message .= "💳 Сумма инвестиций: <b>{$amount}₽</b>\n";
        $message .= "💰 Итоговая прибыль: <b>{$profit}₽</b>\n";
        $message .= "💼 Общий баланс: <b>{$currentBalance}₽</b>\n\n";
        $message .= "Благодарим за доверие!\n";
        $message .= "С уважением,\n";
        $message .= "Ваш инвестиционный бот 🤖";
        
        sendMessage($userId, $message);
    }
}

// -----------------------------
// ⌨️ Keyboards (All Inline)
// -----------------------------
function getSubscriptionKeyboard() {
    global $channelId;
    if (empty($channelId)) return null;

    $channelUrl = '';
    if (strpos((string)$channelId, "-100") === 0) {
        $channelIdForLink = substr((string)$channelId, 4);
        $channelUrl = 'https://t.me/c/' . $channelIdForLink;
    } elseif ($channelId[0] === '@') {
        $channelUrl = 'https://t.me/' . ltrim($channelId, '@');
    } else {
        bot_log("Cannot generate channel URL for Channel ID: $channelId", "WARNING");
        return null;
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
            ['text' => '📊 Инвестировать', 'callback_data' => 'show_investment_plans'],
            ['text' => '📈 Мои инвестиции', 'callback_data' => 'show_my_investments']
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

function getInvestmentPlansKeyboard() {
    global $investmentPlans;
    
    $keyboard = ['inline_keyboard' => []];
    
    foreach ($investmentPlans as $id => $plan) {
        $keyboard['inline_keyboard'][] = [[
            'text' => "📊 {$plan['name']} - {$plan['percent']}% за {$plan['days']} дней",
            'callback_data' => "select_investment_plan_$id"
        ]];
    }
    
    $keyboard['inline_keyboard'][] = [['text' => '⬅️ Назад в меню', 'callback_data' => 'main_menu_show']];
    
    return $keyboard;
}

function getInvestmentAmountKeyboard($planId) {
    global $investmentPlans;
    
    $plan = $investmentPlans[$planId] ?? null;
    if (!$plan) {
        return getBackToMainMenuKeyboard();
    }
    
    $minAmount = $plan['min_amount'];
    
    $keyboard = ['inline_keyboard' => [
        [
            ['text' => "$minAmount₽", 'callback_data' => "invest_{$planId}_{$minAmount}"],
            ['text' => ($minAmount*2)."₽", 'callback_data' => "invest_{$planId}_".($minAmount*2)]
        ],
        [
            ['text' => ($minAmount*5)."₽", 'callback_data' => "invest_{$planId}_".($minAmount*5)],
            ['text' => ($minAmount*10)."₽", 'callback_data' => "invest_{$planId}_".($minAmount*10)]
        ],
        [['text' => '⬅️ Назад к планам', 'callback_data' => 'show_investment_plans']],
        [['text' => '⬅️ Назад в меню', 'callback_data' => 'main_menu_show']]
    ]];
    
    return $keyboard;
}

function getAdminPanelKeyboard() {
    return ['inline_keyboard' => [
        [['text' => '📊 Статистика', 'callback_data' => 'admin_stats_show']],
        [['text' => '👤 Участники', 'callback_data' => 'admin_users_list']],
        [['text' => '💰 Инвестиции', 'callback_data' => 'admin_investments_list']],
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

    // Статистика по инвестициям
    $investmentStats = ['total' => 0, 'active' => 0, 'completed' => 0, 'total_amount' => 0];
    $invResult = $db->query("SELECT status, amount FROM investments");
    while ($inv = $invResult->fetchArray(SQLITE3_ASSOC)) {
        $investmentStats['total']++;
        $investmentStats['total_amount'] += $inv['amount'];
        if ($inv['status'] === 'active') {
            $investmentStats['active']++;
        } else if ($inv['status'] === 'completed') {
            $investmentStats['completed']++;
        }
    }

       $message = "📊 <b>Статистика бота</b>\n";
    $message .= "👥 Всего пользователей: <b>{$stats['total']}</b>\n";
    $message .= "🟢 Активных (не заблокированных): <b>{$stats['active']}</b>\n";
    $message .= "💰 Общий баланс пользователей: <b>{$stats['balance']}</b>\n";
    $message .= "🔗 Всего привлечено рефералов: <b>{$stats['referrals']}</b>\n\n";
    
    $message .= "📈 <b>Инвестиции</b>\n";
    $message .= "💼 Всего инвестиций: <b>{$investmentStats['total']}</b>\n";
    $message .= "⏳ Активных инвестиций: <b>{$investmentStats['active']}</b>\n";
    $message .= "✅ Завершенных инвестиций: <b>{$investmentStats['completed']}</b>\n";
    $message .= "💵 Общая сумма инвестиций: <b>{$investmentStats['total_amount']}₽</b>\n\n";
    
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
                sendMessage($referrer['user_id'], "🎉 Новый реферал присоединился по вашей ссылке! <b>+50 баллов</b> на ваш счет.");
                
                $updateUserStmt = $db->prepare("UPDATE users SET referred_by = :referrer_id WHERE user_id = :user_id");
                $updateUserStmt->bindValue(':referrer_id', $referrer['user_id'], SQLITE3_INTEGER);
                $updateUserStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $updateUserStmt->execute();
            }
        }
    }
    
    if (!empty($channelId) && !isSubscribed($userId) && $userId != $adminId) {
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
    $message .= "💰 Зарабатывайте баллы, инвестируйте и выводите прибыль.\n";
    $message .= "👥 Приглашайте друзей и получайте бонусы! Ваша реферальная ссылка:\n<code>$refLink</code>\n\n";
    $message .= "👇 Используйте меню ниже для навигации.";
    sendMessage($chatId, $message, getMainMenuInlineKeyboard($userId == $adminId));
}

function handleCallback($callbackQuery) {
    global $db, $adminId, $botUsername, $channelId, $investmentPlans;

    $callbackQueryId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $msgId = $callbackQuery['message']['message_id'];
    $userId = $callbackQuery['from']['id']; 
    $data = $callbackQuery['data'];
    $userIsAdmin = ($userId == $adminId);

    // Всегда отвечаем на callback_query, чтобы убрать загрузку с кнопки
    $callbackAnswered = false;

    // Проверка подписки
    if ($data === 'check_subscription') {
        if (!empty($channelId) && isSubscribed($userId)) {
            $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
            $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
            $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');
            $message = "✅ <b>Спасибо за подписку!</b>\n\nТеперь вы можете пользоваться всеми функциями бота.\n\nВаша реферальная ссылка для приглашения друзей:\n<code>$refLink</code>\n\n";
            $message .= "👇 Вот главное меню:";
            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
            answerCallbackQuery($callbackQueryId);
            $callbackAnswered = true;
        } else if (empty($channelId)) {
            $message = "✅ Проверка подписки не требуется, так как канал не настроен.\n\n";
            $message .= "👇 Вот главное меню:";
            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
            answerCallbackQuery($callbackQueryId);
            $callbackAnswered = true;
        } else {
            answerCallbackQuery($callbackQueryId, "❌ Вы всё ещё не подписаны. Пожалуйста, подпишитесь и нажмите кнопку ещё раз.", true);
            $callbackAnswered = true;
        }
        return;
    }
    
    // Проверка подписки для всех других действий
    if (!$userIsAdmin && !empty($channelId) && !isSubscribed($userId)) {
        $text = "Пожалуйста, <b>подпишитесь на наш канал</b>, чтобы продолжить.";
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
        $message = "👋 <b>Главное меню</b> @$botUsername!\n\n";
        $message .= "💰 Зарабатывайте баллы и выводите их.\n";
        $message .= "👥 Приглашайте друзей: <code>$refLink</code>\n\n";
        $message .= "👇 Используйте кнопки ниже:";
        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        answerCallbackQuery($callbackQueryId);
        $callbackAnswered = true;
    }

    else if ($data === 'earn_money') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        $earnedAmount = 100; // Изменено на 100 баллов
        $updateStmt = $db->prepare("UPDATE users SET balance = balance + :amount WHERE user_id = :user_id");
        $updateStmt->bindValue(':amount', $earnedAmount, SQLITE3_INTEGER);
        $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateStmt->execute();
        $newBalance = ($row['balance'] ?? 0) + $earnedAmount;
        
        $message = "🎉 <b>Поздравляем!</b>\n\n";
        $message .= "Вы получили <b>+$earnedAmount баллов</b>!\n";
        $message .= "Ваш текущий баланс: <b>$newBalance баллов</b>\n\n";
        $message .= "Вы можете инвестировать эти средства и получить прибыль!";
        
        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        answerCallbackQuery($callbackQueryId, "✅ +$earnedAmount баллов! Ваш баланс: $newBalance", false);
        $callbackAnswered = true;
    }

    else if ($data === 'show_balance') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        
        $message = "💳 <b>Ваш баланс</b>\n\n";
        $message .= "Текущий баланс: <b>$balance баллов</b>\n\n";
        $message .= "Вы можете увеличить свой баланс:\n";
        $message .= "• Нажав кнопку «Заработать»\n";
        $message .= "• Инвестируя в один из планов\n";
        $message .= "• Приглашая рефералов\n";
        
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
        answerCallbackQuery($callbackQueryId);
        $callbackAnswered = true;
    }

    else if ($data === 'show_investment_plans') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        
        $message = "📊 <b>Инвестиционные планы</b>\n\n";
        $message .= "Ваш текущий баланс: <b>$balance баллов</b>\n\n";
        $message .= "Выберите план для инвестирования:\n\n";
        
        foreach ($investmentPlans as $id => $plan) {
            $message .= "<b>{$plan['name']}</b>\n";
            $message .= "💰 Минимальная сумма: {$plan['min_amount']}₽\n";
            $message .= "⏱ Срок: {$plan['days']} дней\n";
            $message .= "📈 Доходность: {$plan['percent']}%\n";
            $message .= "💵 Пример: 1000₽ → " . (1000 + 1000 * $plan['percent'] / 100) . "₽\n\n";
        }
        
        editMessage($chatId, $msgId, $message, getInvestmentPlansKeyboard());
        answerCallbackQuery($callbackQueryId);
        $callbackAnswered = true;
    }

    else if (strpos($data, 'select_investment_plan_') === 0) {
        $planId = (int)str_replace('select_investment_plan_', '', $data);
        
        if (!isset($investmentPlans[$planId])) {
            answerCallbackQuery($callbackQueryId, "План не найден", true);
            $callbackAnswered = true;
            return;
        }
        
        $plan = $investmentPlans[$planId];
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        
        $message = "💰 <b>Инвестиционный план: {$plan['name']}</b>\n\n";
        $message .= "⏱ Срок: {$plan['days']} дней\n";
        $message .= "📈 Доходность: {$plan['percent']}%\n";
        $message .= "💵 Минимальная сумма: {$plan['min_amount']}₽\n\n";
        $message .= "Ваш текущий баланс: <b>$balance баллов</b>\n\n";
        $message .= "Выберите сумму для инвестирования:";
        
        editMessage($chatId, $msgId, $message, getInvestmentAmountKeyboard($planId));
        answerCallbackQuery($callbackQueryId);
        $callbackAnswered = true;
    }

    else if (strpos($data, 'invest_') === 0) {
        $parts = explode('_', $data);
        if (count($parts) !== 3) {
            answerCallbackQuery($callbackQueryId, "Неверный формат данных", true);
            $callbackAnswered = true;
            return;
        }
        
        $planId = (int)$parts[1];
        $amount = (int)$parts[2];
        
        if (!isset($investmentPlans[$planId])) {
            answerCallbackQuery($callbackQueryId, "План не найден", true);
            $callbackAnswered = true;
            return;
        }
        
        $plan = $investmentPlans[$planId];
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        
        if ($balance < $amount) {
            answerCallbackQuery($callbackQueryId, "Недостаточно средств на балансе", true);
            $callbackAnswered = true;
            return;
        }
        
        if ($amount < $plan['min_amount']) {
            answerCallbackQuery($callbackQueryId, "Сумма меньше минимальной", true);
            $callbackAnswered = true;
            return;
        }
        
        if (createInvestment($userId, $planId, $amount)) {
            $profit = round($amount * ($plan['percent'] / 100));
            $total = $amount + $profit;
            
            $message = "✅ <b>Инвестиция успешно создана!</b>\n\n";
            $message .= "📊 План: <b>{$plan['name']}</b>\n";
            $message .= "💰 Сумма инвестиции: <b>$amount₽</b>\n";
            $message .= "⏱ Срок: <b>{$plan['days']} дней</b>\n";
            $message .= "📈 Ожидаемая прибыль: <b>$profit₽</b>\n";
            $message .= "💵 К получению через {$plan['days']} дней: <b>$total₽</b>\n\n";
            $message .= "Спасибо за доверие! Вы можете следить за своими инвестициями в разделе «Мои инвестиции».";
            
            editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
            answerCallbackQuery($callbackQueryId, "Инвестиция успешно создана!", false);
            $callbackAnswered = true;
        } else {
            answerCallbackQuery($callbackQueryId, "Ошибка при создании инвестиции", true);
            $callbackAnswered = true;
        }
    }

    else if ($data === 'show_my_investments') {
        $investments = getUserActiveInvestments($userId);
        
        $message = "📈 <b>Ваши активные инвестиции</b>\n\n";
        
        if (empty($investments)) {
            $message .= "У вас пока нет активных инвестиций.\n\n";
            $message .= "Вы можете создать инвестицию в разделе «Инвестировать».";
        } else {
            foreach ($investments as $index => $inv) {
                $message .= "<b>Инвестиция #" . ($index + 1) . "</b>\n";
                $message .= "📊 План: <b>{$inv['plan_name']}</b>\n";
                $message .= "💰 Сумма: <b>{$inv['amount']}₽</b>\n";
                $message .= "⏱ Осталось дней: <b>{$inv['days_left']}</b>\n";
                $message .= "📈 Ожидаемая прибыль: <b>{$inv['profit']}₽</b>\n";
                $message .= "💵 К получению: <b>{$inv['total']}₽</b>\n";
                $message .= "📅 Дата создания: " . date('d.m.Y', $inv['start_date']) . "\n";
                $message .= "📅 Дата завершения: " . date('d.m.Y', $inv['end_date']) . "\n\n";
            }
        }
        
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
        answerCallbackQuery($callbackQueryId);
        $callbackAnswered = true;
    }

    else if ($data === 'show_top_users') {
        editMessage($chatId, $msgId, getBotStatsText(), getBackToMainMenuKeyboard());
        answerCallbackQuery($callbackQueryId);
        $callbackAnswered = true;
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
        answerCallbackQuery($callbackQueryId);
        $callbackAnswered = true;
    }

    else if ($data === 'initiate_withdraw') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $balance = $userRow['balance'] ?? 0;
        $minWithdraw = 100;

        if ($balance < $minWithdraw) {
            $needed = $minWithdraw - $balance;
            answerCallbackQuery($callbackQueryId, "❌ Мин. сумма: $minWithdraw баллов. Вам не хватает: $needed.", true);
            $callbackAnswered = true;
        } else {
            $userFrom = $callbackQuery['from'];
            $usernameFrom = isset($userFrom['username']) ? "@".htmlspecialchars($userFrom['username']) : "ID: ".$userId;

            $adminMsg = "🔔 <b>Новый запрос на вывод средств!</b>\n\n";
            $adminMsg .= "👤 Пользователь: $usernameFrom (ID: $userId)\n";
            $adminMsg .= "💰 Сумма к выводу: <b>$balance</b> баллов\n";
            $adminMsg .= "⏱ Время запроса: " . date('d.m.Y H:i:s');

            sendMessage($adminId, $adminMsg, getWithdrawKeyboard($userId));
            $userConfirmationMsg = "✅ Ваш запрос на вывод $balance баллов отправлен администратору. Ожидайте.";
            answerCallbackQuery($callbackQueryId, $userConfirmationMsg, false);
            $callbackAnswered = true;
        }
    }

    else if ($data === 'show_help_info') {
        $msg = "ℹ️ <b>Информация и Помощь</b>\n\n";
        $msg .= "🤖 @$botUsername - это инвестиционный бот.\n\n";
        $msg .= "💰 <b>Заработать</b> — Нажмите, чтобы получить баллы.\n";
        $msg .= "💳 <b>Баланс</b> — Узнать ваш текущий баланс.\n";
        $msg .= "📊 <b>Инвестировать</b> — Выбрать инвестиционный план.\n";
        $msg .= "📈 <b>Мои инвестиции</b> — Просмотр ваших активных инвестиций.\n";
        $msg .= "🏆 <b>Топ</b> — Посмотреть рейтинг пользователей.\n";
        $msg .= "👥 <b>Рефералы</b> — Приглашайте друзей и получайте бонусы.\n";
        $msg .= "💸 <b>Вывод</b> — Запросить вывод (мин. 100 баллов).\n\n";
        if (!empty($channelId)) $msg .= "📢 Не забудьте быть подписанным на наш основной канал!\n\n";
        $msg .= "При возникновении проблем обращайтесь к администрации.";
        editMessage($chatId, $msgId, $msg, getBackToMainMenuKeyboard());
        answerCallbackQuery($callbackQueryId);
        $callbackAnswered = true;
    }

    // --- Admin Panel Callbacks ---
    else if ($userIsAdmin) { // Group all admin actions here
        if ($data === 'admin_panel_show') {
            editMessage($chatId, $msgId, "⚙️ <b>Админ-панель</b>\nВыберите действие:", getAdminPanelKeyboard());
            answerCallbackQuery($callbackQueryId);
            $callbackAnswered = true;
        }
        else if ($data === 'admin_stats_show') {
            editMessage($chatId, $msgId, getBotStatsText(), getBackToAdminPanelKeyboard());
            answerCallbackQuery($callbackQueryId);
            $callbackAnswered = true;
        }
        else if ($data === 'admin_investments_list') {
            $result = $db->query("SELECT i.*, u.username FROM investments i 
                                 JOIN users u ON i.user_id = u.user_id 
                                 WHERE i.status = 'active' 
                                 ORDER BY i.end_date ASC LIMIT 20");
            
            $message = "💰 <b>Активные инвестиции (последние 20)</b>:\n\n";
            $count = 0;
            
            if ($result) {
                while ($inv = $result->fetchArray(SQLITE3_ASSOC)) {
                    $count++;
                    $planId = $inv['plan_id'];
                    $plan = $investmentPlans[$planId] ?? ['name' => 'Неизвестный план'];
                    $username = $inv['username'] ? "@".htmlspecialchars($inv['username']) : "ID:".$inv['user_id'];
                    
                    $message .= "<b>Инвестиция #" . $inv['id'] . "</b>\n";
                    $message .= "👤 Пользователь: $username\n";
                    $message .= "📊 План: {$plan['name']}\n";
                    $message .= "💰 Сумма: {$inv['amount']}₽\n";
                    $message .= "📅 Завершение: " . date('d.m.Y', $inv['end_date']) . "\n\n";
                }
            }
            
            if ($count == 0) $message .= "Пока нет активных инвестиций.";
            
            $message .= "\n⚠️ Для проверки и начисления завершенных инвестиций используйте эндпоинт <code>/check_investments=1</code>";
            
            editMessage($chatId, $msgId, $message, getBackToAdminPanelKeyboard());
            answerCallbackQuery($callbackQueryId);
            $callbackAnswered = true;
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
            answerCallbackQuery($callbackQueryId);
            $callbackAnswered = true;
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

                // Добавляем информацию об инвестициях пользователя
                $invStmt = $db->prepare("SELECT COUNT(*) as count, SUM(amount) as total FROM investments WHERE user_id = :user_id");
                $invStmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
                $invResult = $invStmt->execute()->fetchArray(SQLITE3_ASSOC);
                
                $message .= "\n📊 <b>Инвестиции</b>\n";
                $message .= "Всего инвестиций: <b>{$invResult['count']}</b>\n";
                $message .= "Общая сумма: <b>" . ($invResult['total'] ?: 0) . "₽</b>\n";

                editMessage($chatId, $msgId, $message, getUserActionsKeyboard($targetUserId, $user['blocked']));
                answerCallbackQuery($callbackQueryId);
                $callbackAnswered = true;
            } else {
                answerCallbackQuery($callbackQueryId, "Пользователь не найден.", true);
                $callbackAnswered = true;
            }
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

                $adminConfirmationMsg = "✅ Заявка на вывод для ID $targetUserId (" . ($user['username'] ? "@".htmlspecialchars($user['username']) : '') . ") на <b>$amount</b> баллов <b>ОДОБРЕНА</b>.\nБаланс обнулен.";
                editMessage($chatId, $msgId, $adminConfirmationMsg); 
                sendMessage($targetUserId, "🎉 Ваша заявка на вывод <b>$amount</b> баллов <b>ОДОБРЕНА</b>!");
                answerCallbackQuery($callbackQueryId);
                $callbackAnswered = true;
            } else {
                editMessage($chatId, $msgId, "❌ Ошибка: Пользователь $targetUserId для одобрения не найден.");
                answerCallbackQuery($callbackQueryId);
                $callbackAnswered = true;
            }
        }
        else if (strpos($data, 'reject_withdraw_') === 0) {
            $targetUserId = (int)str_replace('reject_withdraw_', '', $data);
            $stmt = $db->prepare("SELECT balance, username FROM users WHERE user_id = :user_id");
            $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
            $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if ($user) {
                $amount = $user['balance']; 
                $adminConfirmationMsg = "❌ Заявка на вывод для ID $targetUserId (" . ($user['username'] ? "@".htmlspecialchars($user['username']) : '') . ") на <b>$amount</b> баллов <b>ОТКЛОНЕНА</b>.\nБаланс НЕ изменен.";
                editMessage($chatId, $msgId, $adminConfirmationMsg); 
                sendMessage($targetUserId, "⚠️ Ваша заявка на вывод <b>$amount</b> баллов <b>ОТКЛОНЕНА</b>. Средства остаются на балансе.");
                answerCallbackQuery($callbackQueryId);
                $callbackAnswered = true;
            } else {
                editMessage($chatId, $msgId, "❌ Ошибка: Пользователь $targetUserId для отклонения не найден.");
                answerCallbackQuery($callbackQueryId);
                $callbackAnswered = true;
            }
        }
        else if (strpos($data, 'block_user_') === 0) {
            $targetUserId = (int)str_replace('block_user_', '', $data);
            if ($targetUserId != $adminId) { 
                $stmt = $db->prepare("UPDATE users SET blocked=1 WHERE user_id = :user_id");
                $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
                $stmt->execute();
                sendMessage($targetUserId, "🚫 Администратор заблокировал ваш доступ к боту.");
                answerCallbackQuery($callbackQueryId, "✅ Пользователь ID $targetUserId заблокирован.", false);
                $callbackAnswered = true;
                
                // Обновляем информацию в админ-панели
                $userStmt = $db->prepare("SELECT * FROM users WHERE user_id = :user_id");
                $userStmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
                $updatedUser = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
                if ($updatedUser) {
                    $message = "👤 <b>Профиль пользователя</b>\n";
                    $message .= "ID: <b>{$updatedUser['user_id']}</b>\n";
                    $message .= "Username: " . ($updatedUser['username'] ? htmlspecialchars("@{$updatedUser['username']}") : "<i>не указан</i>") . "\n";
                    $message .= "Баланс: <b>{$updatedUser['balance']}</b> баллов\n";
                    $message .= "Рефералов: <b>{$updatedUser['referrals']}</b>\n";
                    $message .= "Статус: 🚫 <b>Заблокирован</b>\n";
                    
                    editMessage($chatId, $msgId, $message, getUserActionsKeyboard($targetUserId, true));
                }
            } else {
                answerCallbackQuery($callbackQueryId, "⛔ Нельзя заблокировать самого себя.", true);
                $callbackAnswered = true;
            }
        }
        else if (strpos($data, 'unblock_user_') === 0) {
            $targetUserId = (int)str_replace('unblock_user_', '', $data);
            $stmt = $db->prepare("UPDATE users SET blocked=0 WHERE user_id = :user_id");
            $stmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
            $stmt->execute();
            sendMessage($targetUserId, "🎉 Ваш доступ к боту восстановлен!");
            answerCallbackQuery($callbackQueryId, "✅ Пользователь ID $targetUserId разблокирован.", false);
            $callbackAnswered = true;
            
            // Обновляем информацию в админ-панели
            $userStmt = $db->prepare("SELECT * FROM users WHERE user_id = :user_id");
            $userStmt->bindValue(':user_id', $targetUserId, SQLITE3_INTEGER);
            $updatedUser = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($updatedUser) {
                $message = "👤 <b>Профиль пользователя</b>\n";
                $message .= "ID: <b>{$updatedUser['user_id']}</b>\n";
                $message .= "Username: " . ($updatedUser['username'] ? htmlspecialchars("@{$updatedUser['username']}") : "<i>не указан</i>") . "\n";
                $message .= "Баланс: <b>{$updatedUser['balance']}</b> баллов\n";
                $message .= "Рефералов: <b>{$updatedUser['referrals']}</b>\n";
                $message .= "Статус: ✅ <b>Активен</b>\n";
                
                editMessage($chatId, $msgId, $message, getUserActionsKeyboard($targetUserId, false));
            }
        }
    } else if (!$userIsAdmin && 
              (strpos($data, 'admin_') === 0 || 
               strpos($data, 'approve_') === 0 || 
               strpos($data, 'reject_') === 0 || 
               strpos($data, 'block_') === 0 || 
               strpos($data, 'unblock_') === 0)
             ) {
        answerCallbackQuery($callbackQueryId, "⛔ У вас нет доступа к этой функции.", true);
        $callbackAnswered = true;
    }

    // Fallback для необработанных callback-запросов
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

try {
    $message_thread_id = $update['message']['message_thread_id'] ?? ($update['callback_query']['message']['message_thread_id'] ?? null);

    if (isset($update['callback_query'])) {
        $userId = $update['callback_query']['from']['id'];
        $username = $update['callback_query']['from']['username'] ?? null;
        
        // Проверяем, существует ли пользователь в базе
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
        
        // Обрабатываем callback-запрос
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

        // Проверяем, существует ли пользователь в базе
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

        // Проверяем, не заблокирован ли пользователь
        $userBlockedStmt = $db->prepare("SELECT blocked FROM users WHERE user_id = :user_id");
        $userBlockedStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userStatus = $userBlockedStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($userId != $adminId && isset($userStatus['blocked']) && $userStatus['blocked'] == 1) {
            sendMessage($chatId, "🚫 Вы заблокированы администратором и не можете использовать этого бота.", null, $message_thread_id);
            echo "OK";
            exit;
        }

        // Обрабатываем текстовые команды
        $text = trim($message['text'] ?? '');
        if (strpos($text, '/start') === 0) {
            handleStart($chatId, $userId, $text);
        } else {
            $userIsAdmin = ($userId == $adminId);
            $is_subscribed = empty($channelId) || isSubscribed($userId) || $userIsAdmin;

            if ($is_subscribed) {
                sendMessage($chatId, "Пожалуйста, используйте кнопки меню. Если меню не видно, используйте команду /start.", getMainMenuInlineKeyboard($userIsAdmin), $message_thread_id);
            } else {
                $subKeyboard = getSubscriptionKeyboard();
                $subMessage = "Привет! Пожалуйста, <b>подпишитесь на наш канал</b> для доступа к боту.";
                if ($subKeyboard) {
                    sendMessage($chatId, $subMessage, $subKeyboard, $message_thread_id);
                } else {
                    sendMessage($chatId, $subMessage . "\nНе удалось сформировать ссылку на канал. Пожалуйста, начните с команды /start или обратитесь к администратору.", null, $message_thread_id);
                }
            }
        }
    }
} catch (Throwable $e) {
    bot_log("!!! Uncaught Throwable: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()."\nStack trace:\n".$e->getTraceAsString(), "FATAL");
    
    // Отправляем уведомление админу о критической ошибке
    if (!empty($adminId)) {
        sendMessage($adminId, "❌ Критическая ошибка в боте: ".$e->getMessage());
    }
}

// Всегда отвечаем OK для Telegram
http_response_code(200);
echo "OK"; 
?>
