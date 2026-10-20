# TISU Edu

O'quv markazi platformasi: kurslar va darslar, testlar va sertifikat, guruhlar, davomat, to'lovlar, Telegram bot va Mini App.

| Qism | Joy | Deploy |
|------|-----|--------|
| `apps/web` | Next.js | Vercel → `tisu.uzstudents.uz` |
| `apps/api` | Fastify + Prisma (MySQL) | myxvest.ru → `api.tisu.uzstudents.uz` |
| `packages/core` | Biznes qoidalar (davomat, test, to'lov, sertifikat) | — |

## Ishga tushirish
```bash
corepack pnpm install
corepack pnpm test
corepack pnpm dev:api
corepack pnpm dev:web
```

Reja: [docs/PLAN.md](docs/PLAN.md). Deploy: [docs/DEPLOY.md](docs/DEPLOY.md).
