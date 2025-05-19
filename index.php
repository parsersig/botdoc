<?php
// Вывод отладочной информации
error_log("[DEBUG] Starting Index.php at " . date('Y-m-d H:i:s'));
error_log("[DEBUG] Environment variables:");
error_log("[DEBUG] TELEGRAM_BOT_TOKEN: " . (empty($_ENV['TELEGRAM_BOT_TOKEN']) ? 'NOT SET' : 'SET'));
error_log("[DEBUG] ADMIN_ID: " . $_ENV['ADMIN_ID']);
error_log("[DEBUG] BOT_USERNAME: " . $_ENV['BOT_USERNAME']);
error_log("[DEBUG] CHANNEL_ID: " . $_ENV['CHANNEL_ID']);

require_once 'config.php';

// Логирование ошибок
function logError($message) {
    global $ERROR_LOG;
    if (defined('ERROR_LOG')) {
        error_log(date('Y-m-d H:i:s') . " [ERROR] " . $message . "\n", 3, $ERROR_LOG);
    }
}

// Проверка существования необходимых констант
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

// CHANNEL_ID может быть не обязательным
$channelId = defined('CHANNEL_ID') ? CHANNEL_ID : null;

// Инициализация переменных
$botToken = TELEGRAM_BOT_TOKEN;
$adminId = ADMIN_ID;
$botUsername = BOT_USERNAME;

// URL для API Telegram
$API_URL = "https://api.telegram.org/bot " . $botToken . "/";

// Конфигурация бота
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

// Создаем файл лога, если он не существует
if (!file_exists(ERROR_LOG)) {
    file_put_contents(ERROR_LOG, '');
    chmod(ERROR_LOG, 0666);
}

define('ADMIN_ID', $adminId);
define('BOT_USERNAME', $botUsername);

// Загрузка пользователей
function loadUsers() {
    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, json_encode([]));
    }
    return json_decode(file_get_contents(USERS_FILE), true) ?: [];
}

// Сохранение пользователей
function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
}

// Получение статистики бота
function getBotStats() {
    $users = loadUsers();
    $totalUsers = count($users);
    $activeUsers = 0;
    $totalBalance = 0;
    $totalReferrals = 0;

    foreach ($users as $user) {
        $totalBalance += $user['balance'];
        $totalReferrals += $user['referrals'];
        if (!$user['blocked']) {
            $activeUsers++;
        }
    }

    // Получаем топ-5 пользователей по балансу
    $topUsers = $users;
    uasort($topUsers, function($a, $b) {
        return $b['balance'] - $a['balance'];
    });
    $topUsers = array_slice($topUsers, 0, 5, true);

    // Формируем сообщение
    $message = "📊 <b>Статистика бота</b>\n";
    $message .= "👥 Всего пользователей: <b>$totalUsers</b>\n";
    $message .= "🟢 Активных: <b>$activeUsers</b>\n";
    $message .= "💰 Общий баланс: <b>$totalBalance</b> баллов\n";
    $message .= "👥 Всего рефералов: <b>$totalReferrals</b>\n";
    $message .= "🏆 <b>Топ-5 пользователей</b>:\n";
    $i = 1;
    foreach ($topUsers as $id => $user) {
        $status = $user['blocked'] ? '🚫' : '✅';
        $message .= "$i. ID $id: <b>{$user['balance']}</b> баллов (Реф: {$user['referrals']}) $status\n";
        $i++;
    }
    $message .= "\n⏱ Обновлено: " . date('d.m.Y H:i:s');
    return $message;
}

// Функция для безопасной отправки сообщений с использованием cURL
function sendMessage($chat_id, $text, $keyboard = null) {
    global $API_URL;

    // Проверяем входные данные
    if (!is_numeric($chat_id)) {
        error_log("[ERROR] Invalid chat_id: " . $chat_id);
        return false;
    }

    // Ограничиваем размер сообщения
    $text = substr($text, 0, 4096); // Максимальная длина сообщения в Telegram

    // Формируем параметры запроса
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

    // Используем cURL для более надежной отправки
    $ch = curl_init($API_URL . 'sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_SSL_VERIFYPEER => true, // Проверяем SSL сертификат
        CURLOPT_TIMEOUT => 5, // Таймаут 5 секунд
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ]
    ]);

    // Выполняем запрос
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Логируем результат
    if ($error) {
        error_log("[ERROR] sendMessage failed: " . $error);
        return false;
    }
    if ($httpCode !== 200) {
        error_log("[ERROR] sendMessage HTTP error: " . $httpCode);
        return false;
    }

    return $response !== false;
}

// Логирование ошибок
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
}

