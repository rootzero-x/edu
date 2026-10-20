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
4. **.env.** Parolni bitta tirnoqda yozing: `DB_PASSWORD='...'` (`$` va `#` belgilari uchun). `~/www/api.tisu.uzstudents.uz/.env` ni `apps/backend/.env.example` asosida yarating (`chmod 600`).
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

## Cron (avtomatik vazifalar) — majburiy
Shared hostingda doimiy worker yo'q: hamma fon ishi (Telegram xabarlari navbati, eslatmalar, darslarni yaratish,
zaxira, to'lov hisoblari) **har daqiqadagi bitta cron** orqali ishlaydi. ISPmanager → Cron:
```bash
* * * * * /opt/alt/php83/usr/bin/php ~/www/api.tisu.uzstudents.uz/artisan schedule:run >> /dev/null 2>&1
```
- `schedule:run` har daqiqada yurak urishini yozadi; admin bosh sahifasidagi **«Tizim holati»** kartasi va
  `GET /health` (`"cron": "ok" | "stale" | "never"`) shunga qaraydi.
- Cron `automation.heartbeat_stale_minutes` (standart 10) daqiqa jim bo'lsa yoki biror vazifa kutilgan oralig'ining
  2 baravaridan ko'p muvaffaqiyatli ishlamasa — admin Telegram chatlariga ogohlantirish (soatiga bir marta).
- Telegram xabarlari `telegram_outbox` navbatidan `tisu:outbox-drain` bilan yuboriladi (≈40 soniya, 25 xabar/soniya,
  429 da `retry_after` kutiladi, botni bloklaganlar belgilanib o'tkazib yuboriladi). Qo'lda: `php artisan tisu:outbox-drain`.

## Zaxira: shifrlash va serverdan tashqariga
Har kuni 02:30 da `tisu:backup`: `mysqldump` → `~/tisu-backups/db-daily-*.sql.gz.enc` (AES-256-GCM) →
admin Telegram chat(lar)iga hujjat sifatida (50 MB dan kattasi `.part1of3` … bo'laklarga bo'linadi).
Ochiq (shifrlanmagan) nusxa serverda qolmaydi va hech qachon tashqariga yuborilmaydi.

**Bir martalik sozlash:**
1. Kalit: lokal yoki serverda `php artisan tisu:backup-key` → chiqqan `BACKUP_ENCRYPTION_KEY=base64:...` ni
   serverdagi `.env` ga qo'ying va **parol menejerida ham saqlang** (server yo'qolsa, kalitsiz Telegram'dagi
   zaxirani ochib bo'lmaydi). Keyin `php artisan config:cache`.
2. `telegram.admin_chat_ids` da faol admin chati bo'lsin (zaxira shu chatlarga keladi).
3. Tekshiruv: `php artisan tisu:backup --force` → Telegram'ga fayl kelishi kerak; `php artisan tisu:backup-list`.

Sozlamalar (Sozlamalar → Avtomatik vazifalar): `backup.enabled`, `backup.keep_days` (lokal, standart 14),
`backup.offsite_telegram`, `backup.offsite_require_encryption`, `backup.telegram_part_mb`, `backup.offsite_max_mb`.

### Tiklash mashqi (oyiga bir marta, ~10 daqiqa)
Maqsad: zaxira haqiqatan ochiladi va to'liq bazaga aylanadi. Ishlayotgan bazaga **tegmaydi**.
1. ISPmanager → Bazalar: bo'sh mashq bazasi yarating (masalan `tisu_restore_drill`), foydalanuvchiga ruxsat bering.
2. Telegram admin chatidan oxirgi zaxirani (barcha bo'laklarini) yuklab, serverga ko'chiring, masalan `~/drill/`.
   (Yoki serverdagi `~/tisu-backups/db-daily-*.sql.gz.enc` dan foydalaning.)
3. Tekshiruv (bazaga hech narsa yozilmaydi):
   ```bash
   php artisan tisu:backup-restore ~/drill/db-daily-*.enc.part1of2 ~/drill/db-daily-*.enc.part2of2 --dry-run
   ```
   SHA-256 Telegram izohidagi bilan bir xil, "Shifr ochildi", jadvallar ro'yxati va INSERT soni chiqishi kerak.
4. Mashq bazasiga tiklash:
   ```bash
   php artisan tisu:backup-restore ~/drill/<fayl yoki bo'laklar> --into=tisu_restore_drill --confirm
   ```
5. Solishtirish: `mysql tisu_restore_drill -e "select count(*) from users; select max(created_at) from audit_logs;"` —
   son va oxirgi sana kutilganga yaqin bo'lsin. Keyin mashq bazasini o'chiring va `~/drill/` ni tozalang.
6. Natijani (sana, fayl, OK/xato) yozib qo'ying.

**Haqiqiy tiklash** (server yoki baza yo'qolganda): yangi bazaga yuqoridagidek tiklang va `.env` dagi `DB_DATABASE`
ni almashtiring. Joriy bazaning ustiga yozish faqat qo'shimcha `--live` bilan mumkin (avval saytni `artisan down` qiling).

## Sprint 1 deploy eslatmalari (bir martalik)
- `.env`: `BACKUP_ENCRYPTION_KEY` (yuqoriga qarang); ixtiyoriy `BACKUP_DIR`.
- `migrate --force` (deploy skripti qiladi): `system_status`, `telegram_outbox`, `telegram_batches`,
  `telegram_links.blocked_at`, `users.pending_at`, `certificates.revoked_*`, `questions.archived_at`.
- Cron yuqoridagidek turganini tekshiring (endi Telegram xabarlari ham shunga bog'liq).
- Eski raqamlarni normal shaklga keltirish: `php artisan tisu:users-duplicates` (hisobot) → `--fix`.
- O'qituvchi huquqlari: agar `roles.permissions` bazada qayta yozilgan bo'lsa, `TEACHER` da `course.manage` qolgan
  bo'lishi mumkin — «Tizim holati» va Sozlamalar sahifasi ogohlantiradi; `course.author` ga almashtiring.
- Bot orqali ro'yxatdan o'tish endi ariza (admin tasdiqlaydi): `telegram.self_registration_approval`.

## Orqaga qaytarish
```bash
cd ~/www/api.tisu.uzstudents.uz && tar -xzf ~/tisu-backups/code-<REL>.tgz
gunzip -c ~/tisu-backups/db-<REL>.sql.gz | mysql -u <user> -p <db>
```

## Boshqa loyihalar xavfsizligi
Skript faqat `~/www/api.tisu.uzstudents.uz` va `~/tisu-backups` ga yozadi.
`api.hast.uz`, `api.onlinecompiler.uz`, boshqa bazalar, crontab va `.htaccess`'larga tegilmaydi.
