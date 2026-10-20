# TISU Edu

O'quv markazi platformasi: kurslar va darslar, testlar va sertifikat, guruhlar, davomat, to'lovlar, Telegram bot va Mini App.

| Qism | Texnologiya | Deploy |
|------|-------------|--------|
| `apps/web` | Next.js 15 | Vercel → `tisu.uzstudents.uz` |
| `apps/backend` | Laravel 12 + Sanctum + MySQL | myxvest → `api.tisu.uzstudents.uz` |

Biznes qoidalar: `apps/backend/app/Domain` (davomat, test baholash, to'lov, sertifikat, rollar).

## Lokal ishga tushirish
```bash
cd apps/backend && composer install && cp .env.example .env && php artisan key:generate && php artisan test
corepack pnpm install && corepack pnpm dev:web
```

Reja: [docs/PLAN.md](docs/PLAN.md). Deploy: [docs/DEPLOY.md](docs/DEPLOY.md).
