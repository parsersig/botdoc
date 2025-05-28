<?php
// =============================================
// 🚀 Telegram Investment Bot - Professional Edition
// =============================================
// Version 2.0.0: Enhanced for Real Investment Platform

require_once __DIR__ . '/bootstrap.php';

// Health check endpoint
if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/ping') {
    header('Content-Type: text/plain');
    echo 'OK';
    exit;
}

// Critical configuration checks
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

$apiUrl = "https://api.telegram.org/bot$botToken";

// Enhanced health check endpoint
if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'time' => date('Y-m-d H:i:s'),
        'version' => '2.0.0',
        'uptime' => time() - filemtime(__FILE__),
        'memory_usage' => memory_get_usage(true),
        'db_status' => $db ? 'connected' : 'disconnected'
    ]);
    exit;
}

// ... existing code for shutdown function and webhook setup ...

// -----------------------------
// 💰 Реалистичные инвестиционные планы
// -----------------------------
$investmentPlans = [
    1 => [
        'name' => 'Консервативный', 
        'min_amount' => 5000, 
        'days' => 30, 
        'percent' => 8.5, 
        'description' => 'Низкорисковый план с гарантированной доходностью 8.5% в месяц',
        'risk_level' => 'Низкий',
        'category' => 'Облигации и депозиты'
    ],
    2 => [
        'name' => 'Сбалансированный', 
        'min_amount' => 15000, 
        'days' => 60, 
        'percent' => 22, 
        'description' => 'Оптимальное соотношение риска и доходности - 22% за 2 месяца',
        'risk_level' => 'Средний',
        'category' => 'Смешанный портфель'
    ],
    3 => [
        'name' => 'Агрессивный', 
        'min_amount' => 50000, 
        'days' => 90, 
        'percent' => 45, 
        'description' => 'Высокодоходный план для опытных инвесторов - 45% за 3 месяца',
        'risk_level' => 'Высокий',
        'category' => 'Акции роста и криптовалюты'
    ],
    4 => [
        'name' => 'VIP Премиум', 
        'min_amount' => 100000, 
        'days' => 180, 
        'percent' => 120, 
        'description' => 'Эксклюзивный план для крупных инвесторов - 120% за 6 месяцев',
        'risk_level' => 'Очень высокий',
        'category' => 'Альтернативные инвестиции'
    ]
];

// Enhanced API request function with better error handling
function apiRequest($method, $params = [], $retries = 3) {
    global $apiUrl;
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
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Investment Bot 2.0',
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ];
    curl_setopt_array($ch, $curlOptions);

    for ($i = 0; $i < $retries; $i++) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false) {
            bot_log("API Error ($method): cURL Error: $curlError. HTTP Code: $httpCode. Retry " . ($i+1) . "/$retries", "ERROR");
            if ($i < $retries - 1) {
                sleep(2 ** $i); // Exponential backoff
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
            sleep(2 ** $i); 
            continue;
        }
        
        curl_close($ch);
        return $result;
    }
    return false;
}

// Enhanced message sending with formatting
function sendMessage($chatId, $text, $keyboard = null, $message_thread_id = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'protect_content' => false
    ];
    if ($keyboard) {
        $params['reply_markup'] = $keyboard;
    }
    if ($message_thread_id) {
        $params['message_thread_id'] = $message_thread_id;
    }
    return apiRequest('sendMessage', $params);
}

// ... existing editMessage and answerCallbackQuery functions ...

