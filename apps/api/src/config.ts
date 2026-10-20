import { z } from "zod";

const Env = z.object({
  NODE_ENV: z.enum(["development", "test", "production"]).default("development"),
  PORT: z.coerce.number().int().positive().default(3100),
  HOST: z.string().default("127.0.0.1"),
  DATABASE_URL: z.string().optional(),
  JWT_SECRET: z.string().min(32).optional(),
  CORS_ORIGIN: z.string().default("http://localhost:3000"),
  TELEGRAM_BOT_TOKEN: z.string().optional(),
  TELEGRAM_WEBHOOK_SECRET: z.string().optional(),
});

export type Config = z.infer<typeof Env>;

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const cfg = Env.parse(env);
  if (cfg.NODE_ENV === "production") {
    if (!cfg.DATABASE_URL) throw new Error("DATABASE_URL production'da majburiy");
    if (!cfg.JWT_SECRET) throw new Error("JWT_SECRET production'da majburiy (kamida 32 belgi)");
  }
  return cfg;
}
