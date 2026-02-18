import Fastify from "fastify";
import cors from "@fastify/cors";
import jwt from "@fastify/jwt";
import { z } from "zod";

import { env } from "./config.js";
import { createBot } from "./bot.js";
import { verifyTelegramInitData } from "./telegramWebAppAuth.js";
import { prisma } from "./db.js";

const app = Fastify({ logger: true });

await app.register(cors, {
  origin: true,
  credentials: true,
});

await app.register(jwt, {
  secret: env.JWT_SECRET,
});

const bot = createBot({ token: env.TELEGRAM_BOT_TOKEN, prisma });
// Инициализируем botInfo один раз, чтобы grammY знал данные бота
await bot.init();

// Webhook endpoint для Telegram.
app.post("/telegram/webhook", async (req, reply) => {
  // grammY ожидает объект update
  const update = req.body as unknown;
  try {
    await bot.handleUpdate(update as any);
  } catch (e) {
    req.log.error({ err: e }, "bot.handleUpdate failed");
    // Telegram будет ретраить — лучше отвечать 200, а ошибку логировать
  }
  return reply.code(200).send({ ok: true });
});

// Mini App: обмен initData -> JWT
app.post("/api/auth/telegram", async (req, reply) => {
  const Body = z.object({ initData: z.string().min(1) });
  const body = Body.parse(req.body);

  const verified = verifyTelegramInitData({
    initData: body.initData,
    botToken: env.TELEGRAM_BOT_TOKEN,
    allowStaleSeconds: env.TELEGRAM_WEBAPP_ALLOW_STALE_SECONDS,
  });

  if (!verified.ok) {
    return reply.code(401).send({ ok: false, error: verified.reason });
  }

  const token = await reply.jwtSign({
    sub: String(verified.user.id),
    telegramUserId: verified.user.id,
  });

  return reply.send({ ok: true, token, user: verified.user });
});

// Пример защищенного эндпоинта (дашборд Mini App)
app.get("/api/dashboard", async (req, reply) => {
  await req.jwtVerify();
  const claims = req.user as { telegramUserId?: number; sub?: string };
  const userId = BigInt(claims.telegramUserId ?? claims.sub ?? "0");

  const [habits, goalsCount, txCount] = await Promise.all([
    prisma.habit.findMany({
      where: { userId },
      select: { id: true, title: true, days: true, notify: true },
      orderBy: { title: "asc" },
    }),
    prisma.goal.count({ where: { userId } }),
    prisma.transaction.count({ where: { userId } }),
  ]);

  const habitsCount = habits.length;

  // агрегируем привычки по дням недели для графиков
  const weekdayCounts: Record<number, number> = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0, 6: 0, 7: 0 };
  for (const h of habits) {
    const days = h.days
      .split(",")
      .map((d) => Number(d))
      .filter((n) => Number.isFinite(n) && n >= 1 && n <= 7);
    for (const d of days) weekdayCounts[d]++;
  }

  const weekdays = [
    { day: "Пн", value: weekdayCounts[1] },
    { day: "Вт", value: weekdayCounts[2] },
    { day: "Ср", value: weekdayCounts[3] },
    { day: "Чт", value: weekdayCounts[4] },
    { day: "Пт", value: weekdayCounts[5] },
    { day: "Сб", value: weekdayCounts[6] },
    { day: "Вс", value: weekdayCounts[7] },
  ];

  return reply.send({
    ok: true,
    telegramUserId: claims.telegramUserId ?? claims.sub,
    stats: {
      habitsCount,
      goalsCount,
      transactionsCount: txCount,
    },
    habits,
    habitsByWeekday: weekdays,
  });
});

app.get("/health", async () => ({ ok: true }));

await app.listen({ port: env.PORT, host: "0.0.0.0" });

