import type { PrismaClient } from "@prisma/client";
import { InlineKeyboard } from "grammy";
import type { BotContext } from "./bot.js";
import { escapeHtml } from "./bot.js";

const DAYS_MAP: Record<number, string> = {
  1: "Пн",
  2: "Вт",
  3: "Ср",
  4: "Чт",
  5: "Пт",
  6: "Сб",
  7: "Вс",
};

type TempData = {
  title?: string;
  days?: number[];
  edit_id?: number;
};

export async function handleHabitsCallback(ctx: BotContext, data: string): Promise<boolean> {
  if (!ctx.dbUser) return false;

  const user = await ctx.prisma.user.findUnique({ where: { id: ctx.dbUser.id } });
  if (!user) return false;

  const temp: TempData = (user.tempData as any) ?? {};
  const userId = user.id;

  // Главное меню привычек
  if (data === "habits_menu") {
    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABITS_MENU", tempData: null },
    });
    const text = await getHabitsText(ctx.prisma, userId);
    const kb = habitsMenuKeyboard();
    await ctx.reply(text, { reply_markup: kb, parse_mode: "HTML" });
    return true;
  }

  // Добавление: шаг 1 — ввод названия
  if (data === "habit_add_start") {
    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABIT_ADD_NAME", tempData: {} },
    });
    await ctx.reply("✍️ Введите название привычки:", {
      reply_markup: backToHabitsKb(),
      parse_mode: "HTML",
    });
    return true;
  }

  // Добавление: шаг 2 — выбор дней
  if (
    data.startsWith("habit_day_toggle_") ||
    data === "habit_days_all" ||
    data === "render_days" ||
    data === "habit_back_to_days"
  ) {
    const days = new Set<number>(temp.days ?? []);

    if (data.startsWith("habit_day_toggle_")) {
      const day = Number(data.replace("habit_day_toggle_", ""));
      if (days.has(day)) days.delete(day);
      else days.add(day);
    } else if (data === "habit_days_all") {
      Object.keys(DAYS_MAP).forEach((k) => days.add(Number(k)));
    }

    const nextTemp: TempData = {
      ...temp,
      days: Array.from(days.values()).sort((a, b) => a - b),
    };

    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABIT_ADD_DAYS", tempData: nextTemp },
    });

    const kb = buildDaysKeyboard(nextTemp.days ?? [], "habit_day_toggle_", "habit_add_start", "habit_add_notify");

    const text = `🗓 Выберите дни для <b>${escapeHtml(nextTemp.title ?? "привычки")}</b>:`;

    // При выборе дней обновляем существующее сообщение, а не шлём новое
    await ctx.editMessageText(text, { reply_markup: kb, parse_mode: "HTML" });
    return true;
  }

  // Добавление: шаг 3 — уведомления
  if (data === "habit_add_notify") {
    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABIT_ADD_NOTIFY", tempData: temp },
    });
    const kb = new InlineKeyboard()
      .text("Вкл 🔔", "h_save_1")
      .text("Выкл 🔕", "h_save_0")
      .row()
      .text("⬅️ Назад", "habit_back_to_days");
    await ctx.reply(
      `🔔 Включить уведомления для <b>${escapeHtml(temp.title ?? "привычки")}</b>?`,
      { reply_markup: kb, parse_mode: "HTML" },
    );
    return true;
  }

  // Финал: сохранение
  if (data.startsWith("h_save_")) {
    const notify = data.endsWith("1");
    const daysArr = (temp.days ?? []).map((d) => d.toString());
    await ctx.prisma.habit.create({
      data: {
        userId,
        title: temp.title ?? "Без названия",
        days: daysArr.join(","),
        notify,
      },
    });
    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABITS_MENU", tempData: null },
    });
    const text = await getHabitsText(ctx.prisma, userId);
    const kb = habitsMenuKeyboard();
    await ctx.reply(`✅ Привычка сохранена.\n\n${text}`, {
      reply_markup: kb,
      parse_mode: "HTML",
    });
    return true;
  }

  // --- УДАЛЕНИЕ ---
  // список для удаления
  if (data === "habit_delete_list") {
    const habits = await ctx.prisma.habit.findMany({
      where: { userId },
      orderBy: { title: "asc" },
    });
    if (!habits.length) {
      await ctx.reply("Список привычек пуст.", {
        reply_markup: backToHabitsKb(),
        parse_mode: "HTML",
      });
      return true;
    }
    const kb = new InlineKeyboard();
    for (const h of habits) {
      kb.row().text(`🗑 ${h.title}`, `hdel_conf_${h.id}`);
    }
    kb.row().text("⬅️ Назад", "habits_menu");
    await ctx.reply("Выберите привычку, которую хотите <b>удалить</b>:", {
      reply_markup: kb,
      parse_mode: "HTML",
    });
    return true;
  }

  // шаг подтверждения
  if (data.startsWith("hdel_conf_")) {
    const id = Number(data.replace("hdel_conf_", ""));
    const habit = await ctx.prisma.habit.findFirst({ where: { id, userId } });
    if (!habit) {
      // перерисовываем список
      return await handleHabitsCallback(ctx, "habit_delete_list");
    }
    const kb = new InlineKeyboard()
      .text("✅ Да, удалить", `hdel_do_${id}`)
      .text("❌ Нет, отмена", "habit_delete_list");
    await ctx.reply(
      `⚠️ Вы уверены, что хотите удалить привычку: <b>${escapeHtml(habit.title)}</b>?`,
      { reply_markup: kb, parse_mode: "HTML" },
    );
    return true;
  }

  // само удаление
  if (data.startsWith("hdel_do_")) {
    const id = Number(data.replace("hdel_do_", ""));
    await ctx.prisma.habit.deleteMany({ where: { id, userId } });

    const habits = await ctx.prisma.habit.findMany({
      where: { userId },
      orderBy: { title: "asc" },
    });
    const kb = new InlineKeyboard();
    for (const h of habits) {
      kb.row().text(`🗑 ${h.title}`, `hdel_conf_${h.id}`);
    }
    kb.row().text("⬅️ Назад", "habits_menu");

    const suffix = habits.length
      ? "Выберите следующую для удаления:"
      : "Больше привычек нет.";
    const text = `🗑 Привычка удалена.\n${suffix}`;
    await ctx.reply(text, { reply_markup: kb, parse_mode: "HTML" });
    return true;
  }

  // --- РЕДАКТИРОВАНИЕ ---
  if (data === "habit_edit_list") {
    const habits = await ctx.prisma.habit.findMany({
      where: { userId },
      orderBy: { title: "asc" },
    });
    if (!habits.length) {
      await ctx.reply("Нет привычек.", {
        reply_markup: backToHabitsKb(),
        parse_mode: "HTML",
      });
      return true;
    }
    const kb = new InlineKeyboard();
    for (const h of habits) {
      kb.row().text(h.title, `hedit_sel_${h.id}`);
    }
    kb.row().text("⬅️ Назад", "habits_menu");
    await ctx.reply("✏️ Выберите для редактирования:", {
      reply_markup: kb,
      parse_mode: "HTML",
    });
    return true;
  }

  // меню редактирования конкретной привычки
  if (
    data.startsWith("hedit_sel_") ||
    data === "hedit_refresh" ||
    data === "hedit_toggle_n"
  ) {
    const editId =
      data.startsWith("hedit_sel_") && data !== "hedit_refresh"
        ? Number(data.replace("hedit_sel_", ""))
        : temp.edit_id;
    if (!editId) return true;

    if (data === "hedit_toggle_n") {
      await ctx.prisma.habit.updateMany({
        where: { id: editId, userId },
        data: { notify: { set: undefined }, notify: undefined },
      });
      // так как toggle через updateMany сложнее, сделаем ручной toggle:
      const h = await ctx.prisma.habit.findFirst({ where: { id: editId, userId } });
      if (h) {
        await ctx.prisma.habit.updateMany({
          where: { id: editId, userId },
          data: { notify: !h.notify },
        });
      }
    }

    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABIT_EDIT_MENU", tempData: { edit_id: editId } },
    });

    await showEditHabitMenu(ctx, userId, editId, null);
    return true;
  }

  if (data === "hedit_title") {
    if (!temp.edit_id) return true;
    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABIT_EDIT_WAIT_T", tempData: temp },
    });
    const kb = new InlineKeyboard().text("⬅️ Отмена", "hedit_refresh");
    await ctx.reply("✍️ Введите новое название:", {
      reply_markup: kb,
      parse_mode: "HTML",
    });
    return true;
  }

  // редактирование дней (аналогично добавлению)
  if (data === "hedit_days_st" || data.startsWith("hedit_day_toggle_")) {
    if (!temp.edit_id) return true;
    let daysSet = new Set<number>(temp.days ?? []);

    if (data === "hedit_days_st") {
      const h = await ctx.prisma.habit.findFirst({
        where: { id: temp.edit_id, userId },
      });
      if (!h) return true;
      daysSet = new Set(
        h.days
          .split(",")
          .filter((x) => x.length)
          .map((d) => Number(d)),
      );
    } else {
      const day = Number(data.replace("hedit_day_toggle_", ""));
      if (daysSet.has(day)) daysSet.delete(day);
      else daysSet.add(day);
    }

    const nextTemp: TempData = {
      ...temp,
      days: Array.from(daysSet.values()).sort((a, b) => a - b),
    };

    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABIT_EDIT_DAYS", tempData: nextTemp },
    });

    const kb = buildDaysKeyboard(
      nextTemp.days ?? [],
      "hedit_day_toggle_",
      "hedit_refresh",
      "hedit_days_save",
    );

    // Для редактирования тоже обновляем то же сообщение
    await ctx.editMessageText("📅 Изменение дней:", {
      reply_markup: kb,
      parse_mode: "HTML",
    });
    return true;
  }

  if (data === "hedit_days_save") {
    if (!temp.edit_id || !temp.days) return true;
    await ctx.prisma.habit.updateMany({
      where: { id: temp.edit_id, userId },
      data: { days: temp.days.map((d) => d.toString()).join(",") },
    });
    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABIT_EDIT_MENU", tempData: { edit_id: temp.edit_id } },
    });
    await showEditHabitMenu(ctx, userId, temp.edit_id, "Дни обновлены.");
    return true;
  }

  return false;
}

