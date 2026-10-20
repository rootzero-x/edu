namespace TisuEdu.Core.Services;

/// <summary>
/// Video havolasini ilova ichida ko'rsatish uchun embed manzilga aylantiradi (web'dagi lib/embed bilan bir xil):
/// faqat course.embed_hosts sozlamasidagi xostlar (YouTube, Vimeo …).
/// </summary>
public static class Embed
{
    public static Uri? Url(string? url, IReadOnlyList<string> allowedHosts)
    {
        if (!Uri.TryCreate(url, UriKind.Absolute, out var u) || u.Scheme != Uri.UriSchemeHttps && u.Scheme != Uri.UriSchemeHttp) return null;
        var host = u.Host.StartsWith("www.", StringComparison.OrdinalIgnoreCase) ? u.Host[4..] : u.Host;
        host = host.StartsWith("m.", StringComparison.OrdinalIgnoreCase) ? host[2..] : host;
        if (!allowedHosts.Any(h => string.Equals(h.Trim(), host, StringComparison.OrdinalIgnoreCase))) return null;

        string? Q(string key) => u.Query.TrimStart('?').Split('&').Select(p => p.Split('=', 2))
            .FirstOrDefault(p => p.Length == 2 && p[0] == key)?[1];

        var segments = u.AbsolutePath.Trim('/').Split('/', StringSplitOptions.RemoveEmptyEntries);
        string? id = null;
        switch (host.ToLowerInvariant())
        {
            case "youtube.com":
                id = segments is ["watch"] ? Q("v") : segments is ["embed" or "shorts" or "live", var x, ..] ? x : null;
                return Valid(id) ? new Uri($"https://www.youtube.com/embed/{id}") : null;
            case "youtu.be":
                id = segments.FirstOrDefault();
                return Valid(id) ? new Uri($"https://www.youtube.com/embed/{id}") : null;
            case "vimeo.com":
                id = segments.LastOrDefault(s => s.All(char.IsAsciiDigit));
                return id is not null ? new Uri($"https://player.vimeo.com/video/{id}") : null;
            case "player.vimeo.com":
                return u;
            default:
                return null;
        }
    }

    private static bool Valid(string? id) => !string.IsNullOrEmpty(id) && id.All(c => char.IsAsciiLetterOrDigit(c) || c is '-' or '_');
}
