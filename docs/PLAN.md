# TISU Edu platforma — professional reja

## Context
O'quv markazi (TISU) uchun edu platforma: kurslar/darslar, testlar/baholash/sertifikat, guruhlar/davomat/to'lovlar, Telegram bot + Mini App.
- Kod: `/home/rootzero-x/Edu` (bo'sh) → `git init`, `git remote add origin https://github.com/rootzero-x/edu.git` → `main` ga push.
- SSH: kalit orqali (tasdiqlangan). API domeni: `api.tisu.uzstudents.uz` (tasdiqlangan).
- Frontend: Next.js → Vercel, domen `tisu.uzstudents.uz` (Vercel'ga allaqachon ulangan).
- Backend: myxvest.ru hostingida (SSH akkaunt va baza nomi — shared/.env da).
- Serverda boshqa real loyihalar ishlayapti → ularga hech narsa tegmasligi kerak.

## Xavfsizlik qoidalari (majburiy)
- Parollar chatga yozildi → ishga tushirgandan keyin **ikkalasini almashtiring** (hosting panelidan).
- Men parol bilan kirmayman. Siz SSH kalitni qo'shasiz: men `~/.ssh/tisu_deploy` kalit juftini yarataman, siz public kalitni hosting paneli (SSH Keys) yoki `ssh-copy-id` orqali qo'shasiz.
- Baza paroli faqat serverdagi `.env` faylida (chmod 600) — gitga hech qachon tushmaydi. Siz uni o'zingiz yozasiz yoki men `.env.example` beraman.

## Serverda izolyatsiya (boshqa loyihalarni buzmaslik)
1. Avval faqat **o'qish**: `whoami`, `ls ~`, `node -v`, `ps`, band portlar, cPanel/Node.js App mavjudligi, baza turi (MySQL yoki Postgres). Hech narsa o'zgartirilmaydi.
2. Hammasi bitta katalogda: `~/apps/tisu-api/` (releases/, shared/.env, current symlink). Mavjud fayl/katalog, crontab, nginx/apache, `.htaccess`, boshqa bazalarga tegilmaydi.
3. Node: hostingning "Setup Node.js App" (Passenger) bo'lsa — o'sha orqali alohida ilova; aks holda `nvm` foydalanuvchi katalogida + `pm2` alohida nom bilan, bo'sh port.
4. API subdomeni: `api.tisu.uzstudents.uz` (DNS A-yozuv → myxvest server, SSL — hosting AutoSSL/Let's Encrypt).
5. Baza: faqat o'z bazamiz ichida migratsiyalar; har deploydan oldin `mysqldump`/`pg_dump` zaxira.
6. Rollback: `current` symlinkni oldingi releasega qaytarish.

## Stack
- Monorepo (npm workspaces): `apps/api` (Fastify 5 + TypeScript), `apps/web` (Next.js 15, App Router — admin, o'qituvchi, o'quvchi kabineti + Telegram Mini App sahifalari `/tg/*`), `apps/bot` (grammY, API bilan bitta jarayonda webhook rejimida), `packages/core` (biznes qoidalar), `packages/contract` (OpenAPI + zod sxemalar).
- ORM: Prisma (MySQL va Postgres ikkalasini qo'llaydi — server tekshiruvidan keyin tanlanadi).
- Auth: telefon + parol / OTP, Telegram `initData` tekshiruvi, JWT access + refresh (httpOnly cookie), RBAC: super-admin, admin, o'qituvchi, o'quvchi, ota-ona.
- Fayllar: video — YouTube/Vimeo unlisted havola yoki S3-mos saqlash; kichik fayllar serverda `shared/uploads`.
- Sertifikat PDF: pdfkit + QR tekshirish sahifasi.

## Ma'lumotlar modeli (asosiy)
users, branches, courses, modules, lessons, lesson_materials, groups, group_members, schedules, sessions, attendance, quizzes, questions, options, attempts, answers, grades, certificates, payments, invoices, discounts, notifications, telegram_links, audit_log.

## Bosqichlar
| # | Ish | Done mezoni |
|---|-----|-------------|
| M0 | Repo, monorepo, lint/test, GitHub Actions CI, `.env.example`, docs/ | `npm test` yashil, push qilingan |
| M1 | Server tekshiruvi (faqat o'qish), izolyatsiyalangan API deploy, `/health` | `https://api.tisu.uzstudents.uz/health` ishlaydi, boshqa saytlar o'zgarmagan |
| M2 | Auth + RBAC + foydalanuvchilar | login/refresh testlari |
| M3 | Kurslar, modullar, darslar, materiallar | admin kurs yaratadi, o'quvchi ko'radi |
| M4 | Guruhlar, jadval, davomat | o'qituvchi davomat belgilaydi |
| M5 | Testlar, baholash, sertifikat | test topshirish → ball → PDF sertifikat |
| M6 | To'lovlar, qarzdorlik, hisobotlar (Click/Payme keyin) | oylik hisob to'g'ri |
| M7 | Telegram bot + Mini App | eslatmalar, ota-onaga davomat xabari |
| M8 | Web sayqal, uz/ru, prod tekshiruv | Vercel prod `tisu.uzstudents.uz` |

## Deploy oqimi
- Frontend: GitHub push → Vercel (Root Directory `apps/web`, env `NEXT_PUBLIC_API_URL=https://api.tisu.uzstudents.uz`).
- Backend: GitHub Actions yoki lokal skript `deploy/deploy.sh` → SSH kalit bilan `rsync` releases/ ga → `npm ci --omit=dev` → `prisma migrate deploy` (zaxiradan keyin) → restart → health-check, xato bo'lsa avtomatik rollback.
- CORS: faqat `https://tisu.uzstudents.uz`.

## Tekshirish
- Lokal: `npm test`, `npm run build` (api + web).
- Deploydan oldin va keyin serverdagi boshqa saytlar HTTP statusi solishtiriladi.
- `/health`, login, kurs yaratish, Mini App ochilishi — qo'lda smoke test.