export async function handleHabitsText(ctx: BotContext, text: string): Promise<boolean> {
  if (!ctx.dbUser) return false;
  const user = await ctx.prisma.user.findUnique({ where: { id: ctx.dbUser.id } });
  if (!user) return false;

  const temp: TempData = (user.tempData as any) ?? {};
  const userId = user.id;

  // Шаг 1: ввод названия при добавлении
  if (user.state === "HABIT_ADD_NAME") {
    const nextTemp: TempData = { ...temp, title: text, days: temp.days ?? [] };
    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABIT_ADD_DAYS", tempData: nextTemp },
    });

    const kb = buildDaysKeyboard(nextTemp.days ?? [], "habit_day_toggle_", "habit_add_start", "habit_add_notify");

    await ctx.reply(
      `🗓 Выберите дни для <b>${escapeHtml(nextTemp.title ?? "привычки")}</b>:`,
      { reply_markup: kb, parse_mode: "HTML" },
    );
    return true;
  }

  // Редактирование названия
  if (user.state === "HABIT_EDIT_WAIT_T" && temp.edit_id) {
    await ctx.prisma.habit.updateMany({
      where: { id: temp.edit_id, userId },
      data: { title: text },
    });
    await ctx.prisma.user.update({
      where: { id: userId },
      data: { state: "HABIT_EDIT_MENU", tempData: { edit_id: temp.edit_id } },
    });
    await showEditHabitMenu(ctx, userId, temp.edit_id, "Название обновлено.");
    return true;
  }

  return false;
}

