# Deploy

## Arxitektura
| Qism | Texnologiya | Joy | Manzil |
|------|-------------|-----|--------|
| `apps/web` | Next.js 15 | Vercel | `tisu.uzstudents.uz` |
| `apps/backend` | Laravel 12, PHP 8.3, Sanctum | myxvest (ISPmanager, shared) | `api.tisu.uzstudents.uz` |
| Baza | MySQL | myxvest, faqat localhost | `694fc8f1e180d_tisu-uzstudents` |

myxvest — PHP shared hosting (CloudLinux LVE: 650 MiB RAM, 65 jarayon, 28% CPU).
Doimiy ishlaydigan Node jarayoni yo'q, shuning uchun backend PHP'da.

## Frontend (Vercel)
1. Settings → General → **Root Directory**: `apps/web`. Framework: Next.js.
2. Settings → Environment Variables: `NEXT_PUBLIC_API_URL=https://api.tisu.uzstudents.uz`.
3. `main` ga har push — avtomatik deploy.

## Backend: bir martalik tayyorgarlik
1. **SSH kalit.** ISPmanager → Shell client'da (yoki o'z terminalingizdan `ssh-copy-id`) quyidagini bajaring:
   ```bash
   mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo "<~/.ssh/tisu_deploy.pub mazmuni>" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys
   ```
2. **Sayt.** ISPmanager → Sites → Create: `api.tisu.uzstudents.uz`, PHP **8.3** (lsapi). Let's Encrypt SSL.
3. **DNS.** `api.tisu.uzstudents.uz` → A `176.9.111.172` (domen DNS'i turgan joyda).
4. **.env.** `/www/api.tisu.uzstudents.uz/.env` ni `apps/backend/.env.example` asosida yarating (`chmod 600`).
   `APP_KEY` uchun: `php artisan key:generate --show` (lokal), natijani qo'ying.
5. Tekshiruv (faqat o'qish):
   ```bash
   ./deploy/deploy-backend.sh --check
   ```

## Backend: har deploy
```bash
./deploy/deploy-backend.sh
```
Ketma-ketlik: lokal build (`composer --no-dev`) → yuklash → kod va baza zaxirasi (`~/tisu-backups`) →
`artisan down` → kod almashtirish (`.env`, `storage` saqlanadi) → `migrate --force` → kesh → `artisan up` → `/health`.

Birinchi admin: serverda `php artisan tisu:create-admin +99890XXXXXXX Ism` (parol interaktiv so'raladi).

## Orqaga qaytarish
```bash
cd /www/api.tisu.uzstudents.uz && tar -xzf ~/tisu-backups/code-<REL>.tgz
gunzip -c ~/tisu-backups/db-<REL>.sql.gz | mysql -u <user> -p <db>
```

## Boshqa loyihalar xavfsizligi
Skript faqat `/www/api.tisu.uzstudents.uz` va `~/tisu-backups` ga yozadi.
`api.hast.uz`, `api.onlinecompiler.uz`, boshqa bazalar, crontab va `.htaccess`'larga tegilmaydi.
