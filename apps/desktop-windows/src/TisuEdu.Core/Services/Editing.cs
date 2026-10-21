using System.Globalization;
using System.Text.Json;
using TisuEdu.Core.Models;

namespace TisuEdu.Core.Services;

/// <summary>
/// Tahrirlash formalari va API so'rov tanalari. Validatsiya qoidalari backend'dagi bilan bir xil
/// (CourseController::rules, CurriculumController, QuizController, AnnouncementController, GroupController, UserController).
/// Bo'sh ixtiyoriy maydonlar yuborilmaydi (Laravel 'sometimes|integer' null'ni qabul qilmaydi).
/// </summary>
public static class Editing
{
    public static KeyValuePair<string, string> Opt(string value, string label) => new(value, label);

    private static string B(bool v) => v ? "1" : "0";

    private static string N(int? v) => v?.ToString(CultureInfo.InvariantCulture) ?? "";

    // ── Kurs ─────────────────────────────────────────────────
    public static List<FormField> CourseForm(Course? c) =>
    [
        new("title", "Nomi", FieldKind.Text, c?.Title) { Required = true, MaxLength = 200 },
        new("description", "Tavsif", FieldKind.Multiline, c?.Description),
        new("duration_months", "Davomiyligi (oy)", FieldKind.Number, N(c?.DurationMonths)),
        new("cover_url", "Muqova rasmi (URL)", FieldKind.Text, c?.CoverUrl) { Placeholder = "https://…" },
        FormField.Toggle("published", "Chop etilgan (o'quvchilarga ko'rinadi)", c?.Published ?? false),
    ];

    public static Dictionary<string, object?> CourseBody(FormValues v)
    {
        var d = new Dictionary<string, object?>
        {
            ["title"] = v.Str("title"),
            ["description"] = v.StrOrNull("description"),
            ["cover_url"] = v.StrOrNull("cover_url"),
            ["published"] = v.Bool("published"),
        };
        if (v.Int("duration_months") is { } m) d["duration_months"] = m;
        return d;
    }

    // ── Modul va dars ────────────────────────────────────────
    public static List<FormField> ModuleForm(string? title) => [new("title", "Modul nomi", FieldKind.Text, title) { Required = true, MaxLength = 200 }];

    public static List<FormField> LessonForm(string? title, string? body, bool published) =>
    [
        new("title", "Dars nomi", FieldKind.Text, title) { Required = true, MaxLength = 200 },
        new("body", "Dars matni", FieldKind.Multiline, body) { Hint = "O'quvchilarga ko'rinadigan asosiy matn" },
        FormField.Toggle("published", "Chop etilgan", published),
    ];

    public static Dictionary<string, object?> LessonBody(FormValues v) => new()
    {
        ["title"] = v.Str("title"),
        ["body"] = v.StrOrNull("body"),
        ["published"] = v.Bool("published"),
    };

    // ── Material ─────────────────────────────────────────────
    public static readonly KeyValuePair<string, string>[] MaterialTypes =
        [Opt("VIDEO", "Video"), Opt("FILE", "Fayl"), Opt("LINK", "Havola"), Opt("TEXT", "Matn")];

    public static List<FormField> MaterialForm(Material? m) =>
    [
        FormField.Choice("type", "Turi", MaterialTypes, m?.Type ?? "VIDEO"),
        new("title", "Nomi", FieldKind.Text, m?.Title) { Required = true, MaxLength = 200 },
        new("url", "Havola (video, fayl yoki sahifa)", FieldKind.Text, m?.Url) { Placeholder = "https://…" },
        new("content", "Matn (faqat «Matn» turi uchun)", FieldKind.Multiline, m?.Content),
    ];