async function getHabitsText(prisma: PrismaClient, userId: bigint): Promise<string> {
  const habits = await prisma.habit.findMany({
    where: { userId },
    orderBy: { title: "asc" },
  });
  if (!habits.length) return "У вас пока нет добавленных привычек.";

  let text = "📌 <b>Ваши привычки:</b>\n\n";
  for (const h of habits) {
    const daysArr = h.days.split(",").map((d) => Number(d));
    const daysStr = daysArr
      .map((d) => DAYS_MAP[d])
      .filter(Boolean)
      .join(", ");
    const icon = h.notify ? "🔔" : "🔕";
    text += `<b>${escapeHtml(h.title)}</b>\n- (${daysStr}) ${icon}\n\n`;
  }
  return text;
}

function habitsMenuKeyboard() {
  const kb = new InlineKeyboard()
    .text("➕ Добавить", "habit_add_start")
    .row()
    .text("✏️ Редактировать", "habit_edit_list")
    .row()
    .text("🗑 Удалить", "habit_delete_list")
    .row()
    .text("⬅️ Назад", "main_menu");
  return kb;
}

function backToHabitsKb() {
  return new InlineKeyboard().text("⬅️ Назад", "habits_menu");
}

function buildDaysKeyboard(
  selected: number[],
  prefix: string,
  backCallback: string,
  nextCallback: string,
) {
  const kb = new InlineKeyboard();
  const selectedSet = new Set(selected);

  let row: { label: string; data: string }[] = [];
  for (const [idStr, name] of Object.entries(DAYS_MAP)) {
    const id = Number(idStr);
    const isOn = selectedSet.has(id);
    row.push({ label: `${isOn ? "✅ " : ""}${name}`, data: `${prefix}${id}` });
    if (row.length === 4) {
      kb.row();
      for (const btn of row) kb.text(btn.label, btn.data);
      row = [];
    }
  }
  if (row.length) {
    kb.row();
    for (const btn of row) kb.text(btn.label, btn.data);
  }

  kb.row().text("📅 Выбрать все", "habit_days_all");

  kb.row().text("⬅️ Назад", backCallback);
  if (selected.length) {
    kb.text("Далее ➡️", nextCallback);
  }

  return kb;
}

async function showEditHabitMenu(
  ctx: BotContext,
  userId: bigint,
  habitId: number,
  notice: string | null,
) {
  const h = await ctx.prisma.habit.findFirst({ where: { id: habitId, userId } });
  if (!h) {
    await ctx.reply("Эта привычка не найдена.", { parse_mode: "HTML" });
    return;
  }
  const daysStr = h.days
    .split(",")
    .filter((x) => x.length)
    .map((d) => DAYS_MAP[Number(d)])
    .filter(Boolean)
    .join(", ");
  let text = "🛠 <b>Редактирование</b>\n\n";
  if (notice) text += `✅ ${notice}\n\n`;
  text += `<b>${escapeHtml(h.title)}</b>\n- (${daysStr}) ${h.notify ? "🔔" : "🔕"}`;

  const kb = new InlineKeyboard()
    .text("📝 Изменить название", "hedit_title")
    .row()
    .text("📅 Изменить дни", "hedit_days_st")
    .row()
    .text(`🔔 Уведомления: ${h.notify ? "ВКЛ" : "ВЫКЛ"}`, "hedit_toggle_n")
    .row()
    .text("⬅️ К списку", "habit_edit_list");

  await ctx.reply(text, { reply_markup: kb, parse_mode: "HTML" });
}

