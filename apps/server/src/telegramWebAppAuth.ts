import crypto from "node:crypto";

type InitDataMap = Record<string, string>;

function parseInitData(initData: string): InitDataMap {
  const out: InitDataMap = {};
  const params = new URLSearchParams(initData);
  for (const [k, v] of params.entries()) out[k] = v;
  return out;
}

function buildDataCheckString(map: InitDataMap): string {
  const pairs: string[] = [];
  for (const [k, v] of Object.entries(map)) {
    if (k === "hash") continue;
    pairs.push(`${k}=${v}`);
  }
  pairs.sort((a, b) => a.localeCompare(b));
  return pairs.join("\n");
}

function hmacSha256Hex(key: Buffer | string, data: string): string {
  return crypto.createHmac("sha256", key).update(data).digest("hex");
}

function getWebAppSecretKey(botToken: string): Buffer {
  // secret_key = HMAC_SHA256(key="WebAppData", data=bot_token)
  return crypto.createHmac("sha256", "WebAppData").update(botToken).digest();
}

export type TelegramWebAppUser = {
  id: number;
  first_name?: string;
  last_name?: string;
  username?: string;
  language_code?: string;
};

export type VerifyInitDataResult =
  | { ok: true; user: TelegramWebAppUser; authDate: number; raw: InitDataMap }
  | { ok: false; reason: string };

export function verifyTelegramInitData(args: {
  initData: string;
  botToken: string;
  allowStaleSeconds: number;
  nowSeconds?: number;
}): VerifyInitDataResult {
  const { initData, botToken, allowStaleSeconds } = args;
  const nowSeconds = args.nowSeconds ?? Math.floor(Date.now() / 1000);

  if (!initData || initData.trim() === "") return { ok: false, reason: "initData пустой" };

  const map = parseInitData(initData);
  const hash = map.hash;
  if (!hash) return { ok: false, reason: "нет hash" };

  const authDate = Number(map.auth_date);
  if (!Number.isFinite(authDate)) return { ok: false, reason: "нет/битый auth_date" };
  if (nowSeconds - authDate > allowStaleSeconds) return { ok: false, reason: "initData устарел" };

  const dcs = buildDataCheckString(map);
  const secretKey = getWebAppSecretKey(botToken);
  const expected = hmacSha256Hex(secretKey, dcs);

  // тайминг-безопасное сравнение
  const a = Buffer.from(expected, "hex");
  const b = Buffer.from(hash, "hex");
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
    return { ok: false, reason: "hash не совпал" };
  }

  const userJson = map.user;
  if (!userJson) return { ok: false, reason: "нет user" };

  let user: TelegramWebAppUser;
  try {
    user = JSON.parse(userJson) as TelegramWebAppUser;
  } catch {
    return { ok: false, reason: "user не JSON" };
  }
  if (!user?.id) return { ok: false, reason: "user.id отсутствует" };

  return { ok: true, user, authDate, raw: map };
}