    public static string? ValidateMaterial(FormValues v)
    {
        var type = v.Str("type");
        if (type == "TEXT" && v.Str("content").Length == 0) return "Matnli material uchun matn kiriting.";
        if (type != "TEXT" && !Uri.TryCreate(v.Str("url"), UriKind.Absolute, out var u)) return "To'g'ri havola kiriting (https://…).";
        if (type != "TEXT" && Uri.TryCreate(v.Str("url"), UriKind.Absolute, out var url) && url.Scheme is not ("http" or "https")) return "Havola http yoki https bo'lsin.";
        return null;
    }

    public static Dictionary<string, object?> MaterialBody(FormValues v)
    {
        var text = v.Str("type") == "TEXT";
        return new()
        {
            ["type"] = v.Str("type"),
            ["title"] = v.Str("title"),
            ["url"] = text ? null : v.StrOrNull("url"),
            ["content"] = text ? v.StrOrNull("content") : null,
        };
    }

    // ── Test va savol ────────────────────────────────────────
    public static List<FormField> QuizForm(QuizInfo? q, int defaultPass, int defaultAttempts) =>
    [
        new("title", "Test nomi", FieldKind.Text, q?.Title) { Required = true, MaxLength = 200 },
        new("pass_percent", "O'tish foizi", FieldKind.Number, N(q?.PassPercent ?? defaultPass)),
        new("max_attempts", "Urinishlar soni", FieldKind.Number, N(q?.MaxAttempts ?? defaultAttempts)),
        new("time_limit_min", "Vaqt (daqiqa, bo'sh — cheklanmagan)", FieldKind.Number, q?.TimeLimitSec is { } t ? N(t / 60) : ""),
        FormField.Toggle("is_final", "Yakuniy test (sertifikat uchun)", q?.IsFinal ?? false),
        FormField.Toggle("shuffle", "Savol va variantlarni aralashtirish", q?.Shuffle ?? false),
        FormField.Toggle("published", "Chop etilgan", q?.Published ?? false),
    ];

    public static Dictionary<string, object?> QuizBody(FormValues v)
    {
        var d = new Dictionary<string, object?>
        {
            ["title"] = v.Str("title"),
            ["is_final"] = v.Bool("is_final"),
            ["shuffle"] = v.Bool("shuffle"),
            ["published"] = v.Bool("published"),
            ["time_limit_sec"] = v.Int("time_limit_min") is { } m and > 0 ? m * 60 : null,
        };
        if (v.Int("pass_percent") is { } p) d["pass_percent"] = p;
        if (v.Int("max_attempts") is { } a) d["max_attempts"] = a;
        return d;
    }

    public static readonly KeyValuePair<string, string>[] QuestionTypes =
        [Opt("SINGLE", "Bitta to'g'ri javob"), Opt("MULTIPLE", "Bir nechta to'g'ri javob"), Opt("TEXT", "Matnli javob")];

    public const string OptionsHint = "Har bir variant alohida qatorda. To'g'ri variant boshiga * qo'ying, masalan: *Toshkent";

    public static List<FormField> QuestionForm(QuizQuestion? q)
    {
        var fields = new List<FormField>();
        if (q is null) fields.Add(FormField.Choice("type", "Savol turi", QuestionTypes, "SINGLE"));
        fields.Add(new("text", "Savol matni", FieldKind.Multiline, q?.Text) { Required = true });
        fields.Add(new("points", "Ball", FieldKind.Number, N(q?.Points ?? 1)));
        if (q is null || q.Type != "TEXT")
            fields.Add(new("options", "Variantlar", FieldKind.Multiline,
                q is null ? "" : string.Join("\n", q.Options.Select(o => (o.IsCorrect == true ? "*" : "") + o.Text))) { Hint = OptionsHint });
        if (q is null || q.Type == "TEXT")
            fields.Add(new("accepted", "Qabul qilinadigan javoblar (matnli savol, har biri alohida qatorda)", FieldKind.Multiline,
                q?.AcceptedAnswers is { } a ? string.Join("\n", a) : ""));
        return fields;
    }