// Enhanced investment creation with validation
function createInvestment($userId, $planId, $amount) {
    global $db, $investmentPlans;
    
    if (!isset($investmentPlans[$planId])) return false;
    
    $plan = $investmentPlans[$planId];
    
    // Validate minimum amount
    if ($amount < $plan['min_amount']) {
        return ['success' => false, 'error' => 'Сумма меньше минимальной для данного плана'];
    }
    
    // Check user balance
    $balanceStmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
    $balanceStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $balanceResult = $balanceStmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$balanceResult || $balanceResult['balance'] < $amount) {
        return ['success' => false, 'error' => 'Недостаточно средств на балансе'];
    }
    
    $startDate = time();
    $endDate = $startDate + ($plan['days'] * 86400);
    $expectedProfit = round($amount * ($plan['percent'] / 100));
    
    $stmt = $db->prepare("INSERT INTO investments (user_id, plan_id, amount, start_date, end_date, expected_profit, status, created_at) 
                         VALUES (:user_id, :plan_id, :amount, :start_date, :end_date, :expected_profit, 'active', :created_at)");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':plan_id', $planId, SQLITE3_INTEGER);
    $stmt->bindValue(':amount', $amount, SQLITE3_INTEGER);
    $stmt->bindValue(':start_date', $startDate, SQLITE3_INTEGER);
    $stmt->bindValue(':end_date', $endDate, SQLITE3_INTEGER);
    $stmt->bindValue(':expected_profit', $expectedProfit, SQLITE3_INTEGER);
    $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
    
    if ($stmt->execute()) {
        // Deduct amount from user balance
        $updateBalanceStmt = $db->prepare("UPDATE users SET balance = balance - :amount, total_invested = total_invested + :amount WHERE user_id = :user_id");
        $updateBalanceStmt->bindValue(':amount', $amount, SQLITE3_INTEGER);
        $updateBalanceStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateBalanceStmt->execute();
        
        return ['success' => true, 'investment_id' => $db->lastInsertRowID()];
    }
    
    bot_log("Failed to create investment for user $userId, plan $planId. DB Error: ".$db->lastErrorMsg(), "ERROR");
    return ['success' => false, 'error' => 'Ошибка базы данных'];
}

// Enhanced user investment display
function getUserActiveInvestments($userId) {
    global $db, $investmentPlans;
    
    $stmt = $db->prepare("SELECT * FROM investments WHERE user_id = :user_id AND status = 'active' ORDER BY created_at DESC");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $investments = [];
    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $planId = $row['plan_id'];
            $plan = $investmentPlans[$planId] ?? ['name' => 'Неизвестный план', 'percent' => 0, 'days' => 0];
            
            $row['plan_name'] = $plan['name'];
            $row['percent'] = $plan['percent'];
            $row['days'] = $plan['days'];
            $row['risk_level'] = $plan['risk_level'] ?? 'Неизвестно';
            $row['category'] = $plan['category'] ?? 'Неизвестно';
            $row['profit'] = round($row['amount'] * ($plan['percent'] / 100));
            $row['total'] = $row['amount'] + $row['profit'];
            $row['days_left'] = max(0, ceil(($row['end_date'] - time()) / 86400));
            $row['progress'] = min(100, round((time() - $row['start_date']) / ($row['end_date'] - $row['start_date']) * 100));
            
            $investments[] = $row;
        }
    }
    return $investments;
}

// Enhanced keyboard functions
function getMainMenuInlineKeyboard($isAdmin = false) {
    $inline_keyboard = [
        [['text' => '💰 Пополнить баланс', 'callback_data' => 'earn_money'], ['text' => '💳 Мой кабинет', 'callback_data' => 'show_balance']],
        [['text' => '📊 Инвестиционные планы', 'callback_data' => 'show_investment_plans']],
        [['text' => '📈 Портфель инвестиций', 'callback_data' => 'show_my_investments'], ['text' => '📋 История операций', 'callback_data' => 'show_transaction_history']],
        [['text' => '🏆 Рейтинг инвесторов', 'callback_data' => 'show_top_users'], ['text' => '👥 Партнёрская программа', 'callback_data' => 'show_referrals_info']],
        [['text' => '💸 Заявка на вывод', 'callback_data' => 'initiate_withdraw'], ['text' => '📞 Поддержка', 'callback_data' => 'show_help_info']]
    ];
    if ($isAdmin) $inline_keyboard[] = [['text' => '⚙️ Панель управления', 'callback_data' => 'admin_panel_show']];
    return ['inline_keyboard' => $inline_keyboard];
}

function getInvestmentPlansKeyboard() {
    global $investmentPlans;
    $keyboard = ['inline_keyboard' => []];
    
    foreach ($investmentPlans as $id => $plan) {
        $riskEmoji = ['Низкий' => '🟢', 'Средний' => '🟡', 'Высокий' => '🟠', 'Очень высокий' => '🔴'][$plan['risk_level']] ?? '⚪';
        $keyboard['inline_keyboard'][] = [['text' => "{$riskEmoji} {$plan['name']} - {$plan['percent']}% за {$plan['days']} дн.", 'callback_data' => "select_investment_plan_$id"]];
    }
    
    $keyboard['inline_keyboard'][] = [['text' => '📊 Калькулятор доходности', 'callback_data' => 'profit_calculator']];
    $keyboard['inline_keyboard'][] = [['text' => '⬅️ Главное меню', 'callback_data' => 'main_menu_show']];
    return $keyboard;
}

