<?php
require_once 'config.php';

/**
 * Простой логгер (файл из env LOG_FILE или стандартный error_log)
 */
function appLog(string $message, array $context = []): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $line .= PHP_EOL;

    $logFile = function_exists('env') ? env('LOG_FILE') : null;
    if ($logFile) {
        @file_put_contents($logFile, $line, FILE_APPEND);
        return;
    }
    error_log(rtrim($line));
}

/**
 * Подключение к базе данных через PDO
 */
function dbConnect() {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/**
 * Отправка методов в Telegram API
 */
function botRequest($method, $data = []) {
    $url = "https://api.telegram.org/bot" . TG_TOKEN . "/" . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = $errno ? curl_error($ch) : null;
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        appLog('Telegram API curl error', ['method' => $method, 'errno' => $errno, 'error' => $err]);
        return ['ok' => false, 'error' => 'curl', 'errno' => $errno, 'message' => $err];
    }

    $json = json_decode((string)$res, true);
    if (!is_array($json)) {
        appLog('Telegram API invalid JSON', ['method' => $method, 'http' => $http, 'raw' => mb_strimwidth((string)$res, 0, 500, '...')]);
        return ['ok' => false, 'error' => 'json', 'http' => $http];
    }

    if (($json['ok'] ?? null) !== true) {
        appLog('Telegram API returned not ok', ['method' => $method, 'http' => $http, 'resp' => $json]);
    }

    return $json;
}

/**
 * Получение данных пользователя или регистрация нового
 */
function getUser($userId, $chatId, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        $pdo->prepare("INSERT INTO users (id, chat_id) VALUES (?, ?)")->execute([$userId, $chatId]);
        return ['id' => $userId, 'chat_id' => $chatId, 'state' => 'MAIN_MENU', 'last_msg_id' => null, 'temp_data' => null];
    }
    return $user;
}

/**
 * Обновление состояния и временных данных пользователя
 */
function updateUserState($userId, $state, $tempData, $pdo) {
    $json = $tempData ? json_encode($tempData, JSON_UNESCAPED_UNICODE) : null;
    $pdo->prepare("UPDATE users SET state = ?, temp_data = ? WHERE id = ?")->execute([$state, $json, $userId]);
}

/**
 * Рендеринг интерфейса в одном сообщении
 */
function renderView($chatId, $text, $keyboard, $user, $pdo, $isCallback = false) {
    $hasKb = is_array($keyboard) && !empty($keyboard) && (!isset($keyboard['inline_keyboard']) || !empty($keyboard['inline_keyboard']));

    if ($isCallback && !empty($user['last_msg_id'])) {
        // Если нажата кнопка — редактируем текущее сообщение
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $user['last_msg_id'],
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($hasKb) $payload['reply_markup'] = json_encode($keyboard);
        botRequest('editMessageText', $payload);
    } else {
        // Если прислали текст — удаляем старое сообщение бота и шлем новое
        if (!empty($user['last_msg_id'])) {
            botRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $user['last_msg_id']]);
        }
        
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($hasKb) $payload['reply_markup'] = json_encode($keyboard);
        $res = botRequest('sendMessage', $payload);
        
        // Запоминаем ID нового сообщения
        if (isset($res['result']['message_id'])) {
            $pdo->prepare("UPDATE users SET last_msg_id = ? WHERE id = ?")
                ->execute([$res['result']['message_id'], $user['id']]);
        }
    }
}

/**
 * Парсинг даты формата ДД.ММ.ГГГГ -> Y-m-d (или null/false)
 * - null: пустая/не задана
 * - false: формат неверный
 */
function parseRuDateToSql(?string $input) {
    $input = trim((string)$input);
    if ($input === '') return null;

    $dt = DateTime::createFromFormat('d.m.Y', $input);
    $errors = DateTime::getLastErrors();
    if (!$dt || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
        return false;
    }
    return $dt->format('Y-m-d');
}