    /// <summary>Savol tanasi; xato bo'lsa matn qaytaradi.</summary>
    public static (Dictionary<string, object?>? Body, string? Error) QuestionBody(FormValues v, string? existingType)
    {
        var type = existingType ?? v.Str("type");
        var d = new Dictionary<string, object?> { ["text"] = v.Str("text") };
        if (existingType is null) d["type"] = type;
        if (v.Int("points") is { } p) d["points"] = p;
        if (type == "TEXT")
        {
            var accepted = v.Lines("accepted");
            if (accepted.Count == 0) return (null, "Kamida bitta to'g'ri javob kiriting.");
            d["accepted_answers"] = accepted;
            return (d, null);
        }
        var options = v.Lines("options").Select(l => new { text = l.TrimStart('*').Trim(), is_correct = l.StartsWith('*') }).ToList();
        if (options.Count < 2) return (null, "Kamida ikkita variant kiriting.");
        var correct = options.Count(o => o.is_correct);
        if (correct == 0) return (null, "To'g'ri variant boshiga * qo'ying.");
        if (type == "SINGLE" && correct != 1) return (null, "Bitta javobli savolda faqat bitta to'g'ri variant bo'ladi.");
        d["options"] = options;
        return (d, null);
    }

    // ── E'lon ────────────────────────────────────────────────
    public static List<FormField> AnnouncementForm(Announcement? a, SiteSettings st, IEnumerable<KeyValuePair<string, string>> groups, bool canAll)
    {
        var audiences = new List<KeyValuePair<string, string>>();
        if (canAll) audiences.AddRange([Opt("ALL", "Hammaga"), Opt("ROLE", "Rol bo'yicha")]);
        audiences.Add(Opt("GROUP", "Guruhga"));
        var roles = new[] { "STUDENT", "TEACHER", "ADMIN", "SUPER_ADMIN" }.Select(r => Opt(r, st.RoleLabel(r)));
        var fields = new List<FormField>
        {
            new("title", "Sarlavha", FieldKind.Text, a?.Title) { Required = true, MaxLength = 200 },
            new("body", "Matn", FieldKind.Multiline, a?.Body) { Required = true, MaxLength = 5000 },
            FormField.Choice("audience", "Kimga", audiences, a?.Audience ?? audiences[0].Key),
            FormField.Choice("audience_role", "Rol («Rol bo'yicha» uchun)", roles, a?.AudienceRole ?? "STUDENT"),
            FormField.Choice("group_id", "Guruh («Guruhga» uchun)", groups, a?.Group?.Id.ToString(CultureInfo.InvariantCulture)),
            new("image_url", "Rasm (https URL, ixtiyoriy)", FieldKind.Text, a?.ImageUrl),
            FormField.Toggle("pinned", "Muhim (yuqorida turadi)", a?.Pinned ?? false),
            FormField.Toggle("is_public", "Saytning bosh sahifasida ham ko'rinsin (faqat «Hammaga»)", a?.IsPublic ?? false),
            FormField.Toggle("notify_telegram", "Chop etilganda Telegram'da xabar yuborish", a?.NotifyTelegram ?? true),
        };
        if (a is null) fields.Add(FormField.Toggle("publish", "Darhol chop etish", true));
        return fields;
    }

    public static Dictionary<string, object?> AnnouncementBody(FormValues v, bool create)
    {
        var audience = v.Str("audience");
        var d = new Dictionary<string, object?>
        {
            ["title"] = v.Str("title"),
            ["body"] = v.Str("body"),
            ["audience"] = audience,
            ["audience_role"] = audience == "ROLE" ? v.Str("audience_role") : null,
            ["group_id"] = audience == "GROUP" ? v.Int("group_id") : null,
            ["image_url"] = v.StrOrNull("image_url"),
            ["pinned"] = v.Bool("pinned"),
            ["is_public"] = audience == "ALL" && v.Bool("is_public"),
            ["notify_telegram"] = v.Bool("notify_telegram"),
        };
        if (create) d["publish"] = v.Bool("publish");
        return d;
    }

