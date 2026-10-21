namespace TisuEdu.Core.Services;

/// <summary>
/// Tashqi havolalarni ochish uchun yagona tekshiruv: faqat https, http, tg va tel. Server yoki sozlamadan kelgan
/// bo'sh, nisbiy yoki boshqa sxemali (file:, javascript:, ms-…) qiymatlar ochilmaydi va istisno chiqarmaydi.
/// </summary>
public static class ExternalLinks
{
    private static readonly string[] Schemes = [Uri.UriSchemeHttps, Uri.UriSchemeHttp, "tg", "tel"];

    public static bool TryCreate(string? url, out Uri uri)
    {
        uri = null!;
        if (string.IsNullOrWhiteSpace(url)) return false;
        var text = url.Trim();
        // "C:\..." yoki "/path" Linux'da file: URI bo'lib qolmasin — sxema aniq yozilgan bo'lishi shart.
        if (!text.Contains(':', StringComparison.Ordinal)) return false;
        if (!Uri.TryCreate(text, UriKind.Absolute, out var parsed) || parsed is null) return false;
        if (!Schemes.Contains(parsed.Scheme, StringComparer.OrdinalIgnoreCase)) return false;
        if ((parsed.Scheme == Uri.UriSchemeHttps || parsed.Scheme == Uri.UriSchemeHttp) && string.IsNullOrEmpty(parsed.Host)) return false;
        uri = parsed;
        return true;
    }

    public static bool IsAllowed(Uri uri) => TryCreate(uri.OriginalString, out _);

    /// <summary>Ruxsat etilgan havola bo'lsa ochadi; aks holda false (hech narsa qilmaydi).</summary>
    public static bool OpenUrl(this IPlatform platform, string? url)
    {
        if (!TryCreate(url, out var uri)) return false;
        platform.Open(uri);
        return true;
    }
}
