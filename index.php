<?php
// =============================================
// 🚀 Telegram Bot Webhook Handler for Render.com
// =============================================
// Version 1.6.0: Refactored with bootstrap.php

require_once __DIR__ . '/bootstrap.php';

// Health check endpoint (ping for Render uptime)
if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/ping' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain');
    echo 'OK';
    exit; // Завершаем выполнение, чтобы бот не обрабатывал запрос дальше
}

// Critical check for $botToken after including bootstrap.php
if (empty($botToken)) {
    // bot_log is available from bootstrap.php
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
        'version' => '1.6.0' // Consider making version dynamic or part of bootstrap
    ]);
    exit;
}

// Register shutdown function for fatal errors (uses $errorLogPath from bootstrap)
register_shutdown_function(function() use ($errorLogPath) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        // Use bot_log if available, otherwise direct file_put_contents
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
            // Fallback if custom log path isn't writable
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
    $testFunctionResult = testFormatting($adminId); // $adminId from bootstrap
    echo "Test message sent to admin. Result: " . json_encode($testFunctionResult);
    exit;
}

if (isset($_GET['check_investments']) && $_GET['check_investments'] === '1') {
    if (!empty($adminSecretToken) && (empty($_GET['admin_token']) || $_GET['admin_token'] !== $adminSecretToken)) {
        http_response_code(403);
        bot_log("Unauthorized GET request: Missing or invalid admin_token for check_investments.", "WARNING");
        die("Forbidden: Invalid or missing admin token.");
    }
    checkCompletedInvestments(); // Assumes $db is global or passed if needed
    echo "Investment check completed.";
    exit;
}

// Main webhook handler
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Check if it's one of the allowed GET requests (already handled above) or health check
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
    http_response_code(200); // Telegram expects 200 even for empty body if it's a legitimate ping
    bot_log("Empty request body received.", "INFO");
    echo "Empty request body.";
    exit;
}

bot_log("Received update: " . $content, "INFO");


/**
 * Sends an API request to Telegram.
 *
 * @param string $method The API method name (e.g., sendMessage).
 * @param array $params Parameters for the API method.
 * @param int $retries Number of retries for the request.
 * @return array|false Decoded JSON response from Telegram or false on failure.
 */
function apiRequest($method, $params = [], $retries = 3) {
    global $apiUrl, $errorLogPath; // $apiUrl from this script, $errorLogPath from bootstrap
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
        CURLOPT_SSL_VERIFYPEER => true, // Recommended for production
    ];
    curl_setopt_array($ch, $curlOptions);

    for ($i = 0; $i < $retries; $i++) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false) {
            bot_log("API Error ($method): cURL Error: $curlError. HTTP Code: $httpCode. Retry " . ($i+1) . "/$retries", "ERROR");
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
        
        bot_log("API Error ($method): HTTP $httpCode - Response: $response. Retry " . ($i+1) . "/$retries", "ERROR");
        if ($httpCode >= 500 && $i < $retries - 1) { // Retry on server errors
            sleep(1 + $i); 
            continue;
        }
        
        curl_close($ch);
        return $result; // Return non-OK result for client-side errors (4xx) or after retries
    }
    return false; // Should not be reached if retries are exhausted
}

