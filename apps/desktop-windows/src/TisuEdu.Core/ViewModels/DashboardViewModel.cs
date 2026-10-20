using System.Collections.ObjectModel;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

public sealed record StatCard(string Title, string Value, string? Caption, string Glyph, Page? Target = null);

public sealed class TodayItem(TodaySession s, SiteSettings settings)
{
    public TodaySession Session { get; } = s;
    public string Time => Format.TimeRange(s.StartsAt, s.EndsAt);
    public string Title => s.GroupName;
    public string Subtitle => string.Join(" · ", new[] { s.CourseTitle, s.Teacher, string.IsNullOrEmpty(s.Room) ? null : $"{settings.Text("room", "Xona")}: {s.Room}" }.Where(x => !string.IsNullOrEmpty(x)));
    public string? Topic => s.Topic;
    public bool HasTopic => !string.IsNullOrWhiteSpace(s.Topic);
    public bool Cancelled => s.Cancelled;
    public string CancelledText => settings.Text("cancelled", "bekor qilindi");
    public string Marked => settings.Text("sheet_marked", "Belgilangan: {marked}/{total}", ("marked", s.MarkedCount), ("total", s.StudentsCount));
    public bool CanMark => s.CanMark;
    public bool IsDone => !s.Cancelled && s.StudentsCount > 0 && s.MarkedCount >= s.StudentsCount;
}

/// <summary>Bosh sahifa: /v1/dashboard/summary + so'nggi yangiliklar (parallel so'rovlar).</summary>
public sealed partial class DashboardViewModel(AppServices services) : PageViewModel(services)
{
    public ObservableCollection<StatCard> Stats { get; } = [];
    public ObservableCollection<TodayItem> Today { get; } = [];
    public ObservableCollection<Announcement> News { get; } = [];

    [ObservableProperty] private string _greeting = "";
    [ObservableProperty] private string _dateText = "";
    [ObservableProperty] private bool _hasToday;
    [ObservableProperty] private bool _hasNews;
    [ObservableProperty] private int _unmarked;
    [ObservableProperty] private bool _hasUnmarked;

    public string TodayTitle => Settings.Text("today_title", "Bugungi darslar");
    public string TodayEmpty => Settings.Text("today_empty", "Bugun dars yo'q.");
    public string NewsTitle => Settings.Str("news.title", "Yangiliklar va e'lonlar");
    public string NewsEmpty => Settings.Text("news_empty", "Hozircha yangiliklar yo'q.");
    public string UnmarkedText => $"Davomati belgilanmagan darslar: {Unmarked}";

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var user = S.Session.User;
        Greeting = Settings.Text("hello", "Salom, {name}!", ("name", user?.FirstName));
        DateText = Format.LongDate(ServerClock.Now);

        // Parallel: xulosa va yangiliklar bir vaqtda.
        var summaryTask = S.Api.DashboardAsync(ct);
        var newsTask = S.Api.AnnouncementsAsync(1, ct);
        var summary = await summaryTask;
        Track(summary);
        Apply(summary.Value);

        try
        {
            var news = await newsTask;
            News.Clear();
            foreach (var a in news.Value.Data.Take(4)) News.Add(a);
            HasNews = News.Count > 0;
            S.Watcher.SetUnread(news.Value.Meta.Unread);
        }
        catch (ApiException) { HasNews = false; }
    }

    private void Apply(DashboardSummary d)
    {
        var st = d.Stats;
        var labels = (Func<string, string>)Settings.StatusLabel;
        Stats.Clear();
        Stats.Add(new StatCard(TodayTitle, Format.Number(st.TodaySessions),
            st.UnmarkedSessions is null ? null : Settings.Text("sheet_marked", "Belgilangan: {marked}/{total}", ("marked", st.TodayMarked), ("total", st.TodaySessions)),
            "", S.Session.Can(Permissions.AttendanceMark) ? Page.Attendance : null));
        Stats.Add(new StatCard(Settings.Text("attendance_rate", "Davomat"), Format.Percent(st.AttendanceRate),
            $"{st.AttendanceDays} kun · {labels(AttendanceStatus.Absent)}: {st.AttendanceCounts.GetValueOrDefault(AttendanceStatus.Absent)}", ""));
        Stats.Add(new StatCard(S.Settings.Current.Nav("/dashboard/groups", "Guruhlar"), Format.Number(st.Groups), null, "", Page.Groups));
        if (st.Courses is not null)
            Stats.Add(new StatCard(S.Settings.Current.Nav("/dashboard/courses", "Kurslar"), Format.Number(st.Courses), null, "", Page.Courses));
        if (st.Students is not null)
            Stats.Add(new StatCard("O'quvchilar", Format.Number(st.Students), null, ""));

        Today.Clear();
        foreach (var s in d.Today) Today.Add(new TodayItem(s, Settings));
        HasToday = Today.Count > 0;
        Unmarked = st.UnmarkedSessions ?? 0;
        HasUnmarked = Unmarked > 0;
        OnPropertyChanged(nameof(UnmarkedText));
    }

    [RelayCommand]
    private void OpenSession(TodayItem? item)
    {
        if (item is not null) S.Navigator.Navigate(Page.Sheet, item.Session.Id);
    }

    [RelayCommand]
    private void OpenStat(StatCard? card)
    {
        if (card?.Target is { } p) S.Navigator.Navigate(p);
    }

    [RelayCommand]
    private void OpenNews() => S.Navigator.Navigate(Page.News);

    [RelayCommand]
    private void OpenUnmarked() => S.Navigator.Navigate(Page.Attendance);
}
