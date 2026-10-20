using System.Collections.ObjectModel;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

public sealed partial class NewsItem(Announcement a, SiteSettings st) : ObservableObject
{
    public Announcement Announcement { get; } = a;
    public int Id => a.Id;
    public string Title => a.Title;
    public string Body => a.Body;
    public string Preview => a.Body.Length > 160 ? a.Body[..160].TrimEnd() + "…" : a.Body;
    public string Meta => string.Join(" · ", new[] { Format.DateTime(a.PublishedAt), a.Author, a.Group?.Name }.Where(x => !string.IsNullOrEmpty(x)));
    public bool Pinned => a.Pinned;
    public string PinnedText => st.Text("news_pinned", "Muhim");
    public string NewText => st.Text("news_new", "Yangi");
    public string? ImageUrl => string.IsNullOrWhiteSpace(a.ImageUrl) ? null : a.ImageUrl;
    public bool HasImage => ImageUrl is not null;

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsUnread))]
    private bool _isRead = a.Read;

    public bool IsUnread => !IsRead;
    public bool IsDraft => a.PublishedAt is null;
    public string StatusText => IsDraft ? "Qoralama" : "Chop etilgan";
}

/// <summary>Yangiliklar: sahifalab yuklash, ochilganda o'qilgan deb belgilash, menyu belgisi bilan sinxron.</summary>
public sealed partial class NewsViewModel(AppServices services) : PageViewModel(services)
{
    private int _page = 1;
    private int _pages = 1;

    public ObservableCollection<NewsItem> Items { get; } = [];

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(HasSelection))]
    private NewsItem? _selected;

    [ObservableProperty] private bool _isEmpty;
    [ObservableProperty] private bool _canLoadMore;
    [ObservableProperty] private int _unread;

    public bool HasSelection => Selected is not null;

    // ── Boshqarish (announcement.manage): o'z e'lonlari, admin — hammasi ──
    public bool CanManage => S.Session.Can(Permissions.AnnouncementManage);

    [ObservableProperty] private bool _isManageMode;

    partial void OnIsManageModeChanged(bool value)
    {
        Selected = null;
        _ = RefreshAsync();
    }

    private bool IsAdmin => S.Session.User?.Role is "ADMIN" or "SUPER_ADMIN";

    private async Task<List<KeyValuePair<string, string>>> GroupOptionsAsync()
    {
        try
        {
            var g = await S.Api.GroupsAsync();
            var me = S.Session.User?.Id;
            return g.Value.Data.Where(x => x.Active && (IsAdmin || x.TeacherId == me))
                .Select(x => Editing.Opt(x.Id.ToString(System.Globalization.CultureInfo.InvariantCulture), x.Name)).ToList();
        }
        catch (ApiException) { return []; }
    }

    [RelayCommand]
    private async Task NewAnnouncementAsync()
    {
        var groups = await GroupOptionsAsync();
        var ok = await S.Forms.ShowAsync("Yangi e'lon", Editing.AnnouncementForm(null, Settings, groups, IsAdmin),
            v => Submit(() => S.Api.CreateAnnouncementAsync(Editing.AnnouncementBody(v, create: true))), "Yaratish");
        if (!ok) return;
        if (!IsManageMode) IsManageMode = true; else await RefreshAsync();
        Notice = "E'lon yaratildi.";
    }

    [RelayCommand]
    private async Task EditAnnouncementAsync()
    {
        if (Selected is not { } item) return;
        var groups = await GroupOptionsAsync();
        await EditAsync("E'lonni tahrirlash", Editing.AnnouncementForm(item.Announcement, Settings, groups, IsAdmin),
            v => S.Api.UpdateAnnouncementAsync(item.Id, Editing.AnnouncementBody(v, create: false)), "E'lon saqlandi.");
    }

    [RelayCommand]
    private Task TogglePublishAsync()
    {
        if (Selected is not { } item) return Task.CompletedTask;
        var publish = item.IsDraft;
        return ConfirmActAsync(publish ? "Chop etish" : "Yashirish",
            publish ? $"«{item.Title}» chop etilsinmi? Sozlamaga ko'ra Telegram'da xabar yuboriladi." : $"«{item.Title}» yashirilsinmi (qoralamaga qaytadi)?",
            publish ? "Chop etish" : "Yashirish", () => S.Api.PublishAnnouncementAsync(item.Id, publish), publish ? "Chop etildi." : "Yashirildi.");
    }

    [RelayCommand]
    private Task DeleteAnnouncementAsync()
    {
        if (Selected is not { } item) return Task.CompletedTask;
        return ConfirmActAsync("E'lonni o'chirish", $"«{item.Title}» o'chirilsinmi?", "O'chirish",
            async () => { await S.Api.DeleteAnnouncementAsync(item.Id); Selected = null; }, "E'lon o'chirildi.");
    }
    public string Title => Settings.Str("news.title", Settings.Nav("/dashboard/news", "Yangiliklar"));
    public string EmptyText => Settings.Text("news_empty", "Hozircha yangiliklar yo'q.");

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var r = IsManageMode ? await S.Api.ManagedAnnouncementsAsync(1, ct) : await S.Api.AnnouncementsAsync(1, ct);
        Track(r);
        Items.Clear();
        Append(r.Value);
        if (Selected is not null) Selected = Items.FirstOrDefault(i => i.Id == Selected.Id);
    }

    private void Append(AnnouncementPage p)
    {
        foreach (var a in p.Data) Items.Add(new NewsItem(a, Settings));
        _page = p.Meta.Page;
        _pages = p.Meta.Pages;
        CanLoadMore = _page < _pages;
        Unread = p.Meta.Unread;
        S.Watcher.SetUnread(Unread);
        OnPropertyChanged(nameof(CanManage));
        IsEmpty = Items.Count == 0;
    }

    [RelayCommand]
    private async Task LoadMoreAsync()
    {
        if (!CanLoadMore) return;
        await ActAsync(async () => Append((IsManageMode ? await S.Api.ManagedAnnouncementsAsync(_page + 1) : await S.Api.AnnouncementsAsync(_page + 1)).Value));
    }

    partial void OnSelectedChanged(NewsItem? value)
    {
        if (value is { IsRead: false, IsDraft: false } && !IsManageMode) _ = MarkReadAsync(value);
    }

    private async Task MarkReadAsync(NewsItem item)
    {
        try
        {
            Unread = await S.Api.MarkReadAsync(item.Id);
            item.IsRead = true;
            S.Watcher.SetUnread(Unread);
        }
        catch (ApiException) { /* keyingi safar */ }
    }

    [RelayCommand]
    private async Task MarkAllReadAsync()
    {
        foreach (var item in Items.Where(i => i.IsUnread).ToList()) await MarkReadAsync(item);
    }

    public void Select(int id) => Selected = Items.FirstOrDefault(i => i.Id == id);
}