/**
 * Sends a message to a Telegram chat.
 *
 * @param int|string $chatId The target chat ID.
 * @param string $text The message text. Supports HTML parse mode.
 * @param array|null $keyboard Optional. An inline keyboard markup array.
 * @param int|null $message_thread_id Optional. Unique identifier for the target message thread (topic) of the forum.
 * @return array|false The decoded JSON response from Telegram API or false on failure.
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
 *
 * @param int|string $chatId The chat ID where the message is.
 * @param int $msgId The message ID to edit.
 * @param string $text The new message text. Supports HTML parse mode.
 * @param array|null $keyboard Optional. An inline keyboard markup array.
 * @return array|false The decoded JSON response from Telegram API or false on failure.
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
 *
 * @param string $callbackQueryId The ID of the callback query to answer.
 * @param string|null $text Optional. Text to show to the user.
 * @param bool $showAlert Optional. Whether to show an alert to the user instead of a notification.
 * @return array|false The decoded JSON response from Telegram API or false on failure.
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
    global $botToken, $channelId; // $botToken, $channelId from bootstrap.php
    
    if (empty($channelId) || $channelId === '@') { // Also check if it's just "@"
        return true; // No channel configured for subscription check
    }
    
    $url = "https://api.telegram.org/bot$botToken/getChatMember?chat_id=$channelId&user_id=$userId";
    $response = @file_get_contents($url); 
    if ($response === false) {
        bot_log("isSubscribed: Failed to fetch from $url. User: $userId", "ERROR");
        return false; // Potentially treat as not subscribed or temporary error
    }
    $data = json_decode($response, true);

    if (!isset($data['ok']) || $data['ok'] === false) {
        if (isset($data['description'])) {
            bot_log("isSubscribed: API error for User $userId, Channel $channelId: " . $data['description'], "WARNING");
            if (strpos($data['description'], "not found") !== false || 
                strpos($data['description'], "kicked") !== false ||
                strpos($data['description'], "chat not found") !== false ||
                strpos($data['description'], "user not found") !== false) {
                return false; // Definitely not a member or channel is invalid
            }
        }
        return false; // Generic API error, assume not subscribed or error
    }
    
    $status = $data['result']['status'] ?? '';
    return in_array($status, ['member', 'administrator', 'creator']);
}

// Test function for formatting (uses sendMessage)
function testFormatting($adminId) { // $adminId from bootstrap
    return sendMessage($adminId, 
        "<b>Тест жирного</b>\n" .
        "<i>Тест курсива</i>\n" .
        "<code>Тест моноширинного</code>\n" .
        "<pre>Тест блока кода</pre>\n" .
        "<a href='https://t.me/'>Тест ссылки</a>"
    );
}

// -----------------------------
// 💰 Инвестиционные планы (remains the same)
// -----------------------------
$investmentPlans = [
    1 => ['name' => 'Базовый', 'min_amount' => 100, 'days' => 10, 'percent' => 20, 'description' => 'Инвестиционный план на 10 дней с доходностью 20%'],
    2 => ['name' => 'Стандарт', 'min_amount' => 500, 'days' => 20, 'percent' => 50, 'description' => 'Инвестиционный план на 20 дней с доходностью 50%'],
    3 => ['name' => 'Премиум', 'min_amount' => 1000, 'days' => 30, 'percent' => 90, 'description' => 'Инвестиционный план на 30 дней с доходностью 90%']
];

// createInvestment uses $db, $investmentPlans
function createInvestment($userId, $planId, $amount) {
    global $db, $investmentPlans; // $db from bootstrap
    
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
    global $db, $investmentPlans; // $db from bootstrap
    
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
    global $db, $investmentPlans; // $db from bootstrap
    
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
            $currentBalance = $balanceResult ? $balanceResult['balance'] : 'N/A';
            
            $message = "🚀 <b>Поздравляем!</b>\n\n" .
                       "Ваш инвестиционный план <b>{$plan['name']}</b> на {$plan['days']} дней отработал и принёс отличные результаты. " .
                       "За это время Ваш капитал вырос на {$plan['percent']}% относительно начальной суммы.\n\n" .
                       "💳 Сумма инвестиций: <b>{$amount}₽</b>\n" .
                       "💰 Итоговая прибыль: <b>{$profit}₽</b>\n" .
                       "💼 Общий баланс: <b>{$currentBalance}₽</b>\n\n" .
                       "Благодарим за доверие!\nС уважением,\nВаш инвестиционный бот 🤖";
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
    global $channelUsername; // from bootstrap
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
        [['text' => "$minAmount₽", 'callback_data' => "invest_{$planId}_{$minAmount}"], ['text' => ($minAmount*2)."₽", 'callback_data' => "invest_{$planId}_".($minAmount*2)]],
        [['text' => ($minAmount*5)."₽", 'callback_data' => "invest_{$planId}_".($minAmount*5)], ['text' => ($minAmount*10)."₽", 'callback_data' => "invest_{$planId}_".($minAmount*10)]],
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
    global $db; // from bootstrap
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
    $message .= "💰 Общий баланс пользователей: <b>{$stats['balance']}</b>\n";
    $message .= "🔗 Всего привлечено рефералов: <b>{$stats['referrals']}</b>\n\n";
    $message .= "📈 <b>Инвестиции</b>\n";
    $message .= "💼 Всего инвестиций: <b>{$investmentStats['total']}</b>\n";
    $message .= "⏳ Активных инвестиций: <b>{$investmentStats['active']}</b>\n";
    $message .= "✅ Завершенных инвестиций: <b>{$investmentStats['completed']}</b>\n";
    $message .= "💵 Общая сумма инвестиций: <b>{$investmentStats['total_amount']}₽</b>\n\n";
    $message .= "🏆 <b>Топ-5 пользователей по балансу</b>:\n";
    if (empty($topUsers)) $message .= "Пока нет пользователей в топе.\n";
    else foreach ($topUsers as $i => $user) $message .= ($i+1) . ". " . ($user['username'] ? htmlspecialchars("@".$user['username']) : "ID: ".$user['user_id']) . " - <b>{$user['balance']}</b> баллов (Реф: {$user['referrals']}) " . ($user['blocked'] ? '🚫' : '✅') . "\n";
    $message .= "\n⏱ Обновлено: " . date('d.m.Y H:i:s');
    return $message;
}

/**
 * Handles the /start command, user registration, and referral logic.
 *
 * @param int|string $chatId The chat ID where the command was received.
 * @param int $userId The user ID of the person who sent the command.
 * @param string $text The full message text, including the command.
 * @return void
 */
