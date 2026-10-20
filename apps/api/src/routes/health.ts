import type { FastifyInstance } from "fastify";

export interface HealthDeps {
  version: string;
  /** Baza bilan aloqa tekshiruvi; yo'q bo'lsa "skipped". */
  pingDb?: () => Promise<void>;
}

export async function healthRoutes(app: FastifyInstance, deps: HealthDeps) {
  app.get("/health", async (_req, reply) => {
    let db: "ok" | "down" | "skipped" = "skipped";
    if (deps.pingDb) {
      try {
        await deps.pingDb();
        db = "ok";
      } catch (err) {
        app.log.error({ err }, "db ping failed");
        db = "down";
      }
    }
    const status = db === "down" ? "degraded" : "ok";
    return reply.code(status === "ok" ? 200 : 503).send({ status, version: deps.version, db, time: new Date().toISOString() });
  });
}
