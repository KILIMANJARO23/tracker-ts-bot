## Tracker Telegram Bot (PHP)

Небольшой Telegram-бот на PHP с хранением данных в MySQL/MariaDB. Реализованы разделы:
- **Привычки**: список, добавление, редактирование, удаление.
- **Цели**: список, добавление, редактирование, удаление + шаги плана.
- **Финансы**: операции доход/расход (за текущий месяц), баланс за месяц, редактирование/удаление, привязка операции к цели.

Проект работает как **webhook endpoint**: Telegram присылает обновления (update) HTTP POST-ом, `bot.php` читает `php://input`.

---

## Файлы и ответственность

- **`bot.php`**: точка входа webhook, роутинг по разделам, обработка `message` и `callback_query`.
- **`config.php`**: конфиг + справочники (категории целей/финансов, дни недели). Загружает переменные из `.env`.
- **`env.php`**: минималистичный загрузчик `.env` и helper `env()`.
- **`functions.php`**:
  - `dbConnect()` — подключение к БД (PDO)
  - `botRequest()` — запросы к Telegram Bot API (c таймаутами + логирование ошибок)
  - `getUser()`, `updateUserState()` — FSM: `users.state` и `users.temp_data`
  - `renderView()` — UI “в одном сообщении”: `editMessageText` по кнопкам или `sendMessage` при вводе текста
  - `parseRuDateToSql()` — безопасный парсинг даты `ДД.ММ.ГГГГ` -> `Y-m-d`
- **`habits.php`**: логика раздела привычек.
- **`goals.php`**: логика раздела целей + шаги (`goal_steps`).
- **`finance.php`**: логика раздела финансов + операции (`transactions`).
- **`tg_bot.sql`**: схема БД и пример данных.

---

## База данных (кратко)

Таблицы:
- **`users`**: пользователи, текущее состояние (`state`), последнее сообщение бота (`last_msg_id`), временные данные (`temp_data` JSON).
- **`habits`**: привычки.
- **`goals`**: цели.
- **`goal_steps`**: шаги целей (FK на `goals`, `ON DELETE CASCADE`).
- **`transactions`**: фин. операции (FK `goal_id` на `goals`, `ON DELETE SET NULL`).

---

## Настройка окружения

Секреты не храним в коде — используем `.env` (см. `.env.example`).

Создайте `.env` рядом с `bot.php`:

```env
TG_TOKEN=123456789:ABCDEF_replace_me
DB_HOST=localhost
DB_NAME=tg_bot
DB_USER=tg_bot
DB_PASS=replace_me

# опционально:
# LOG_FILE=/absolute/path/to/bot.log
```

---

## Быстрый запуск локально (для разработки)

1) Импортируйте `tg_bot.sql` в MySQL/MariaDB (создайте БД/пользователя).

2) Запустите локальный PHP-сервер:

```bash
php -S 127.0.0.1:8080
```

Webhook endpoint: `http://127.0.0.1:8080/bot.php`

3) Сделайте публичный HTTPS-URL (Telegram webhook требует HTTPS), например через ngrok:

```bash
ngrok http 8080
```

4) Установите webhook:

```bash
curl -s "https://api.telegram.org/bot<TG_TOKEN>/setWebhook" \
  -d "url=https://<your-ngrok-host>/bot.php"
```

Проверка:

```bash
curl -s "https://api.telegram.org/bot<TG_TOKEN>/getWebhookInfo"
```

---

## Важно

- **Если токен/пароль БД когда-то “светились” публично** — перевыпустите токен у BotFather и смените пароль БД.
- Проект сейчас — **бот**, а не Telegram Mini App (WebApp). Mini App можно добавить отдельным фронтендом на JS/TS.