function handleStart($chatId, $userId, $text) {
    global $db, $botUsername, $adminId, $channelId, $channelUsername; // All from bootstrap

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
            $referrerQuery = $db->prepare("SELECT user_id FROM users WHERE ref_code = :ref_code AND user_id != :user_id");
            $referrerQuery->bindValue(':ref_code', $refCode, SQLITE3_TEXT);
            $referrerQuery->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $referrer = $referrerQuery->execute()->fetchArray(SQLITE3_ASSOC);

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

    $message = "👋 Добро пожаловать в @$botUsername!\n\n";
    $message .= "💰 Зарабатывайте баллы, инвестируйте и выводите прибыль.\n";
    $message .= "👥 Приглашайте друзей и получайте бонусы! Ваша реферальная ссылка:\n<code>$refLink</code>\n\n";
    $message .= "👇 Используйте меню ниже для навигации.";
    sendMessage($chatId, $message, getMainMenuInlineKeyboard($userId == $adminId));
}

/**
 * Handles callback queries from inline keyboard button presses.
 *
 * @param array $callbackQuery The callback_query object from Telegram.
 * @return void
 */
function handleCallback($callbackQuery) {
    global $db, $adminId, $botUsername, $channelId, $channelUsername, $investmentPlans; // All from bootstrap

    $callbackQueryId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $msgId = $callbackQuery['message']['message_id'];
    $userId = $callbackQuery['from']['id']; 
    $data = $callbackQuery['data'];
    $userIsAdmin = ($userId == $adminId);
    $callbackAnswered = false;

    if ($data === 'check_subscription') {
        if (!empty($channelId) && $channelId !== '@' && isSubscribed($userId)) {
            $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
            $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
            $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');
            $message = "✅ <b>Спасибо за подписку!</b>\n\nТеперь вы можете пользоваться всеми функциями бота.\n\nВаша реферальная ссылка для приглашения друзей:\n<code>$refLink</code>\n\n👇 Вот главное меню:";
            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        } else if (empty($channelId) || $channelId === '@') { // No subscription needed
            $message = "✅ Проверка подписки не требуется.\n\n👇 Вот главное меню:";
            editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        } else {
            answerCallbackQuery($callbackQueryId, "❌ Вы всё ещё не подписаны на канал @$channelUsername. Пожалуйста, подпишитесь и нажмите кнопку ещё раз.", true);
            $callbackAnswered = true; // Set true here as we answered
            return; // Return to prevent further processing
        }
        answerCallbackQuery($callbackQueryId); // Answer if not already answered
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
        $message = "👋 <b>Главное меню</b> @$botUsername!\n\n💰 Зарабатывайте баллы и выводите их.\n👥 Приглашайте друзей: <code>$refLink</code>\n\n👇 Используйте кнопки ниже:";
        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
    }
    else if ($data === 'earn_money') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $earnedAmount = 100;
        $updateStmt = $db->prepare("UPDATE users SET balance = balance + :amount, last_earn = :last_earn WHERE user_id = :user_id"); // Added last_earn
        $updateStmt->bindValue(':amount', $earnedAmount, SQLITE3_INTEGER);
        $updateStmt->bindValue(':last_earn', time(), SQLITE3_INTEGER);
        $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateStmt->execute();
        $newBalance = ($row['balance'] ?? 0) + $earnedAmount;
        $message = "🎉 <b>Поздравляем!</b>\n\nВы получили <b>+$earnedAmount баллов</b>!\nВаш текущий баланс: <b>$newBalance баллов</b>\n\nВы можете инвестировать эти средства и получить прибыль!";
        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin)); // Changed to main menu after earning
        answerCallbackQuery($callbackQueryId, "✅ +$earnedAmount баллов! Ваш баланс: $newBalance", false); $callbackAnswered = true;
    }
    else if ($data === 'show_balance') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        $message = "💳 <b>Ваш баланс</b>\n\nТекущий баланс: <b>$balance баллов</b>\n\nВы можете увеличить свой баланс:\n• Нажав кнопку «Заработать»\n• Инвестируя в один из планов\n• Приглашая рефералов";
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
    }
    else if ($data === 'show_investment_plans') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        $message = "📊 <b>Инвестиционные планы</b>\n\nВаш текущий баланс: <b>$balance баллов</b>\n\nВыберите план для инвестирования:\n\n";
        foreach ($investmentPlans as $id => $plan) $message .= "<b>{$plan['name']}</b>\n💰 Мин. сумма: {$plan['min_amount']}₽\n⏱ Срок: {$plan['days']} дней\n📈 Доходность: {$plan['percent']}%\n💵 Пример: 1000₽ → " . (1000 + 1000 * $plan['percent'] / 100) . "₽\n\n";
        editMessage($chatId, $msgId, $message, getInvestmentPlansKeyboard());
    }
    else if (strpos($data, 'select_investment_plan_') === 0) {
        $planId = (int)str_replace('select_investment_plan_', '', $data);
        if (!isset($investmentPlans[$planId])) { answerCallbackQuery($callbackQueryId, "План не найден", true); $callbackAnswered = true; return; }
        $plan = $investmentPlans[$planId];
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        $message = "💰 <b>Инвестиционный план: {$plan['name']}</b>\n\n⏱ Срок: {$plan['days']} дней\n📈 Доходность: {$plan['percent']}%\n💵 Минимальная сумма: {$plan['min_amount']}₽\n\nВаш текущий баланс: <b>$balance баллов</b>\n\nВыберите сумму для инвестирования:";
        editMessage($chatId, $msgId, $message, getInvestmentAmountKeyboard($planId));
    }
    else if (strpos($data, 'invest_') === 0) {
        $parts = explode('_', $data);
        if (count($parts) !== 3) { answerCallbackQuery($callbackQueryId, "Неверный формат данных", true); $callbackAnswered = true; return; }
        $planId = (int)$parts[1]; $amount = (int)$parts[2];
        if (!isset($investmentPlans[$planId])) { answerCallbackQuery($callbackQueryId, "План не найден", true); $callbackAnswered = true; return; }
        $plan = $investmentPlans[$planId];
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        if ($balance < $amount) { answerCallbackQuery($callbackQueryId, "Недостаточно средств на балансе", true); $callbackAnswered = true; return; }
        if ($amount < $plan['min_amount']) { answerCallbackQuery($callbackQueryId, "Сумма меньше минимальной", true); $callbackAnswered = true; return; }
        if (createInvestment($userId, $planId, $amount)) {
            $profit = round($amount * ($plan['percent'] / 100)); $total = $amount + $profit;
            $message = "✅ <b>Инвестиция успешно создана!</b>\n\n📊 План: <b>{$plan['name']}</b>\n💰 Сумма: <b>$amount₽</b>\n⏱ Срок: <b>{$plan['days']} дн.</b>\n📈 Прибыль: <b>$profit₽</b>\n💵 К получению: <b>$total₽</b>\n\nСледите в «Мои инвестиции».";
            editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
            answerCallbackQuery($callbackQueryId, "Инвестиция успешно создана!", false); $callbackAnswered = true;
        } else { answerCallbackQuery($callbackQueryId, "Ошибка при создании инвестиции", true); $callbackAnswered = true; }
    }
    else if ($data === 'show_my_investments') {
        $investments = getUserActiveInvestments($userId);
        $message = "📈 <b>Ваши активные инвестиции</b>\n\n";
        if (empty($investments)) $message .= "У вас пока нет активных инвестиций.\n\nВы можете создать их в разделе «Инвестировать».";
        else foreach ($investments as $idx => $inv) $message .= "<b>Инвестиция #".($idx+1)."</b>\n📊 План: <b>{$inv['plan_name']}</b> (Ост. {$inv['days_left']} дн.)\n💰 Сумма: {$inv['amount']}₽ ➡️ {$inv['total']}₽\n📅 ".date('d.m.Y',$inv['start_date'])." - ".date('d.m.Y',$inv['end_date'])."\n\n";
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
    }
    else if ($data === 'show_top_users') { editMessage($chatId, $msgId, getBotStatsText(), getBackToMainMenuKeyboard()); }
    else if ($data === 'show_referrals_info') {
        $stmt = $db->prepare("SELECT ref_code, referrals FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');
        $msg = "👥 <b>Ваша реферальная система</b>\n\nКод: <code>" . ($user['ref_code'] ?? 'N/A'). "</code>\nПриглашено: <b>" . ($user['referrals'] ?? 0) . "</b> чел.\nСсылка:\n<code>$refLink</code>\n\n💰 Вы получаете <b>50 баллов</b> за каждого друга!";
        editMessage($chatId, $msgId, $msg, getBackToMainMenuKeyboard());
    }
    else if ($data === 'initiate_withdraw') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        $minWithdraw = 100;
        if ($balance < $minWithdraw) { answerCallbackQuery($callbackQueryId, "❌ Мин. сумма: $minWithdraw баллов. Вам не хватает: ".($minWithdraw-$balance).".", true); $callbackAnswered = true; }
        else {
            $usernameFrom = isset($callbackQuery['from']['username']) ? "@".htmlspecialchars($callbackQuery['from']['username']) : "ID: ".$userId;
            $adminMsg = "🔔 <b>Новый запрос на вывод!</b>\n👤 $usernameFrom (ID: $userId)\n💰 Сумма: <b>$balance</b> баллов\n⏱ " . date('d.m.Y H:i:s');
            sendMessage($adminId, $adminMsg, getWithdrawKeyboard($userId));
            answerCallbackQuery($callbackQueryId, "✅ Ваш запрос на вывод $balance баллов отправлен. Ожидайте.", false); $callbackAnswered = true;
        }
    }
    else if ($data === 'show_help_info') {
        $msg = "ℹ️ <b>Информация и Помощь</b>\n\n🤖 @$botUsername - это инвестиционный бот.\n\n" .
               "💰 <b>Заработать</b> — Получить баллы.\n💳 <b>Баланс</b> — Ваш текущий баланс.\n" .
               "📊 <b>Инвестировать</b> — Выбрать план.\n📈 <b>Мои инвестиции</b> — Ваши активные инвестиции.\n" .
               "🏆 <b>Топ</b> — Рейтинг.\n👥 <b>Рефералы</b> — Пригласить друзей.\n" .
               "💸 <b>Вывод</b> — Запросить вывод (мин. 100 баллов).\n\n";
        if (!empty($channelId) && $channelId !== '@') $msg .= "📢 Не забудьте подписаться на @$channelUsername!\n\n";
        $msg .= "При возникновении проблем обращайтесь к администрации.";
        editMessage($chatId, $msgId, $msg, getBackToMainMenuKeyboard());
    }
    // --- Admin Panel Callbacks (userIsAdmin check is important) ---
    else if ($userIsAdmin) {
        if ($data === 'admin_panel_show') editMessage($chatId, $msgId, "⚙️ <b>Админ-панель</b>\nВыберите действие:", getAdminPanelKeyboard());
        else if ($data === 'admin_stats_show') editMessage($chatId, $msgId, getBotStatsText(), getBackToAdminPanelKeyboard());
        else if ($data === 'admin_investments_list') { /* ... DB query and message formatting ... */ } // Placeholder for brevity
        else if ($data === 'admin_users_list') {
            $result = $db->query("SELECT user_id, username, balance, blocked FROM users ORDER BY joined_at DESC LIMIT 20"); 
            $kb = ['inline_keyboard' => []]; $txt = "👥 <b>Участники (20)</b>:\n\n"; $c=0;
            if($result) while($u=$result->fetchArray(SQLITE3_ASSOC)){ $c++; $s=$u['blocked']?'🚫':'✅'; $un=$u['username']?htmlspecialchars("@".$u['username']):"ID:".$u['user_id']; $kb['inline_keyboard'][]=[['text'=>"$s $un | 💰:{$u['balance']}",'callback_data'=>"admin_user_details_{$u['user_id']}"]];}
            if($c==0) $txt.="Нет пользователей."; $kb['inline_keyboard'][]=[['text'=>'⬅️ Назад','callback_data'=>'admin_panel_show']]; editMessage($chatId,$msgId,$txt,$kb);
        }
        else if (strpos($data, 'admin_user_details_') === 0) {
            $targetUserId = (int)str_replace('admin_user_details_', '', $data);
            $stmt = $db->prepare("SELECT * FROM users WHERE user_id = :id"); $stmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER); $u = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if($u){ $msg = "👤 <b>Профиль ID {$u['user_id']}</b>\nUsername: ".($u['username']?htmlspecialchars("@{$u['username']}"):"<i>нет</i>")."\nБаланс: <b>{$u['balance']}</b>\nРеф-ов: {$u['referrals']}\nПригл.: ".($u['referred_by']?:'<i>нет</i>')."\nКод: <code>{$u['ref_code']}</code>\nПодписка: ".(!empty($channelId)&&$channelId!=='@'&&isSubscribed($targetUserId)?'✅':'❌')."\nСтатус: ".($u['blocked']?'🚫<b>Заблокирован</b>':'✅Активен')."\nРег.: {$u['joined_at']}\n";
                $invS = $db->prepare("SELECT COUNT(*) c, SUM(amount) t FROM investments WHERE user_id=:id"); $invS->bindValue(':id',$targetUserId,SQLITE3_INTEGER); $invR=$invS->execute()->fetchArray(SQLITE3_ASSOC);
                $msg.="\n📊 Инвестиций: <b>{$invR['c']}</b> на сумму <b>".($invR['t']?:0)."₽</b>";
                editMessage($chatId,$msgId,$msg,getUserActionsKeyboard($targetUserId,$u['blocked']));
            } else { answerCallbackQuery($callbackQueryId, "Пользователь не найден.", true); $callbackAnswered = true;}
        }
        else if (strpos($data, 'approve_withdraw_') === 0) { /* ... logic ... */ } // Placeholder
        else if (strpos($data, 'reject_withdraw_') === 0) { /* ... logic ... */ } // Placeholder
        else if (strpos($data, 'block_user_') === 0) { /* ... logic ... */ } // Placeholder
        else if (strpos($data, 'unblock_user_') === 0) { /* ... logic ... */ } // Placeholder
    } else if (strpos($data, 'admin_') === 0 || strpos($data, 'approve_') === 0 || strpos($data, 'reject_') === 0 || strpos($data, 'block_') === 0 || strpos($data, 'unblock_') === 0) {
        answerCallbackQuery($callbackQueryId, "⛔ У вас нет доступа к этой функции.", true); $callbackAnswered = true;
    }

    if (!$callbackAnswered) answerCallbackQuery($callbackQueryId); // Default answer if not handled
}

