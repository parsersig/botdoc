<?php
// =============================================
// 🚀 Telegram Bot Webhook Handler for Render.com
// =============================================

// -----------------------------
// 🔧 Конфигурация и инициализация
// -----------------------------

// Handle non-Telegram requests (e.g., health checks, browser access)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty(file_get_contents("php://input"))) {
    http_response_code(200);
    echo "This is a Telegram webhook endpoint.";
    exit;
}

// Включение логов ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/error.log');

// Константы
define('USERS_FILE', '/tmp/users.json');
define('ERROR_LOG', '/tmp/error.log');
define('REQUEST_LOG', '/tmp/request.log');
define('WEBHOOK_URL', 'https://'.($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Создание файлов если их нет
foreach ([USERS_FILE, ERROR_LOG, REQUEST_LOG] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, $file === USERS_FILE ? '[]' : '');
        chmod($file, 0666);
    }
}

// Логирование
function logMessage($message, $file = ERROR_LOG) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($file, "[$timestamp] $message\n", FILE_APPEND);
}

// Загрузка конфигурации из переменных окружения
$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$adminId = getenv('ADMIN_ID') ?: '';
$botUsername = getenv('BOT_USERNAME') ?: '';
$channelId = getenv('CHANNEL_ID') ?: null;

// Проверка обязательных переменных
foreach (['TELEGRAM_BOT_TOKEN' => $botToken, 'ADMIN_ID' => $adminId, 'BOT_USERNAME' => $botUsername] as $key => $value) {
    if (empty($value)) {
        logMessage("Critical: Missing or empty $key environment variable");
        http_response_code(500);
        die("Configuration error");
    }
}

// Инициализация API
$apiUrl = "https://api.telegram.org/bot$botToken/";

// -----------------------------
// 🛠️ Вспомогательные функции
// -----------------------------

function loadUsers() {
    $data = file_get_contents(USERS_FILE);
    $users = json_decode($data, true);
    if ($users === null) {
        logMessage("Error: Failed to decode users.json");
        return [];
    }
    return $users;
}

function saveUsers($users) {
    if (!file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        logMessage("Error: Failed to write to users.json");
        return false;
    }
    chmod(USERS_FILE, 0666);
    return true;
}

function apiRequest($method, $params = [], $retries = 3) {
    global $apiUrl;
    
    $url = $apiUrl.$method;
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data'],
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    for ($i = 0; $i < $retries; $i++) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error || $httpCode != 200) {
            logMessage("API Error: $method - HTTP $httpCode - ".($error ?: "No CURL error"));
            if ($i < $retries - 1) {
                sleep(2); // Ждем перед повтором
                continue;
            }
            curl_close($ch);
            return false;
        }
        
        $result = json_decode($response, true);
        if (!$result || !isset($result['ok']) || !$result['ok']) {
            logMessage("API Error: $method - Response: ".json_encode($result));
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        return $result;
    }
    
    curl_close($ch);
    return false;
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
            'resize_keyboard' => true,
            'one_time_keyboard' => false
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
        $stats['balance'] += $user['balance'] ?? 0;
        $stats['referrals'] += $user['referrals'] ?? 0;
        if (!isset($user['blocked']) || !$user['blocked']) $stats['active']++;
    }
    
    uasort($users, fn($a, $b) => ($b['balance'] ?? 0) <=> ($a['balance'] ?? 0));
    $topUsers = array_slice($users, 0, 5, true);
    
    $message = "📊 <b>Статистика бота</b>\n\n";
    $message .= "👥 Всего пользователей: <b>{$stats['total']}</b>\n";
    $message .= "🟢 Активных: <b>{$stats['active']}</b>\n";
    $message .= "💰 Общий баланс: <b>{$stats['balance']}</b>\n";
    $message .= "👥 Всего рефералов: <b>{$stats['referrals']}</b>\n\n";
    $message .= "🏆 <b>Топ-5 пользователей</b>:\n";
    
    $i = 1;
    foreach ($topUsers as $id => $user) {
        $status = (isset($user['blocked']) && $user['blocked']) ? '🚫' : '✅';
        $balance = $user['balance'] ?? 0;
        $referrals = $user['referrals'] ?? 0;
        $message .= "$i. ID $id: <b>$balance</b> (Реф: $referrals) $status\n";
        $i++;
    }
    
    $message .= "\n⏱ Обновлено: ".date('d.m.Y H:i:s');
    return $message;
}