    // ── Guruh ────────────────────────────────────────────────
    public static List<FormField> GroupForm(Group? g, IEnumerable<KeyValuePair<string, string>> courses, IEnumerable<KeyValuePair<string, string>> teachers, SiteSettings st)
    {
        var fields = new List<FormField>
        {
            new("name", "Guruh nomi", FieldKind.Text, g?.Name) { Required = true, MaxLength = 120 },
            FormField.Choice("course_id", "Kurs", courses, g?.CourseId.ToString(CultureInfo.InvariantCulture)),
            FormField.Choice("teacher_id", "O'qituvchi", teachers, g?.TeacherId?.ToString(CultureInfo.InvariantCulture)),
            new("start_date", "Boshlanish sanasi", FieldKind.Date, g?.StartDate ?? Today),
            new("end_date", "Tugash sanasi (ixtiyoriy)", FieldKind.Date, g?.EndDate),
            new("room", "Xona", FieldKind.Text, g?.Room) { MaxLength = 60 },
            new("schedule", "Haftalik jadval", FieldKind.Multiline, g is null ? "" : ScheduleText(g.Schedules, st))
            {
                Hint = $"Har bir dars alohida qatorda: kun boshlanish-tugash, masalan «{Format.WeekdayShort(1, st)} 14:00-15:30»",
            },
        };
        if (g is not null) fields.Add(FormField.Toggle("active", "Faol", g.Active));
        return fields;
    }

    public static string ScheduleText(IEnumerable<GroupSchedule> slots, SiteSettings st) =>
        string.Join("\n", slots.OrderBy(s => s.Weekday).ThenBy(s => s.StartTime)
            .Select(s => $"{Format.WeekdayShort(s.Weekday, st)} {Format.Hhmm(s.StartTime)}-{Format.Hhmm(s.EndTime)}"));

    /// <summary>"Du 14:00-15:30" qatorlarini jadvalga aylantiradi (kun — sozlamadagi qisqa nom yoki 1..7).</summary>
    public static (List<object>? Slots, string? Error) ParseSchedule(IEnumerable<string> lines, SiteSettings st)
    {
        var names = Enumerable.Range(1, 7).ToDictionary(i => Format.WeekdayShort(i, st).ToLowerInvariant(), i => i);
        var slots = new List<object>();
        foreach (var raw in lines)
        {
            var parts = raw.Replace('–', '-').Split([' ', '\t'], StringSplitOptions.RemoveEmptyEntries);
            if (parts.Length != 2) return (null, $"«{raw}»: kun va vaqt kerak, masalan «{Format.WeekdayShort(1, st)} 14:00-15:30».");
            var day = parts[0].ToLowerInvariant();
            if (!names.TryGetValue(day, out var weekday) && !(int.TryParse(day, out weekday) && weekday is >= 1 and <= 7))
                return (null, $"«{parts[0]}» — noma'lum kun.");
            var times = parts[1].Split('-');
            if (times.Length != 2) return (null, $"«{raw}»: vaqt «14:00-15:30» ko'rinishida bo'lsin.");
            var start = Pad(times[0]);
            var end = Pad(times[1]);
            if (!Format.IsValidHhmm(start) || !Format.IsValidHhmm(end)) return (null, $"«{raw}»: vaqt «14:00-15:30» ko'rinishida bo'lsin.");
            if (string.CompareOrdinal(end, start) <= 0) return (null, $"«{raw}»: tugash vaqti boshlanishdan keyin bo'lsin.");
            slots.Add(new { weekday, start_time = start, end_time = end });
        }
        return (slots, null);
    }

    private static string Pad(string t) => t.Trim().Length == 4 && t.Trim()[1] == ':' ? "0" + t.Trim() : t.Trim();

