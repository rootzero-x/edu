# TISU Edu — glossary (uz)

One word per concept, everywhere: web dashboard, Mini App, bot texts, settings labels, docs.
Apostrophes: plain ASCII `'` (o', g', ma'lumot) — the house style. Do not mix in ʻ / ’.

| Concept | Uzbek term | Use for | Don't use |
|---|---|---|---|
| Course content unit (text, video, files inside a module) | **dars** (pl. *darslar*) | course editor, lesson page, "Keyingi dars" | for a scheduled class |
| Scheduled class at a date/time for a group | **mashg'ulot** (pl. *mashg'ulotlar*) | calendar, group page, attendance sheet, reminders, "Bugungi mashg'ulotlar" | *dars* |
| Weekly timetable of a group | **jadval** | group schedule, "Jadval" in bot/Mini App | — |
| Group of students with a teacher and a schedule | **guruh** | everywhere | *sinf* |
| Student | **o'quvchi** | everywhere | *talaba* |
| Teacher | **o'qituvchi** | everywhere | *ustoz* (only in friendly bot greetings) |
| Administrator | **administrator** (short: *admin*) | roles, settings | *menejer* |
| Room | **xona** | rooms list, calendar filter, clash messages | *auditoriya* |
| Homework assignment | **uyga vazifa** (short: *vazifa*) | homework pages, bot "📒 Uyga vazifa" | *topshiriq* (only for the task text field) |
| Student's answer to homework | **javob** | submission form, "Javoblar" | — |
| Mark / grade | **baho** (pl. *baholar*), points — **ball** | marks, grading | *ocenka* |
| Attendance | **davomat** | sheet, reports | — |
| Notification | **bildirishnoma** (bell), Telegram message — **xabar** | notification centre | — |
| Audit log | **audit jurnali** | admin page | *log* |

## Status words (one per state)

| State | Word | Notes |
|---|---|---|
| Session/certificate/billing entry cancelled | **bekor qilingan** | never just "Bekor" |
| Group closed | **yopiq** | |
| Course or lesson not visible to students | **yashirin** (draft: *qoralama*) | |
| Account disabled | **faol emas** / **bloklangan** | "bloklangan" only for disabled accounts; a user who blocked the bot is "botni bloklagan" |
| Homework: not submitted yet | **topshirilmagan** | |
| Homework: submitted on time / late | **topshirildi** / **kechikib topshirildi** | |
| Homework: graded | **baholandi** | |
| Homework: deadline passed, nothing submitted | **muddati o'tgan** | |
| Notification delivery | **yetkazildi**, **xato**, **Telegram ulanmagan**, **botni bloklagan** | settings `notifications.delivery_labels` |

All of these are defaults in `apps/backend/config/tisu.php` (status labels are settings), so the owner can still rename them without a deploy.
