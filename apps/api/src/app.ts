import Fastify from "fastify";
import cors from "@fastify/cors";
import helmet from "@fastify/helmet";
import rateLimit from "@fastify/rate-limit";
import type { Config } from "./config.ts";
import { healthRoutes, type HealthDeps } from "./routes/health.ts";

export const VERSION = "0.1.0";

export async function buildApp(cfg: Config, deps: Partial<HealthDeps> = {}) {
  const app = Fastify({
    logger: cfg.NODE_ENV === "test" ? false : { level: cfg.NODE_ENV === "production" ? "info" : "debug" },
    trustProxy: true,
  });

  await app.register(helmet);
  await app.register(cors, {
    origin: cfg.CORS_ORIGIN.split(",").map((s) => s.trim()),
    credentials: true,
  });
  await app.register(rateLimit, { max: 300, timeWindow: "1 minute" });

  await app.register(healthRoutes, { version: VERSION, ...deps });
  app.get("/", async () => ({ name: "TISU Edu API", version: VERSION }));

  return app;
}