// Отправка уведомления админу о выводе
function notifyAdminWithdraw($chat_id, $amount) {
    $users = loadUsers();
    $user = $users[$chat_id];
    $message = "🔔 Новый запрос на вывод\n";
    $message .= "ID пользователя: $chat_id\n";
    $message .= "Сумма: $amount баллов\n";
    $message .= "Баланс: {$user['balance']}\n";
    $message .= "Рефералов: {$user['referrals']}\n";
    $message .= "Время: " . date('d.m.Y H:i:s');
    sendMessage(ADMIN_ID, $message);
}

// Обновление статуса вывода
function updateWithdrawStatus($chat_id, $status) {
    $users = loadUsers();
    if (isset($users[$chat_id])) {
        $users[$chat_id]['withdraw_status'] = $status;
        saveUsers($users);
    }
}

// Главная клавиатура
function getMainKeyboard($isAdmin = false) {
    $keyboard = [
        [['text' => '💰 Заработать', 'callback_data' => 'earn'], ['text' => '💳 Баланс', 'callback_data' => 'balance']],
        [['text' => '🏆 Топ', 'callback_data' => 'leaderboard'], ['text' => '👥 Рефералы', 'callback_data' => 'referrals']],
        [['text' => 'mtx Вывод', 'callback_data' => 'withdraw'], ['text' => '❓ Помощь', 'callback_data' => 'help']]
    ];
    if ($isAdmin) {
        $keyboard[] = [['text' => '⚙️ Админ', 'callback_data' => 'admin_menu']];
    }
    return $keyboard;
}

function getAdminKeyboard() {
    return [
        [['text' => '📊 Статистика', 'callback_data' => 'admin_stats']],
        [['text' => '👤 Список участников', 'callback_data' => 'admin_users']],
        [['text' => '✉️ Написать участнику', 'callback_data' => 'admin_message']],
        [['text' => '🚫 Заблокировать участника', 'callback_data' => 'admin_block']],
        [['text' => '🔙 Назад', 'callback_data' => 'back_to_main']]
    ];
}