// -----------------------------
// 🚀 Main Webhook Logic
// -----------------------------
$update = json_decode($content, true);

if (!$update) {
    bot_log("Invalid JSON received: " . $content, "ERROR"); // $content can be large, consider truncating or just error type
    http_response_code(400);
    echo "Invalid JSON.";
    exit;
}

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
            if (!$insertStmt->execute()) bot_log("Failed to insert user $userId ($username) on callback. DB Error: " . $db->lastErrorMsg(), "ERROR");
        }
        handleCallback($update['callback_query']);

    } elseif (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;
        $userId = $message['from']['id'] ?? null; 

        if (!$chatId || !$userId) {
            bot_log("No chat_id or user_id in message: " . json_encode($message), "WARNING");
            echo "OK"; exit;
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
            if(!$insertStmt->execute()) bot_log("Failed to insert new user $userId ($username). DB Error: " . $db->lastErrorMsg(), "ERROR");
            else bot_log("New user $userId ($username) initialized with ref_code $refCode.", "INFO");
        }

        $userBlockedStmt = $db->prepare("SELECT blocked FROM users WHERE user_id = :user_id");
        $userBlockedStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userStatus = $userBlockedStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($userId != $adminId && isset($userStatus['blocked']) && $userStatus['blocked'] == 1) {
            sendMessage($chatId, "🚫 Вы заблокированы администратором и не можете использовать этого бота.", null, $message_thread_id);
            echo "OK"; exit;
        }

        $text = trim($message['text'] ?? '');
        if (strpos($text, '/start') === 0) handleStart($chatId, $userId, $text);
        else {
            $userIsAdmin = ($userId == $adminId);
            $is_subscribed = (empty($channelId) || $channelId === '@') || isSubscribed($userId) || $userIsAdmin;
            if ($is_subscribed) sendMessage($chatId, "Пожалуйста, используйте кнопки меню. Если меню не видно, используйте команду /start.", getMainMenuInlineKeyboard($userIsAdmin), $message_thread_id);
            else sendMessage($chatId, "👋 Добро пожаловать в @$botUsername!\n\nПожалуйста, <b>подпишитесь на наш канал</b> (@$channelUsername) для доступа к боту.", getSubscriptionKeyboard(), $message_thread_id);
        }
    }
} catch (Throwable $e) { // Catching Throwable for broader error handling, including Exceptions and Errors.
    bot_log("!!! Uncaught Throwable: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()."\nStack trace:\n".$e->getTraceAsString(), "FATAL");
    if (!empty($adminId)) sendMessage($adminId, "❌ Критическая ошибка в боте: ".$e->getMessage());
}

http_response_code(200);
echo "OK"; 
?>
