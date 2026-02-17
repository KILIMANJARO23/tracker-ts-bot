<?php

/**
 * Модуль управления финансами v3.0
 * (Баланс за месяц БЕЗ аналитики + Атомарное редактирование + Предпросмотр при добавлении)
 */
function processFinance($pdo, $user, $chatId, $messageText, $callbackData, $isCallback) {
    $userId = $user['id'];
    $state = $user['state'];
    $tempData = json_decode($user['temp_data'] ?? '{}', true) ?? [];

    // --- 0. ГЛАВНОЕ МЕНЮ РАЗДЕЛА ---
    if ($callbackData == 'finance_menu') {
        updateUserState($userId, 'FIN_MENU', null, $pdo);
        
        $stmt = $pdo->prepare("
            SELECT type, amount, category, created_at 
            FROM transactions 
            WHERE user_id = ? 
              AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
              AND YEAR(created_at) = YEAR(CURRENT_DATE())
            ORDER BY created_at ASC
        ");
        $stmt->execute([$userId]);
        $transactions = $stmt->fetchAll();

        $listText = "";
        foreach ($transactions as $tr) {
            $icon = ($tr['type'] == 'inc') ? "🟢 +" : "🔴 -";
            $catName = FIN_CATS[$tr['type']][$tr['category']]['name'] ?? $tr['category'];
            $date = date('d.m', strtotime($tr['created_at']));
            $listText .= "$icon <b>{$tr['amount']}</b>\n└ {$catName} ({$date})\n\n";
        }

        $text = "💰 <b>Финансы</b>\n\n" . ($listText ?: "<i>Операций пока нет.</i>\n");
        $text .= "----------------------------\nВыберите раздел:";
        
        $kb = ['inline_keyboard' => [
            [['text' => '➕ Добавить', 'callback_data' => 'fin_add'], ['text' => '✏️ Ред.', 'callback_data' => 'fin_edit'], ['text' => '🗑 Удал.', 'callback_data' => 'fin_del']],
            [['text' => '💳 Баланс', 'callback_data' => 'fin_balance'], ['text' => '🏁 Фин. цели', 'callback_data' => 'fin_goals'], ['text' => '📊 Анализ', 'callback_data' => 'fin_analytics']],
            [['text' => '⬅️ Назад', 'callback_data' => 'main_menu']]
        ]];
        renderView($chatId, $text, $kb, $user, $pdo, $isCallback);
        return;
    }

    // --- 1. РАЗДЕЛ: БАЛАНС (ТОЛЬКО МЕСЯЦ) ---
    if ($callbackData == 'fin_balance') {
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN type = 'inc' THEN amount ELSE 0 END) as month_inc,
                SUM(CASE WHEN type = 'exp' THEN amount ELSE 0 END) as month_exp
            FROM transactions 
            WHERE user_id = ? 
              AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
              AND YEAR(created_at) = YEAR(CURRENT_DATE())
        ");
        $stmt->execute([$userId]);
        $month = $stmt->fetch();

        $mInc = (float)($month['month_inc'] ?? 0);
        $mExp = (float)($month['month_exp'] ?? 0);
        $mDiff = $mInc - $mExp;

        $months = [1=>'Январь', 2=>'Февраль', 3=>'Март', 4=>'Апрель', 5=>'Май', 6=>'Июнь', 7=>'Июль', 8=>'Август', 9=>'Сентябрь', 10=>'Октябрь', 11=>'Ноябрь', 12=>'Декабрь'];
        $mName = $months[(int)date('m')];

        $text = "💳 <b>Баланс за {$mName}</b>\n\n";
        $text .= "🟢 Доходы: <b>+" . number_format($mInc, 2, '.', ' ') . "</b>\n";
        $text .= "🔴 Расходы: <b>-" . number_format($mExp, 2, '.', ' ') . "</b>\n";
        $text .= "----------------------------\n";
        $text .= (($mDiff >= 0) ? "⚖️" : "⚠️") . " Итог: <b>" . ($mDiff >= 0 ? "+" : "") . number_format($mDiff, 2, '.', ' ') . "</b>\n";
        if ($mDiff < 0) $text .= "\n<i>Внимание: расходы превысили доходы!</i>";

        $kb = ['inline_keyboard' => [[['text' => '⬅️ Назад', 'callback_data' => 'finance_menu']]]];
        renderView($chatId, $text, $kb, $user, $pdo, true);
        return;
    }

    // --- 2. ОБЩАЯ ЦЕПОЧКА (СУММА И КАТЕГОРИЯ) ---
    if ($state == 'FIN_ADD_SUM' && $messageText) {
        $sum = (float)str_replace(',', '.', $messageText);
        if ($sum <= 0) { renderView($chatId, "⚠️ Введите число > 0:", [], $user, $pdo, false); return; }
        $tempData['sum'] = $sum;

        if (isset($tempData['edit_id'])) {
            $pdo->prepare("UPDATE transactions SET amount = ? WHERE id = ? AND user_id = ?")->execute([$sum, $tempData['edit_id'], $userId]);
            processFinance($pdo, getUser($userId, $chatId, $pdo), $chatId, null, "f_edit_item_" . $tempData['edit_id'], false);
        } else {
            updateUserState($userId, 'FIN_ADD_CAT', $tempData, $pdo);
            $btns = [];
            foreach (FIN_CATS[$tempData['type']] as $k => $v) $btns[] = [['text' => $v['icon'].' '.$v['name'], 'callback_data' => "f_cat_$k"]];
            $btns[] = [['text' => '⬅️ Назад', 'callback_data' => "f_type_".$tempData['type']]];
            renderView($chatId, "Выберите категорию:", ['inline_keyboard' => $btns], $user, $pdo, false);
        }
        return;
    }

    if (strpos($callbackData, 'f_cat_') === 0) {
        $newCat = substr($callbackData, 6);
        if (isset($tempData['edit_id'])) {
            $finalType = $tempData['pending_type'] ?? $tempData['type'];
            $pdo->prepare("UPDATE transactions SET category = ?, type = ? WHERE id = ? AND user_id = ?")->execute([$newCat, $finalType, $tempData['edit_id'], $userId]);
            $tempData['type'] = $finalType; $tempData['cat'] = $newCat; unset($tempData['pending_type']);
            updateUserState($userId, 'FIN_EDIT_CHOISE', $tempData, $pdo);
            processFinance($pdo, getUser($userId, $chatId, $pdo), $chatId, null, "f_edit_item_" . $tempData['edit_id'], true);
        } else {
            $tempData['cat'] = $newCat;
            updateUserState($userId, 'FIN_ADD_GOAL', $tempData, $pdo);
            $stmt = $pdo->prepare("SELECT id, title FROM goals WHERE user_id = ?"); $stmt->execute([$userId]);
            $btns = [];
            foreach ($stmt->fetchAll() as $g) $btns[] = [['text' => '🎯 '.$g['title'], 'callback_data' => "f_goal_".$g['id']]];
            $btns[] = [['text' => '⏩ Пропустить', 'callback_data' => 'f_goal_skip'],['text' => '⬅️ Назад', 'callback_data' => "f_type_".$tempData['type']]];
            renderView($chatId, "Связать с целью?", ['inline_keyboard' => $btns], $user, $pdo, true);
        }
        return;
    }

    // --- 3. ДОБАВЛЕНИЕ НОВОЙ ЗАПИСИ ---
    if ($callbackData == 'fin_add') {
        updateUserState($userId, 'FIN_ADD_TYPE', [], $pdo);
        renderView($chatId, "Выберите тип:", ['inline_keyboard' => [[['text' => '💰 Доход', 'callback_data' => 'f_type_inc'], ['text' => '💸 Расход', 'callback_data' => 'f_type_exp']],[['text' => '⬅️ Назад', 'callback_data' => 'finance_menu']]]], $user, $pdo, true);
        return;
    }

    if (strpos($callbackData, 'f_type_') === 0) {
        $tempData['type'] = substr($callbackData, 7);
        updateUserState($userId, 'FIN_ADD_SUM', $tempData, $pdo);
        renderView($chatId, "Введите сумму:", ['inline_keyboard' => [[['text' => '⬅️ Назад', 'callback_data' => 'fin_add']]]], $user, $pdo, true);
        return;
    }

    if (strpos($callbackData, 'f_goal_') === 0) {
        $goalId = substr($callbackData, 7);
        $tempData['goal_id'] = ($goalId === 'skip') ? null : (int)$goalId;
        updateUserState($userId, 'FIN_ADD_PREVIEW', $tempData, $pdo);
        $cat = FIN_CATS[$tempData['type']][$tempData['cat']];
        $preview = "<b>Подтвердите:</b>\n\n".($tempData['type']=='inc'?'💰 Доход':'💸 Расход').": <b>{$tempData['sum']}</b>\nКат: {$cat['icon']} {$cat['name']}";
        $kb = ['inline_keyboard' => [[['text' => '💾 Сохранить', 'callback_data' => 'f_save_new']], [['text' => '⬅️ Назад', 'callback_data' => "f_cat_".$tempData['cat']]]]];
        renderView($chatId, $preview, $kb, $user, $pdo, true);
        return;
    }

    if ($callbackData == 'f_save_new') {
        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, category, goal_id) VALUES (?,?,?,?,?)")->execute([$userId, $tempData['type'], $tempData['sum'], $tempData['cat'], $tempData['goal_id'] ?? null]);
        processFinance($pdo, getUser($userId, $chatId, $pdo), $chatId, null, 'finance_menu', true);
        return;
    }

    // --- 4. РЕДАКТИРОВАНИЕ ---
    if ($callbackData == 'fin_edit') {
        $stmt = $pdo->prepare("SELECT id, type, amount, category FROM transactions WHERE user_id = ? AND MONTH(created_at) = MONTH(CURRENT_DATE()) ORDER BY created_at ASC");
        $stmt->execute([$userId]);
        $btns = [];
        foreach ($stmt->fetchAll() as $tr) {
            $txt = "✏️ ".($tr['type']=='inc'?'🟢':'🔴')." {$tr['amount']} | ".(FIN_CATS[$tr['type']][$tr['category']]['name'] ?? $tr['category']);
            $btns[] = [['text' => $txt, 'callback_data' => "f_edit_item_{$tr['id']}"]];
        }
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => 'finance_menu']];
        renderView($chatId, "Выберите для изменения:", ['inline_keyboard' => $btns], $user, $pdo, true);
        return;
    }

    if (strpos($callbackData, 'f_edit_item_') === 0) {
        $id = (int)substr($callbackData, 12); unset($tempData['pending_type']);
        $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?"); $stmt->execute([$id, $userId]);
        $tr = $stmt->fetch();
        if ($tr) {
            $tempData = ['edit_id' => $id, 'type' => $tr['type'], 'sum' => $tr['amount'], 'cat' => $tr['category'], 'goal_id' => $tr['goal_id']];
            updateUserState($userId, 'FIN_EDIT_CHOISE', $tempData, $pdo);
            $cat = FIN_CATS[$tr['type']][$tr['category']]['name'] ?? $tr['category'];
            $text = "✏️ <b>Карточка</b>\n\nТип: ".($tr['type']=='inc'?'💰 Доход':'💸 Расход')."\nСумма: <b>{$tr['amount']}</b>\nКат: $cat";
            $kb = ['inline_keyboard' => [[['text' => '🔄 Тип', 'callback_data' => 'f_edit_type'], ['text' => '💵 Сумма', 'callback_data' => 'f_edit_sum'], ['text' => '🏷 Кат.', 'callback_data' => 'f_edit_cat']],[['text' => '⬅️ Назад', 'callback_data' => 'fin_edit']]]];
            renderView($chatId, $text, $kb, $user, $pdo, true);
        }
        return;
    }

    if ($callbackData == 'f_edit_type') {
        $next = ($tempData['type'] == 'inc') ? 'exp' : 'inc';
        $tempData['pending_type'] = $next;
        updateUserState($userId, 'FIN_ADD_CAT', $tempData, $pdo);
        $btns = []; foreach (FIN_CATS[$next] as $k => $v) $btns[] = [['text' => $v['icon'].' '.$v['name'], 'callback_data' => "f_cat_$k"]];
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => "f_edit_item_".$tempData['edit_id']]];
        renderView($chatId, "⚠️ Тип сменится на ".($next=='inc'?'Доход':'Расход').". Выберите категорию:", ['inline_keyboard' => $btns], $user, $pdo, true);
        return;
    }

    if ($callbackData == 'f_edit_sum') {
        updateUserState($userId, 'FIN_ADD_SUM', $tempData, $pdo);
        renderView($chatId, "Новая сумма:", ['inline_keyboard' => [[['text' => '⬅️ Назад', 'callback_data' => "f_edit_item_".$tempData['edit_id']]]]], $user, $pdo, true);
        return;
    }

    if ($callbackData == 'f_edit_cat') {
        unset($tempData['pending_type']); updateUserState($userId, 'FIN_ADD_CAT', $tempData, $pdo);
        $btns = []; foreach (FIN_CATS[$tempData['type']] as $k => $v) $btns[] = [['text' => $v['icon'].' '.$v['name'], 'callback_data' => "f_cat_$k"]];
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => "f_edit_item_".$tempData['edit_id']]];
        renderView($chatId, "Новая категория:", ['inline_keyboard' => $btns], $user, $pdo, true);
        return;
    }

    // --- 5. УДАЛЕНИЕ ---
    if ($callbackData == 'fin_del' || strpos($callbackData, 'f_confirm_del_') === 0 || strpos($callbackData, 'f_execute_del_') === 0) {
        if ($callbackData == 'fin_del') {
            $stmt = $pdo->prepare("SELECT id, type, amount, category FROM transactions WHERE user_id = ? AND MONTH(created_at) = MONTH(CURRENT_DATE()) ORDER BY created_at ASC");
            $stmt->execute([$userId]);
            $btns = [];
            foreach ($stmt->fetchAll() as $tr) {
                $bt = ($tr['type']=='inc'?'🟢+':'🔴-')."{$tr['amount']} | ".(FIN_CATS[$tr['type']][$tr['category']]['name'] ?? $tr['category']);
                $btns[] = [['text' => $bt, 'callback_data' => "f_confirm_del_{$tr['id']}"]];
            }
            $btns[] = [['text' => '⬅️ Назад', 'callback_data' => 'finance_menu']];
            renderView($chatId, "Удалить:", ['inline_keyboard' => $btns], $user, $pdo, true);
        } elseif (strpos($callbackData, 'f_confirm_del_') === 0) {
            $id = (int)substr($callbackData, 14);
            renderView($chatId, "⚠️ Удалить?", ['inline_keyboard' => [[['text' => '🗑 Да', 'callback_data' => "f_execute_del_$id"]], [['text' => '⬅️ Назад', 'callback_data' => 'fin_del']]]], $user, $pdo, true);
        } else {
            $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?")->execute([(int)substr($callbackData, 14), $userId]);
            processFinance($pdo, getUser($userId, $chatId, $pdo), $chatId, null, 'finance_menu', true);
        }
        return;
    }
}