// Обработка обновлений
function processUpdate($update) {
    $users = loadUsers();

    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');

        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null,
                'blocked' => false
            ];
            saveUsers($users);
        }

        if ($users[$chat_id]['blocked']) {
            sendMessage($chat_id, "🚫 Вы были заблокированы администратором.");
            return;
        }

        if (strpos($text, '/start') === 0) {
            $ref = explode(' ', $text)[1] ?? null;
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50;
                        saveUsers($users);
                        sendMessage($id, "🎉 Новый реферал! +50 баллов.");
                        break;
                    }
                }
            }

            $msg = "Добро пожаловать в Бот Заработка!\nЗарабатывайте баллы, приглашайте друзей и выводите средства!\nВаш реферальный код: <b>{$users[$chat_id]['ref_code']}</b>";
            $isAdmin = ($chat_id == ADMIN_ID);
            sendMessage($chat_id, $msg, getMainKeyboard($isAdmin));
            return;
        }

        // Обработка команд админа
        if (strpos($text, '/sendmsg') === 0 && $chat_id == ADMIN_ID) {
            $parts = explode(' ', $text, 3);
            if (count($parts) === 3) {
                $targetId = $parts[1];
                $message = $parts[2];
                sendMessage($targetId, "Сообщение от администратора:\n$message");
                sendMessage($chat_id, "✅ Сообщение отправлено пользователю $targetId");
            }
            return;
        }

        if (strpos($text, '/block') === 0 && $chat_id == ADMIN_ID) {
            $parts = explode(' ', $text, 2);
            if (count($parts) === 2) {
                $targetId = $parts[1];
                if (isset($users[$targetId])) {
                    $users[$targetId]['blocked'] = true;
                    saveUsers($users);
                    sendMessage($chat_id, "✅ Пользователь $targetId заблокирован");
                    sendMessage($targetId, "🚫 Вы были заблокированы администратором.");
                } else {
                    sendMessage($chat_id, "❌ Пользователь не найден");
                }
            }
            return;
        }

        if (strpos($text, '/withdraw_status') === 0 && $chat_id == ADMIN_ID) {
            $parts = explode(' ', $text, 3);
            if (count($parts) === 3) {
                $targetId = $parts[1];
                $status = $parts[2];
                if (isset($users[$targetId])) {
                    updateWithdrawStatus($targetId, $status);
                    sendMessage($chat_id, "✅ Статус вывода для пользователя $targetId обновлен на: $status");
                    sendMessage($targetId, "✅ Статус вашего запроса на вывод обновлен: $status");
                } else {
                    sendMessage($chat_id, "❌ Пользователь не найден");
                }
            }
            return;
        }
    } elseif (isset($update['callback_query'])) {
        $chat_id = $update['callback_query']['message']['chat']['id'];
        $data = $update['callback_query']['data'];

        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null,
                'blocked' => false
            ];
            saveUsers($users);
        }

        if ($users[$chat_id]['blocked']) {
            sendMessage($chat_id, "🚫 Вы были заблокированы администратором.");
            return;
        }

        $msg = "Неизвестная команда";
        $keyboard = null;

        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Пожалуйста, подождите $remaining секунд перед следующим заработком!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ Вы заработали $earn баллов!\nНовый баланс: {$users[$chat_id]['balance']}";
                    saveUsers($users);
                }
                break;

            case 'balance':
                $msg = "💳 Ваш баланс\nБаллы: {$users[$chat_id]['balance']}\nРефералы: {$users[$chat_id]['referrals']}";
                break;

            case 'leaderboard':
                $sorted = $users;
                uasort($sorted, function($a, $b) {
                    return $b['balance'] <=> $a['balance'];
                });
                $top = array_slice($sorted, 0, 5, true);
                $msg = "🏆 Топ участников\n";
                $i = 1;
                foreach ($top as $id => $user) {
                    $msg .= "$i. Пользователь $id: {$user['balance']} баллов\n";
                    $i++;
                }
                break;

            case 'referrals':
                $msg = "👥 Реферальная система\nВаш код: <b>{$users[$chat_id]['ref_code']}</b>\nРефералы: {$users[$chat_id]['referrals']}\nСсылка для приглашения: t.me/" . BOT_USERNAME . "?start={$users[$chat_id]['ref_code']}\n50 баллов за каждого приглашенного!";
                break;

            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "mtx Вывод средств\nМинимум: $min баллов\nВаш баланс: {$users[$chat_id]['balance']}\nНеобходимо еще " . ($min - $users[$chat_id]['balance']) . " баллов!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $users[$chat_id]['withdraw_status'] = 'pending';
                    saveUsers($users);
                    notifyAdminWithdraw($chat_id, $amount);
                    $msg = "mtx Запрос на вывод $amount баллов отправлен!\nНаша команда скоро обработает его.";
                }
                break;

            case 'help':
                $msg = "❓ Помощь\n💰 Заработать: Получайте 10 баллов в минуту\n👥 Приглашайте друзей: 50 баллов за каждого\nmtx Вывод: Минимум 100 баллов\nИспользуйте кнопки ниже для навигации!";
                break;

            case 'admin_menu':
                if ($chat_id == ADMIN_ID) {
                    sendMessage($chat_id, "⚙️ Админ меню:", getAdminKeyboard());
                    return;
                } else {
                    $msg = "🚫 У вас нет доступа к этому разделу.";
                }
                break;

            case 'admin_stats':
                if ($chat_id == ADMIN_ID) {
                    $msg = getBotStats();
                } else {
                    $msg = "🚫 У вас нет доступа к этому разделу.";
                }
                break;

            case 'admin_users':
                if ($chat_id == ADMIN_ID) {
                    $msg = "👤 Список участников:\n";
                    foreach ($users as $id => $user) {
                        $status = $user['blocked'] ? '🚫' : '✅';
                        $msg .= "$status ID: $id, Баланс: {$user['balance']}, Рефералы: {$user['referrals']}\n";
                    }
                } else {
                    $msg = "🚫 У вас нет доступа к этому разделу.";
                }
                break;

            case 'admin_message':
                if ($chat_id == ADMIN_ID) {
                    $msg = "✉️ Введите ID пользователя и сообщение в формате:\n/sendmsg ID сообщение";
                } else {
                    $msg = "🚫 У вас нет доступа к этому разделу.";
                }
                break;

            case 'admin_block':
                if ($chat_id == ADMIN_ID) {
                    $msg = "🚫 Введите ID пользователя для блокировки в формате:\n/block ID";
                } else {
                    $msg = "🚫 У вас нет доступа к этому разделу.";
                }
                break;

            case 'back_to_main':
                $isAdmin = ($chat_id == ADMIN_ID);
                $msg = "Главное меню";
                $keyboard = getMainKeyboard($isAdmin);
                break;
        }

        sendMessage($chat_id, $msg, $keyboard);
    }

    saveUsers($users);
}

// Основной код
try {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);
    if (!$update) {
        throw new Exception('Invalid update');
    }
    processUpdate($update);
} catch (Exception $e) {
    logError($e->getMessage());
    http_response_code(500);
}
?>