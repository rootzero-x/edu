using TisuEdu.Core.Api;
using TisuEdu.Core.Models;

namespace TisuEdu.Core.Services;

/// <summary>
/// /v1/announcements/unread ni davriy so'raydi: menyudagi belgi (badge) uchun son va yangi e'lonlar uchun
/// Windows bildirishnomasi. Bir e'lon faqat bir marta ko'rsatiladi (oxirgi ko'rsatilgan id diskda saqlanadi).
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

    public AnnouncementWatcher(Session session, SettingsService settings, INotifier notifier, string stateDir)
    {
        _session = session;
        _settings = settings;
        _notifier = notifier;
        _stateDir = stateDir;
    }

    /// <summary>Bildirishnomalar yoqilganmi (ilova sozlamasi).</summary>
    public bool NotificationsEnabled { get; set; } = true;

    public int Unread { get; private set; }

    public event EventHandler<int>? UnreadChanged;

    /// <summary>So'rovlar orasidagi oraliq: ui.refresh_seconds, lekin fon rejimida kamida 30 soniya.</summary>
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

    /// <summary>Joriy foydalanuvchi uchun holatni tiklash (har kirishda).</summary>
    internal void Reset()
    {
        Unread = 0;
        _stateFile = Path.Combine(_stateDir, $"notified-{_session.User?.Id ?? 0}.txt");
        _lastNotifiedId = ReadState();
    }

    public void Stop()
    {
        _cts?.Cancel();
        _cts?.Dispose();
        _cts = null;
    }

    /// <summary>Yangiliklar sahifasi o'qilganda belgi darhol yangilansin.</summary>
    public void SetUnread(int unread)
    {
        if (Unread == unread) return;
        Unread = unread;
        UnreadChanged?.Invoke(this, unread);
    }

    public async Task CheckNowAsync(CancellationToken ct = default)
    {
        if (!_session.IsSignedIn) return;
        int unread;
        try { unread = await _session.Api.UnreadAsync(ct).ConfigureAwait(false); }
        catch (ApiException) { return; }

        var grew = unread > Unread;
        SetUnread(unread);
        if (unread == 0 || (!grew && _lastNotifiedId >= 0)) return;

        AnnouncementPage page;
        try { page = (await _session.Api.AnnouncementsAsync(1, ct).ConfigureAwait(false)).Value; }
        catch (ApiException) { return; }

        var fresh = page.Data.Where(a => !a.Read).OrderBy(a => a.Id).ToList();
        if (fresh.Count == 0) return;
        var maxId = fresh.Max(a => a.Id);

        // Birinchi ishga tushirishda eski o'qilmaganlar bilan "bombardimon" qilinmaydi — faqat chegarani eslab qolamiz.
        if (_lastNotifiedId < 0)
        {
            SaveState(maxId);
            return;
        }
        if (NotificationsEnabled)
        {
            foreach (var a in fresh.Where(a => a.Id > _lastNotifiedId).TakeLast(3))
            {
                _notifier.ShowAnnouncement(a, _settings.Current.SiteName);
            }
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