function getInvestmentAmountKeyboard($planId) {
    global $investmentPlans;
    $plan = $investmentPlans[$planId] ?? null;
    if (!$plan) return getBackToMainMenuKeyboard();
    
    $minAmount = $plan['min_amount'];
    return ['inline_keyboard' => [
        [['text' => number_format($minAmount, 0, ',', ' ')."₽", 'callback_data' => "invest_{$planId}_{$minAmount}"], 
         ['text' => number_format($minAmount*2, 0, ',', ' ')."₽", 'callback_data' => "invest_{$planId}_".($minAmount*2)]],
        [['text' => number_format($minAmount*5, 0, ',', ' ')."₽", 'callback_data' => "invest_{$planId}_".($minAmount*5)], 
         ['text' => number_format($minAmount*10, 0, ',', ' ')."₽", 'callback_data' => "invest_{$planId}_".($minAmount*10)]],
        [['text' => '💰 Другая сумма', 'callback_data' => "custom_amount_$planId"]],
        [['text' => '⬅️ К планам', 'callback_data' => 'show_investment_plans']],
        [['text' => '⬅️ Главное меню', 'callback_data' => 'main_menu_show']]
    ]];
}

// Enhanced statistics function
function getBotStatsText() {
    global $db;
    
    $stats = [
        'total_users' => 0,
        'active_users' => 0,
        'total_balance' => 0,
        'total_invested' => 0,
        'total_profit_paid' => 0,
        'active_investments' => 0,
        'completed_investments' => 0
    ];

    // User statistics
    $userResult = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN blocked = 0 THEN 1 ELSE 0 END) as active, 
                             SUM(balance) as total_balance, SUM(total_invested) as total_invested FROM users");
    if ($userResult) {
        $userStats = $userResult->fetchArray(SQLITE3_ASSOC);
        $stats['total_users'] = $userStats['total'] ?? 0;
        $stats['active_users'] = $userStats['active'] ?? 0;
        $stats['total_balance'] = $userStats['total_balance'] ?? 0;
        $stats['total_invested'] = $userStats['total_invested'] ?? 0;
    }

    // Investment statistics
    $invResult = $db->query("SELECT status, COUNT(*) as count, SUM(amount) as total_amount, 
                            SUM(CASE WHEN status = 'completed' THEN expected_profit ELSE 0 END) as total_profit 
                            FROM investments GROUP BY status");
    if ($invResult) {
        while ($inv = $invResult->fetchArray(SQLITE3_ASSOC)) {
            if ($inv['status'] === 'active') {
                $stats['active_investments'] = $inv['count'];
            } elseif ($inv['status'] === 'completed') {
                $stats['completed_investments'] = $inv['count'];
                $stats['total_profit_paid'] = $inv['total_profit'] ?? 0;
            }
        }
    }

    $message = "📊 <b>Статистика инвестиционной платформы</b>\n\n";
    $message .= "👥 <b>Пользователи:</b>\n";
    $message .= "• Всего зарегистрировано: <b>" . number_format($stats['total_users'], 0, ',', ' ') . "</b>\n";
    $message .= "• Активных инвесторов: <b>" . number_format($stats['active_users'], 0, ',', ' ') . "</b>\n\n";
    
    $message .= "💰 <b>Финансовые показатели:</b>\n";
    $message .= "• Общий баланс: <b>" . number_format($stats['total_balance'], 0, ',', ' ') . " ₽</b>\n";
    $message .= "• Всего инвестировано: <b>" . number_format($stats['total_invested'], 0, ',', ' ') . " ₽</b>\n";
    $message .= "• Выплачено прибыли: <b>" . number_format($stats['total_profit_paid'], 0, ',', ' ') . " ₽</b>\n\n";
    
    $message .= "📈 <b>Инвестиции:</b>\n";
    $message .= "• Активных: <b>" . number_format($stats['active_investments'], 0, ',', ' ') . "</b>\n";
    $message .= "• Завершённых: <b>" . number_format($stats['completed_investments'], 0, ',', ' ') . "</b>\n\n";
    
    $message .= "⏱ <i>Обновлено: " . date('d.m.Y H:i:s') . "</i>";
    
    return $message;
}

