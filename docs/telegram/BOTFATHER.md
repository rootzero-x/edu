# Telegram bot: profil va BotFather

Bot: **@edu_tisu_bot**. Bot tokeni va webhook siri faqat serverdagi `.env` faylida turadi (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`). Ularni bu yerga, gitga yoki chatga yozmang.

## Avtomatik sozlanadiganlar

Quyidagi buyruq hamma narsani sozlamalardan oladi va Bot API'ga qo'llaydi:

```bash
php artisan tisu:telegram-setup
```

| Nima | Sozlama | Bot API |
|---|---|---|
| Webhook (`message`, `callback_query`) | `.env`: `APP_URL`, `TELEGRAM_WEBHOOK_SECRET` | `setWebhook` |
| Bot nomi (uz/ru/en) | `telegram.profile.name` | `setMyName` |
| Qisqa tavsif / bio | `telegram.profile.short_description` | `setMyShortDescription` |
| Tavsif (bo'sh chatda) | `telegram.profile.description` | `setMyDescription` |
| Buyruqlar ro'yxati (uz/ru/en) | `telegram.profile.commands` | `setMyCommands` |
| Adminlarga qo'shimcha buyruqlar | `telegram.profile.admin_commands` + `telegram.admin_chat_ids` | `setMyCommands` (scope: chat) |
| Menu tugmasi → Mini App | `telegram.miniapp_url`, `telegram.profile.menu_button_text` | `setChatMenuButton` (`web_app`) |
| Profil rasmi (avatar) | `telegram.profile.photo_url` (JPG) | `setMyProfilePhoto` |

Til kalitlari: `default` hamma foydalanuvchilarga ko'rinadi. `ru`, `en` va boshqalar shu tildagi Telegram foydalanuvchilariga ko'rinadi.

Buyruqning xususiyatlari:
- Nom va tavsiflar joriy qiymat bilan solishtiriladi. O'zgarmagan bo'lsa, so'rov yuborilmaydi, chunki `setMyName` uchun Telegram limiti qattiq.
- Avatar faqat rasm o'zgarganda yuklanadi. Qayta yuklash uchun `--force-photo` ni qo'shing.
- Bir qadam xato bersa ham qolganlari bajariladi. Bunday holda buyruq 1 kodi bilan tugaydi.
- Qo'shimcha flaglar: `--skip-webhook` (faqat profil), `--skip-profile` (faqat getMe va webhook).

### Profil rasmi

Bot API 9.4 (2026-yil fevral) `setMyProfilePhoto` metodini qo'shgan. Rasm faqat multipart orqali yuklanadi, URL yoki file_id qabul qilinmaydi. Shuning uchun `tisu:telegram-setup` rasmni `telegram.profile.photo_url` dan yuklab olib, Telegram'ga fayl sifatida yuboradi.

Tayyor avatar: [`avatar.svg`](avatar.svg) (manba), [`avatar.png`](avatar.png) va [`avatar.jpg`](avatar.jpg), 512×512. Web deploy qilingandan keyin u `https://tisu.uzstudents.uz/telegram/avatar.jpg` manzilida bo'ladi (`apps/web/public/telegram/`).

```bash
php artisan tisu:setting telegram.profile.photo_url '"https://tisu.uzstudents.uz/telegram/avatar.jpg"'
php artisan tisu:telegram-setup --skip-webhook
```

## BotFather'da qo'lda qilinadigan qadamlar

Bot API'da **description picture** (bo'sh chatda tavsif ustidagi rasm) uchun metod yo'q. U faqat BotFather orqali o'rnatiladi.

Tayyor rasm: [`description.png`](description.png), 640×360. Manbasi: [`description.svg`](description.svg).

1. Telegram'da [@BotFather](https://t.me/BotFather) ni oching.
2. `/mybots` → **@edu_tisu_bot** → **Edit Bot** → **Edit Description Picture**.
3. `docs/telegram/description.png` faylini **rasm sifatida** yuboring (fayl sifatida emas).
4. Tekshiring: bot bilan chatni tozalang (yoki boshqa akkauntdan oching). Tavsif ustida rasm ko'rinishi kerak.

Ixtiyoriy qadamlar. Ular avtomatik sozlanadi, BotFather faqat zaxira yo'l:
- **Edit Botpic**: `avatar.jpg`. Bu `setMyProfilePhoto` ishlamasa kerak bo'ladi (masalan, server Telegram'ga fayl yuklay olmasa).
- **Bot Settings → Menu Button**: `tisu:telegram-setup` uni Mini App'ga o'rnatadi.
- **Bot Settings → Configure Mini App → Enable Mini App**, URL `https://tisu.uzstudents.uz/tg`. Bu botning profilida **Open App** tugmasini va `t.me/edu_tisu_bot?startapp` havolasini yoqadi. Menu tugmasi uchun shart emas.

## Deploy'dan keyin

```bash
php artisan tisu:setting telegram.admin_chat_ids '[6817322973]'
php artisan tisu:setting telegram.profile.photo_url '"https://tisu.uzstudents.uz/telegram/avatar.jpg"'
php artisan tisu:telegram-setup
```

`tisu:setting` istalgan sozlamani `Settings::set` validatsiyasi bilan o'rnatadi. Qiymat JSON ko'rinishida beriladi: satr `'"matn"'`, son `5`, ro'yxat `'[1,2]'`. Qiymatsiz chaqirilsa, joriy qiymatni ko'rsatadi. `--reset` default'ga qaytaradi.

## Bot ichidagi admin

Admin buyruqlari faqat `telegram.admin_chat_ids` dagi chatlardan ishlaydi:

- `/admin`: admin panel.
- `/stats`: foydalanuvchilar (rollar bo'yicha), Telegram ulanganlar, faol guruhlar, bugungi darslar va davomat.
- `/find 90123`: telefon raqami bo'yicha qidirish.
- `/broadcast`: xabar tarqatish. Qadamlar: auditoriya → matn → ko'rib chiqish → **✅ Yuborish**. Har bir xabar `notifications_log` ga yoziladi (`kind = broadcast`), umumiy natija `audit_logs` ga (`telegram.broadcast`). Tezlik `telegram.broadcast_per_second` sozlamasida.
- `/cancel`: kutilayotgan amalni bekor qiladi.
- Yangi foydalanuvchi bot orqali ro'yxatdan o'tsa, adminlarga xabar boradi. Bu `telegram.admin_notify_registrations` bilan o'chiriladi.

Bot matnlari `telegram.text.*`, menyu tuzilishi `telegram.menu` (rol → tugmalar qatorlari), Mini App matnlari `miniapp.texts` sozlamalarida. Hammasi admin paneldagi sozlamalar sahifasidan yoki `tisu:setting` orqali o'zgartiriladi.
