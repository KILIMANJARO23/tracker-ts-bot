<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'habits.php';
require_once 'goals.php';
require_once 'finance.php';

$pdo = dbConnect();

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$chatId = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$userId = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;
$messageText = $update['message']['text'] ?? null;
$callbackData = $update['callback_query']['data'] ?? null;
$isCallback = isset($update['callback_query']);

if (!$chatId || !$userId) exit;

// Удаление входящих команд для чистоты чата
if ($messageText) {
    botRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $update['message']['message_id']]);
}
if ($isCallback) {
    botRequest('answerCallbackQuery', ['callback_query_id' => $update['callback_query']['id']]);
}

$user = getUser($userId, $chatId, $pdo);

// --- ГЛАВНЫЙ РОУТИНГ ---

// Главное меню и Старт
if ($callbackData == 'main_menu' || $messageText == '/start') {
    updateUserState($userId, 'MAIN_MENU', null, $pdo);
    $text = "🏠 <b>Главное меню</b>";
    $kb = ['inline_keyboard' => [
        [['text' => '💎 Привычки', 'callback_data' => 'habits_menu']],
        [['text' => '🎯 Цели', 'callback_data' => 'goals_menu']],
        [['text' => '💰 Финансы', 'callback_data' => 'finance_menu']],
    ]];
    renderView($chatId, $text, $kb, $user, $pdo, $isCallback);
    exit;
}

// ПРИВЫЧКИ
if (strpos((string)$user['state'], 'HABIT') === 0 || strpos((string)$callbackData, 'h') === 0 || $callbackData == 'render_days') {
    processHabits($pdo, $user, $chatId, $messageText, $callbackData, $isCallback);
    exit;
}

// ЦЕЛИ
$isGoalAction = (strpos((string)$user['state'], 'GOAL') === 0) 
                || (strpos((string)$callbackData, 'goal') === 0) 
                || (strpos((string)$callbackData, 'gcat') === 0)
                || (strpos((string)$callbackData, 'gdate') === 0)
                || (strpos((string)$callbackData, 'gedit') === 0)
                || (strpos((string)$callbackData, 'gsave') === 0)
                || (strpos((string)$callbackData, 'gstep') === 0)
                || (strpos((string)$callbackData, 'gdel') === 0);

if ($isGoalAction) {
    processGoals($pdo, $user, $chatId, $messageText, $callbackData, $isCallback);
    exit;
}

// ФИНАНСЫ
$isFinAction = (strpos((string)$user['state'], 'FIN') === 0) 
               || (strpos((string)$callbackData, 'fin') === 0) 
               || (strpos((string)$callbackData, 'f_') === 0)
               || (strpos((string)$callbackData, 'back_to_cat') === 0);

if ($isFinAction) {
    processFinance($pdo, $user, $chatId, $messageText, $callbackData, $isCallback);
    exit;
}