<?php
// =============================================
// 🚀 Telegram Bot Webhook Handler for Render.com
// =============================================
// Version 1.6.1: Enhanced with better investment plans and RUB

require_once __DIR__ . '/bootstrap.php';

// Health check endpoint (ping for Render uptime)
if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/ping') {
    header('Content-Type: text/plain');
    echo 'OK';
    exit;
}

// Critical check for $botToken after including bootstrap.php
if (empty($botToken)) {
    bot_log("CRITICAL: TELEGRAM_BOT_TOKEN is not set in bootstrap. Halting index.php.", "ERROR");
    http_response_code(500);
    die("Configuration error: TELEGRAM_BOT_TOKEN is not set.");
}
if ($db === null) {
    bot_log("CRITICAL: Database connection failed in bootstrap. Halting index.php.", "ERROR");
    http_response_code(500);
    die("Configuration error: Database connection failed.");
}

// API URL - $botToken comes from bootstrap.php
$apiUrl = "https://api.telegram.org/bot$botToken";

// Health check endpoint (remains the same, does not need bootstrap for basic health)
if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'time' => date('Y-m-d H:i:s'),
        'version' => '1.6.1'
    ]);
    exit;
}

// Register shutdown function for fatal errors (uses $errorLogPath from bootstrap)
register_shutdown_function(function() use ($errorLogPath) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        if (function_exists('bot_log')) {
            bot_log(sprintf("FATAL Error: %s in %s on line %d", $error['message'], $error['file'], $error['line']), "FATAL");
        } else if (!empty($errorLogPath) && (is_writable(dirname($errorLogPath)) || (file_exists($errorLogPath) && is_writable($errorLogPath)))) {
            $logMessage = sprintf(
                "[%s] Fatal Error: %s in %s on line %d\n",
                date('Y-m-d H:i:s'),
                $error['message'],
                $error['file'],
                $error['line']
            );
            file_put_contents($errorLogPath, $logMessage, FILE_APPEND);
        } else {
            error_log(sprintf("Fatal Error: %s in %s on line %d", $error['message'], $error['file'], $error['line']));
        }
    }
});

// Webhook auto-setup (uses $adminSecretToken, $webhookBaseUrl, $apiUrl, $errorLogPath from bootstrap)
if (isset($_GET['setwebhook']) && $_GET['setwebhook'] === '1') {
    if (!empty($adminSecretToken) && (empty($_GET['admin_token']) || $_GET['admin_token'] !== $adminSecretToken)) {
        http_response_code(403);
        bot_log("Unauthorized GET request: Missing or invalid admin_token for setwebhook.", "WARNING");
        die("Forbidden: Invalid or missing admin token.");
    }
    $scriptPath = $_SERVER['PHP_SELF'];
    $webhookUrlToSet = rtrim($webhookBaseUrl, '/') . $scriptPath;
    
    bot_log("Attempting to set webhook to: $webhookUrlToSet", "INFO");
    
    $deleteResult = @file_get_contents("$apiUrl/deleteWebhook");
    bot_log("Delete webhook result: " . ($deleteResult !== false ? $deleteResult : "Request failed"), "INFO");
    
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
    
    bot_log("Webhook setup attempt to $webhookUrlToSet. Result: " . ($result !== false ? $result : "Request failed") . ", HTTP Code: $httpCode", "INFO");
    
    echo "Webhook setup attempt. Result: " . htmlspecialchars($result !== false ? $result : "Request failed");
    exit;
}

if (isset($_GET['deletewebhook']) && $_GET['deletewebhook'] === '1') {
    if (!empty($adminSecretToken) && (empty($_GET['admin_token']) || $_GET['admin_token'] !== $adminSecretToken)) {
        http_response_code(403);
        bot_log("Unauthorized GET request: Missing or invalid admin_token for deletewebhook.", "WARNING");
        die("Forbidden: Invalid or missing admin token.");
    }
    $result = @file_get_contents("$apiUrl/deleteWebhook");
    bot_log("Webhook delete attempt. Result: " . ($result !== false ? $result : "Request failed"), "INFO");
    echo "Webhook delete attempt. Result: " . htmlspecialchars($result !== false ? $result : "Request failed");
    exit;
}

if (isset($_GET['webhook_info']) && $_GET['webhook_info'] === '1') {
    if (!empty($adminSecretToken) && (empty($_GET['admin_token']) || $_GET['admin_token'] !== $adminSecretToken)) {
        http_response_code(403);
        bot_log("Unauthorized GET request: Missing or invalid admin_token for webhook_info.", "WARNING");
        die("Forbidden: Invalid or missing admin token.");
    }
    $result = @file_get_contents("$apiUrl/getWebhookInfo");
    echo "<pre>Webhook Info: " . htmlspecialchars($result !== false ? $result : "Request failed") . "</pre>";
    exit;
}

if (isset($_GET['logs']) && $_GET['logs'] === '1') {
    if (!empty($adminSecretToken) && (empty($_GET['admin_token']) || $_GET['admin_token'] !== $adminSecretToken)) {
        http_response_code(403);
        bot_log("Unauthorized GET request: Missing or invalid admin_token for logs.", "WARNING");
        die("Forbidden: Invalid or missing admin token.");
    }
    if (file_exists($errorLogPath)) {
        $logs = file_get_contents($errorLogPath);
        echo "<pre>" . htmlspecialchars($logs) . "</pre>";
    } else {
        echo "Log file not found at: " . htmlspecialchars($errorLogPath);
    }
    exit;
}

