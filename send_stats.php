<?php
// Настройка отображения ошибок
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Скрипт для автоматической отправки статистики бота
require_once __DIR__ . '/config.php'; // Подключаем конфигурацию бота

// Инициализация базы данных
$db = new SQLite3($dbPath ?? '/tmp/bot_database.db');

// Логируем запуск
file_put_contents('/tmp/cron_log.txt', date('Y-m-d H:i:s') . " - Запуск скрипта отправки статистики\n", FILE_APPEND);

// Функция для отправки запросов к API Telegram
function apiRequest($method, $params = []) {
    global $botToken;
    $url = "https://api.telegram.org/bot$botToken/$method";
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        file_put_contents('/tmp/cron_log.txt', date('Y-m-d H:i:s') . " - ERROR: $error\n", FILE_APPEND);
        return false;
    }
    return json_decode($response, true);
}

// Функция отправки сообщения
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
        $params['reply_markup'] = $replyMarkup;
    }
    
    return apiRequest('sendMessage', $params);
}

// Функция для получения статистики бота
function getBotStats() {
    global $db;
    
    // Общая статистика пользователей
    $stats = ['total' => 0, 'active' => 0, 'balance' => 0, 'referrals' => 0];
    $result = $db->query("SELECT COUNT(*) as total, 
                         SUM(CASE WHEN blocked = 0 THEN 1 ELSE 0 END) as active,
                         SUM(balance) as balance,
                         SUM(referrals) as referrals
                         FROM users");
    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $stats = $row;
    }
    
    // Топ-5 пользователей по балансу
    $topUsers = [];
    $topResult = $db->query("SELECT user_id, username, balance, referrals, blocked 
                           FROM users 
                           ORDER BY balance DESC, referrals DESC 
                           LIMIT 5");
    while ($user = $topResult->fetchArray(SQLITE3_ASSOC)) {
        $topUsers[] = $user;
    }
    
    $message = "📊 <b>Статистика бота</b>\n\n";
    $message .= "👥 Всего пользователей: <b>{$stats['total']}</b>\n";
    $message .= "🟢 Активных: <b>{$stats['active']}</b>\n";
    $message .= "💰 Общий баланс: <b>{$stats['balance']}</b> баллов\n";
    $message .= "👥 Всего рефералов: <b>{$stats['referrals']}</b>\n\n";
    
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

// Проверяем, существует ли таблица stat_channels
$tableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='stat_channels'");
if (!$tableExists) {
    $db->exec("CREATE TABLE IF NOT EXISTS stat_channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_id TEXT NOT NULL UNIQUE,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    file_put_contents('/tmp/cron_log.txt', date('Y-m-d H:i:s') . " - Создана таблица stat_channels\n", FILE_APPEND);
}

// Получаем список каналов для отправки статистики
$channelsQuery = $db->query("SELECT channel_id FROM stat_channels");
$channels = [];
while ($row = $channelsQuery->fetchArray(SQLITE3_ASSOC)) {
    $channels[] = $row['channel_id'];
}

// Если нет каналов, отправляем статистику только админу
if (empty($channels)) {
    global $adminId;
    if (!empty($adminId)) {
        $channels = [$adminId];
        file_put_contents('/tmp/cron_log.txt', date('Y-m-d H:i:s') . " - Нет каналов, отправляем админу: $adminId\n", FILE_APPEND);
    } else {
        file_put_contents('/tmp/cron_log.txt', date('Y-m-d H:i:s') . " - Нет каналов и не задан админ\n", FILE_APPEND);
    }
}

// Получаем статистику
$statsMessage = getBotStats();

// Отправляем статистику во все каналы/чаты
foreach ($channels as $channelId) {
    $result = sendMessage($channelId, $statsMessage);
    // Логируем результат
    if ($result) {
        file_put_contents('/tmp/cron_log.txt', date('Y-m-d H:i:s') . " - Статистика отправлена в канал $channelId\n", FILE_APPEND);
    } else {
        file_put_contents('/tmp/cron_log.txt', date('Y-m-d H:i:s') . " - Ошибка отправки в канал $channelId\n", FILE_APPEND);
    }
    // Небольшая задержка между отправками
    usleep(300000); // 0.3 секунды
}

// Закрываем соединение с БД
$db->close();

file_put_contents('/tmp/cron_log.txt', date('Y-m-d H:i:s') . " - Скрипт отправки статистики завершен\n", FILE_APPEND);
echo "Статистика успешно отправлена в " . count($channels) . " каналов/чатов.";
?>
