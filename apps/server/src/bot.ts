import { Bot, Context, InlineKeyboard } from "grammy";
import type { PrismaClient, User } from "@prisma/client";
import { handleHabitsCallback, handleHabitsText } from "./habits.js";

export type BotDeps = {
  token: string;
  prisma: PrismaClient;
};

export type BotContext = Context & { prisma: PrismaClient; dbUser?: User };

export function createBot(deps: BotDeps) {
  const bot = new Bot<BotContext>(deps.token);

  // прокидываем prisma и пользователя в контекст
  bot.use(async (ctx, next) => {
    (ctx as BotContext).prisma = deps.prisma;
    if (ctx.from && ctx.chat) {
      const user = await ensureUser(deps.prisma, ctx.from.id, ctx.chat.id);
      (ctx as BotContext).dbUser = user;
    }
    await next();
  });

  // Простейший лог всех апдейтов, чтобы отладить, что доходит до бота
  bot.use(async (ctx, next) => {
    console.log("[BOT] update", {
      fromId: ctx.from?.id,
      chatId: ctx.chat?.id,
      text: "message" in ctx.update ? ctx.update.message?.text : undefined,
      hasCallback: Boolean("callback_query" in ctx.update),
    });
    try {
      await next();
    } catch (err) {
      console.error("[BOT] handler error", err);
      throw err;
    }
  });

  bot.command("start", async (ctx) => {
    if (!ctx.dbUser) return;
    await ctx.prisma.user.update({
      where: { id: ctx.dbUser.id },
      data: { state: "MAIN_MENU", tempData: null },
    });

    const kb = mainMenuKeyboard();

    await ctx.reply("🏠 Главное меню", {
      reply_markup: kb,
      parse_mode: "HTML",
    });
  });

  bot.on("callback_query:data", async (ctx) => {
    const data = ctx.callbackQuery.data;
    await ctx.answerCallbackQuery();

    // роутинг по разделам
    if (data === "main_menu") {
      const kb = mainMenuKeyboard();
      await ctx.reply("🏠 Главное меню", { reply_markup: kb, parse_mode: "HTML" });
      return;
    }

    const handledHabits = await handleHabitsCallback(ctx, data);
    if (handledHabits) return;

    // заглушка по умолчанию
    await ctx.reply(
      `Нажато: <b>${escapeHtml(data)}</b>\n\nЭта функция пока не реализована в TS-боте.`,
      { parse_mode: "HTML" },
    );
  });

  bot.on("message:text", async (ctx) => {
    const text = ctx.message.text;
    const handledHabits = await handleHabitsText(ctx, text);
    if (handledHabits) return;
    // Остальной свободный текст пока игнорируем или можно добавить help
  });

  return bot;
}

async function ensureUser(prisma: PrismaClient, fromId: number, chatId: number) {
  const id = BigInt(fromId);
  const cId = BigInt(chatId);
  let user = await prisma.user.findUnique({ where: { id } });
  if (!user) {
    user = await prisma.user.create({
      data: {
        id,
        chatId: cId,
      },
    });
  }
  return user;
}

export function escapeHtml(s: string): string {
  return s
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function mainMenuKeyboard() {
  return new InlineKeyboard()
    .text("💎 Привычки", "habits_menu")
    .row()
    .text("🎯 Цели", "goals_menu")
    .row()
    .text("💰 Финансы", "finance_menu")
    .row()
    .url("Открыть Mini App", "https://example.com");
}