if (isset($_GET['test_message']) && $_GET['test_message'] === '1') {
    if (!empty($adminSecretToken) && (empty($_GET['admin_token']) || $_GET['admin_token'] !== $adminSecretToken)) {
        http_response_code(403);
        bot_log("Unauthorized GET request: Missing or invalid admin_token for test_message.", "WARNING");
        die("Forbidden: Invalid or missing admin token.");
    }
    $testFunctionResult = testFormatting($adminId);
    echo "Test message sent to admin. Result: " . json_encode($testFunctionResult);
    exit;
}

if (isset($_GET['check_investments']) && $_GET['check_investments'] === '1') {
    if (!empty($adminSecretToken) && (empty($_GET['admin_token']) || $_GET['admin_token'] !== $adminSecretToken)) {
        http_response_code(403);
        bot_log("Unauthorized GET request: Missing or invalid admin_token for check_investments.", "WARNING");
        die("Forbidden: Invalid or missing admin token.");
    }
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
        echo "Method Not Allowed. This endpoint expects POST requests from Telegram or specific GET administrative actions.";
    }
    exit;
}

$content = file_get_contents("php://input");
if (empty($content)) {
    http_response_code(200);
    bot_log("Empty request body received.", "INFO");
    echo "Empty request body.";
    exit;
}

bot_log("Received update: " . $content, "INFO");

$update = json_decode($content, true);
if (!$update) {
    bot_log("Failed to decode JSON from update.", "ERROR");
    echo "Invalid JSON.";
    exit;
}

// -----------------------------
// 💰 Улучшенные инвестиционные планы (в рублях)
// -----------------------------
$investmentPlans = [
    1 => [
        'name' => 'Стартовый', 
        'min_amount' => 1000, 
        'days' => 7, 
        'percent' => 15, 
        'description' => 'Краткосрочный план для новичков - 15% за неделю'
    ],
    2 => [
        'name' => 'Стандарт', 
        'min_amount' => 5000, 
        'days' => 14, 
        'percent' => 35, 
        'description' => 'Оптимальный план для стабильного дохода - 35% за 2 недели'
    ],
    3 => [
        'name' => 'Профи', 
        'min_amount' => 15000, 
        'days' => 21, 
        'percent' => 65, 
        'description' => 'Высокодоходный план для опытных инвесторов - 65% за 3 недели'
    ]
];

/**
 * Sends an API request to Telegram.
 */
