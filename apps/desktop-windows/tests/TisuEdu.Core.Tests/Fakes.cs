using System.Net;
using System.Text;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.Core.Tests;

/// <summary>Yo'l bo'yicha javob qaytaradigan soxta HTTP handler.</summary>
internal sealed class FakeHandler : HttpMessageHandler
{
    private readonly Dictionary<string, Queue<Func<HttpRequestMessage, HttpResponseMessage>>> _routes = [];

    public List<(string Method, string Path, string? Body)> Requests { get; } = [];

    public bool Offline { get; set; }

    public FakeHandler On(string method, string pathAndQuery, HttpStatusCode status, string body)
    {
        var key = method + " " + pathAndQuery;
        if (!_routes.TryGetValue(key, out var q)) _routes[key] = q = new();
        q.Enqueue(_ => new HttpResponseMessage(status) { Content = new StringContent(body, Encoding.UTF8, "application/json") });
        return this;
    }

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        var path = request.RequestUri!.PathAndQuery.Replace("/v1/", "");
        Requests.Add((request.Method.Method, path, request.Content is null ? null : await request.Content.ReadAsStringAsync(ct)));
        if (Offline) throw new HttpRequestException("offline");
        var key = request.Method.Method + " " + path;
        if (_routes.TryGetValue(key, out var q) && q.Count > 0)
        {
            var make = q.Count > 1 ? q.Dequeue() : q.Peek();
            return make(request);
        }
        return new HttpResponseMessage(HttpStatusCode.NotFound) { Content = new StringContent("{\"message\":\"no route " + key + "\"}") };
    }
}

internal sealed class MemoryTokenStore : ITokenStore
{
    public string? Token { get; set; }
    public string? Load() => Token;
    public void Save(string token) => Token = token;
    public void Clear() => Token = null;
}

internal sealed class MemoryCache : IResponseCache
{
    private readonly Dictionary<string, string> _items = [];

    public Task<(string Json, DateTimeOffset SavedAt)?> ReadAsync(string scope, string key, CancellationToken ct = default) =>
        Task.FromResult<(string, DateTimeOffset)?>(_items.TryGetValue(scope + key, out var v) ? (v, DateTimeOffset.UtcNow) : null);

    public Task WriteAsync(string scope, string key, string json, CancellationToken ct = default)
    {
        _items[scope + key] = json;
        return Task.CompletedTask;
    }

    public Task ClearAsync(string? scope = null)
    {
        _items.Clear();
        return Task.CompletedTask;
    }
}

internal sealed class FakePlatform : IPlatform
{
    public List<Uri> Opened { get; } = [];
    public bool ConfirmResult { get; set; } = true;
    public void Open(Uri uri) => Opened.Add(uri);
    public Task<bool> SaveFileAsync(string suggestedName, string extensionLabel, string extension, byte[] content) => Task.FromResult(true);
    public Task<bool> ConfirmAsync(string title, string message, string primary, string close) => Task.FromResult(ConfirmResult);
    public void CopyText(string text) { }
    public PickedFile? NextFile { get; set; }
    public Task<PickedFile?> PickFileAsync(IReadOnlyList<string> extensions) => Task.FromResult(NextFile);
}

internal sealed class FakeNavigator : INavigator
{
    public List<(Page Page, object? Param)> History { get; } = [];
    public void Navigate(Page page, object? parameter = null) => History.Add((page, parameter));
    public bool GoBack() => false;
}

/// <summary>Formalarni "to'ldiradi": navbatdagi qiymatlar standart qiymatlar ustiga yoziladi.</summary>
internal sealed class FakeForms : IForms
{
    public Queue<Dictionary<string, string>> Answers { get; } = new();
    public List<string> Errors { get; } = [];
    public List<string> Titles { get; } = [];

    public async Task<bool> ShowAsync(string title, IReadOnlyList<FormField> fields, Func<FormValues, Task<string?>> submit, string primaryText = "Saqlash")
    {
        Titles.Add(title);
        if (Answers.Count == 0) return false; // foydalanuvchi bekor qildi
        var values = fields.ToDictionary(f => f.Key, f => f.Value);
        foreach (var (k, v) in Answers.Dequeue()) values[k] = v;
        var error = await submit(new FormValues(values));
        if (error is null) return true;
        Errors.Add(error);
        return false;
    }
}

internal sealed class FakeNotifier : INotifier
{
    public List<Announcement> Shown { get; } = [];
    public void ShowAnnouncement(Announcement announcement, string siteName) => Shown.Add(announcement);
    public List<NotificationItem> ShownItems { get; } = [];
    public void ShowNotification(NotificationItem item, string siteName) => ShownItems.Add(item);
}

internal sealed class TestApp : IDisposable
{
    public TestApp()
    {
        Dir = Path.Combine(Path.GetTempPath(), "tisu-tests-" + Guid.NewGuid().ToString("N"));
        Handler = new FakeHandler();
        Cache = new MemoryCache();
        Http = new ApiClient(new HttpClient(Handler), Cache, "https://api.test");
        Api = new TisuApi(Http);
        Tokens = new MemoryTokenStore();
        Session = new Session(Api, Tokens, Cache, Path.Combine(Dir, "user.json"));
        Settings = new SettingsService(Api, Path.Combine(Dir, "settings.json"));
        Platform = new FakePlatform();
        Navigator = new FakeNavigator();
        Notifier = new FakeNotifier();
        Watcher = new AnnouncementWatcher(Session, Settings, Notifier, Dir);
        Preferences = new Preferences(Path.Combine(Dir, "prefs.json"));
        Forms = new FakeForms();
        Services = new AppServices(Session, Settings, Platform, Navigator, Watcher, Preferences, Forms);
    }

    public string Dir { get; }
    public FakeHandler Handler { get; }
    public MemoryCache Cache { get; }
    public ApiClient Http { get; }
    public TisuApi Api { get; }
    public MemoryTokenStore Tokens { get; }
    public Session Session { get; }
    public SettingsService Settings { get; }
    public FakePlatform Platform { get; }
    public FakeNavigator Navigator { get; }
    public FakeNotifier Notifier { get; }
    public AnnouncementWatcher Watcher { get; }
    public Preferences Preferences { get; }
    public FakeForms Forms { get; }
    public AppServices Services { get; }

    public const string UserJson = """{"id":7,"phone":"+998901234567","first_name":"Ali","last_name":"Valiyev","role":"TEACHER","branch_id":null,"active":true,"locale":"uz","permissions":["course.view","attendance.mark","report.view"]}""";

    public void SignIn() => Session.Complete("tok", Json.Deserialize<User>(UserJson)!);

    public void Dispose()
    {
        Watcher.Dispose();
        try { Directory.Delete(Dir, true); } catch (IOException) { } catch (UnauthorizedAccessException) { }
    }
}
