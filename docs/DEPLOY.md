# Deploy

## Frontend (Vercel)
1. Vercel → Project → Settings → General → **Root Directory**: `apps/web`. Framework: Next.js.
2. Settings → Environment Variables: `NEXT_PUBLIC_API_URL=https://api.tisu.uzstudents.uz`.
3. Domen `tisu.uzstudents.uz` allaqachon ulangan. `main` ga har push → avtomatik deploy.

## Backend (myxvest.ru)
Bir martalik tayyorgarlik:
1. `~/.ssh/tisu_deploy.pub` kalitini hosting paneli → SSH Access → Import Key orqali qo'shing va **Authorize** qiling.
2. Faqat o'qiydigan tekshiruv:
   ```bash
   DEPLOY_HOST=<server> DEPLOY_USER=<login> ./deploy/server-audit.sh
   ```
3. Serverda `~/apps/tisu-api/shared/.env` yarating (`.env.example` asosida), `chmod 600`.
4. DNS: `api.tisu.uzstudents.uz` A-yozuv → server IP. Hosting panelida subdomen yaratib, uni `127.0.0.1:3100` ga proxy qilish (yoki "Setup Node.js App").

Har deploy:
```bash
DEPLOY_HOST=<server> DEPLOY_USER=<login> ./deploy/deploy.sh
```
Skript zaxira oladi, migratsiya qiladi, `current` ni almashtiradi, `/health` muvaffaqiyatsiz bo'lsa avtomatik orqaga qaytaradi.

## Serverdagi boshqa loyihalar
Skriptlar faqat `~/apps/tisu-api/` va `tisu-api` nomli pm2 jarayoniga tegadi.
Mavjud saytlar, crontab, `.htaccess`, boshqa bazalar o'zgartirilmaydi.