function apiRequest($method, $params = [], $retries = 3) {
    global $apiUrl, $errorLogPath;
    $url = "$apiUrl/$method";

    if (isset($params['reply_markup']) && is_array($params['reply_markup'])) {
        $params['reply_markup'] = json_encode($params['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
            bot_log("API Error ($method): cURL Error: $curlError. HTTP Code: $httpCode. Retry " . ($i+1) . "/$retries", "ERROR");
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
        
        bot_log("API Error ($method): HTTP $httpCode - Response: $response. Retry " . ($i+1) . "/$retries", "ERROR");
        if ($httpCode >= 500 && $i < $retries - 1) {
            sleep(1 + $i); 
            continue;
        }
        
        curl_close($ch);
        return $result;
    }
    return false;
}

/**
 * Sends a message to a Telegram chat.
 */
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

/**
 * Edits an existing message in a Telegram chat.
 */
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

/**
 * Answers a callback query (e.g., from an inline button press).
 */
function answerCallbackQuery($callbackQueryId, $text = null, $showAlert = false) {
    $params = ['callback_query_id' => $callbackQueryId];
    if ($text !== null) {
        $params['text'] = $text;
    }
    $params['show_alert'] = $showAlert;
    return apiRequest('answerCallbackQuery', $params);
}

// isSubscribed uses $botToken, $channelId, bot_log from bootstrap
function isSubscribed($userId) {
    global $botToken, $channelId;
    
    if (empty($channelId) || $channelId === '@') {
        return true;
    }
    
    $url = "https://api.telegram.org/bot$botToken/getChatMember?chat_id=$channelId&user_id=$userId";
    $response = @file_get_contents($url); 
    if ($response === false) {
        bot_log("isSubscribed: Failed to fetch from $url. User: $userId", "ERROR");
        return false;
    }
    $data = json_decode($response, true);

    if (!isset($data['ok']) || $data['ok'] === false) {
        if (isset($data['description'])) {
            bot_log("isSubscribed: API error for User $userId, Channel $channelId: " . $data['description'], "WARNING");
            if (strpos($data['description'], "not found") !== false || 
                strpos($data['description'], "kicked") !== false ||
                strpos($data['description'], "chat not found") !== false ||
                strpos($data['description'], "user not found") !== false) {
                return false;
            }
        }
        return false;
    }
    
    $status = $data['result']['status'] ?? '';
    return in_array($status, ['member', 'administrator', 'creator']);
}

// Test function for formatting (uses sendMessage)
function testFormatting($adminId) {
    return sendMessage($adminId, 
        "<b>Тест жирного</b>\n" .
        "<i>Тест курсива</i>\n" .
        "<code>Тест моноширинного</code>\n" .
        "<pre>Тест блока кода</pre>\n" .
        "<a href='https://t.me/'>Тест ссылки</a>"
    );
}

// createInvestment uses $db, $investmentPlans
function createInvestment($userId, $planId, $amount) {
    global $db, $investmentPlans;
    
    if (!isset($investmentPlans[$planId])) return false;
    
    $plan = $investmentPlans[$planId];
    $startDate = time();
    $endDate = $startDate + ($plan['days'] * 86400);
    
    $stmt = $db->prepare("INSERT INTO investments (user_id, plan_id, amount, start_date, end_date, status) 
                         VALUES (:user_id, :plan_id, :amount, :start_date, :end_date, 'active')");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':plan_id', $planId, SQLITE3_INTEGER);
    $stmt->bindValue(':amount', $amount, SQLITE3_INTEGER);
    $stmt->bindValue(':start_date', $startDate, SQLITE3_INTEGER);
    $stmt->bindValue(':end_date', $endDate, SQLITE3_INTEGER);
    
    if ($stmt->execute()) {
        $updateBalanceStmt = $db->prepare("UPDATE users SET balance = balance - :amount WHERE user_id = :user_id");
        $updateBalanceStmt->bindValue(':amount', $amount, SQLITE3_INTEGER);
        $updateBalanceStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateBalanceStmt->execute();
        return true;
    }
    bot_log("Failed to create investment for user $userId, plan $planId. DB Error: ".$db->lastErrorMsg(), "ERROR");
    return false;
}

// getUserActiveInvestments uses $db, $investmentPlans
function getUserActiveInvestments($userId) {
    global $db, $investmentPlans;
    
    $stmt = $db->prepare("SELECT * FROM investments WHERE user_id = :user_id AND status = 'active'");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $investments = [];
    if ($result) {
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
    } else {
        bot_log("Failed to get active investments for user $userId. DB Error: ".$db->lastErrorMsg(), "ERROR");
    }
    return $investments;
}

// checkCompletedInvestments uses $db, $investmentPlans, sendMessage
function checkCompletedInvestments() {
    global $db, $investmentPlans;
    
    $now = time();
    $stmt = $db->prepare("SELECT * FROM investments WHERE status = 'active' AND end_date <= :now");
    $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    if ($result) {
        while ($investment = $result->fetchArray(SQLITE3_ASSOC)) {
            $userId = $investment['user_id'];
            $planId = $investment['plan_id'];
            $amount = $investment['amount'];
            
            if (!isset($investmentPlans[$planId])) {
                bot_log("Completed investment check: Plan ID {$planId} not found for investment ID {$investment['id']}.", "WARNING");
                continue;
            }
            
            $plan = $investmentPlans[$planId];
            $profit = round($amount * ($plan['percent'] / 100));
            $total = $amount + $profit;
            
            $updateInvStmt = $db->prepare("UPDATE investments SET status = 'completed' WHERE id = :id");
            $updateInvStmt->bindValue(':id', $investment['id'], SQLITE3_INTEGER);
            $updateInvStmt->execute();
            
            $updateBalanceStmt = $db->prepare("UPDATE users SET balance = balance + :total WHERE user_id = :user_id");
            $updateBalanceStmt->bindValue(':total', $total, SQLITE3_INTEGER);
            $updateBalanceStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $updateBalanceStmt->execute();
            
            $balanceStmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
            $balanceStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $balanceResult = $balanceStmt->execute()->fetchArray(SQLITE3_ASSOC);
            $currentBalance = $balanceResult ? number_format($balanceResult['balance'], 0, ',', ' ') : 'N/A';
            
            $message = "🎉 <b>Поздравляем!</b>\n\n" .
                       "Ваш инвестиционный план <b>{$plan['name']}</b> успешно завершён!\n\n" .
                       "💰 Сумма инвестиций: <b>" . number_format($amount, 0, ',', ' ') . "₽</b>\n" .
                       "📈 Прибыль: <b>" . number_format($profit, 0, ',', ' ') . "₽</b>\n" .
                       "💎 Общий баланс: <b>{$currentBalance}₽</b>\n\n" .
                       "Благодарим за доверие к нашей платформе! 🚀";
            sendMessage($userId, $message);
        }
    } else {
        bot_log("Failed to check completed investments. DB Error: ".$db->lastErrorMsg(), "ERROR");
    }
}

// -----------------------------
// ⌨️ Keyboards (All Inline) - use $channelUsername from bootstrap
// -----------------------------
function getSubscriptionKeyboard() {
    global $channelUsername;
    $channelUrl = 'https://t.me/' . $channelUsername;
    return ['inline_keyboard' => [[['text' => '📢 Подписаться на канал', 'url' => $channelUrl], ['text' => '✅ Я подписался', 'callback_data' => 'check_subscription']]]];
}

function getMainMenuInlineKeyboard($isAdmin = false) {
    $inline_keyboard = [
        [['text' => '💰 Заработать', 'callback_data' => 'earn_money'], ['text' => '💳 Баланс', 'callback_data' => 'show_balance']],
        [['text' => '📊 Инвестировать', 'callback_data' => 'show_investment_plans'], ['text' => '📈 Мои инвестиции', 'callback_data' => 'show_my_investments']],
        [['text' => '🏆 Топ', 'callback_data' => 'show_top_users'], ['text' => '👥 Рефералы', 'callback_data' => 'show_referrals_info']],
        [['text' => '💸 Вывод', 'callback_data' => 'initiate_withdraw'], ['text' => 'ℹ️ Помощь', 'callback_data' => 'show_help_info']]
    ];
    if ($isAdmin) $inline_keyboard[] = [['text' => '⚙️ Админ-панель', 'callback_data' => 'admin_panel_show']];
    return ['inline_keyboard' => $inline_keyboard];
}

function getBackToMainMenuKeyboard() { return ['inline_keyboard' => [[['text' => '⬅️ Назад в меню', 'callback_data' => 'main_menu_show']]]]; }

function getInvestmentPlansKeyboard() {
    global $investmentPlans;
    $keyboard = ['inline_keyboard' => []];
    foreach ($investmentPlans as $id => $plan) {
        $keyboard['inline_keyboard'][] = [['text' => "📊 {$plan['name']} - {$plan['percent']}% за {$plan['days']} дней", 'callback_data' => "select_investment_plan_$id"]];
    }
    $keyboard['inline_keyboard'][] = [['text' => '⬅️ Назад в меню', 'callback_data' => 'main_menu_show']];
    return $keyboard;
}

function getInvestmentAmountKeyboard($planId) {
    global $investmentPlans;
    $plan = $investmentPlans[$planId] ?? null;
    if (!$plan) return getBackToMainMenuKeyboard();
    $minAmount = $plan['min_amount'];
    return ['inline_keyboard' => [
        [['text' => number_format($minAmount, 0, ',', ' ')."₽", 'callback_data' => "invest_{$planId}_{$minAmount}"], ['text' => number_format($minAmount*2, 0, ',', ' ')."₽", 'callback_data' => "invest_{$planId}_".($minAmount*2)]],
        [['text' => number_format($minAmount*5, 0, ',', ' ')."₽", 'callback_data' => "invest_{$planId}_".($minAmount*5)], ['text' => number_format($minAmount*10, 0, ',', ' ')."₽", 'callback_data' => "invest_{$planId}_".($minAmount*10)]],
        [['text' => '⬅️ Назад к планам', 'callback_data' => 'show_investment_plans']],
        [['text' => '⬅️ Назад в меню', 'callback_data' => 'main_menu_show']]
    ]];
}

function getAdminPanelKeyboard() {
    return ['inline_keyboard' => [
        [['text' => '📊 Статистика', 'callback_data' => 'admin_stats_show']],
        [['text' => '👤 Участники', 'callback_data' => 'admin_users_list']],
        [['text' => '💰 Инвестиции', 'callback_data' => 'admin_investments_list']],
        [['text' => '⬅️ В главное меню', 'callback_data' => 'main_menu_show']]
    ]];
}

function getBackToAdminPanelKeyboard() { return ['inline_keyboard' => [[['text' => '⬅️ Назад в админ-панель', 'callback_data' => 'admin_panel_show']]]]; }
function getWithdrawKeyboard($targetUserId) { return ['inline_keyboard' => [[['text' => '✅ Одобрить', 'callback_data' => "approve_withdraw_$targetUserId"], ['text' => '❌ Отклонить', 'callback_data' => "reject_withdraw_$targetUserId"]]]]; }

function getUserActionsKeyboard($targetUserId, $isBlocked) {
    $blockButtonText = $isBlocked ? '✅ Разблокировать' : '🚫 Заблокировать';
    $blockCallbackData = $isBlocked ? "unblock_user_$targetUserId" : "block_user_$targetUserId";
    return ['inline_keyboard' => [[['text' => $blockButtonText, 'callback_data' => $blockCallbackData]], [['text' => '⬅️ К списку участников', 'callback_data' => 'admin_users_list']]]];
}

// getBotStatsText uses $db, bot_log
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
        if (count($topUsers) < 5) $topUsers[] = $user;
    }

    $investmentStats = ['total' => 0, 'active' => 0, 'completed' => 0, 'total_amount' => 0];
    $invResult = $db->query("SELECT status, amount FROM investments");
    if ($invResult) {
        while ($inv = $invResult->fetchArray(SQLITE3_ASSOC)) {
            $investmentStats['total']++;
            $investmentStats['total_amount'] += $inv['amount'];
            if ($inv['status'] === 'active') $investmentStats['active']++;
            else if ($inv['status'] === 'completed') $investmentStats['completed']++;
        }
    } else {
        bot_log("Error fetching investment stats: " . $db->lastErrorMsg(), "ERROR");
    }
    

    $message = "📊 <b>Статистика бота</b>\n";
    $message .= "👥 Всего пользователей: <b>{$stats['total']}</b>\n";
    $message .= "🟢 Активных (не заблокированных): <b>{$stats['active']}</b>\n";
    $message .= "💰 Общий баланс пользователей: <b>" . number_format($stats['balance'], 0, ',', ' ') . "₽</b>\n";
    $message .= "🔗 Всего привлечено рефералов: <b>{$stats['referrals']}</b>\n\n";
    $message .= "📈 <b>Инвестиции</b>\n";
    $message .= "💼 Всего инвестиций: <b>{$investmentStats['total']}</b>\n";
    $message .= "⏳ Активных инвестиций: <b>{$investmentStats['active']}</b>\n";
    $message .= "✅ Завершенных инвестиций: <b>{$investmentStats['completed']}</b>\n";
    $message .= "💵 Общая сумма инвестиций: <b>" . number_format($investmentStats['total_amount'], 0, ',', ' ') . "₽</b>\n\n";
    $message .= "🏆 <b>Топ-5 пользователей по балансу</b>:\n";
    if (empty($topUsers)) $message .= "Пока нет пользователей в топе.\n";
    else foreach ($topUsers as $i => $user) $message .= ($i+1) . ". " . ($user['username'] ? htmlspecialchars("@".$user['username']) : "ID: ".$user['user_id']) . " - <b>" . number_format($user['balance'], 0, ',', ' ') . "₽</b> (Реф: {$user['referrals']}) " . ($user['blocked'] ? '🚫' : '✅') . "\n";
    $message .= "\n⏱ Обновлено: " . date('d.m.Y H:i:s');
    return $message;
}

/**
 * Handles the /start command, user registration, and referral logic.
 */
function handleStart($chatId, $userId, $text) {
    global $db, $botUsername, $adminId, $channelId, $channelUsername;

    $refCode = '';
    if (strpos($text, ' ') !== false) {
        $parts = explode(' ', $text, 2);
        if (count($parts) > 1) $refCode = trim($parts[1]);
    }

    $userExistsStmt = $db->prepare("SELECT 1 FROM users WHERE user_id = :user_id");
    $userExistsStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $userExistsResult = $userExistsStmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($userExistsResult && !empty($refCode)) {
        $userReferralInfoStmt = $db->prepare("SELECT referred_by FROM users WHERE user_id = :user_id");
        $userReferralInfoStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userReferralInfo = $userReferralInfoStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($userReferralInfo && empty($userReferralInfo['referred_by'])) { 
            // Только сохраняем, кто пригласил, но не начисляем бонус
            $referrerQuery = $db->prepare("SELECT user_id FROM users WHERE ref_code = :ref_code AND user_id != :user_id");
            $referrerQuery->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
            $referrerQuery->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $referrer = $referrerQuery->execute()->fetchArray(SQLITE3_ASSOC);

            if ($referrer && $referrer['user_id'] != $userId) {
                $updateUserStmt = $db->prepare("UPDATE users SET referred_by = :referrer_id WHERE user_id = :user_id");
                $updateUserStmt->bindValue(':referrer_id', $referrer['user_id'], SQLITE3_INTEGER);
                $updateUserStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $updateUserStmt->execute();
            }
        }
    }
    
    if (!empty($channelId) && $channelId !== '@' && !isSubscribed($userId) && $userId != $adminId) {
        $message = "👋 Добро пожаловать в @$botUsername!\n\n";
        $message .= "Для начала, пожалуйста, <b>подпишитесь на наш канал</b> (@$channelUsername). Это обязательное условие для использования бота.\n\n";
        $message .= "После подписки нажмите кнопку «Я подписался».";
        sendMessage($chatId, $message, getSubscriptionKeyboard());
        return;
    }

    $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
    $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');

    $message = "🏛 <b>Добро пожаловать в инвестиционную платформу!</b>\n\n";
    $message .= "💰 Зарабатывайте, инвестируйте и получайте стабильную прибыль.\n";
    $message .= "👥 Приглашайте друзей и получайте <b>500₽</b> за каждого! Ваша реферальная ссылка:\n<code>$refLink</code>\n\n";
    $message .= "👇 Используйте меню ниже для навигации.";
    sendMessage($chatId, $message, getMainMenuInlineKeyboard($userId == $adminId));
}

/**
 * Handles callback queries from inline keyboard button presses.
 */
function handleCallback($callbackQuery) {
    global $db, $adminId, $botUsername, $channelId, $channelUsername, $investmentPlans;

    $callbackQueryId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $msgId = $callbackQuery['message']['message_id'];
    $userId = $callbackQuery['from']['id']; 
    $data = $callbackQuery['data'];
    $userIsAdmin = ($userId == $adminId);
    $callbackAnswered = false;

    if ($data === 'check_subscription') {
        if (!empty($channelId) && $channelId !== '@' && isSubscribed($userId)) {
            // --- Реферальная логика: начислять бонус только после подписки ---
            $userReferralInfoStmt = $db->prepare("SELECT referred_by FROM users WHERE user_id = :user_id");
            $userReferralInfoStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $userReferralInfo = $userReferralInfoStmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($userReferralInfo && empty($userReferralInfo['referred_by'])) {
                // Найти реферера по ref_code из /start
                $userStartStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
                $userStartStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $userStart = $userStartStmt->execute()->fetchArray(SQLITE3_ASSOC);
                $refCode = $userStart['ref_code'] ?? '';
                if (!empty($refCode)) {
                    $referrerQuery = $db->prepare("SELECT user_id FROM users WHERE ref_code = :ref_code AND user_id != :user_id");
                    $referrerQuery->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
                    $referrerQuery->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $referrer = $referrerQuery->execute()->fetchArray(SQLITE3_ASSOC);
                    if ($referrer && $referrer['user_id'] != $userId) {
                        $updateReferrerStmt = $db->prepare("UPDATE users SET referrals = referrals + 1, balance = balance + 500 WHERE user_id = :referrer_id");
                        $updateReferrerStmt->bindValue(':referrer_id', $referrer['user_id'], SQLITE3_INTEGER);
                        $updateReferrerStmt->execute();
                        sendMessage($referrer['user_id'], "🎉 Новый реферал присоединился по вашей ссылке! <b>+500₽</b> на ваш счет.");
                        $updateUserStmt = $db->prepare("UPDATE users SET referred_by = :referrer_id WHERE user_id = :user_id");
                        $updateUserStmt->bindValue(':referrer_id', $referrer['user_id'], SQLITE3_INTEGER);
                        $updateUserStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                        $updateUserStmt->execute();
                    }
                }
            }
            $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
            $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
            $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');
            $message = "✅ <b>Спасибо за подписку!</b>\n\nТеперь вы можете пользоваться всеми функциями бота.\n\nВаша реферальная ссылка для приглашения друзей:\n<code>$refLink</code>\n\n👇 Вот главное меню:";
            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        } else if (empty($channelId) || $channelId === '@') {
            $message = "✅ Проверка подписки не требуется.\n\n👇 Вот главное меню:";
            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        } else {
            answerCallbackQuery($callbackQueryId, "❌ Вы всё ещё не подписаны на канал @$channelUsername. Пожалуйста, подпишитесь и нажмите кнопку ещё раз.", true);
            $callbackAnswered = true;
            return;
        }
        answerCallbackQuery($callbackQueryId);
        $callbackAnswered = true;
        return;
    }
    
    if (!$userIsAdmin && !empty($channelId) && $channelId !== '@' && !isSubscribed($userId)) {
        $text = "👋 Добро пожаловать в @$botUsername!\n\nПожалуйста, <b>подпишитесь на наш канал</b> (@$channelUsername), чтобы продолжить.";
        editMessage($chatId, $msgId, $text, getSubscriptionKeyboard());
        answerCallbackQuery($callbackQueryId);
        return;
    }

    // --- Main Menu Callbacks ---
    if ($data === 'main_menu_show') {
        $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
        $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');
        $message = "🏛 <b>Главное меню</b> инвестиционной платформы!\n\n💰 Зарабатывайте и инвестируйте с умом.\n👥 Приглашайте друзей: <code>$refLink</code>\n\n👇 Используйте кнопки ниже:";
        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
    }
    else if ($data === 'earn_money') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $earnedAmount = 1000; // Увеличили бонус до 1000₽
        $updateStmt = $db->prepare("UPDATE users SET balance = balance + :amount, last_earn = :last_earn WHERE user_id = :user_id");
        $updateStmt->bindValue(':amount', $earnedAmount, SQLITE3_INTEGER);
        $updateStmt->bindValue(':last_earn', time(), SQLITE3_INTEGER);
        $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateStmt->execute();
        
        $newBalance = ($row['balance'] ?? 0) + $earnedAmount;
        $message = "🎉 <b>Ежедневный бонус получен!</b>\n\n💰 Начислено: <b>+" . number_format($earnedAmount, 0, ',', ' ') . "₽</b>\n💳 Ваш баланс: <b>" . number_format($newBalance, 0, ',', ' ') . "₽</b>\n\n💡 Используйте баланс для инвестирования и получения пассивного дохода!";
        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        answerCallbackQuery($callbackQueryId, "✅ Получено " . number_format($earnedAmount, 0, ',', ' ') . "₽!", false);
    }
    else if ($data === 'show_balance') {
        $stmt = $db->prepare("SELECT balance, referrals FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userInfo = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        $balance = $userInfo['balance'] ?? 0;
        $referrals = $userInfo['referrals'] ?? 0;
        
        $message = "💳 <b>Ваш баланс</b>\n\n💰 Доступно: <b>" . number_format($balance, 0, ',', ' ') . "₽</b>\n👥 Рефералов: <b>{$referrals}</b>\n\n💡 Инвестируйте средства для получения прибыли!";
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
    }
    else if ($data === 'show_investment_plans') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        
        $message = "📊 <b>Инвестиционные планы</b>\n\n💳 Ваш баланс: <b>" . number_format($balance, 0, ',', ' ') . "₽</b>\n\n";
        
        foreach ($investmentPlans as $id => $plan) {
            $message .= "📈 <b>{$plan['name']}</b>\n";
            $message .= "💰 От " . number_format($plan['min_amount'], 0, ',', ' ') . "₽\n";
            $message .= "⏱ Срок: {$plan['days']} дней\n";
            $message .= "📊 Доходность: <b>{$plan['percent']}%</b>\n";
            $message .= "📋 {$plan['description']}\n\n";
        }
        
        editMessage($chatId, $msgId, $message, getInvestmentPlansKeyboard());
    }
    else if (strpos($data, 'select_investment_plan_') === 0) {
        $planId = (int)str_replace('select_investment_plan_', '', $data);
        if (!isset($investmentPlans[$planId])) {
            answerCallbackQuery($callbackQueryId, "План не найден", true);
            return;
        }
        
        $plan = $investmentPlans[$planId];
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        
        $message = "📊 <b>План: {$plan['name']}</b>\n\n";
        $message .= "📋 {$plan['description']}\n\n";
        $message .= "💰 Минимум: " . number_format($plan['min_amount'], 0, ',', ' ') . "₽\n";
        $message .= "⏱ Срок: {$plan['days']} дней\n";
        $message .= "📈 Доходность: <b>{$plan['percent']}%</b>\n\n";
        
        $exampleAmount = $plan['min_amount'];
        $exampleProfit = round($exampleAmount * ($plan['percent'] / 100));
        $exampleTotal = $exampleAmount + $exampleProfit;
        
        $message .= "💡 <b>Пример:</b>\n";
        $message .= "Инвестиция: " . number_format($exampleAmount, 0, ',', ' ') . "₽\n";
        $message .= "Прибыль: " . number_format($exampleProfit, 0, ',', ' ') . "₽\n";
        $message .= "Итого: " . number_format($exampleTotal, 0, ',', ' ') . "₽\n\n";
        $message .= "💳 Ваш баланс: " . number_format($balance, 0, ',', ' ') . "₽\n\nВыберите сумму:";
        
        editMessage($chatId, $msgId, $message, getInvestmentAmountKeyboard($planId));
    }
    else if (strpos($data, 'invest_') === 0) {
        $parts = explode('_', $data);
        if (count($parts) !== 3) {
            answerCallbackQuery($callbackQueryId, "Неверный формат", true);
            return;
        }
        
        $planId = (int)$parts[1];
        $amount = (int)$parts[2];
        
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        
        if ($balance < $amount) {
            answerCallbackQuery($callbackQueryId, "Недостаточно средств на балансе", true);
            return;
        }
        
        if (createInvestment($userId, $planId, $amount)) {
            $plan = $investmentPlans[$planId];
            $profit = round($amount * ($plan['percent'] / 100));
            $total = $amount + $profit;
            $endDate = date('d.m.Y', time() + ($plan['days'] * 86400));
            
            $message = "✅ <b>Инвестиция оформлена!</b>\n\n";
            $message .= "📊 План: <b>{$plan['name']}</b>\n";
            $message .= "💰 Сумма: " . number_format($amount, 0, ',', ' ') . "₽\n";
            $message .= "📈 Прибыль: " . number_format($profit, 0, ',', ' ') . "₽\n";
            $message .= "💎 Итого: " . number_format($total, 0, ',', ' ') . "₽\n";
            $message .= "📅 Завершение: {$endDate}\n\n";
            $message .= "🎉 Спасибо за доверие!";
            
            editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
            answerCallbackQuery($callbackQueryId, "Инвестиция успешно создана!", false);
        } else {
            answerCallbackQuery($callbackQueryId, "Ошибка создания инвестиции", true);
        }
    }
    else if ($data === 'show_my_investments') {
        $investments = getUserActiveInvestments($userId);
        
        $message = "📈 <b>Мои инвестиции</b>\n\n";
        
        if (empty($investments)) {
            $message .= "📭 У вас пока нет активных инвестиций.\n\n";
            $message .= "💡 Начните инвестировать уже сегодня!";
        } else {
            foreach ($investments as $idx => $inv) {
                $message .= "📊 <b>Инвестиция #" . ($idx + 1) . "</b>\n";
                $message .= "💼 План: {$inv['plan_name']}\n";
                $message .= "💰 Сумма: " . number_format($inv['amount'], 0, ',', ' ') . "₽\n";
                $message .= "📈 Прибыль: " . number_format($inv['profit'], 0, ',', ' ') . "₽\n";
                $message .= "⏱ Осталось: {$inv['days_left']} дн.\n";
                $message .= "📅 " . date('d.m.Y', $inv['start_date']) . " → " . date('d.m.Y', $inv['end_date']) . "\n\n";
            }
        }
        
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
    }
    else if ($data === 'show_top_users') {
        $result = $db->query("SELECT username, balance, referrals FROM users WHERE blocked = 0 ORDER BY balance DESC LIMIT 10");
        $message = "🏆 <b>Топ инвесторов</b>\n\n";
        
        if ($result) {
            $position = 1;
            while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
                $emoji = ['🥇', '🥈', '🥉'][$position - 1] ?? '🏅';
                // Только имя (username) или 'Пользователь', без ссылки и id
                $username = $user['username'] ? htmlspecialchars($user['username']) : "Пользователь";
                $message .= "{$emoji} {$position}. {$username}\n";
                $message .= "💰 " . number_format($user['balance'], 0, ',', ' ') . "₽ | 👥 {$user['referrals']} реф.\n\n";
                $position++;
            }
        } else {
            $message .= "Пока нет данных для отображения.";
        }
        
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
    }
    else if ($data === 'show_referrals_info') {
        $stmt = $db->prepare("SELECT referrals FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userInfo = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $referrals = $userInfo['referrals'] ?? 0;
        
        $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
        $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');
        
        $message = "👥 <b>Партнёрская программа</b>\n\n";
        $message .= "🎯 Ваших рефералов: <b>{$referrals}</b>\n";
        $message .= "💰 Заработано: <b>" . number_format($referrals * 500, 0, ',', ' ') . "₽</b>\n\n";
        $message .= "💡 <b>Как это работает:</b>\n";
        $message .= "• Поделитесь своей ссылкой\n";
        $message .= "• За каждого друга получите 500₽\n";
        $message .= "• Деньги зачисляются мгновенно\n\n";
        $message .= "🔗 <b>Ваша ссылка:</b>\n<code>{$refLink}</code>";
        
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
    }
    else if ($data === 'initiate_withdraw') {
        $message = "💸 <b>Заявка на вывод средств</b>\n\n";
        $message .= "📝 Для вывода средств обратитесь к администратору:\n";
        $message .= "👤 @admin_username\n\n";
        $message .= "📋 Укажите:\n";
        $message .= "• Сумму для вывода\n";
        $message .= "• Реквизиты для перевода\n";
        $message .= "• Ваш ID: <code>{$userId}</code>\n\n";
        $message .= "⏱ Обработка заявок: 24-48 часов";
        
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
    }
    else if ($data === 'show_help_info') {
        $message = "ℹ️ <b>Справка и поддержка</b>\n\n";
        $message .= "🤖 <b>Как пользоваться ботом:</b>\n";
        $message .= "• Получайте ежедневный бонус\n";
        $message .= "• Выбирайте инвестиционный план\n";
        $message .= "• Следите за прогрессом инвестиций\n";
        $message .= "• Приглашайте друзей за вознаграждение\n\n";
        $message .= "💰 <b>Инвестиционные планы:</b>\n";
        foreach ($investmentPlans as $plan) {
            $message .= "• {$plan['name']}: {$plan['percent']}% за {$plan['days']} дн.\n";
        }
        $message .= "\n📞 <b>Поддержка:</b> @admin_username";
        
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
    }
    
    // --- Admin Panel Callbacks ---
    else if ($data === 'admin_panel_show' && $userIsAdmin) {
        $message = "⚙️ <b>Панель администратора</b>\n\nВыберите действие:";
        editMessage($chatId, $msgId, $message, getAdminPanelKeyboard());
    }
    else if ($data === 'admin_stats_show' && $userIsAdmin) {
        $statsText = getBotStatsText();
        editMessage($chatId, $msgId, $statsText, getBackToAdminPanelKeyboard());
    }
    else if ($data === 'admin_users_list' && $userIsAdmin) {
        $result = $db->query("SELECT user_id, username, balance, referrals, blocked FROM users ORDER BY user_id DESC LIMIT 20");
        $message = "👤 <b>Список пользователей</b> (последние 20)\n\n";
        
        if ($result) {
            while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
                $status = $user['blocked'] ? '🚫' : '✅';
                $username = $user['username'] ? "@" . htmlspecialchars($user['username']) : "ID: " . $user['user_id'];
                $message .= "{$status} {$username}\n";
                $message .= "💰 " . number_format($user['balance'], 0, ',', ' ') . "₽ | 👥 {$user['referrals']} реф.\n\n";
            }
        } else {
            $message .= "Ошибка получения данных.";
        }
        
        editMessage($chatId, $msgId, $message, getBackToAdminPanelKeyboard());
    }
    else if ($data === 'admin_investments_list' && $userIsAdmin) {
        $result = $db->query("SELECT i.*, u.username FROM investments i LEFT JOIN users u ON i.user_id = u.user_id ORDER BY i.id DESC LIMIT 20");
        $message = "💼 <b>Список инвестиций</b> (последние 20)\n\n";
        
        if ($result) {
            while ($inv = $result->fetchArray(SQLITE3_ASSOC)) {
                $username = $inv['username'] ? "@" . htmlspecialchars($inv['username']) : "ID: " . $inv['user_id'];
                $planName = $investmentPlans[$inv['plan_id']]['name'] ?? 'Неизвестный';
                $status = $inv['status'] === 'active' ? '⏳' : '✅';
                
                $message .= "{$status} {$username}\n";
                $message .= "📊 {$planName} | " . number_format($inv['amount'], 0, ',', ' ') . "₽\n";
                $message .= "📅 " . date('d.m.Y', $inv['start_date']) . " → " . date('d.m.Y', $inv['end_date']) . "\n\n";
            }
        } else {
            $message .= "Ошибка получения данных.";
        }
        
        editMessage($chatId, $msgId, $message, getBackToAdminPanelKeyboard());
    }

    if (!$callbackAnswered) {
        answerCallbackQuery($callbackQueryId);
    }
}

