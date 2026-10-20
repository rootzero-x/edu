using System.Collections.ObjectModel;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

public sealed class GroupCard(Group g, SiteSettings st)
{
    public Group Group { get; } = g;
    public string Name => g.Name;
    public string Course => g.Course?.Title ?? "";
    public string Teacher => g.Teacher?.FullName ?? "";
    public string Schedule => GroupText.Schedule(g, st);
    public string Students => $"{g.StudentsCount} {st.Text("students", "o'quvchi")}";
    public bool Active => g.Active;
    public bool Inactive => !g.Active;
    public string Room => string.IsNullOrEmpty(g.Room) ? "" : $"{st.Text("room", "Xona")}: {g.Room}";
}

internal static class GroupText
{
    public static string Schedule(Group g, SiteSettings st) =>
        string.Join(", ", g.Schedules.OrderBy(s => s.Weekday).ThenBy(s => s.StartTime)
            .Select(s => $"{Format.WeekdayShort(s.Weekday, st)} {Format.Hhmm(s.StartTime)}–{Format.Hhmm(s.EndTime)}"));
}

public sealed partial class GroupsViewModel(AppServices services) : PageViewModel(services)
{
    private List<GroupCard> _all = [];

    public ObservableCollection<GroupCard> Items { get; } = [];

    [ObservableProperty] private string _search = "";
    [ObservableProperty] private bool _onlyActive = true;
    [ObservableProperty] private bool _isEmpty;

    public string Title => Settings.Nav("/dashboard/groups", "Guruhlar");

    partial void OnSearchChanged(string value) => ApplyFilter();
    partial void OnOnlyActiveChanged(bool value) => ApplyFilter();

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var r = await S.Api.GroupsAsync(ct);
        Track(r);
        _all = r.Value.Data.Select(g => new GroupCard(g, Settings)).ToList();
        ApplyFilter();
    }

    private void ApplyFilter()
    {
        var q = Search.Trim();
        Items.Clear();
        foreach (var g in _all.Where(g => (!OnlyActive || g.Active)
                     && (q.Length == 0 || g.Name.Contains(q, StringComparison.CurrentCultureIgnoreCase)
                         || g.Course.Contains(q, StringComparison.CurrentCultureIgnoreCase)
                         || g.Teacher.Contains(q, StringComparison.CurrentCultureIgnoreCase))))
            Items.Add(g);
        IsEmpty = Items.Count == 0 && HasLoaded;
    }

    [RelayCommand]
    private void Open(GroupCard? item)
    {
        if (item is not null) S.Navigator.Navigate(Page.Group, item.Group.Id);
    }
}

public sealed class SessionItem(ClassSession s, int students, SiteSettings st)
{
    public ClassSession Session { get; } = s;
    public string Date => Format.Date(s.StartsAt);
    public string Weekday => Format.WeekdayShort(Format.IsoWeekday(ServerClock.ToServer(s.StartsAt).DayOfWeek), st);
    public string Time => Format.TimeRange(s.StartsAt, s.EndsAt);
    public string Topic => s.Topic ?? "";
    public bool Cancelled => s.Cancelled;
    public string CancelledText => st.Text("cancelled", "bekor qilindi");
    public bool Future => s.StartsAt > DateTimeOffset.UtcNow;
    public string Marked => Future || s.Cancelled ? "" : $"{s.MarkedCount}/{students}";
    public bool Incomplete => !Future && !s.Cancelled && students > 0 && s.MarkedCount < students;
    public bool IsToday => ServerClock.ToServer(s.StartsAt).Date == ServerClock.Now.Date;
}

public sealed class StudentRow(int id, string name, string? phone, StudentAttendance? a, SiteSettings st)
{
    public int Id { get; } = id;
    public string Name { get; } = name;
    public string Phone { get; } = phone ?? "";
    public bool HasPhone => !string.IsNullOrEmpty(phone);
    public string Rate => Format.Percent(a?.Rate);
    public double RateValue => a?.Rate ?? 0;
    public bool HasRate => a?.Rate is not null;
    public string Counts => a is null ? "" : string.Join("  ", AttendanceStatus.All.Select(s => $"{st.StatusLabel(s)}: {a.Counts.GetValueOrDefault(s)}"));
}

/// <summary>Guruh: ma'lumot, jadval, o'quvchilar (davomat foizi bilan), darslar ro'yxati → davomat varaqasi.</summary>
public sealed partial class GroupViewModel(AppServices services, int groupId) : PageViewModel(services)
{
    public ObservableCollection<SessionItem> Sessions { get; } = [];
    public ObservableCollection<StudentRow> Students { get; } = [];

    [ObservableProperty] private string _name = "";
    [ObservableProperty] private string _course = "";
    [ObservableProperty] private string _teacher = "";
    [ObservableProperty] private string _schedule = "";
    [ObservableProperty] private string _period = "";
    [ObservableProperty] private string _room = "";
    [ObservableProperty] private string _branch = "";
    [ObservableProperty] private bool _active;
    [ObservableProperty] private bool _hasSessions;
    [ObservableProperty] private bool _hasStudents;
    [ObservableProperty] private bool _showUpcoming;

