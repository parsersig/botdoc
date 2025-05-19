<?php
// =============================================
// 🚀 Telegram Bot Webhook Handler for Render.com
// =============================================

// -----------------------------
// 🔧 Конфигурация и инициализация
// -----------------------------

// Включение логов ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/error.log');

// Константы
define('USERS_FILE', __DIR__.'/users.json');
define('ERROR_LOG', __DIR__.'/error.log');
define('REQUEST_LOG', __DIR__.'/request.log');
define('WEBHOOK_URL', 'https://'.($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Создание файлов если их нет
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode([]));
    chmod(USERS_FILE, 0666);
}

if (!file_exists(ERROR_LOG)) {
    file_put_contents(ERROR_LOG, '');
    chmod(ERROR_LOG, 0666);
}

if (!file_exists(REQUEST_LOG)) {
    file_put_contents(REQUEST_LOG, '');
    chmod(REQUEST_LOG, 0666);
}

// Логирование
function logMessage($message, $file = ERROR_LOG) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($file, "[$timestamp] $message\n", FILE_APPEND);
}

// Загрузка конфига
require_once __DIR__.'/config.php';

// Проверка обязательных констант
foreach (['TELEGRAM_BOT_TOKEN', 'ADMIN_ID', 'BOT_USERNAME'] as $const) {
    if (!defined($const) || empty(constant($const))) {
        logMessage("Critical: Missing $const");
        http_response_code(500);
        die("Configuration error");
    }
}

// Инициализация API
$botToken = TELEGRAM_BOT_TOKEN;
$apiUrl = "https://api.telegram.org/bot$botToken/";
$adminId = ADMIN_ID;
$botUsername = BOT_USERNAME;
$channelId = defined('CHANNEL_ID') ? CHANNEL_ID : null;

// -----------------------------
// 🛠️ Вспомогательные функции
// -----------------------------

function loadUsers() {
    $data = file_exists(USERS_FILE) ? file_get_contents(USERS_FILE) : '[]';
    return json_decode($data, true) ?: [];
}

function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
    chmod(USERS_FILE, 0666);
}

function apiRequest($method, $params = []) {
    global $apiUrl;
    
    $url = $apiUrl.$method;
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || $httpCode != 200) {
        logMessage("API Error: $method - ".($error ?: "HTTP $httpCode"));
        return false;
    }
    
    return json_decode($response, true);
}

function sendMessage($chatId, $text, $keyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    
    if ($keyboard) {
        $params['reply_markup'] = json_encode([
            'keyboard' => $keyboard,
            'resize_keyboard' => true
        ]);
    }
    
    return apiRequest('sendMessage', $params);
}

// -----------------------------
// ⌨️ Клавиатуры
// -----------------------------

