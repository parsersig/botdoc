<?php
// -----------------------------
// 🔧 Константы и функции
// -----------------------------
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

// 📌 Логирование ошибок
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
}

// Создание файла лога, если он не существует
if (!file_exists(ERROR_LOG)) {
    file_put_contents(ERROR_LOG, '');
    chmod(ERROR_LOG, 0666);
}

// -----------------------------
// 🐞 Отладочная информация
// -----------------------------
error_log("[DEBUG] Starting index.php at " . date('Y-m-d H:i:s'));
error_log("[DEBUG] Environment:");
error_log("[DEBUG] TELEGRAM_BOT_TOKEN: " . (empty($_ENV['TELEGRAM_BOT_TOKEN']) ? 'NOT SET' : 'SET'));
error_log("[DEBUG] ADMIN_ID: " . $_ENV['ADMIN_ID']);
error_log("[DEBUG] BOT_USERNAME: " . $_ENV['BOT_USERNAME']);
error_log("[DEBUG] CHANNEL_ID: " . $_ENV['CHANNEL_ID']);

// -----------------------------
// ✅ Проверка конфигурации
// -----------------------------
require_once 'config.php';

if (!defined('TELEGRAM_BOT_TOKEN')) {
    logError('Missing TELEGRAM_BOT_TOKEN');
    exit(1);
}
if (!defined('ADMIN_ID')) {
    logError('Missing ADMIN_ID');
    exit(1);
}
if (!defined('BOT_USERNAME')) {
    logError('Missing BOT_USERNAME');
    exit(1);
}

// CHANNEL_ID — необязательный
$channelId = defined('CHANNEL_ID') ? CHANNEL_ID : null;

// -----------------------------
// 📦 Глобальные переменные
// -----------------------------
$botToken = TELEGRAM_BOT_TOKEN;
$adminId = ADMIN_ID;
$botUsername = BOT_USERNAME;
$API_URL = "https://api.telegram.org/bot" . $botToken . "/";

// -----------------------------
// 🔄 Работа с пользователями
// -----------------------------
function loadUsers() {
    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, json_encode([]));
    }
    return json_decode(file_get_contents(USERS_FILE), true) ?: [];
}

function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
}

// -----------------------------
// 📊 Статистика бота
// -----------------------------
function getBotStats() {
    $users = loadUsers();
    $total = count($users);
    $active = 0;
    $balance = 0;
    $referrals = 0;

    foreach ($users as $u) {
        if (!$u['blocked']) $active++;
        $balance += $u['balance'];
        $referrals += $u['referrals'];
    }

    uasort($users, fn($a, $b) => $b['balance'] <=> $a['balance']);
    $top5 = array_slice($users, 0, 5, true);

    $msg = "📊 <b>Статистика бота</b>\n";
    $msg .= "👥 Всего: <b>$total</b>\n";
    $msg .= "🟢 Активных: <b>$active</b>\n";
    $msg .= "💰 Общий баланс: <b>$balance</b>\n";
    $msg .= "👥 Рефералов: <b>$referrals</b>\n\n";
    $msg .= "🏆 <b>Топ-5</b>:\n";

    $i = 1;
    foreach ($top5 as $id => $u) {
        $status = $u['blocked'] ? '🚫' : '✅';
        $msg .= "$i. ID $id: <b>{$u['balance']}</b> баллов (Реф: {$u['referrals']}) $status\n";
        $i++;
    }

    $msg .= "\n⏱ Обновлено: " . date('d.m.Y H:i:s');
    return $msg;
}

// -----------------------------
// ✉️ Отправка сообщений
// -----------------------------
function sendMessage($chat_id, $text, $keyboard = null) {
    global $API_URL;

    if (!is_numeric($chat_id)) {
        error_log("[ERROR] Invalid chat_id: $chat_id");
        return false;
    }

    $text = substr($text, 0, 4096);
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard) {
        $params['reply_markup'] = json_encode([
            'keyboard' => $keyboard,
            'resize_keyboard' => true
        ]);
    }

    $ch = curl_init($API_URL . 'sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $code !== 200) {
        error_log("[ERROR] sendMessage failed: $error | HTTP $code");
        return false;
    }

    return $response;
}

// -----------------------------
// 🔔 Уведомления
// -----------------------------
function notifyAdminWithdraw($chat_id, $amount) {
    $users = loadUsers();
    $u = $users[$chat_id];
    $msg = "🔔 Запрос на вывод\nID: $chat_id\nСумма: $amount\nБаланс: {$u['balance']}\nРеф: {$u['referrals']}\nВремя: " . date('d.m.Y H:i:s');
    sendMessage(ADMIN_ID, $msg);
}

function updateWithdrawStatus($chat_id, $status) {
    $users = loadUsers();
    if (isset($users[$chat_id])) {
        $users[$chat_id]['withdraw_status'] = $status;
        saveUsers($users);
    }
}

// -----------------------------
// ⌨️ Клавиатуры
// -----------------------------
function getMainKeyboard($admin = false) {
    $kb = [
        [['text' => '💰 Заработать'], ['text' => '💳 Баланс']],
        [['text' => '🏆 Топ'], ['text' => '👥 Рефералы']],
        [['text' => 'mtx Вывод'], ['text' => '❓ Помощь']]
    ];
    if ($admin) $kb[] = [['text' => '⚙️ Админ']];
    return $kb;
}

function getAdminKeyboard() {
    return [
        [['text' => '📊 Статистика']],
        [['text' => '👤 Список участников']],
        [['text' => '✉️ Написать участнику']],
        [['text' => '🚫 Заблокировать участника']],
        [['text' => '🔙 Назад']]
    ];
}
