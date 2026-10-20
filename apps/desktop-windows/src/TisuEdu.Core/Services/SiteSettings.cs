using System.Text.Json;
using TisuEdu.Core.Api;

namespace TisuEdu.Core.Services;

/// <summary>
/// /v1/public/settings ning o'qish uchun qulay ko'rinishi. Har bir o'qishda sozlamada qiymat bo'lmasa
/// berilgan zaxira (oflayn birinchi ishga tushirish uchun) qaytariladi.
/// </summary>
public sealed class SiteSettings
{
    public static readonly SiteSettings Empty = new(default);

    private readonly JsonElement _root;

    public SiteSettings(JsonElement settings) => _root = settings;

    public bool IsEmpty => _root.ValueKind != JsonValueKind.Object;

    public JsonElement Raw => _root;

    public bool TryGet(string key, out JsonElement value)
    {
        if (_root.ValueKind == JsonValueKind.Object && _root.TryGetProperty(key, out value) && value.ValueKind != JsonValueKind.Null)
        {
            return true;
        }
        value = default;
        return false;
    }

    public string Str(string key, string fallback = "") =>
        TryGet(key, out var v) && v.ValueKind == JsonValueKind.String && !string.IsNullOrWhiteSpace(v.GetString()) ? v.GetString()! : fallback;

    public int Int(string key, int fallback) =>
        TryGet(key, out var v) && v.ValueKind == JsonValueKind.Number && v.TryGetInt32(out var n) ? n : fallback;

    public bool Bool(string key, bool fallback) =>
        TryGet(key, out var v) && v.ValueKind is JsonValueKind.True or JsonValueKind.False ? v.GetBoolean() : fallback;

    public IReadOnlyList<string> List(string key)
    {
        if (!TryGet(key, out var v) || v.ValueKind != JsonValueKind.Array) return [];
        return v.EnumerateArray().Where(e => e.ValueKind == JsonValueKind.String).Select(e => e.GetString()!).ToList();
    }

    /// <summary>Obyekt turidagi sozlamaning bitta kaliti (masalan attendance.status_labels → PRESENT).</summary>
    public string ObjStr(string key, string field, string fallback)
    {
        if (TryGet(key, out var v) && v.ValueKind == JsonValueKind.Object && v.TryGetProperty(field, out var f)
            && f.ValueKind == JsonValueKind.String && !string.IsNullOrWhiteSpace(f.GetString()))
        {
            return f.GetString()!;
        }
        return fallback;
    }

    // ── Tez-tez ishlatiladiganlar ────────────────────────────

    public string SiteName => Str("site.name", "TISU Edu");
    public string Tagline => Str("site.tagline");
    public string SiteUrl => Str("site.url", "https://tisu.uzstudents.uz").TrimEnd('/');
    public string PrimaryColor => Str("site.primary_color", "#2563eb");
    public string BotUsername => Str("telegram.bot_username").TrimStart('@');
    public string CertificateBaseUrl => Str("certificate.public_base_url", SiteUrl + "/c/");
    public string Timezone => Str("app.timezone");

    /// <summary>Avtomatik yangilanish oralig'i (ui.refresh_seconds; 0 — o'chiq).</summary>
    public int RefreshSeconds => Math.Max(0, Int("ui.refresh_seconds", 20));

    /// <summary>Panel menyusi nomi (ui.nav_labels: web manzili → nom).</summary>
    public string Nav(string webPath, string fallback) => ObjStr("ui.nav_labels", webPath, fallback);

    /// <summary>Mini App matnlari (miniapp.texts) — ilova ham shu matnlarni qayta ishlatadi.</summary>
    public string Text(string key, string fallback) => ObjStr("miniapp.texts", key, fallback);

    public string Text(string key, string fallback, params (string Name, object? Value)[] vars)
    {
        var s = Text(key, fallback);
        foreach (var (name, value) in vars) s = s.Replace("{" + name + "}", Convert.ToString(value, System.Globalization.CultureInfo.CurrentCulture));
        return s;
    }

    public string StatusLabel(string? status) =>
        status is null ? "—" : ObjStr("attendance.status_labels", status, status);

    public string RoleLabel(string role) => ObjStr("telegram.role_labels", role, role);

    public string Login(string key, string fallback) => Str("login." + key, fallback);
}

/// <summary>
/// Ochiq sozlamalar keshi: ishga tushganda diskdagi nusxa darhol beriladi, tarmoqdan esa fon rejimida yangilanadi
/// (server Cache-Control: max-age=60 — shu oraliqdan tez-tez so'ralmaydi).
/// </summary>
public sealed class SettingsService(TisuApi api, string cacheFile)
{
    private static readonly TimeSpan MaxAge = TimeSpan.FromSeconds(60);
    private readonly SemaphoreSlim _gate = new(1, 1);
    private DateTimeOffset _fetchedAt = DateTimeOffset.MinValue;

    public SiteSettings Current { get; private set; } = SiteSettings.Empty;

    public event EventHandler<SiteSettings>? Changed;

    public async Task LoadCachedAsync()
    {
        try
        {
            if (!File.Exists(cacheFile)) return;
            var json = await File.ReadAllTextAsync(cacheFile).ConfigureAwait(false);
            Apply(JsonDocument.Parse(json).RootElement.Clone());
        }
        catch (Exception) { /* buzilgan kesh — e'tiborsiz */ }
    }

    /// <summary>Tarmoqdan yangilaydi (60 soniyadan eski bo'lsa yoki force). Aloqa bo'lmasa joriy qiymat qoladi.</summary>
    public async Task<SiteSettings> RefreshAsync(bool force = false, CancellationToken ct = default)
    {
        if (!force && DateTimeOffset.UtcNow - _fetchedAt < MaxAge && !Current.IsEmpty) return Current;
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (!force && DateTimeOffset.UtcNow - _fetchedAt < MaxAge && !Current.IsEmpty) return Current;
            var doc = await api.PublicSettingsAsync(ct).ConfigureAwait(false);
            if (doc.TryGetProperty("settings", out var s) && s.ValueKind == JsonValueKind.Object)
            {
                _fetchedAt = DateTimeOffset.UtcNow;
                Apply(s.Clone());
                try
                {
                    Directory.CreateDirectory(Path.GetDirectoryName(cacheFile)!);
                    await File.WriteAllTextAsync(cacheFile, s.GetRawText(), ct).ConfigureAwait(false);
                }
                catch (IOException) { }
            }
        }
        catch (ApiException) { /* oflayn — keshdagi sozlamalar bilan ishlaymiz */ }
        finally
        {
            _gate.Release();
        }
        return Current;
    }

    private void Apply(JsonElement settings)
    {
        Current = new SiteSettings(settings);
        ServerClock.SetZone(Current.Timezone);
        Changed?.Invoke(this, Current);
    }
}