function getMainKeyboard($isAdmin = false) {
    $keyboard = [
        [['text' => '💰 Заработать'], ['text' => '💳 Баланс']],
        [['text' => '🏆 Топ'], ['text' => '👥 Рефералы']],
        [['text' => '🏧 Вывод'], ['text' => '❓ Помощь']]
    ];
    
    if ($isAdmin) {
        $keyboard[] = [['text' => '⚙️ Админ']];
    }
    
    return $keyboard;
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

// -----------------------------
// 📊 Статистика
// -----------------------------

function getBotStats() {
    $users = loadUsers();
    $stats = [
        'total' => 0,
        'active' => 0,
        'balance' => 0,
        'referrals' => 0
    ];
    
    foreach ($users as $user) {
        $stats['total']++;
        $stats['balance'] += $user['balance'];
        $stats['referrals'] += $user['referrals'];
        if (!$user['blocked']) $stats['active']++;
    }
    
    uasort($users, fn($a, $b) => $b['balance'] <=> $a['balance']);
    $topUsers = array_slice($users, 0, 5, true);
    
    $message = "📊 <b>Статистика бота</b>\n\n";
    $message .= "👥 Всего пользователей: <b>{$stats['total']}</b>\n";
    $message .= "🟢 Активных: <b>{$stats['active']}</b>\n";
    $message .= "💰 Общий баланс: <b>{$stats['balance']}</b>\n";
    $message .= "👥 Всего рефералов: <b>{$stats['referrals']}</b>\n\n";
    $message .= "🏆 <b>Топ-5 пользователей</b>:\n";
    
    $i = 1;
    foreach ($topUsers as $id => $user) {
        $status = $user['blocked'] ? '🚫' : '✅';
        $message .= "$i. ID $id: <b>{$user['balance']}</b> (Реф: {$user['referrals']}) $status\n";
        $i++;
    }
    
    $message .= "\n⏱ Обновлено: ".date('d.m.Y H:i:s');
    return $message;
}

// -----------------------------
// 📨 Обработка команд
// -----------------------------

function handleStart($chatId, $text, &$users) {
    // Обработка реферальной ссылки
    $refCode = trim(str_replace('/start', '', $text));
    
    if ($refCode && !isset($users[$chatId]['referred_by'])) {
        foreach ($users as $id => $user) {
            if ($user['ref_code'] === $refCode && $id != $chatId) {
                $users[$chatId]['referred_by'] = $id;
                $users[$id]['referrals']++;
                $users[$id]['balance'] += 50;
                sendMessage($id, "🎉 Новый реферал! +50 баллов.");
                break;
            }
        }
    }
    
    // Приветственное сообщение
    $refLink = "https://t.me/$botUsername?start={$users[$chatId]['ref_code']}";
    $message = "👋 Добро пожаловать в $botUsername!\n\n";
    $message .= "💰 Зарабатывайте баллы и выводите их\n";
    $message .= "👥 Приглашайте друзей по реферальной ссылке:\n";
    $message .= "<code>$refLink</code>\n\n";
    $message .= "Используйте кнопки ниже для навигации!";
    
    sendMessage($chatId, $message, getMainKeyboard($chatId == $adminId));
}

function handleEarn($chatId, &$users) {
    $cooldown = 60; // 1 минута
    $reward = 10; // 10 баллов
    
    $lastEarn = $users[$chatId]['last_earn'] ?? 0;
    $remaining = $cooldown - (time() - $lastEarn);
    
    if ($remaining > 0) {
        sendMessage($chatId, "⏳ Подождите $remaining секунд перед следующим заработком!");
        return;
    }
    
    $users[$chatId]['balance'] += $reward;
    $users[$chatId]['last_earn'] = time();
    saveUsers($users);
    
    sendMessage($chatId, "✅ +$reward баллов! Текущий баланс: {$users[$chatId]['balance']}");
}

function handleWithdraw($chatId, &$users) {
    $minAmount = 100;
    
    if ($users[$chatId]['balance'] < $minAmount) {
        $needed = $minAmount - $users[$chatId]['balance'];
        sendMessage($chatId, "❌ Минимальная сумма вывода: $minAmount баллов\nВам не хватает: $needed баллов");
        return;
    }
    
    $amount = $users[$chatId]['balance'];
    $users[$chatId]['balance'] = 0;
    $users[$chatId]['withdraw_status'] = 'pending';
    saveUsers($users);
    
    // Уведомление админу
    $adminMsg = "🔔 Новый запрос на вывод\n\n";
    $adminMsg .= "👤 Пользователь: $chatId\n";
    $adminMsg .= "💰 Сумма: $amount баллов\n";
    $adminMsg .= "⏱ Время: ".date('d.m.Y H:i:s');
    sendMessage($adminId, $adminMsg);
    
    sendMessage($chatId, "✅ Запрос на вывод $amount баллов отправлен администратору.");
}

// -----------------------------
// 🚀 Основной обработчик
// -----------------------------

// Получаем входящее обновление
$content = file_get_contents("php://input");
logMessage("Incoming update: $content", REQUEST_LOG);

if (empty($content)) {
    logMessage("Empty request received");
    die("OK");
}

$update = json_decode($content, true);
if (!$update) {
    logMessage("Invalid JSON received");
    die("OK");
}

try {
    $users = loadUsers();
    
    // Обработка сообщения
    if (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $text = trim($message['text'] ?? '');
        
        // Инициализация нового пользователя
        if (!isset($users[$chatId])) {
            $users[$chatId] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chatId.time()), 0, 8),
                'referred_by' => null,
                'blocked' => false,
                'withdraw_status' => null
            ];
            saveUsers($users);
        }
        
        // Проверка блокировки
        if ($users[$chatId]['blocked']) {
            sendMessage($chatId, "🚫 Вы заблокированы администратором.");
            die("OK");
        }
        
        // Обработка команд
        if (strpos($text, '/start') === 0) {
            handleStart($chatId, $text, $users);
        }
        elseif ($text === '💰 Заработать') {
            handleEarn($chatId, $users);
        }
        elseif ($text === '💳 Баланс') {
            $msg = "💰 Ваш баланс: <b>{$users[$chatId]['balance']}</b> баллов\n";
            $msg .= "👥 Рефералов: <b>{$users[$chatId]['referrals']}</b>";
            sendMessage($chatId, $msg);
        }
        elseif ($text === '🏆 Топ') {
            sendMessage($chatId, getBotStats());
        }
        elseif ($text === '👥 Рефералы') {
            $refLink = "https://t.me/$botUsername?start={$users[$chatId]['ref_code']}";
            $msg = "👥 <b>Реферальная система</b>\n\n";
            $msg .= "Ваш код: <code>{$users[$chatId]['ref_code']}</code>\n";
            $msg .= "Приглашено: <b>{$users[$chatId]['referrals']}</b>\n\n";
            $msg .= "Ссылка для приглашения:\n<code>$refLink</code>\n\n";
            $msg .= "💵 50 баллов за каждого друга!";
            sendMessage($chatId, $msg);
        }
        elseif ($text === '🏧 Вывод') {
            handleWithdraw($chatId, $users);
        }
        elseif ($text === '❓ Помощь') {
            $msg = "ℹ️ <b>Помощь</b>\n\n";
            $msg .= "💰 <b>Заработать</b> - получайте 10 баллов каждую минуту\n";
            $msg .= "👥 <b>Рефералы</b> - приглашайте друзей и получайте бонусы\n";
            $msg .= "🏧 <b>Вывод</b> - минимальная сумма 100 баллов\n\n";
            $msg .= "Используйте кнопки меню для навигации!";
            sendMessage($chatId, $msg);
        }
        elseif ($text === '⚙️ Админ' && $chatId == $adminId) {
            sendMessage($chatId, "⚙️ <b>Админ-панель</b>", getAdminKeyboard());
        }
        elseif ($text === '📊 Статистика' && $chatId == $adminId) {
            sendMessage($chatId, getBotStats());
        }
        elseif ($text === '🔙 Назад' && $chatId == $adminId) {
            sendMessage($chatId, "Главное меню", getMainKeyboard(true));
        }
        // Админские команды
        elseif ($chatId == $adminId && strpos($text, '/send ') === 0) {
            $parts = explode(' ', $text, 3);
            if (count($parts) === 3) {
                $targetId = $parts[1];
                $msg = $parts[2];
                sendMessage($targetId, "📩 Сообщение от администратора:\n\n$msg");
                sendMessage($chatId, "✅ Сообщение отправлено пользователю $targetId");
            }
        }
        elseif ($chatId == $adminId && strpos($text, '/block ') === 0) {
            $parts = explode(' ', $text, 2);
            if (count($parts) === 2) {
                $targetId = $parts[1];
                if (isset($users[$targetId])) {
                    $users[$targetId]['blocked'] = true;
                    saveUsers($users);
                    sendMessage($chatId, "✅ Пользователь $targetId заблокирован");
                    sendMessage($targetId, "🚫 Вы были заблокированы администратором.");
                } else {
                    sendMessage($chatId, "❌ Пользователь не найден");
                }
            }
        }
    }
    
    // Сохраняем изменения
    saveUsers($users);
    
} catch (Exception $e) {
    logMessage("Error: ".$e->getMessage());
    http_response_code(500);
}

// Всегда возвращаем OK для Telegram
echo "OK";
