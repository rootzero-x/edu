namespace TisuEdu.Core;

/// <summary>
/// Ilova interfeysining o'z matnlari (o'zbek, lotin). Sozlamalarda mavjud matnlar (sayt nomi, menyu nomlari,
/// davomat holatlari, kirish sahifasi, Mini App matnlari va h.k.) bu yerda emas — ular
/// <see cref="Services.SiteSettings"/> orqali /v1/public/settings dan o'qiladi; bu yerda faqat ularning
/// oflayn zaxira qiymatlari va sozlamada yo'q texnik matnlar turadi.
/// </summary>
public static class Strings
{
    // Xatolar
    public const string Offline = "Server bilan aloqa yo'q. Internetni tekshiring.";
    public const string SessionExpired = "Sessiya tugadi. Qaytadan kiring.";
    public const string Forbidden = "Bu amal uchun ruxsat yo'q.";
    public const string NotFound = "Topilmadi.";
    public const string TooManyRequests = "Juda ko'p so'rov. Biroz kutib, qayta urinib ko'ring.";
    public const string ServerError = "Serverda xatolik. Keyinroq urinib ko'ring.";
    public const string UnknownError = "Noma'lum xatolik.";
    public const string BadResponse = "Serverdan noto'g'ri javob keldi";
    public const string FileTooLarge = "Fayl juda katta — server qabul qilmadi.";
    public const string AmountWhole = "Summa so'mda butun son bo'lishi kerak (masalan 150 000).";
    public const string OfflineCached = "Oflayn rejim — oxirgi saqlangan ma'lumotlar ko'rsatilmoqda";

    // Kirish
    public const string PhoneRequired = "Telefon raqamini kiriting.";
    public const string PasswordRequired = "Parolni kiriting.";
    public const string TelegramExpired = "Kirish havolasi eskirdi. Qaytadan urinib ko'ring.";
    public const string TelegramOpened = "Telegram ochildi. Botda kirishni tasdiqlang.";

    // Davomat
    public const string ReasonRequired = "O'zgartirish sababini kiriting.";
    public const string NothingToSave = "Saqlash uchun o'zgarish yo'q.";
    public const string NoStatusOrTime = "Holat yoki kelgan vaqtni tanlang.";
    public const string ArrivedFormat = "Kelgan vaqt SS:DD ko'rinishida bo'lsin (masalan 09:05).";

    // Profil
    public const string PasswordChanged = "Parol o'zgartirildi. Boshqa qurilmalardagi sessiyalar yopildi.";
    public const string PasswordMismatch = "Yangi parol va tasdiq mos emas.";
    public const string ProfileSaved = "Profil saqlandi.";

    // Hisobot
    public const string CsvSaved = "CSV fayl saqlandi.";
}
