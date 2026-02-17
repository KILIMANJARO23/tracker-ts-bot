<?php
/**
 * Конфигурация системы v2.2
 *
 * Секреты (TG_TOKEN, DB_*) не храним в коде:
 * - положите их в `.env` (см. `.env.example`), или
 * - задайте переменные окружения на сервере.
 */

require_once __DIR__ . '/env.php';
loadEnvFile(__DIR__ . '/.env');

$tgToken = env('TG_TOKEN');
$dbHost  = env('DB_HOST', 'localhost');
$dbName  = env('DB_NAME');
$dbUser  = env('DB_USER');
$dbPass  = env('DB_PASS');

if (!$tgToken)  { throw new RuntimeException('Не задан TG_TOKEN (env или .env)'); }
if (!$dbName)   { throw new RuntimeException('Не задан DB_NAME (env или .env)'); }
if (!$dbUser)   { throw new RuntimeException('Не задан DB_USER (env или .env)'); }
if ($dbPass === null) { throw new RuntimeException('Не задан DB_PASS (env или .env)'); }

define('TG_TOKEN', $tgToken);
define('DB_HOST', $dbHost);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);

const DAYS_MAP = [
    1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'
];

const GOAL_CATS = [
    'health' => ['name' => 'Здоровье', 'icon' => '🍏'],
    'work'   => ['name' => 'Работа', 'icon' => '💼'],
    'edu'    => ['name' => 'Обучение', 'icon' => '📚'],
    'fin'    => ['name' => 'Финансы', 'icon' => '💰'],
    // Встречается в данных (напр. "Проект"), добавляем алиас:
    'prj'    => ['name' => 'Проект', 'icon' => '💻'],
    'other'  => ['name' => 'Другое', 'icon' => '📌']
];

// Добавляем новый блок для Финансов
const FIN_CATS = [
    'inc' => [
        'salary' => ['name' => 'Зарплата', 'icon' => '💵'],
        'prj'    => ['name' => 'Проект', 'icon' => '💻'],
        'inv'    => ['name' => 'Инвестиции', 'icon' => '📈'],
        'other'  => ['name' => 'Другое', 'icon' => '💰']
    ],
    'exp' => [
        'food'   => ['name' => 'Еда', 'icon' => '🍎'],
        'trans'  => ['name' => 'Транспорт', 'icon' => '🚗'],
        'home'   => ['name' => 'Жилье', 'icon' => '🏠'],
        'fun'    => ['name' => 'Развлечения', 'icon' => '🎬'],
        'buy'    => ['name' => 'Покупки', 'icon' => '🛍'],
        'other'  => ['name' => 'Другое', 'icon' => '📦']
    ]
];