using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using TisuEdu.Core.Models;

namespace TisuEdu.Core.Api;

/// <summary>API xatosi: server xabari (message), validatsiya xatolari va kursga kirish rad etilgani.</summary>
public class ApiException : Exception
{
    public ApiException(HttpStatusCode? status, string message, IReadOnlyDictionary<string, string[]>? errors = null, AccessInfo? denied = null, Exception? inner = null)
        : base(message, inner)
    {
        Status = status;
        Errors = errors ?? new Dictionary<string, string[]>();
        Denied = denied;
    }

    public HttpStatusCode? Status { get; }
    public IReadOnlyDictionary<string, string[]> Errors { get; }

    /// <summary>403 COURSE_ACCESS_DENIED bo'lsa — sozlamalardagi sarlavha/matn/tugma.</summary>
    public AccessInfo? Denied { get; }

    /// <summary>Server bilan aloqa yo'q (internet, DNS, timeout).</summary>
    public bool IsOffline => Status is null;

    public bool IsUnauthorized => Status == HttpStatusCode.Unauthorized;
}

/// <summary>Keshdan olingan (oflayn) yoki yangi ma'lumot.</summary>
public sealed record Fetched<T>(T Value, bool FromCache, DateTimeOffset? SavedAt);

public interface IResponseCache
{
    Task<(string Json, DateTimeOffset SavedAt)?> ReadAsync(string scope, string key, CancellationToken ct = default);
    Task WriteAsync(string scope, string key, string json, CancellationToken ct = default);
    Task ClearAsync(string? scope = null);
}

public sealed class ApiClient
{
    public const string DefaultBaseUrl = "https://api.tisu.uzstudents.uz";

    private readonly HttpClient _http;
    private readonly IResponseCache? _cache;
    private string? _token;

    public ApiClient(HttpClient http, IResponseCache? cache = null, string? baseUrl = null)
    {
        _http = http;
        _cache = cache;
        BaseUrl = (baseUrl ?? DefaultBaseUrl).TrimEnd('/');
        if (_http.Timeout == Timeout.InfiniteTimeSpan || _http.Timeout > TimeSpan.FromSeconds(30))
        {
            _http.Timeout = TimeSpan.FromSeconds(30);
        }
    }

    public string BaseUrl { get; }

    /// <summary>Kesh shu foydalanuvchiga tegishli bo'lishi uchun (boshqa akkaunt ma'lumoti aralashmasin).</summary>
    public string CacheScope { get; set; } = "anon";

    public string? Token
    {
        get => _token;
        set => _token = string.IsNullOrWhiteSpace(value) ? null : value;
    }

    public string UserAgent { get; set; } = "TisuEdu-Windows/1.0";

    /// <summary>401 — token eskirgan yoki bekor qilingan. Ilova sessiyani yopib, kirish oynasini ko'rsatadi.</summary>
    public event EventHandler? Unauthorized;

    /// <summary>Tarmoq holati o'zgarganda (true — onlayn).</summary>
    public event EventHandler<bool>? ConnectivityChanged;

    private bool _online = true;
    public bool IsOnline => _online;

    // ── Asosiy so'rovlar ─────────────────────────────────────

    public Task<T> GetAsync<T>(string path, CancellationToken ct = default) =>
        SendAsync<T>(HttpMethod.Get, path, null, ct);

    public Task<T> PostAsync<T>(string path, object? body = null, CancellationToken ct = default) =>
        SendAsync<T>(HttpMethod.Post, path, body ?? new { }, ct);

    public Task<T> PutAsync<T>(string path, object body, CancellationToken ct = default) =>
        SendAsync<T>(HttpMethod.Put, path, body, ct);

    public Task<T> PatchAsync<T>(string path, object body, CancellationToken ct = default) =>
        SendAsync<T>(HttpMethod.Patch, path, body, ct);

    public Task<T> DeleteAsync<T>(string path, object? body = null, CancellationToken ct = default) =>
        SendAsync<T>(HttpMethod.Delete, path, body, ct);

    /// <summary>
    /// Tarmoqdan oladi va diskka saqlaydi; aloqa bo'lmasa oxirgi saqlangan javobni qaytaradi (oflayn rejim).
    /// </summary>
    public async Task<Fetched<T>> GetCachedAsync<T>(string path, CancellationToken ct = default)
    {
        try
        {
            var json = await SendRawAsync(HttpMethod.Get, path, null, ct).ConfigureAwait(false);
            if (_cache is not null)
            {
                _ = _cache.WriteAsync(CacheScope, path, json, CancellationToken.None);
            }
            return new Fetched<T>(Parse<T>(json, path), false, null);
        }
        catch (ApiException ex) when (ex.IsOffline && _cache is not null)
        {
            var hit = await _cache.ReadAsync(CacheScope, path, ct).ConfigureAwait(false);
            if (hit is null) throw;
            return new Fetched<T>(Parse<T>(hit.Value.Json, path), true, hit.Value.SavedAt);
        }
    }

