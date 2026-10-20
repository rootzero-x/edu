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
    public string Title => Settings.Str("news.title", Settings.Nav("/dashboard/news", "Yangiliklar"));
    public string EmptyText => Settings.Text("news_empty", "Hozircha yangiliklar yo'q.");

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var r = await S.Api.AnnouncementsAsync(1, ct);
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
        IsEmpty = Items.Count == 0;
    }

    [RelayCommand]
    private async Task LoadMoreAsync()
    {
        if (!CanLoadMore) return;
        await ActAsync(async () => Append((await S.Api.AnnouncementsAsync(_page + 1)).Value));
    }

    partial void OnSelectedChanged(NewsItem? value)
    {
        if (value is { IsRead: false }) _ = MarkReadAsync(value);
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

    [RelayCommand]
    private void Copy(CertificateItem? item)
    {
        if (item is null) return;
        S.Platform.CopyText(item.Url);
        Notice = "Havola nusxalandi.";
    }
}
