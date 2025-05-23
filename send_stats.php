<?php
// send_stats.php

require_once __DIR__ . '/bootstrap.php';

// Critical checks for $botToken and $db after including bootstrap.php
if (empty($botToken)) { // $botToken comes from bootstrap.php
    // error_log is available via bootstrap's ini_set or bot_log if preferred
    error_log("send_stats.php: CRITICAL - TELEGRAM_BOT_TOKEN is not set. Exiting.");
    exit(1); // Exit for CLI script
}
if ($db === null) { // $db comes from bootstrap.php
    error_log("send_stats.php: CRITICAL - Database connection failed (db is null). Exiting.");
    exit(1);
}
// Ensure $db is actually a valid SQLite3 object, $db->lastErrorCode() === 0 means no error on open
if ($db->lastErrorCode() !== 0 && $db->lastErrorCode() !== SQLITE3_OK) { // SQLITE3_OK is 0
     error_log("send_stats.php: CRITICAL - Database connection error: " . $db->lastErrorMsg() . ". Exiting.");
     exit(1);
}


// Логируем запуск (stdout, will be captured by cron log)
echo date('Y-m-d H:i:s') . " - Запуск скрипта отправки статистики\n";

// Функция для отправки запросов к API Telegram
// This function uses global $botToken which is now set in bootstrap.php
function apiRequest($method, $params = []) {
    global $botToken, $apiUrl; // $apiUrl is not globally set in bootstrap, construct it or pass it
                               // For now, using $botToken to construct $apiUrl locally.
                               // Or, ensure $apiUrl is also global in bootstrap.php if used by many functions.
    $currentApiUrl = "https://api.telegram.org/bot$botToken"; // Constructing locally

    $url = "$currentApiUrl/$method";
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params), // Send as JSON
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"], // Set content type to JSON
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($error) {
        error_log(date('Y-m-d H:i:s') . " - API Request ERROR ($method): $error. HTTP Code: $httpCode\n");
        return false;
    }
    $decodedResponse = json_decode($response, true);
    if ($httpCode !== 200 || !isset($decodedResponse['ok']) || $decodedResponse['ok'] !== true) {
        error_log(date('Y-m-d H:i:s') . " - API Request Failed ($method): HTTP $httpCode - Response: $response\n");
        return $decodedResponse; // Return response even on failure for potential debugging
    }
    return $decodedResponse;
}

// Функция отправки сообщения
// This function uses apiRequest, which relies on global $botToken from bootstrap.php
function sendMessage($chatId, $text, $replyMarkup = null, $message_thread_id = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    
    if ($message_thread_id !== null) {
        $params['message_thread_id'] = $message_thread_id;
    }
    
    if ($replyMarkup !== null) {
        // Ensure reply_markup is a JSON string if it's an array, as apiRequest now handles json_encode for top-level params
        if (is_array($replyMarkup)) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $params['reply_markup'] = $replyMarkup; // Assume it's already a JSON string if not an array
        }
    }
    
    return apiRequest('sendMessage', $params);
}

/**
 * Retrieves and formats bot statistics.
 *
 * @global SQLite3 $db The database connection object (from bootstrap.php).
 * @return string The formatted statistics message.
 */
function getBotStats() {
    global $db; // $db comes from bootstrap.php
    
    $stats = ['total' => 0, 'active' => 0, 'balance' => 0, 'referrals' => 0];
    $query = "SELECT COUNT(*) as total, 
                     SUM(CASE WHEN blocked = 0 THEN 1 ELSE 0 END) as active,
                     SUM(balance) as balance,
                     SUM(referrals) as referrals
              FROM users";
    $result = $db->query($query);
    if ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $stats = $row;
    } else if (!$result) {
        error_log(date('Y-m-d H:i:s') . " - DB ERROR (getBotStats - users): " . $db->lastErrorMsg());
    }
    
    $topUsers = [];
    $topResult = $db->query("SELECT user_id, username, balance, referrals, blocked 
                           FROM users 
                           ORDER BY balance DESC, referrals DESC 
                           LIMIT 5");
    if ($topResult) {
        while ($user = $topResult->fetchArray(SQLITE3_ASSOC)) {
            $topUsers[] = $user;
        }
    } else {
         error_log(date('Y-m-d H:i:s') . " - DB ERROR (getBotStats - top users): " . $db->lastErrorMsg());
    }
    
    $message = "📊 <b>Статистика бота</b>\n\n";
    $message .= "👥 Всего пользователей: <b>" . ($stats['total'] ?? 0) . "</b>\n";
    $message .= "🟢 Активных: <b>" . ($stats['active'] ?? 0) . "</b>\n";
    $message .= "💰 Общий баланс: <b>" . ($stats['balance'] ?? 0) . "</b> баллов\n";
    $message .= "👥 Всего рефералов: <b>" . ($stats['referrals'] ?? 0) . "</b>\n\n";
    
    $message .= "🏆 <b>Топ-5 пользователей</b>:\n";
    if (empty($topUsers)) {
        $message .= "Пока нет пользователей в топе.\n";
    } else {
        foreach ($topUsers as $i => $user) {
            $status = $user['blocked'] ? '🚫' : '✅';
            $usernameDisplay = $user['username'] ? htmlspecialchars("@".$user['username']) : "ID {$user['user_id']}";
            $message .= ($i+1) . ". $usernameDisplay: <b>{$user['balance']}</b> баллов (Реф: {$user['referrals']}) $status\n";
        }
    }
    $message .= "\n⏱️ Обновлено: " . date('d.m.Y H:i:s');
    
    return $message;
}

