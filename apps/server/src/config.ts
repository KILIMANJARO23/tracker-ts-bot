import { z } from "zod";
import dotenv from "dotenv";

dotenv.config();

const EnvSchema = z.object({
  PORT: z.coerce.number().int().positive().default(3000),
  TELEGRAM_BOT_TOKEN: z.string().min(1),
  JWT_SECRET: z.string().min(32),
  DATABASE_URL: z.string().min(1),
  // для Telegram WebApp initData проверки:
  TELEGRAM_WEBAPP_ALLOW_STALE_SECONDS: z.coerce.number().int().positive().default(24 * 60 * 60),
});

export type Env = z.infer<typeof EnvSchema>;

export const env: Env = EnvSchema.parse(process.env);

