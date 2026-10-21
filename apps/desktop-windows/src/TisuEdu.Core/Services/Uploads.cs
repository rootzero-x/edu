namespace TisuEdu.Core.Services;

/// <summary>Yuklash cheklovlari — ochiq sozlamalardan (uploads.*). Server ham tekshiradi; bu yerda — oldindan aniq xabar.</summary>
public static class Uploads
{
    private static readonly string[] DefaultTypes =
        ["pdf", "doc", "docx", "ppt", "pptx", "xls", "xlsx", "odt", "odp", "ods", "txt", "csv", "jpg", "jpeg", "png", "webp", "gif", "mp3", "m4a", "mp4"];

    public static bool Enabled(SiteSettings st) => st.Bool("uploads.enabled", true);

    public static int MaxMb(SiteSettings st) => Math.Max(1, st.Int("uploads.max_mb", 10));

    public static IReadOnlyList<string> Types(SiteSettings st)
    {
        var list = st.List("uploads.allowed_types");
        return (list.Count > 0 ? list : DefaultTypes).Select(t => t.Trim().TrimStart('.').ToLowerInvariant()).ToList();
    }

    public static IReadOnlyList<string> PickerExtensions(SiteSettings st) => Types(st).Select(t => "." + t).ToList();

    public static string? Validate(PickedFile f, SiteSettings st)
    {
        if (!Enabled(st)) return "Fayl yuklash o'chirilgan.";
        var ext = Path.GetExtension(f.Name).TrimStart('.').ToLowerInvariant();
        if (!Types(st).Contains(ext)) return $"«.{ext}» turidagi fayl qabul qilinmaydi. Ruxsat etilgan: {string.Join(", ", Types(st))}.";
        if (f.Content.LongLength > MaxMb(st) * 1024L * 1024L) return $"Fayl juda katta: {Size(f.Content.LongLength)}. Eng ko'pi — {MaxMb(st)} MB.";
        if (f.Content.Length == 0) return "Fayl bo'sh.";
        return null;
    }

    public static string Size(long bytes) => bytes switch
    {
        >= 1024 * 1024 => $"{bytes / 1024d / 1024d:0.#} MB",
        >= 1024 => $"{bytes / 1024d:0} KB",
        _ => $"{bytes} B",
    };

    public static string Mime(string fileName) => Path.GetExtension(fileName).ToLowerInvariant() switch
    {
        ".pdf" => "application/pdf",
        ".doc" => "application/msword",
        ".docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        ".ppt" => "application/vnd.ms-powerpoint",
        ".pptx" => "application/vnd.openxmlformats-officedocument.presentationml.presentation",
        ".xls" => "application/vnd.ms-excel",
        ".xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        ".odt" => "application/vnd.oasis.opendocument.text",
        ".odp" => "application/vnd.oasis.opendocument.presentation",
        ".ods" => "application/vnd.oasis.opendocument.spreadsheet",
        ".txt" => "text/plain",
        ".csv" => "text/csv",
        ".jpg" or ".jpeg" => "image/jpeg",
        ".png" => "image/png",
        ".webp" => "image/webp",
        ".gif" => "image/gif",
        ".mp3" => "audio/mpeg",
        ".m4a" => "audio/mp4",
        ".mp4" => "video/mp4",
        _ => "application/octet-stream",
    };
}