    public static (Dictionary<string, object?>? Body, string? Error) GroupBody(FormValues v, SiteSettings st, bool edit)
    {
        var (slots, error) = ParseSchedule(v.Lines("schedule"), st);
        if (error is not null) return (null, error);
        if (v.Int("course_id") is null) return (null, "Kursni tanlang.");
        if (v.Int("teacher_id") is null) return (null, "O'qituvchini tanlang.");
        var d = new Dictionary<string, object?>
        {
            ["name"] = v.Str("name"),
            ["course_id"] = v.Int("course_id"),
            ["teacher_id"] = v.Int("teacher_id"),
            ["start_date"] = v.Str("start_date"),
            ["end_date"] = v.StrOrNull("end_date"),
            ["room"] = v.StrOrNull("room"),
            ["schedules"] = slots,
        };
        if (edit) d["active"] = v.Bool("active");
        return (d, null);
    }

    // ── Foydalanuvchi ────────────────────────────────────────
    public static readonly string[] Roles = ["STUDENT", "TEACHER", "ADMIN", "SUPER_ADMIN"];

    public static List<FormField> UserForm(UserRow? u, SiteSettings st)
    {
        var fields = new List<FormField>
        {
            new("first_name", "Ism", FieldKind.Text, u?.FirstName) { Required = true, MaxLength = 64 },
            new("last_name", "Familiya", FieldKind.Text, u?.LastName) { MaxLength = 64 },
            new("phone", "Telefon", FieldKind.Text, u?.Phone ?? "+998") { Required = true },
            FormField.Choice("role", "Rol", Roles.Select(r => Opt(r, st.RoleLabel(r))), u?.Role ?? "STUDENT"),
        };
        if (u is null) fields.Add(new("password", "Parol (ixtiyoriy — Telegram orqali kiradi)", FieldKind.Password));
        return fields;
    }

    public static Dictionary<string, object?> UserBody(FormValues v, bool create)
    {
        var d = new Dictionary<string, object?>
        {
            ["first_name"] = v.Str("first_name"),
            ["last_name"] = v.StrOrNull("last_name"),
            ["phone"] = ViewModels.LoginViewModel.NormalizePhone(v.Str("phone")),
            ["role"] = v.Str("role"),
        };
        if (create && v.StrOrNull("password") is { } p) d["password"] = p;
        return d;
    }

    // ── To'lovlar ────────────────────────────────────────────
    public static string Today => ServerClockToday().ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);

    private static DateTime ServerClockToday() => Api.ServerClock.Now.Date;

    public static IEnumerable<KeyValuePair<string, string>> Methods(SiteSettings st)
    {
        if (st.TryGet("billing.method_labels", out var m) && m.ValueKind == JsonValueKind.Object)
            return m.EnumerateObject().Select(p => Opt(p.Name, p.Value.GetString() ?? p.Name)).ToList();
        return [Opt("CASH", "Naqd"), Opt("CARD", "Karta"), Opt("TRANSFER", "O'tkazma")];
    }

    public static List<FormField> PaymentForm(SiteSettings st) =>
    [
        new("amount", $"Summa ({st.Str("billing.currency", "so'm")})", FieldKind.Number) { Required = true },
        FormField.Choice("method", "To'lov usuli", Methods(st), Methods(st).First().Key),
        new("date", "Sana", FieldKind.Date, Today),
        new("note", "Izoh", FieldKind.Multiline) { MaxLength = 500 },
    ];

    public static List<FormField> AdjustmentForm(SiteSettings st) =>
    [
        new("amount", $"Summa ({st.Str("billing.currency", "so'm")}): musbat — balansga qo'shadi, manfiy — ayiradi", FieldKind.Number) { Required = true },
        new("reason", "Sabab (chegirma, xato tuzatish …)", FieldKind.Multiline) { Required = true, MaxLength = 500 },
        new("date", "Sana", FieldKind.Date, Today),
    ];

    public static string Money(int amount, SiteSettings st) =>
        amount.ToString("#,0", CultureInfo.InvariantCulture).Replace(',', ' ') + " " + st.Str("billing.currency", "so'm");
}
