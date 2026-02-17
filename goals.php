<?php

/**
 * Получение списка целей (Алфавитный порядок + кол-во шагов > 0)
 */
function getGoalsText($userId, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM goals WHERE user_id = ? ORDER BY title ASC");
    $stmt->execute([$userId]);
    $goals = $stmt->fetchAll();

    if (!$goals) return "У вас пока нет поставленных целей.";

    $text = "🎯 <b>Ваши цели (А-Я):</b>\n\n";
    foreach ($goals as $g) {
        $cat = GOAL_CATS[$g['category']] ?? ['name' => 'Общая', 'icon' => '📌'];
        $sStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM goal_steps WHERE goal_id = ?");
        $sStmt->execute([$g['id']]);
        $stepsCount = (int)$sStmt->fetch()['cnt'];

        $text .= "<b>" . htmlspecialchars($g['title']) . "</b>\n";
        $text .= "└ {$cat['icon']} {$cat['name']}";
        if ($g['deadline']) $text .= " | 📅 до " . date('d.m.Y', strtotime($g['deadline']));
        if ($stepsCount > 0) $text .= "\n└ Шагов в плане: <b>$stepsCount</b>";
        $text .= "\n\n";
    }
    return $text;
}

function processGoals($pdo, $user, $chatId, $messageText, $callbackData, $isCallback) {
    $userId = $user['id'];
    $state = $user['state'];
    $tempData = json_decode($user['temp_data'], true) ?? [];

    // --- ГЛАВНОЕ МЕНЮ ---
    if ($callbackData == 'goals_menu') {
        updateUserState($userId, 'GOALS_MENU', null, $pdo);
        $text = getGoalsText($userId, $pdo);
        $kb = ['inline_keyboard' => [
            [['text' => '➕ Добавить цель', 'callback_data' => 'goal_add_start']],
            [['text' => '✏️ Редактировать', 'callback_data' => 'goal_edit_list']],
            [['text' => '🗑 Удалить цель', 'callback_data' => 'goal_delete_list']],
            [['text' => '⬅️ Назад', 'callback_data' => 'main_menu']],
        ]];
        renderView($chatId, $text, $kb, $user, $pdo, $isCallback);
        return;
    }

    // --- СПИСОК ДЛЯ ВЫБОРА РЕДАКТИРОВАНИЯ ---
    if ($callbackData == 'goal_edit_list') {
        $stmt = $pdo->prepare("SELECT id, title FROM goals WHERE user_id = ? ORDER BY title ASC");
        $stmt->execute([$userId]);
        $goals = $stmt->fetchAll();
        $btns = array_map(fn($g) => [['text' => '📝 ' . $g['title'], 'callback_data' => 'gedit_view_' . $g['id']]], $goals);
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => 'goals_menu']];
        renderView($chatId, "✏️ Выберите цель для управления:", ['inline_keyboard' => $btns], $user, $pdo, $isCallback);
        return;
    }

    // --- КАРТОЧКА ЦЕЛИ (Управление) ---
    if (strpos($callbackData, 'gedit_view_') === 0) {
        $goalId = (int)substr($callbackData, 11);
        $stmt = $pdo->prepare("SELECT * FROM goals WHERE id = ? AND user_id = ?");
        $stmt->execute([$goalId, $userId]);
        $g = $stmt->fetch();
        if (!$g) return;

        $cat = GOAL_CATS[$g['category']] ?? ['name' => '?', 'icon' => '📌'];
        $date = $g['deadline'] ? date('d.m.Y', strtotime($g['deadline'])) : 'Без даты';
        $safeTitle = htmlspecialchars($g['title']);
        $text = "⚙️ <b>Управление целью</b>\n\nНазвание: <b>{$safeTitle}</b>\nКатегория: {$cat['icon']} {$cat['name']}\nСрок: $date";
        $kb = ['inline_keyboard' => [
            [['text' => '📝 Название', 'callback_data' => "gedit_title_{$goalId}"], ['text' => '📁 Категория', 'callback_data' => "gedit_cat_{$goalId}"]],
            [['text' => '📅 Срок', 'callback_data' => "gedit_date_{$goalId}"], ['text' => '🧱 Шаги плана', 'callback_data' => "gedit_steps_list_{$goalId}"]],
            [['text' => '⬅️ Назад', 'callback_data' => 'goal_edit_list']],
        ]];
        renderView($chatId, $text, $kb, $user, $pdo, $isCallback);
        return;
    }

    // --- УПРАВЛЕНИЕ ШАГАМИ ВНУТРИ ЦЕЛИ ---
    if (strpos($callbackData, 'gedit_steps_list_') === 0) {
        $goalId = (int)substr($callbackData, 17);
        $stmt = $pdo->prepare("SELECT id, step_text FROM goal_steps WHERE goal_id = ?");
        $stmt->execute([$goalId]);
        $steps = $stmt->fetchAll();
        $text = "🧱 <b>Шаги цели:</b>\n\n";
        $btns = [];
        if (!$steps) $text .= "Список шагов пуст.";
        else {
            foreach ($steps as $i => $s) {
                $text .= ($i + 1) . ". " . htmlspecialchars($s['step_text']) . "\n";
                $btns[] = [['text' => '❌ Удалить: ' . mb_strimwidth($s['step_text'], 0, 20, "..."), 'callback_data' => "gstep_del_{$s['id']}_{$goalId}"]];
            }
        }
        $btns[] = [['text' => '➕ Добавить шаг', 'callback_data' => "gstep_add_{$goalId}"]];
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => "gedit_view_$goalId"]];
        renderView($chatId, $text, ['inline_keyboard' => $btns], $user, $pdo, $isCallback);
        return;
    }

    if (strpos($callbackData, 'gstep_del_') === 0) {
        $p = explode('_', $callbackData);
        $pdo->prepare("DELETE FROM goal_steps WHERE id = ?")->execute([$p[2]]);
        processGoals($pdo, $user, $chatId, null, "gedit_steps_list_{$p[3]}", true);
        return;
    }

    if (strpos($callbackData, 'gstep_add_') === 0) {
        $goalId = substr($callbackData, 10);
        updateUserState($userId, 'GOAL_EDIT_STEP_ADD', ['edit_id' => $goalId], $pdo);
        renderView($chatId, "✍️ Введите текст нового шага:", ['inline_keyboard' => [[['text' => '⬅️ Назад', 'callback_data' => "gedit_steps_list_$goalId"]]]], $user, $pdo, true);
        return;
    }

    if ($state == 'GOAL_EDIT_STEP_ADD' && $messageText) {
        $goalId = $tempData['edit_id'];
        $pdo->prepare("INSERT INTO goal_steps (goal_id, step_text) VALUES (?, ?)")->execute([$goalId, $messageText]);
        processGoals($pdo, getUser($userId, $chatId, $pdo), $chatId, null, "gedit_steps_list_$goalId", false);
        return;
    }

    // --- ИЗМЕНЕНИЕ ПОЛЕЙ (Название, Категория, Дата) ---
    if (strpos($callbackData, 'gedit_title_') === 0) {
        $goalId = substr($callbackData, 12);
        updateUserState($userId, 'GOAL_EDIT_TITLE_PROC', ['edit_id' => $goalId], $pdo);
        renderView($chatId, "Введите новое название:", ['inline_keyboard' => [[['text' => '⬅️ Назад', 'callback_data' => "gedit_view_$goalId"]]]], $user, $pdo, true);
        return;
    }
    if ($state == 'GOAL_EDIT_TITLE_PROC' && $messageText) {
        $goalId = $tempData['edit_id'];
        $pdo->prepare("UPDATE goals SET title = ? WHERE id = ? AND user_id = ?")->execute([$messageText, $goalId, $userId]);
        processGoals($pdo, getUser($userId, $chatId, $pdo), $chatId, null, "gedit_view_$goalId", false);
        return;
    }

    if (strpos($callbackData, 'gedit_cat_') === 0) {
        $goalId = substr($callbackData, 10);
        $btns = []; foreach (GOAL_CATS as $k => $c) $btns[] = [['text' => $c['icon'].' '.$c['name'], 'callback_data' => "gsave_cat_{$goalId}_{$k}"]];
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => "gedit_view_$goalId"]];
        renderView($chatId, "Выберите категорию:", ['inline_keyboard' => $btns], $user, $pdo, true); return;
    }
    if (strpos($callbackData, 'gsave_cat_') === 0) {
        $p = explode('_', $callbackData); $goalId = $p[2]; $newCat = $p[3];
        $pdo->prepare("UPDATE goals SET category = ? WHERE id = ? AND user_id = ?")->execute([$newCat, $goalId, $userId]);
        processGoals($pdo, getUser($userId, $chatId, $pdo), $chatId, null, "gedit_view_$goalId", true); return;
    }

    if (strpos($callbackData, 'gedit_date_') === 0) {
        $goalId = substr($callbackData, 11);
        updateUserState($userId, 'GOAL_EDIT_DATE_PROC', ['edit_id' => $goalId], $pdo);
        renderView($chatId, "Введите новую дату (ДД.ММ.ГГГГ) или нажмите кнопку:", ['inline_keyboard' => [[['text' => '⚪️ Без даты', 'callback_data' => "gsave_date_{$goalId}_none"]], [['text' => '⬅️ Назад', 'callback_data' => "gedit_view_$goalId"]]]], $user, $pdo, true);
        return;
    }
    if (strpos($callbackData, 'gsave_date_') === 0 || ($state == 'GOAL_EDIT_DATE_PROC' && $messageText)) {
        if ($isCallback) {
            $p = explode('_', $callbackData);
            $goalId = $p[2];
            // gsave_date_{goalId}_none
            $newDate = null;
        } else {
            $goalId = $tempData['edit_id'];
            $parsed = parseRuDateToSql($messageText);
            if ($parsed === false) {
                renderView($chatId, "⚠️ Неверный формат. Введите дату как <b>ДД.ММ.ГГГГ</b>:", ['inline_keyboard' => [[['text' => '⬅️ Назад', 'callback_data' => "gedit_view_$goalId"]]]], $user, $pdo, false);
                return;
            }
            $newDate = $parsed; // string Y-m-d или null
        }
        $pdo->prepare("UPDATE goals SET deadline = ? WHERE id = ? AND user_id = ?")->execute([$newDate, $goalId, $userId]);
        processGoals($pdo, getUser($userId, $chatId, $pdo), $chatId, null, "gedit_view_$goalId", $isCallback);
        return;
    }

    // --- ДОБАВЛЕНИЕ НОВОЙ ЦЕЛИ ---
    if ($callbackData == 'goal_add_start') {
        updateUserState($userId, 'GOAL_ADD_TITLE', [], $pdo);
        renderView($chatId, "🎯 Введите название цели:", ['inline_keyboard' => [[['text' => '⬅️ Назад', 'callback_data' => 'goals_menu']]]], $user, $pdo, $isCallback);
        return;
    }
    if ($state == 'GOAL_ADD_TITLE' && $messageText) {
        $tempData['title'] = $messageText; updateUserState($userId, 'GOAL_ADD_CAT', $tempData, $pdo);
        $btns = []; foreach (GOAL_CATS as $k => $c) $btns[] = [['text' => $c['icon'].' '.$c['name'], 'callback_data' => 'gcat_'.$k]];
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => 'goal_add_start']];
        renderView($chatId, "📁 Выберите категорию для: <b>" . htmlspecialchars($tempData['title']) . "</b>", ['inline_keyboard' => $btns], $user, $pdo, false);
        return;
    }
    if (strpos($callbackData, 'gcat_') === 0 || $callbackData == 'goal_back_to_date') {
        if (strpos($callbackData, 'gcat_') === 0) $tempData['cat'] = str_replace('gcat_', '', $callbackData);
        updateUserState($userId, 'GOAL_ADD_DATE', $tempData, $pdo);
        renderView($chatId, "📅 Когда достичь?", ['inline_keyboard' => [[['text' => '⚪️ Без даты', 'callback_data' => 'gdate_none']], [['text' => '⬅️ Назад', 'callback_data' => 'goal_add_title_trigger']]]], $user, $pdo, true);
        return;
    }
    if ($callbackData == 'goal_add_title_trigger') { $messageText = $tempData['title']; $user['state'] = 'GOAL_ADD_TITLE'; processGoals($pdo, $user, $chatId, $messageText, null, true); return; }
    if ($callbackData == 'gdate_none' || ($state == 'GOAL_ADD_DATE' && $messageText) || $callbackData == 'goal_steps_loop') {
        if ($messageText && $state == 'GOAL_ADD_DATE') {
            $parsed = parseRuDateToSql($messageText);
            if ($parsed === false) {
                renderView($chatId, "⚠️ Неверный формат. Введите дату как <b>ДД.ММ.ГГГГ</b> или нажмите «Без даты».", [
                    'inline_keyboard' => [
                        [['text' => '⚪️ Без даты', 'callback_data' => 'gdate_none']],
                        [['text' => '⬅️ Назад', 'callback_data' => 'goal_add_title_trigger']]
                    ]
                ], $user, $pdo, false);
                return;
            }
            $tempData['date'] = $parsed; // Y-m-d или null
        }
        if ($callbackData == 'gdate_none') $tempData['date'] = null;
        $tempData['steps'] = $tempData['steps'] ?? []; updateUserState($userId, 'GOAL_ADD_STEPS', $tempData, $pdo);
        $text = "🧱 <b>Разбей цель на шаги</b>\n\n"; foreach ($tempData['steps'] as $i => $s) $text .= ($i+1).". $s\n";
        $btns = [[empty($tempData['steps']) ? ['text' => '➡️ Пропустить шаги', 'callback_data' => 'goal_preview'] : ['text' => '✅ Готово', 'callback_data' => 'goal_preview']], [['text' => '⬅️ Назад', 'callback_data' => 'goal_back_to_date']]];
        renderView($chatId, $text, ['inline_keyboard' => $btns], $user, $pdo, $isCallback); return;
    }
    if ($state == 'GOAL_ADD_STEPS' && $messageText) { $tempData['steps'][] = $messageText; updateUserState($userId, 'GOAL_ADD_STEPS', $tempData, $pdo); processGoals($pdo, getUser($userId, $chatId, $pdo), $chatId, null, 'goal_steps_loop', false); return; }
    if ($callbackData == 'goal_preview') {
        $c = GOAL_CATS[$tempData['cat']]; $stepsCount = count($tempData['steps'] ?? []);
        $prettyDate = $tempData['date'] ? date('d.m.Y', strtotime($tempData['date'])) : 'Без даты';
        $preview = "🏁 <b>Проверь цель:</b>\n\n1. <b>" . htmlspecialchars($tempData['title']) . "</b>\n2. {$c['icon']} {$c['name']}\n3. " . $prettyDate;
        if ($stepsCount > 0) $preview .= "\n4. Шагов в плане: $stepsCount";
        renderView($chatId, $preview, ['inline_keyboard' => [[['text' => '💾 Сохранить цель', 'callback_data' => 'goal_save_final']], [['text' => '⬅️ Назад', 'callback_data' => 'goal_steps_loop']]]], $user, $pdo, true); return;
    }
    if ($callbackData == 'goal_save_final') {
        $deadline = !empty($tempData['date']) ? $tempData['date'] : null; // уже Y-m-d или null
        $stmt = $pdo->prepare("INSERT INTO goals (user_id, title, category, deadline) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $tempData['title'], $tempData['cat'], $deadline]); $goalId = $pdo->lastInsertId();
        if (!empty($tempData['steps'])) { $sStmt = $pdo->prepare("INSERT INTO goal_steps (goal_id, step_text) VALUES (?, ?)"); foreach ($tempData['steps'] as $st) $sStmt->execute([$goalId, $st]); }
        updateUserState($userId, 'GOALS_MENU', null, $pdo); processGoals($pdo, getUser($userId, $chatId, $pdo), $chatId, null, 'goals_menu', true); return;
    }

    // --- УДАЛЕНИЕ ---
    if ($callbackData == 'goal_delete_list' || strpos($callbackData, 'gdel_') === 0) {
        if (strpos($callbackData, 'gdel_do_') === 0) { 
            $pdo->prepare("DELETE FROM goals WHERE id = ? AND user_id = ?")->execute([(int)substr($callbackData, 8), $userId]); 
            $callbackData = 'goal_delete_list'; 
        }
        if (strpos($callbackData, 'gdel_conf_') === 0) {
            $id = (int)substr($callbackData, 10); $stmt = $pdo->prepare("SELECT title FROM goals WHERE id = ?"); $stmt->execute([$id]); $g = $stmt->fetch();
            renderView($chatId, "⚠️ Удалить <b>{$g['title']}</b>?", ['inline_keyboard' => [[['text' => '✅ Да, удалить', 'callback_data' => 'gdel_do_'.$id], ['text' => '⬅️ Назад', 'callback_data' => 'goal_delete_list']]]], $user, $pdo, true); return;
        }
        $stmt = $pdo->prepare("SELECT id, title FROM goals WHERE user_id = ? ORDER BY title ASC"); $stmt->execute([$userId]); $goals = $stmt->fetchAll();
        $btns = array_map(fn($g) => [['text' => '🗑 ' . $g['title'], 'callback_data' => 'gdel_conf_' . $g['id']]], $goals); $btns[] = [['text' => '⬅️ Назад', 'callback_data' => 'goals_menu']];
        renderView($chatId, "🗑 Выберите цель для удаления:", ['inline_keyboard' => $btns], $user, $pdo, $isCallback);
    }
}