// Enhanced start handler
function handleStart($chatId, $userId, $text) {
    global $db, $botUsername, $adminId, $channelId, $channelUsername;

    // ... existing referral logic ...

    // Subscription check
    if (!empty($channelId) && $channelId !== '@' && !isSubscribed($userId) && $userId != $adminId) {
        $message = "🏛 <b>Добро пожаловать в профессиональную инвестиционную платформу!</b>\n\n";
        $message .= "Для доступа к нашим инвестиционным продуктам необходимо подписаться на информационный канал @$channelUsername\n\n";
        $message .= "📈 Здесь вы найдёте:\n";
        $message .= "• Аналитические обзоры рынков\n";
        $message .= "• Рекомендации по инвестированию\n";
        $message .= "• Новости финансового мира\n\n";
        $message .= "После подписки нажмите «Я подписался» для продолжения.";
        sendMessage($chatId, $message, getSubscriptionKeyboard());
        return;
    }

    $userStmt = $db->prepare("SELECT ref_code FROM users WHERE user_id = :user_id");
    $userStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $user = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $refLink = "https://t.me/$botUsername?start=" . ($user['ref_code'] ?? '');

    $message = "🏛 <b>Добро пожаловать в инвестиционную платформу!</b>\n\n";
    $message .= "💼 <b>Наши преимущества:</b>\n";
    $message .= "• Прозрачные условия инвестирования\n";
    $message .= "• Диверсифицированные портфели\n";
    $message .= "• Профессиональное управление активами\n";
    $message .= "• Регулярные выплаты прибыли\n\n";
    $message .= "👥 <b>Партнёрская программа:</b>\n";
    $message .= "Приглашайте друзей и получайте <b>500₽</b> за каждого!\n";
    $message .= "Ваша ссылка: <code>$refLink</code>\n\n";
    $message .= "👇 Выберите действие в меню ниже:";
    
    sendMessage($chatId, $message, getMainMenuInlineKeyboard($userId == $adminId));
}