    public string StudentsTitle => $"O'quvchilar";
    public string SessionsTitle => "Darslar";
    public string StudentsEmpty => Settings.Text("sheet_empty", "Guruhda o'quvchi yo'q.");
    public string SessionsEmpty => Settings.Text("schedule_empty", "Yaqin kunlarda dars yo'q.");
    public string TeacherLabel => Settings.Text("teacher", "O'qituvchi");
    public string WeeklyLabel => Settings.Text("weekly", "Haftalik jadval");

    private List<ClassSession> _sessions = [];
    private int _studentsCount;

    partial void OnShowUpcomingChanged(bool value) => FillSessions();

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var today = DateOnly.FromDateTime(ServerClock.Now.DateTime);
        // Parallel: guruh, darslar (so'nggi 60 kun + keyingi 14 kun) va davomat foizlari.
        var groupTask = S.Api.GroupAsync(groupId, ct);
        var sessionsTask = S.Api.GroupSessionsAsync(groupId, today.AddDays(-60), today.AddDays(14), ct);
        var attendanceTask = S.Api.GroupAttendanceAsync(groupId, ct);

        var g = await groupTask;
        Track(g);
        var group = g.Value;
        Name = group.Name;
        Course = group.Course?.Title ?? "";
        Teacher = group.Teacher is null ? "" : group.Teacher.FullName + (string.IsNullOrEmpty(group.Teacher.Phone) ? "" : " · " + group.Teacher.Phone);
        Schedule = GroupText.Schedule(group, Settings);
        Period = string.Join(" — ", new[] { group.StartDate, group.EndDate }.Where(x => !string.IsNullOrEmpty(x)));
        Room = group.Room ?? "";
        Branch = group.Branch?.Name ?? "";
        Active = group.Active;
        _studentsCount = group.Students.Count;

        Dictionary<int, StudentAttendance> rates = [];
        try { rates = (await attendanceTask).Value.Data.ToDictionary(a => a.StudentId); }
        catch (ApiException) { }

        Students.Clear();
        foreach (var s in group.Students)
            Students.Add(new StudentRow(s.Id, s.FullName, s.Phone, rates.GetValueOrDefault(s.Id), Settings));
        // O'quvchi guruh a'zolari ro'yxatini emas, faqat o'zini ko'radi.
        if (Students.Count == 0)
            foreach (var r in rates.Values) Students.Add(new StudentRow(r.StudentId, r.Name, null, r, Settings));
        HasStudents = Students.Count > 0;

        try { _sessions = (await sessionsTask).Value.Data; }
        catch (ApiException) { _sessions = []; }
        FillSessions();
    }

    private void FillSessions()
    {
        Sessions.Clear();
        var now = DateTimeOffset.UtcNow;
        var list = ShowUpcoming
            ? _sessions.Where(s => s.StartsAt > now).OrderBy(s => s.StartsAt)
            : _sessions.Where(s => s.StartsAt <= now).OrderByDescending(s => s.StartsAt);
        foreach (var s in list) Sessions.Add(new SessionItem(s, _studentsCount, Settings));
        HasSessions = Sessions.Count > 0;
    }

    [RelayCommand]
    private void OpenSession(SessionItem? item)
    {
        if (item is not null) S.Navigator.Navigate(Page.Sheet, item.Session.Id);
    }
}

/// <summary>Belgilanmagan darslar (attendance.mark): o'tgan, bekor qilinmagan, to'liq belgilanmagan.</summary>
public sealed partial class AttendanceViewModel(AppServices services) : PageViewModel(services)
{
    public ObservableCollection<UnmarkedItem> Items { get; } = [];

    [ObservableProperty] private bool _isEmpty;

    public string Title => Settings.Text("nav_attendance", "Davomat");

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var r = await S.Api.UnmarkedAsync(ct);
        Track(r);
        Items.Clear();
        foreach (var u in r.Value.Data) Items.Add(new UnmarkedItem(u, Settings));
        IsEmpty = Items.Count == 0;
    }

    [RelayCommand]
    private void Open(UnmarkedItem? item)
    {
        if (item is not null) S.Navigator.Navigate(Page.Sheet, item.Session.Id);
    }
}

public sealed class UnmarkedItem(UnmarkedSession u, SiteSettings st)
{
    public UnmarkedSession Session { get; } = u;
    public string Group => u.Group.Name ?? "";
    public string When => $"{Format.Date(u.StartsAt)} · {Format.Time(u.StartsAt)}";
    public string Progress => st.Text("sheet_marked", "Belgilangan: {marked}/{total}", ("marked", u.Marked), ("total", u.Members));
    public double Ratio => u.Members == 0 ? 0 : 100.0 * u.Marked / u.Members;
}
