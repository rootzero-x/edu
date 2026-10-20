import { describe, expect, it } from "vitest";
import { buildApp } from "../src/app.ts";
import { loadConfig } from "../src/config.ts";

const cfg = loadConfig({ NODE_ENV: "test" });

describe("GET /health", () => {
  it("baza ulanmagan bo'lsa ok + skipped", async () => {
    const app = await buildApp(cfg);
    const res = await app.inject({ method: "GET", url: "/health" });
    expect(res.statusCode).toBe(200);
    expect(res.json()).toMatchObject({ status: "ok", db: "skipped" });
  });

  it("baza ishlamasa 503", async () => {
    const app = await buildApp(cfg, { pingDb: async () => { throw new Error("down"); } });
    const res = await app.inject({ method: "GET", url: "/health" });
    expect(res.statusCode).toBe(503);
    expect(res.json()).toMatchObject({ status: "degraded", db: "down" });
  });
});

describe("config", () => {
  it("production'da DATABASE_URL va JWT_SECRET majburiy", () => {
    expect(() => loadConfig({ NODE_ENV: "production" })).toThrow(/DATABASE_URL/);
  });
});