// Enhanced callback handler with new features
function handleCallback($callbackQuery) {
    global $db, $adminId, $botUsername, $channelId, $channelUsername, $investmentPlans;

    $callbackQueryId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $msgId = $callbackQuery['message']['message_id'];
    $userId = $callbackQuery['from']['id']; 
    $data = $callbackQuery['data'];
    $userIsAdmin = ($userId == $adminId);

    // ... existing subscription check logic ...

    // Enhanced main menu callbacks
    if ($data === 'show_balance') {
        $stmt = $db->prepare("SELECT balance, total_invested, total_earned FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userInfo = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        $balance = $userInfo['balance'] ?? 0;
        $totalInvested = $userInfo['total_invested'] ?? 0;
        $totalEarned = $userInfo['total_earned'] ?? 0;
        
        $message = "💳 <b>Личный кабинет инвестора</b>\n\n";
        $message .= "💰 <b>Текущий баланс:</b> " . number_format($balance, 0, ',', ' ') . " ₽\n";
        $message .= "📊 <b>Всего инвестировано:</b> " . number_format($totalInvested, 0, ',', ' ') . " ₽\n";
        $message .= "💎 <b>Общая прибыль:</b> " . number_format($totalEarned, 0, ',', ' ') . " ₽\n\n";
        
        if ($totalInvested > 0) {
            $roi = round(($totalEarned / $totalInvested) * 100, 2);
            $message .= "📈 <b>ROI (рентабельность):</b> {$roi}%\n\n";
        }
        
        $message .= "🔄 <i>Баланс обновляется в режиме реального времени</i>";
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
    }
    
    else if ($data === 'show_investment_plans') {
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $balance = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['balance'] ?? 0;
        
        $message = "📊 <b>Инвестиционные продукты</b>\n\n";
        $message .= "💳 Ваш баланс: <b>" . number_format($balance, 0, ',', ' ') . " ₽</b>\n\n";
        
        foreach ($investmentPlans as $id => $plan) {
            $riskEmoji = ['Низкий' => '🟢', 'Средний' => '🟡', 'Высокий' => '🟠', 'Очень высокий' => '🔴'][$plan['risk_level']] ?? '⚪';
            $message .= "{$riskEmoji} <b>{$plan['name']}</b>\n";
            $message .= "💰 От " . number_format($plan['min_amount'], 0, ',', ' ') . " ₽\n";
            $message .= "⏱ Срок: {$plan['days']} дней\n";
            $message .= "📈 Доходность: <b>{$plan['percent']}%</b>\n";
            $message .= "🎯 {$plan['category']}\n";
            $message .= "⚖️ Риск: {$plan['risk_level']}\n\n";
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
        
        $riskEmoji = ['Низкий' => '🟢', 'Средний' => '🟡', 'Высокий' => '🟠', 'Очень высокий' => '🔴'][$plan['risk_level']] ?? '⚪';
        
        $message = "{$riskEmoji} <b>Инвестиционный план: {$plan['name']}</b>\n\n";
        $message .= "📋 <b>Описание:</b> {$plan['description']}\n\n";
        $message .= "💰 <b>Минимальная сумма:</b> " . number_format($plan['min_amount'], 0, ',', ' ') . " ₽\n";
        $message .= "⏱ <b>Срок инвестирования:</b> {$plan['days']} дней\n";
        $message .= "📈 <b>Ожидаемая доходность:</b> {$plan['percent']}%\n";
        $message .= "🎯 <b>Категория активов:</b> {$plan['category']}\n";
        $message .= "⚖️ <b>Уровень риска:</b> {$plan['risk_level']}\n\n";
        
        $exampleAmount = $plan['min_amount'];
        $exampleProfit = round($exampleAmount * ($plan['percent'] / 100));
        $exampleTotal = $exampleAmount + $exampleProfit;
        
        $message .= "💡 <b>Пример расчёта:</b>\n";
        $message .= "Инвестиция: " . number_format($exampleAmount, 0, ',', ' ') . " ₽\n";
        $message .= "Прибыль: " . number_format($exampleProfit, 0, ',', ' ') . " ₽\n";
        $message .= "К получению: " . number_format($exampleTotal, 0, ',', ' ') . " ₽\n\n";
        
        $message .= "💳 <b>Ваш баланс:</b> " . number_format($balance, 0, ',', ' ') . " ₽\n\n";
        $message .= "Выберите сумму для инвестирования:";
        
        editMessage($chatId, $msgId, $message, getInvestmentAmountKeyboard($planId));
    }
    
    else if (strpos($data, 'invest_') === 0) {
        $parts = explode('_', $data);
        if (count($parts) !== 3) {
            answerCallbackQuery($callbackQueryId, "Неверный формат данных", true);
            return;
        }
        
        $planId = (int)$parts[1];
        $amount = (int)$parts[2];
        
        $result = createInvestment($userId, $planId, $amount);
        
        if ($result['success']) {
            $plan = $investmentPlans[$planId];
            $profit = round($amount * ($plan['percent'] / 100));
            $total = $amount + $profit;
            $endDate = date('d.m.Y', time() + ($plan['days'] * 86400));
            
            $message = "✅ <b>Инвестиция успешно оформлена!</b>\n\n";
            $message .= "📊 <b>Детали инвестиции:</b>\n";
            $message .= "• План: <b>{$plan['name']}</b>\n";
            $message .= "• Сумма: <b>" . number_format($amount, 0, ',', ' ') . " ₽</b>\n";
            $message .= "• Срок: <b>{$plan['days']} дней</b>\n";
            $message .= "• Ожидаемая прибыль: <b>" . number_format($profit, 0, ',', ' ') . " ₽</b>\n";
            $message .= "• К получению: <b>" . number_format($total, 0, ',', ' ') . " ₽</b>\n";
            $message .= "• Дата завершения: <b>{$endDate}</b>\n\n";
            $message .= "📈 Следите за прогрессом в разделе «Портфель инвестиций»\n\n";
            $message .= "🎉 Благодарим за доверие к нашей платформе!";
            
            editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
            answerCallbackQuery($callbackQueryId, "Инвестиция оформлена успешно!", false);
        } else {
            answerCallbackQuery($callbackQueryId, $result['error'], true);
        }
    }
    
    else if ($data === 'show_my_investments') {
        $investments = getUserActiveInvestments($userId);
        
        $message = "📈 <b>Ваш инвестиционный портфель</b>\n\n";
        
        if (empty($investments)) {
            $message .= "📭 У вас пока нет активных инвестиций.\n\n";
            $message .= "💡 Начните инвестировать уже сегодня!\n";
            $message .= "Выберите подходящий план в разделе «Инвестиционные планы».";
        } else {
            $totalInvested = 0;
            $totalExpectedProfit = 0;
            
            foreach ($investments as $idx => $inv) {
                $totalInvested += $inv['amount'];
                $totalExpectedProfit += $inv['profit'];
                
                $progressBar = str_repeat('▓', floor($inv['progress'] / 10)) . str_repeat('░', 10 - floor($inv['progress'] / 10));
                $riskEmoji = ['Низкий' => '🟢', 'Средний' => '🟡', 'Высокий' => '🟠', 'Очень высокий' => '🔴'][$inv['risk_level']] ?? '⚪';
                
                $message .= "{$riskEmoji} <b>Инвестиция #" . ($idx + 1) . "</b>\n";
                $message .= "📊 План: <b>{$inv['plan_name']}</b>\n";
                $message .= "💰 Сумма: " . number_format($inv['amount'], 0, ',', ' ') . " ₽\n";
                $message .= "📈 Прибыль: " . number_format($inv['profit'], 0, ',', ' ') . " ₽\n";
                $message .= "⏱ Осталось: <b>{$inv['days_left']} дн.</b>\n";
                $message .= "📊 Прогресс: {$inv['progress']}% {$progressBar}\n";
                $message .= "📅 " . date('d.m.Y', $inv['start_date']) . " → " . date('d.m.Y', $inv['end_date']) . "\n\n";
            }
            
            $message .= "💼 <b>Итого в портфеле:</b>\n";
            $message .= "• Инвестировано: " . number_format($totalInvested, 0, ',', ' ') . " ₽\n";
            $message .= "• Ожидаемая прибыль: " . number_format($totalExpectedProfit, 0, ',', ' ') . " ₽\n";
            $message .= "• Общая доходность: " . round(($totalExpectedProfit / $totalInvested) * 100, 1) . "%";
        }
        
        editMessage($chatId, $msgId, $message, getBackToMainMenuKeyboard());
    }
    
    else if ($data === 'earn_money') {
        $earnedAmount = 1000; // Увеличили до 1000 рублей
        
        $stmt = $db->prepare("SELECT balance, last_earn FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $userInfo = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        $lastEarn = $userInfo['last_earn'] ?? 0;
        $currentTime = time();
        $cooldownTime = 24 * 60 * 60; // 24 часа
        
        if ($currentTime - $lastEarn < $cooldownTime) {
            $remainingTime = $cooldownTime - ($currentTime - $lastEarn);
            $hours = floor($remainingTime / 3600);
            $minutes = floor(($remainingTime % 3600) / 60);
            
            answerCallbackQuery($callbackQueryId, "⏰ Следующее пополнение через {$hours}ч {$minutes}м", true);
            return;
        }
        
        $updateStmt = $db->prepare("UPDATE users SET balance = balance + :amount, last_earn = :last_earn WHERE user_id = :user_id");
        $updateStmt->bindValue(':amount', $earnedAmount, SQLITE3_INTEGER);
        $updateStmt->bindValue(':last_earn', $currentTime, SQLITE3_INTEGER);
        $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateStmt->execute();
        
        $newBalance = ($userInfo['balance'] ?? 0) + $earnedAmount;
        
        $message = "🎉 <b>Ежедневный бонус получен!</b>\n\n";
        $message .= "💰 Начислено: <b>+" . number_format($earnedAmount, 0, ',', ' ') . " ₽</b>\n";
        $message .= "💳 Ваш баланс: <b>" . number_format($newBalance, 0, ',', ' ') . " ₽</b>\n\n";
        $message .= "💡 <b>Рекомендация:</b>\n";
        $message .= "Инвестируйте полученные средства для получения пассивного дохода!\n\n";
        $message .= "⏰ Следующий бонус будет доступен через 24 часа.";
        
        editMessage($chatId, $msgId, $message, getMainMenuInlineKeyboard($userIsAdmin));
        answerCallbackQuery($callbackQueryId, "✅ +" . number_format($earnedAmount, 0, ',', ' ') . "₽! Баланс: " . number_format($newBalance, 0, ',', ' ') . "₽", false);
    }

    // ... rest of existing callback handlers with enhanced formatting ...

    answerCallbackQuery($callbackQueryId);
}

// ... rest of existing code with enhanced error handling and logging ...