// -----------------------------
// 🎯 Main Update Processing
// -----------------------------

$message_thread_id = null;
if (isset($update['message']['message_thread_id'])) {
    $message_thread_id = $update['message']['message_thread_id'];
}

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'] ?? null;
    $userId = $message['from']['id'] ?? null; 

    if (!$chatId || !$userId) {
        bot_log("No chat_id or user_id in message: " . json_encode($message), "WARNING");
        echo "OK"; exit;
    }

    // Initialize user if not exists
    $stmt = $db->prepare("SELECT 1 FROM users WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    if (!$stmt->execute()->fetchArray(SQLITE3_ASSOC)) {
        $username = $message['from']['username'] ?? null;
        $refCode = substr(bin2hex(random_bytes(4)), 0, 8); 
        $insertStmt = $db->prepare("INSERT INTO users (user_id, username, ref_code, balance, referrals, referred_by, blocked, joined_at, last_earn) VALUES (:user_id, :username, :ref_code, 0, 0, NULL, 0, :joined_at, 0)");
        $insertStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $insertStmt->bindValue(':username', $username ? substr($username, 0, 255) : null, SQLITE3_TEXT);
        $insertStmt->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
        $insertStmt->bindValue(':joined_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        if(!$insertStmt->execute()) {
            bot_log("Failed to insert new user $userId ($username). DB Error: " . $db->lastErrorMsg(), "ERROR");
        } else {
            bot_log("New user $userId ($username) initialized with ref_code $refCode.", "INFO");
        }
    }

    // Check if user is blocked
    $userBlockedStmt = $db->prepare("SELECT blocked FROM users WHERE user_id = :user_id");
    $userBlockedStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $userStatus = $userBlockedStmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($userId != $adminId && isset($userStatus['blocked']) && $userStatus['blocked'] == 1) {
        sendMessage($chatId, "🚫 Вы заблокированы администратором и не можете использовать этого бота.", null, $message_thread_id);
        echo "OK"; exit;
    }

    $text = trim($message['text'] ?? '');
    
    if (strpos($text, '/start') === 0) {
        handleStart($chatId, $userId, $text);
    } else {
        $userIsAdmin = ($userId == $adminId);
        $is_subscribed = (empty($channelId) || $channelId === '@') || isSubscribed($userId) || $userIsAdmin;
        
        if ($is_subscribed) {
            $message_response = "📱 Пожалуйста, используйте кнопки меню для навигации.\n\nЕсли меню не отображается, воспользуйтесь командой /start";
            sendMessage($chatId, $message_response, getMainMenuInlineKeyboard($userIsAdmin), $message_thread_id);
        } else {
            sendMessage($chatId, "👋 Добро пожаловать в @$botUsername!\n\nПожалуйста, <b>подпишитесь на наш канал</b> (@$channelUsername) для доступа к боту.", getSubscriptionKeyboard(), $message_thread_id);
        }
    }
}

if (isset($update['callback_query'])) {
    handleCallback($update['callback_query']);
}

// Check for completed investments periodically
if (rand(1, 10) === 1) {
    checkCompletedInvestments();
}

echo "OK";
?>