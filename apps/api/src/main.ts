import { PrismaClient } from "@prisma/client";
import { buildApp } from "./app.ts";
import { loadConfig } from "./config.ts";

const cfg = loadConfig();
const prisma = cfg.DATABASE_URL ? new PrismaClient() : null;

const app = await buildApp(cfg, {
  pingDb: prisma ? async () => { await prisma.$queryRaw`SELECT 1`; } : undefined,
});

const shutdown = async () => {
  await app.close();
  await prisma?.$disconnect();
  process.exit(0);
};
process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);

await app.listen({ port: cfg.PORT, host: cfg.HOST });
