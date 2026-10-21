using TisuEdu.Core.Api;
using TisuEdu.Core.Models;

namespace TisuEdu.Core.Services;

/// <summary>
/// Davriy so'rov: bildirishnomalar markazi (/v1/notifications/unread — uyga vazifa, baholar, e'lonlar, eslatmalar)
/// va yangiliklar belgisi. Server inbox'ni qo'llamasa (404) — eski /announcements/unread ga o'tadi.
/// Yangi elementlar Windows bildirishnomasi sifatida bir marta ko'rsatiladi (oxirgi id diskda).
/// </summary>
public sealed class AnnouncementWatcher : IDisposable
{
    private readonly Session _session;
    private readonly SettingsService _settings;
    private readonly INotifier _notifier;
    private readonly string _stateDir;
    private string _stateFile = "";
    private CancellationTokenSource? _cts;
    private int _lastNotifiedId = -1;
    private bool _inbox = true;

    public AnnouncementWatcher(Session session, SettingsService settings, INotifier notifier, string stateDir)
    {
        _session = session;
        _settings = settings;
        _notifier = notifier;
        _stateDir = stateDir;
    }

    public bool NotificationsEnabled { get; set; } = true;

    /// <summary>Yangiliklar (e'lonlar) o'qilmaganlari.</summary>
    public int Unread { get; private set; }

    /// <summary>Bildirishnomalar markazi o'qilmaganlari.</summary>
    public int InboxUnread { get; private set; }

    public bool InboxAvailable => _inbox;

    public event EventHandler<int>? UnreadChanged;

    public event EventHandler<int>? InboxUnreadChanged;

    public TimeSpan Interval
    {
        get
        {
            var s = _settings.Current.RefreshSeconds;
            return TimeSpan.FromSeconds(s <= 0 ? 120 : Math.Max(30, s));
        }
    }

    public void Start()
    {
        Stop();
        _cts = new CancellationTokenSource();
        Reset();
        _ = LoopAsync(_cts.Token);
    }

    internal void Reset()
    {
        Unread = 0;
        InboxUnread = 0;
        _inbox = _settings.Current.Bool("notifications.inbox_enabled", true);
        _stateFile = Path.Combine(_stateDir, $"notified-{_session.User?.Id ?? 0}.txt");
        _lastNotifiedId = ReadState();
    }

    public void Stop()
    {
        _cts?.Cancel();
        _cts?.Dispose();
        _cts = null;
    }

    public void SetUnread(int unread)
    {
        if (Unread == unread) return;
        Unread = unread;
        UnreadChanged?.Invoke(this, unread);
    }

    public void SetInboxUnread(int unread)
    {
        if (InboxUnread == unread) return;
        InboxUnread = unread;
        InboxUnreadChanged?.Invoke(this, unread);
    }

    public async Task CheckNowAsync(CancellationToken ct = default)
    {
        if (!_session.IsSignedIn) return;
        if (_inbox)
        {
            try
            {
                var r = await _session.Api.NotificationsUnreadAsync(ct).ConfigureAwait(false);
                SetUnread(r.NewsUnread);
                SetInboxUnread(r.Unread);
                Notify(r.Latest.Where(n => !n.Read).Select(n => (n.Id, (Action)(() => _notifier.ShowNotification(n, _settings.Current.SiteName)))).ToList());
                return;
            }
            catch (ApiException ex) when (ex.Status is System.Net.HttpStatusCode.NotFound or System.Net.HttpStatusCode.Forbidden)
            {
                _inbox = false; // eski server — e'lonlar bo'yicha ishlaymiz
            }
            catch (ApiException) { return; }
        }

        int unread;
        try { unread = await _session.Api.UnreadAsync(ct).ConfigureAwait(false); }
        catch (ApiException) { return; }

        var grew = unread > Unread;
        SetUnread(unread);
        if (unread == 0 || (!grew && _lastNotifiedId >= 0)) return;

        AnnouncementPage page;
        try { page = (await _session.Api.AnnouncementsAsync(1, ct).ConfigureAwait(false)).Value; }
        catch (ApiException) { return; }
        Notify(page.Data.Where(a => !a.Read).Select(a => (a.Id, (Action)(() => _notifier.ShowAnnouncement(a, _settings.Current.SiteName)))).ToList());
    }

    /// <summary>Birinchi tekshiruvda faqat chegara eslab qolinadi (eski o'qilmaganlar bilan "bombardimon" qilinmaydi).</summary>
    private void Notify(List<(int Id, Action Show)> fresh)
    {
        if (fresh.Count == 0) return;
        var maxId = fresh.Max(x => x.Id);
        if (_lastNotifiedId < 0)
        {
            SaveState(maxId);
            return;
        }
        if (NotificationsEnabled)
        {
            foreach (var x in fresh.Where(x => x.Id > _lastNotifiedId).OrderBy(x => x.Id).TakeLast(3)) x.Show();
        }
        if (maxId > _lastNotifiedId) SaveState(maxId);
    }

    private async Task LoopAsync(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            await CheckNowAsync(ct).ConfigureAwait(false);
            try { await Task.Delay(Interval, ct).ConfigureAwait(false); }
            catch (OperationCanceledException) { return; }
        }
    }

    private int ReadState()
    {
        try { return File.Exists(_stateFile) && int.TryParse(File.ReadAllText(_stateFile), out var id) ? id : -1; }
        catch (IOException) { return -1; }
    }

    private void SaveState(int id)
    {
        _lastNotifiedId = id;
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_stateFile)!);
            File.WriteAllText(_stateFile, id.ToString(System.Globalization.CultureInfo.InvariantCulture));
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
    }

    public void Dispose() => Stop();
}