    /// <summary>Faqat keshdagi javob (tezkor birinchi chizish uchun). Bo'lmasa null.</summary>
    public async Task<Fetched<T>?> PeekCachedAsync<T>(string path, CancellationToken ct = default)
    {
        if (_cache is null) return null;
        var hit = await _cache.ReadAsync(CacheScope, path, ct).ConfigureAwait(false);
        if (hit is null) return null;
        try { return new Fetched<T>(Parse<T>(hit.Value.Json, path), true, hit.Value.SavedAt); }
        catch (ApiException) { return null; }
    }

    public async Task<byte[]> GetBytesAsync(string path, CancellationToken ct = default)
    {
        using var req = Build(HttpMethod.Get, path, null, accept: "*/*");
        using var res = await Execute(req, ct).ConfigureAwait(false);
        var bytes = await res.Content.ReadAsByteArrayAsync(ct).ConfigureAwait(false);
        if (!res.IsSuccessStatusCode)
        {
            throw Fail(res.StatusCode, Encoding.UTF8.GetString(bytes), path);
        }
        return bytes;
    }

    public Uri Url(string path) => new(BaseUrl + "/v1/" + path.TrimStart('/'));

    // ── Ichki ────────────────────────────────────────────────

    private async Task<T> SendAsync<T>(HttpMethod method, string path, object? body, CancellationToken ct)
    {
        var json = await SendRawAsync(method, path, body, ct).ConfigureAwait(false);
        return Parse<T>(json, path);
    }

    private async Task<string> SendRawAsync(HttpMethod method, string path, object? body, CancellationToken ct)
    {
        using var req = Build(method, path, body, accept: "application/json");
        using var res = await Execute(req, ct).ConfigureAwait(false);
        var text = await res.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
        if (!res.IsSuccessStatusCode)
        {
            throw Fail(res.StatusCode, text, path);
        }
        return text;
    }

    private HttpRequestMessage Build(HttpMethod method, string path, object? body, string accept)
    {
        var req = new HttpRequestMessage(method, Url(path));
        req.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue(accept));
        req.Headers.UserAgent.ParseAdd(UserAgent);
        req.Headers.AcceptLanguage.ParseAdd("uz");
        if (_token is not null)
        {
            req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _token);
        }
        if (body is not null)
        {
            req.Content = new StringContent(JsonSerializer.Serialize(body, body.GetType(), Json.Options), Encoding.UTF8, "application/json");
        }
        return req;
    }

    private async Task<HttpResponseMessage> Execute(HttpRequestMessage req, CancellationToken ct)
    {
        HttpResponseMessage res;
        try
        {
            res = await _http.SendAsync(req, HttpCompletionOption.ResponseContentRead, ct).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or IOException)
        {
            SetOnline(false);
            throw new ApiException(null, Strings.Offline, inner: ex);
        }
        SetOnline(true);
        return res;
    }

    private void SetOnline(bool online)
    {
        if (_online == online) return;
        _online = online;
        ConnectivityChanged?.Invoke(this, online);
    }

    private ApiException Fail(HttpStatusCode status, string body, string path)
    {
        string? message = null;
        Dictionary<string, string[]>? errors = null;
        AccessInfo? denied = null;
        try
        {
            using var doc = JsonDocument.Parse(body);
            var root = doc.RootElement;
            if (root.ValueKind == JsonValueKind.Object)
            {
                if (root.TryGetProperty("message", out var m) && m.ValueKind == JsonValueKind.String) message = m.GetString();
                if (root.TryGetProperty("errors", out var e) && e.ValueKind == JsonValueKind.Object)
                {
                    errors = e.Deserialize<Dictionary<string, string[]>>(Json.Options);
                }
                if (root.TryGetProperty("code", out var c) && c.GetString() == "COURSE_ACCESS_DENIED")
                {
                    denied = root.Deserialize<AccessInfo>(Json.Options);
                    if (denied is not null) denied.Granted = false;
                    message = denied?.Message ?? message;
                }
            }
        }
        catch (JsonException) { /* HTML yoki bo'sh javob */ }

        // Validatsiyada umumiy "message" o'rniga birinchi aniq xato ko'rsatiladi.
        if (errors is { Count: > 0 } && errors.Values.FirstOrDefault(v => v.Length > 0) is { } first)
        {
            message = first[0];
        }

        if (status == HttpStatusCode.Unauthorized && !path.StartsWith("auth/login", StringComparison.Ordinal) && !path.StartsWith("auth/telegram", StringComparison.Ordinal))
        {
            Unauthorized?.Invoke(this, EventArgs.Empty);
            message = Strings.SessionExpired;
        }

        message ??= status switch
        {
            HttpStatusCode.Forbidden => Strings.Forbidden,
            HttpStatusCode.NotFound => Strings.NotFound,
            HttpStatusCode.TooManyRequests => Strings.TooManyRequests,
            >= HttpStatusCode.InternalServerError => Strings.ServerError,
            _ => Strings.UnknownError,
        };
        return new ApiException(status, message, errors, denied);
    }

    private static T Parse<T>(string json, string path)
    {
        try
        {
            return JsonSerializer.Deserialize<T>(json, Json.Options) ?? throw new JsonException("null");
        }
        catch (JsonException ex)
        {
            throw new ApiException(HttpStatusCode.OK, $"{Strings.BadResponse} ({path})", inner: ex);
        }
    }
}