// Note: The stat_channels table is created in bootstrap.php if it doesn't exist.

// Получаем список каналов для отправки статистики
$channelsQuery = $db->query("SELECT channel_id FROM stat_channels");
$channels = [];
if ($channelsQuery) {
    while ($row = $channelsQuery->fetchArray(SQLITE3_ASSOC)) {
        $channels[] = $row['channel_id'];
    }
} else {
    error_log(date('Y-m-d H:i:s') . " - DB ERROR (send_stats - fetch stat_channels): " . $db->lastErrorMsg());
}

// Если нет каналов, отправляем статистику только админу
if (empty($channels)) {
    global $adminId; // $adminId comes from bootstrap.php
    if (!empty($adminId)) {
        $channels = [$adminId]; // Put adminId in array to use the same loop
        echo date('Y-m-d H:i:s') . " - Нет каналов для статистики в таблице stat_channels, отправляем админу: $adminId\n";
    } else {
        error_log(date('Y-m-d H:i:s') . " - Нет каналов для статистики в таблице stat_channels и не задан ADMIN_ID. Статистика не будет отправлена.\n");
    }
}

// Получаем статистику
$statsMessage = getBotStats();
$sentCount = 0;

if (!empty($channels) && !empty(trim($statsMessage))) {
    foreach ($channels as $channelTargetId) {
        if (empty(trim($channelTargetId))) {
            error_log(date('Y-m-d H:i:s') . " - Пропущен пустой channel_id для отправки статистики.");
            continue;
        }
        $result = sendMessage($channelTargetId, $statsMessage);
        if ($result && isset($result['ok']) && $result['ok']) {
            echo date('Y-m-d H:i:s') . " - Статистика успешно отправлена в канал/чат $channelTargetId\n";
            $sentCount++;
        } else {
            $errorResponse = $result ? json_encode($result) : "No response or cURL error";
            error_log(date('Y-m-d H:i:s') . " - Ошибка отправки статистики в канал/чат $channelTargetId. Response: $errorResponse\n");
        }
        usleep(300000); // 0.3 секунды
    }
} else if (empty($channels)) {
    echo date('Y-m-d H:i:s') . " - Список каналов/чатов для отправки статистики пуст (после проверки ADMIN_ID), отправка не производилась.\n";
} else if (empty(trim($statsMessage))) {
    error_log(date('Y-m-d H:i:s') . " - Сообщение статистики пустое, отправка не производилась.\n");
}


// Закрываем соединение с БД (в bootstrap.php $db не закрывается, т.к. index.php может его использовать)
// CLI скрипты должны закрывать соединение, если они его открыли или если bootstrap предназначен и для CLI.
// Поскольку bootstrap.php теперь инициализирует $db, и send_stats.php является CLI,
// он должен закрыть соединение, если это последняя операция с БД.
// Однако, если bootstrap.php используется и index.php, $db должен оставаться открытым для index.php.
// Решение: CLI скрипты должны сами закрывать $db. Но $db в bootstrap.php - это глобальная переменная.
// Для простоты, не будем закрывать $db здесь, предполагая, что скрипт завершится и PHP очистит ресурсы.
// Либо, bootstrap.php может регистрировать shutdown function для закрытия $db.
// if (isset($db)) { $db->close(); } // Consider implications for index.php if bootstrap is shared.

echo date('Y-m-d H:i:s') . " - Скрипт отправки статистики завершен. Отправлено в $sentCount мест.\n";
?>