// -----------------------------
// 📨 Обработка команд
// -----------------------------

function handleStart($chatId, $text, &$users) {
    global $botUsername;
    
    // Обработка реферальной ссылки
    $refCode = trim(str_replace('/start', '', $text));
    
    if ($refCode && !isset($users[$chatId]['referred_by'])) {
        foreach ($users as $id => $user) {
            if (isset($user['ref_code']) && $user['ref_code'] === $refCode && $id != $chatId) {
                $users[$chatId]['referred_by'] = $id;
                $users[$id]['referrals'] = ($users[$id]['referrals'] ?? 0) + 1;
                $users[$id]['balance'] = ($users[$id]['balance'] ?? 0) + 50;
                sendMessage($id, "🎉 Новый реферал! +50 баллов.");
                saveUsers($users);
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
    
    sendMessage($chatId, $message, getMainKeyboard($chatId == $GLOBALS['adminId']));
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
    
    $users[$chatId]['balance'] = ($users[$chatId]['balance'] ?? 0) + $reward;
    $users[$chatId]['last_earn'] = time();
    saveUsers($users);
    
    sendMessage($chatId, "✅ +$reward баллов! Текущий баланс: {$users[$chatId]['balance']}");
}

function handleWithdraw($chatId, &$users) {
    global $adminId;
    
    $minAmount = 100;
    
    if (($users[$chatId]['balance'] ?? 0) < $minAmount) {
        $needed = $minAmount - ($users[$chatId]['balance'] ?? 0);
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

$update = json_decode($content, true);
if (!$update) {
    logMessage("Invalid JSON received");
    echo "OK";
    exit;
}

try {
    $users = loadUsers();
    
    // Обработка сообщения
    if (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');
        
        if (!$chatId) {
            logMessage("Error: No chat ID in message");
            echo "OK";
            exit;
        }
        
        // Защита от дублирования
        $updateId = $update['update_id'] ?? 0;
        static $processedUpdates = [];
        if (in_array($updateId, $processedUpdates)) {
            echo "OK";
            exit;
        }
        $processedUpdates[] = $updateId;
        if (count($processedUpdates) > 100) {
            array_shift($processedUpdates);
        }
        
        // Инициализация нового пользователя
        if (!isset($users[$chatId])) {
            $users[$chatId] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chatId.time()), 0, 8),
                'referred_by' => null,
                'blocked' => false,
                'withdraw_status' => null,
                'joined_at' => date('Y-m-d H:i:s')
            ];
            saveUsers($users);
        }
        
        // Проверка блокировки
        if (isset($users[$chatId]['blocked']) && $users[$chatId]['blocked']) {
            sendMessage($chatId, "🚫 Вы заблокированы администратором.");
            echo "OK";
            exit;
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
        elseif ($text === '👤 Список участников' && $chatId == $adminId) {
            $users = loadUsers();
            $msg = "👥 <b>Список участников</b>\n\n";
            foreach ($users as $id => $user) {
                $status = (isset($user['blocked']) && $user['blocked']) ? '🚫' : '✅';
                $balance = $user['balance'] ?? 0;
                $msg .= "ID: $id, Баланс: $balance, Статус: $status\n";
            }
            sendMessage($chatId, $msg);
        }
        elseif ($text === '🔙 Назад' && $chatId == $adminId) {
            sendMessage($chatId, "Главное меню", getMainKeyboard(true));
        }
        elseif ($chatId == $adminId && strpos($text, '/send ') === 0) {
            $parts = explode(' ', $text, 3);
            if (count($parts) === 3) {
                $targetId = $parts[1];
                $msg = $parts[2];
                sendMessage($targetId, "📩 Сообщение от администратора:\n\n$msg");
                sendMessage($chatId, "✅ Сообщение отправлено пользователю $targetId");
            } else {
                sendMessage($chatId, "❌ Формат: /send <ID> <сообщение>");
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
            } else {
                sendMessage($chatId, "❌ Формат: /block <ID>");
            }
        }
    }
    
    // Сохраняем изменения
    saveUsers($users);
    
} catch (Exception $e) {
    logMessage("Error: ".$e->getMessage()." in ".$e->getFile()." on line ".$e->getLine());
    http_response_code(500);
}

// Всегда возвращаем OK для Telegram
echo "OK";
