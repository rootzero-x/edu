# Lug'at (uz) — barcha kanallarda bir xil so'zlar

Sayt, Mini App, bot va hujjatlarda quyidagi atamalar ishlatiladi. Apostrof — oddiy ASCII `'` (o', g').

| Atama | Ma'nosi | Ishlatilmaydi |
|---|---|---|
| **dars** | Kurs kontenti: modul ichidagi o'quv materiali (matn, video, fayl) | jadvaldagi vaqt uchun |
| **mashg'ulot** | Jadvaldagi aniq vaqt: guruhning ma'lum kun va soatdagi uchrashuvi (davomat shunda belgilanadi) | "dars" (jadval ma'nosida) |
| **kurs** | Yo'nalish (masalan, «Ingliz tili»): modullar, darslar, testlar | |
| **guruh** | Bitta kurs bo'yicha birga o'qiydigan o'quvchilar, o'qituvchi va haftalik jadval | "sinf" |
| **o'quvchi** | Talaba (rol STUDENT) | "student", "talaba" |
| **ariza** | Bot orqali ro'yxatdan o'tgan, administrator tasdig'ini kutayotgan akkaunt | "pending" |
| **bekor qilingan** | Mashg'ulot yoki sertifikat bekor qilingan (yozuv saqlanadi) | "bekor", "o'chirilgan" |
| **arxivlangan** | Tarixi bor, lekin endi ishlatilmaydigan yozuv (o'chirilmaydi) | "o'chirilgan" |
| **bloklangan** | Administrator faolsizlantirgan akkaunt | "yopiq", "yashirin" |
| **botni bloklagan** | Foydalanuvchi Telegram'da botni to'xtatgan — xabar yetib bormaydi | |
| **chiqib ketgan** | Guruhdan chiqqan o'quvchi (tarixi va davomati saqlanadi) | |
| **yashirin** | Hali e'lon qilinmagan kurs/dars/test (faqat xodimlarga ko'rinadi) | |

Holatlar uchun bitta so'z: «Bekor qilingan», «Bloklangan», «Yashirin», «Ariza», «Faol».

## Sprint 3 atamalari (taqvim, uyga vazifa, bildirishnomalar)

| Atama | Ma'nosi | Ishlatilmaydi |
|---|---|---|
| **taqvim** | Mashg'ulotlarning haftalik/oylik ko'rinishi | "kalendar" |
| **xona** | Mashg'ulot o'tadigan joy (xonalar ro'yxatidan) | "auditoriya" |
| **uyga vazifa** (qisqa: *vazifa*) | O'qituvchi guruhga beradigan topshiriq, muddati bilan | "topshiriq" (faqat vazifa matni maydoni uchun) |
| **javob** | O'quvchining vazifaga topshirgani (matn, havola, fayl) | |
| **baho** / **ball** | Mashg'ulot yoki vazifa uchun qo'yilgan baho; vazifada — ball (masalan, 8/10) | "ocenka" |
| **bildirishnoma** | Paneldagi qo'ng'iroqchadagi xabar; Telegram'dagisi — **xabar** | |
| **audit jurnali** | Kim, qachon, nimani o'zgartirgani | "log" |

Vazifa holatlari (sozlama `homework.status_labels`): «Topshirilmagan», «Topshirildi», «Kechikib topshirildi», «Baholandi», «Muddati o'tgan».
Yetkazish holatlari (`notifications.delivery_labels`): «Yetkazildi», «Xato», «Telegram ulanmagan», «Botni bloklagan».