public sealed class CertificateItem(Certificate c, SiteSettings st)
{
    public Certificate Certificate { get; } = c;
    public string Code => c.Code;
    public string Student => c.StudentName;
    public string Course => c.Course ?? "";
    public string Meta => string.Join(" · ", new[] { c.Group, Format.Date(c.IssuedAt) }.Where(x => !string.IsNullOrEmpty(x)));
    public string Url => st.CertificateBaseUrl.TrimEnd('/') + "/" + Uri.EscapeDataString(c.Code);
    public string OpenText => st.Text("certificate_open", "Ochish");
    public bool Revoked => c.Revoked;
    public string RevokedText => "Bekor qilingan" + (string.IsNullOrEmpty(c.RevokedReason) ? "" : ": " + c.RevokedReason);
}

public sealed partial class CertificatesViewModel(AppServices services) : PageViewModel(services)
{
    private List<CertificateItem> _all = [];

    public ObservableCollection<CertificateItem> Items { get; } = [];

    [ObservableProperty] private bool _isEmpty;
    [ObservableProperty] private string _search = "";

    public string Title => Settings.Nav("/dashboard/certificates", "Sertifikatlar");
    public string EmptyText => Settings.Text("certificates_empty", "Hozircha sertifikat yo'q.");
    public bool ShowSearch => S.Session.Can(Permissions.GroupManage);

    partial void OnSearchChanged(string value) => Filter();

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var r = await S.Api.CertificatesAsync(ct);
        Track(r);
        _all = r.Value.Data.Select(c => new CertificateItem(c, Settings)).ToList();
        Filter();
    }

    private void Filter()
    {
        var q = Search.Trim();
        Items.Clear();
        foreach (var c in _all.Where(c => q.Length == 0 || c.Student.Contains(q, StringComparison.CurrentCultureIgnoreCase)
                     || c.Code.Contains(q, StringComparison.OrdinalIgnoreCase) || c.Course.Contains(q, StringComparison.CurrentCultureIgnoreCase)))
            Items.Add(c);
        IsEmpty = Items.Count == 0 && HasLoaded;
    }

    [RelayCommand]
    private void Open(CertificateItem? item)
    {
        if (item is not null) S.Platform.Open(new Uri(item.Url));
    }

    public bool CanRevoke => S.Session.Can(Permissions.GroupManage);

    [RelayCommand]
    private async Task RevokeAsync(CertificateItem? item)
    {
        if (item is null || item.Revoked) return;
        var reason = await S.Forms.PromptAsync("Sertifikatni bekor qilish", $"{item.Student} — {item.Code}: sabab", required: false, primary: "Bekor qilish");
        if (reason is null) return;
        if (await ActAsync(() => S.Api.RevokeCertificateAsync(item.Code, reason.Length > 0 ? reason : null)))
        {
            await RefreshAsync();
            Notice = "Sertifikat bekor qilindi.";
        }
    }

    [RelayCommand]
    private void Copy(CertificateItem? item)
    {
        if (item is null) return;
        S.Platform.CopyText(item.Url);
        Notice = "Havola nusxalandi.";
    }
}
