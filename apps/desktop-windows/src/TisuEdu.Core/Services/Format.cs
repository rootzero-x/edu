using System.Globalization;
using System.Text;
using TisuEdu.Core.Api;

namespace TisuEdu.Core.Services;

/// <summary>
/// Sana/son formatlash. Windows'da "uz-Latn-UZ" madaniyati bo'lmasligi mumkin, shuning uchun
/// formatlar qo'lda (web'dagi lib/format bilan bir xil): 20.10.2026, 09:05, 87.5%.
/// </summary>
public static class Format
{
    private static readonly string[] Months =
        ["yanvar", "fevral", "mart", "aprel", "may", "iyun", "iyul", "avgust", "sentabr", "oktabr", "noyabr", "dekabr"];

    private static readonly string[] Weekdays = ["Dushanba", "Seshanba", "Chorshanba", "Payshanba", "Juma", "Shanba", "Yakshanba"];

    public static string Date(DateTimeOffset? v) => v is null ? "—" : ServerClock.ToServer(v.Value).ToString("dd.MM.yyyy", CultureInfo.InvariantCulture);

    public static string Time(DateTimeOffset? v) => v is null ? "—" : ServerClock.ToServer(v.Value).ToString("HH:mm", CultureInfo.InvariantCulture);

    public static string DateTime(DateTimeOffset? v) => v is null ? "—" : ServerClock.ToServer(v.Value).ToString("dd.MM.yyyy HH:mm", CultureInfo.InvariantCulture);

    public static string TimeRange(DateTimeOffset start, DateTimeOffset end) => $"{Time(start)} – {Time(end)}";

    /// <summary>"20-oktabr, dushanba"</summary>
    public static string LongDate(DateTimeOffset v)
    {
        var d = ServerClock.ToServer(v);
        return $"{d.Day}-{Months[d.Month - 1]}, {Weekday(IsoWeekday(d.DayOfWeek)).ToLowerInvariant()}";
    }

    /// <summary>1=Du … 7=Ya (backend ScheduleExpander bilan bir xil), sozlamadagi qisqa nomlar bilan.</summary>
    public static string WeekdayShort(int isoWeekday, SiteSettings settings)
    {
        var names = settings.Text("weekdays", "Du,Se,Cho,Pa,Ju,Sha,Ya").Split(',');
        return isoWeekday is >= 1 and <= 7 && names.Length == 7 ? names[isoWeekday - 1].Trim() : isoWeekday.ToString(CultureInfo.InvariantCulture);
    }

    public static string Weekday(int isoWeekday) => isoWeekday is >= 1 and <= 7 ? Weekdays[isoWeekday - 1] : "";

    public static int IsoWeekday(DayOfWeek d) => d == DayOfWeek.Sunday ? 7 : (int)d;

    public static string Percent(double? v) => v is null ? "—" : v.Value.ToString("0.#", CultureInfo.InvariantCulture) + "%";

    public static string Number(int? v) => v is null ? "—" : v.Value.ToString("#,0", CultureInfo.InvariantCulture).Replace(',', ' ');

    /// <summary>"HH:MM:SS" → "HH:MM"</summary>
    public static string Hhmm(string? t) => string.IsNullOrEmpty(t) ? "" : t.Length >= 5 ? t[..5] : t;

    /// <summary>arrived_at ("2026-10-20 09:05:00" yoki ISO) → "09:05"</summary>
    public static string ArrivedHhmm(string? arrived)
    {
        if (string.IsNullOrWhiteSpace(arrived)) return "";
        var i = arrived.IndexOfAny([' ', 'T']);
        var time = i >= 0 ? arrived[(i + 1)..] : arrived;
        return Hhmm(time);
    }

    public static bool IsValidHhmm(string s) =>
        s.Length == 5 && s[2] == ':' && int.TryParse(s[..2], out var h) && int.TryParse(s[3..], out var m) && h is >= 0 and < 24 && m is >= 0 and < 60;

    public static string Ago(DateTimeOffset when, DateTimeOffset now)
    {
        var d = now - when;
        if (d.TotalMinutes < 1) return "hozirgina";
        if (d.TotalHours < 1) return $"{(int)d.TotalMinutes} daqiqa oldin";
        if (d.TotalDays < 1) return $"{(int)d.TotalHours} soat oldin";
        if (d.TotalDays < 7) return $"{(int)d.TotalDays} kun oldin";
        return Date(when);
    }

    public static string Duration(TimeSpan t) =>
        t.TotalHours >= 1 ? t.ToString(@"h\:mm\:ss", CultureInfo.InvariantCulture) : t.ToString(@"mm\:ss", CultureInfo.InvariantCulture);
}

/// <summary>Excel uchun CSV: UTF-8 BOM, ";" ajratuvchi, formula-injection himoyasi (server attendance.csv bilan bir xil qoida).</summary>
public static class Csv
{
    public static byte[] Build(IEnumerable<string> header, IEnumerable<IEnumerable<string?>> rows)
    {
        var sb = new StringBuilder();
        Line(sb, header);
        foreach (var r in rows) Line(sb, r);
        var bom = Encoding.UTF8.GetPreamble();
        var body = Encoding.UTF8.GetBytes(sb.ToString());
        return [.. bom, .. body];
    }

    private static void Line(StringBuilder sb, IEnumerable<string?> cells)
    {
        sb.AppendJoin(';', cells.Select(Cell));
        sb.Append("\r\n");
    }

    internal static string Cell(string? v)
    {
        v ??= "";
        // =, @, tab, CR bilan yoki +/- bilan boshlanuvchi (oddiy raqamdan tashqari) matn — formula emas, qiymat.
        if (v.Length > 0 && (v[0] is '=' or '@' or '\t' or '\r' || (v[0] is '+' or '-' && !(v.Length > 1 && v[1..].All(char.IsAsciiDigit)))))
        {
            v = "'" + v;
        }
        return v.IndexOfAny([';', '"', '\n', '\r']) >= 0 ? "\"" + v.Replace("\"", "\"\"") + "\"" : v;
    }
}
