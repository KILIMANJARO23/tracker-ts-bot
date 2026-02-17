<?php

/**
 * Формирование текста со списком привычек (сортировка по алфавиту)
 */
function getHabitsText($userId, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM habits WHERE user_id = ? ORDER BY title ASC");
    $stmt->execute([$userId]);
    $habits = $stmt->fetchAll();

    if (!$habits) return "У вас пока нет добавленных привычек.";

    $text = "📌 <b>Ваши привычки:</b>\n\n";
    foreach ($habits as $h) {
        $daysArr = explode(',', $h['days']);
        $daysStr = [];
        foreach ($daysArr as $d) {
            if (isset(DAYS_MAP[$d])) $daysStr[] = DAYS_MAP[$d];
        }
        $icon = $h['notify'] ? '🔔' : '🔕';
        
        $text .= "<b>" . htmlspecialchars($h['title']) . "</b>\n";
        $text .= "- (" . implode(', ', $daysStr) . ") $icon\n\n";
    }
    return $text;
}

/**
 * Основной процессор модуля привычек
 */
function processHabits($pdo, $user, $chatId, $messageText, $callbackData, $isCallback) {
    $userId = $user['id'];
    $state = $user['state'];
    $tempData = json_decode($user['temp_data'], true) ?? [];

    // --- ГЛАВНОЕ МЕНЮ ПРИВЫЧЕК ---
    if ($callbackData == 'habits_menu') {
        updateUserState($userId, 'HABITS_MENU', null, $pdo);
        $text = getHabitsText($userId, $pdo);
        $kb = ['inline_keyboard' => [
            [['text' => '➕ Добавить', 'callback_data' => 'habit_add_start']],
            [['text' => '✏️ Редактировать', 'callback_data' => 'habit_edit_list']],
            [['text' => '🗑 Удалить', 'callback_data' => 'habit_delete_list']],
            [['text' => '⬅️ Назад', 'callback_data' => 'main_menu']],
        ]];
        renderView($chatId, $text, $kb, $user, $pdo, $isCallback);
        return;
    }

    // --- ДОБАВЛЕНИЕ ---
    
    // Шаг 1: Ввод названия
    if ($callbackData == 'habit_add_start') {
        updateUserState($userId, 'HABIT_ADD_NAME', [], $pdo);
        renderView($chatId, "✍️ Введите название привычки:", ['inline_keyboard' => [[['text' => '⬅️ Назад', 'callback_data' => 'habits_menu']]]], $user, $pdo, $isCallback);
        return;
    }

    if ($state == 'HABIT_ADD_NAME' && $messageText) {
        $tempData['title'] = $messageText;
        $tempData['days'] = $tempData['days'] ?? [];
        updateUserState($userId, 'HABIT_ADD_DAYS', $tempData, $pdo);
        $callbackData = 'render_days'; 
    }

    // Шаг 2: Выбор дней
    if (strpos($callbackData, 'habit_day_toggle_') === 0 || $callbackData == 'habit_days_all' || $callbackData == 'render_days' || $callbackData == 'habit_back_to_days') {
        if (strpos($callbackData, 'habit_day_toggle_') === 0) {
            $day = (int)str_replace('habit_day_toggle_', '', $callbackData);
            $tempData['days'] = in_array($day, $tempData['days']) ? array_diff($tempData['days'], [$day]) : array_merge($tempData['days'], [$day]);
        } elseif ($callbackData == 'habit_days_all') {
            $tempData['days'] = [1, 2, 3, 4, 5, 6, 7];
        }
        
        updateUserState($userId, 'HABIT_ADD_DAYS', $tempData, $pdo);

        $kb = []; $row = [];
        foreach (DAYS_MAP as $id => $n) {
            $row[] = ['text' => (in_array($id, $tempData['days']) ? '✅ ' : '') . $n, 'callback_data' => 'habit_day_toggle_'.$id];
            if (count($row) == 4) { $kb[] = $row; $row = []; }
        }
        if ($row) $kb[] = $row;
        $kb[] = [['text' => '📅 Выбрать все', 'callback_data' => 'habit_days_all']];
        
        $nav = [['text' => '⬅️ Назад', 'callback_data' => 'habit_add_start']];
        if (!empty($tempData['days'])) $nav[] = ['text' => 'Далее ➡️', 'callback_data' => 'habit_add_notify'];
        $kb[] = $nav;

        renderView($chatId, "🗓 Выберите дни для <b>" . htmlspecialchars($tempData['title']) . "</b>:", ['inline_keyboard' => $kb], $user, $pdo, $isCallback);
        return;
    }

    // Шаг 3: Выбор уведомлений
    if ($callbackData == 'habit_add_notify') {
        updateUserState($userId, 'HABIT_ADD_NOTIFY', $tempData, $pdo);
        renderView($chatId, "🔔 Включить уведомления для <b>" . htmlspecialchars($tempData['title']) . "</b>?", [
            'inline_keyboard' => [
                [['text' => 'Вкл 🔔', 'callback_data' => 'h_save_1'], ['text' => 'Выкл 🔕', 'callback_data' => 'h_save_0']],
                [['text' => '⬅️ Назад', 'callback_data' => 'habit_back_to_days']]
            ]
        ], $user, $pdo, $isCallback);
        return;
    }

    // Финал: Сохранение
    if (strpos($callbackData, 'h_save_') === 0) {
        $notify = (int)substr($callbackData, -1);
        $pdo->prepare("INSERT INTO habits (user_id, title, days, notify) VALUES (?, ?, ?, ?)")
            ->execute([$userId, $tempData['title'], implode(',', $tempData['days']), $notify]);
        updateUserState($userId, 'HABITS_MENU', null, $pdo);
        processHabits($pdo, getUser($userId, $chatId, $pdo), $chatId, null, 'habits_menu', true);
        return;
    }

    // --- УДАЛЕНИЕ ---   
    // 1. Список привычек для удаления
    if ($callbackData == 'habit_delete_list') {
        $stmt = $pdo->prepare("SELECT id, title FROM habits WHERE user_id = ? ORDER BY title ASC");
        $stmt->execute([$userId]);
        $habits = $stmt->fetchAll();
        
        if (!$habits) {
            renderView($chatId, "Список привычек пуст.", ['inline_keyboard' => [[['text' => '⬅️ Назад', 'callback_data' => 'habits_menu']]]], $user, $pdo, $isCallback);
            return;
        }

        $btns = array_map(fn($h) => [['text' => '🗑 ' . $h['title'], 'callback_data' => 'hdel_conf_' . $h['id']]], $habits);
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => 'habits_menu']];
        
        renderView($chatId, "Выберите привычку, которую хотите <b>удалить</b>:", ['inline_keyboard' => $btns], $user, $pdo, $isCallback);
        return;
    }

    // 2. Шаг подтверждения
    if (strpos($callbackData, 'hdel_conf_') === 0) {
        $id = (int)substr($callbackData, 10);
        $stmt = $pdo->prepare("SELECT title FROM habits WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $h = $stmt->fetch();

        if (!$h) {
            processHabits($pdo, $user, $chatId, null, 'habit_delete_list', true);
            return;
        }

        $text = "⚠️ Вы уверены, что хотите удалить привычку: <b>" . htmlspecialchars($h['title']) . "</b>?";
        $kb = ['inline_keyboard' => [
            [
                ['text' => '✅ Да, удалить', 'callback_data' => 'hdel_do_' . $id],
                ['text' => '❌ Нет, отмена', 'callback_data' => 'habit_delete_list']
            ]
        ]];
        renderView($chatId, $text, $kb, $user, $pdo, $isCallback);
        return;
    }

    // 3. Само удаление
    if (strpos($callbackData, 'hdel_do_') === 0) {
        $id = (int)substr($callbackData, 8);
        $pdo->prepare("DELETE FROM habits WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        
        // Возвращаемся в список удаления с уведомлением
        $stmt = $pdo->prepare("SELECT id, title FROM habits WHERE user_id = ? ORDER BY title ASC");
        $stmt->execute([$userId]);
        $habits = $stmt->fetchAll();
        
        $btns = array_map(fn($h) => [['text' => '🗑 ' . $h['title'], 'callback_data' => 'hdel_conf_' . $h['id']]], $habits);
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => 'habits_menu']];
        
        $text = "🗑 Привычка удалена.\n" . ($habits ? "Выберите следующую для удаления:" : "Больше привычек нет.");
        renderView($chatId, $text, ['inline_keyboard' => $btns], $user, $pdo, $isCallback);
        return;
    }

    // --- РЕДАКТИРОВАНИЕ ---
    if ($callbackData == 'habit_edit_list') {
        $stmt = $pdo->prepare("SELECT id, title FROM habits WHERE user_id = ? ORDER BY title ASC");
        $stmt->execute([$userId]);
        $habits = $stmt->fetchAll();
        if (!$habits) {
            renderView($chatId, "Нет привычек.", ['inline_keyboard' => [[['text' => 'Назад', 'callback_data' => 'habits_menu']]]], $user, $pdo, $isCallback);
            return;
        }
        $btns = array_map(fn($h) => [['text' => $h['title'], 'callback_data' => 'hedit_sel_' . $h['id']]], $habits);
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => 'habits_menu']];
        renderView($chatId, "✏️ Выберите для редактирования:", ['inline_keyboard' => $btns], $user, $pdo, $isCallback);
        return;
    }

    // Меню редактирования конкретной привычки
    if (strpos($callbackData, 'hedit_sel_') === 0 || $callbackData == 'hedit_refresh' || $callbackData == 'hedit_toggle_n') {
        $id = strpos($callbackData, 'hedit_sel_') === 0 ? (int)substr($callbackData, 10) : $tempData['edit_id'];
        
        if ($callbackData == 'hedit_toggle_n') {
            $pdo->prepare("UPDATE habits SET notify = NOT notify WHERE id = ?")->execute([$id]);
        }
        
        $stmt = $pdo->prepare("SELECT * FROM habits WHERE id = ?"); $stmt->execute([$id]);
        $h = $stmt->fetch();
        updateUserState($userId, 'HABIT_EDIT_MENU', ['edit_id' => $id], $pdo);

        $days = implode(', ', array_map(fn($d) => DAYS_MAP[$d], explode(',', $h['days'])));
        $text = "🛠 <b>Редактирование</b>\n\n<b>{$h['title']}</b>\n- ($days) " . ($h['notify'] ? '🔔' : '🔕');
        renderView($chatId, $text, ['inline_keyboard' => [
            [['text' => '📝 Изменить название', 'callback_data' => 'hedit_title']],
            [['text' => '📅 Изменить дни', 'callback_data' => 'hedit_days_st']],
            [['text' => '🔔 Уведомления: ' . ($h['notify'] ? 'ВКЛ' : 'ВЫКЛ'), 'callback_data' => 'hedit_toggle_n']],
            [['text' => '⬅️ К списку', 'callback_data' => 'habit_edit_list']]
        ]], $user, $pdo, $isCallback);
        return;
    }

    // Редактирование названия
    if ($callbackData == 'hedit_title') {
        updateUserState($userId, 'HABIT_EDIT_WAIT_T', $tempData, $pdo);
        renderView($chatId, "✍️ Введите новое название:", ['inline_keyboard' => [[['text' => '⬅️ Отмена', 'callback_data' => 'hedit_refresh']]]], $user, $pdo, $isCallback);
        return;
    }
    if ($state == 'HABIT_EDIT_WAIT_T' && $messageText) {
        $pdo->prepare("UPDATE habits SET title = ? WHERE id = ?")->execute([$messageText, $tempData['edit_id']]);
        processHabits($pdo, getUser($userId, $chatId, $pdo), $chatId, null, 'hedit_refresh', false);
        return;
    }
    
    // Редактирование дней (аналогично добавлению, но для существующей записи)
    if ($callbackData == 'hedit_days_st' || strpos($callbackData, 'hedit_day_toggle_') === 0) {
        if ($callbackData == 'hedit_days_st') {
            $stmt = $pdo->prepare("SELECT days FROM habits WHERE id = ?");
            $stmt->execute([$tempData['edit_id']]);
            $tempData['days'] = explode(',', $stmt->fetch()['days']);
        } else {
            $day = (int)str_replace('hedit_day_toggle_', '', $callbackData);
            $tempData['days'] = in_array($day, $tempData['days']) ? array_diff($tempData['days'], [$day]) : array_merge($tempData['days'], [$day]);
        }
        updateUserState($userId, 'HABIT_EDIT_DAYS', $tempData, $pdo);
        $kb = []; $row = [];
        foreach (DAYS_MAP as $id => $n) {
            $row[] = ['text' => (in_array($id, $tempData['days']) ? '✅ ' : '') . $n, 'callback_data' => 'hedit_day_toggle_'.$id];
            if (count($row) == 4) { $kb[] = $row; $row = []; }
        }
        if ($row) $kb[] = $row;
        $kb[] = [['text' => '💾 Сохранить дни', 'callback_data' => 'hedit_days_save']];
        renderView($chatId, "📅 Изменение дней:", ['inline_keyboard' => $kb], $user, $pdo, $isCallback);
        return;
    }
    if ($callbackData == 'hedit_days_save') {
        $pdo->prepare("UPDATE habits SET days = ? WHERE id = ?")->execute([implode(',', $tempData['days']), $tempData['edit_id']]);
        processHabits($pdo, getUser($userId, $chatId, $pdo), $chatId, null, 'hedit_refresh', true);
    }
}