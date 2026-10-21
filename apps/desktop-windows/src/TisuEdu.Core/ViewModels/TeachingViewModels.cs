using System.Collections.ObjectModel;
using System.Globalization;
using System.Text.RegularExpressions;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;
using Group = TisuEdu.Core.Models.Group;

namespace TisuEdu.Core.ViewModels;

// ── Taqvim ──────────────────────────────────────────────────

public sealed class CalendarItem(CalendarEvent e, SiteSettings st)
{
    public CalendarEvent Event { get; } = e;
    public string Time => Format.TimeRange(e.StartsAt, e.EndsAt);
    public string Title => e.GroupName;
    public string Sub => string.Join(" · ", new[] { e.CourseTitle, e.Teacher, string.IsNullOrEmpty(e.Room) ? null : $"{st.Text("room", "Xona")}: {e.Room}" }
        .Where(x => !string.IsNullOrEmpty(x)));
    public string Topic => e.Topic ?? "";
    public bool HasTopic => !string.IsNullOrWhiteSpace(e.Topic);
    public bool Cancelled => e.Cancelled;
    public string CancelledText => st.Text("cancelled", "bekor qilindi");
    public string Extra => string.Join(" · ", new[]
    {
        e.MarkedCount is { } m && !e.Cancelled ? $"{m}/{e.StudentsCount} belgilangan" : $"{e.StudentsCount} {st.Text("students", "o'quvchi")}",
        e.HomeworkCount > 0 ? $"{e.HomeworkCount} ta uyga vazifa" : null,
    }.Where(x => x is not null));
}

public sealed class CalendarDay(DateTime date, IReadOnlyList<CalendarItem> items)
{
    public DateTime Date { get; } = date;
    public string Title => Format.LongDate(new DateTimeOffset(date, ServerClock.Zone.GetUtcOffset(date)));
    public bool IsToday => date.Date == ServerClock.Now.Date;
    public IReadOnlyList<CalendarItem> Items { get; } = items;
    public bool IsEmpty => Items.Count == 0;
}

/// <summary>Taqvim: hafta yoki oy, o'qituvchi/xona/guruh filtrlari, bekor qilingan darslar chizilgan.</summary>
public sealed partial class CalendarViewModel(AppServices services) : PageViewModel(services)
{
    private DateTime _anchor = ServerClock.Now.Date;
    private bool _filtersLoaded;

    public ObservableCollection<CalendarDay> Days { get; } = [];
    public ObservableCollection<Option> Teachers { get; } = [];
    public ObservableCollection<Option> Rooms { get; } = [];
    public ObservableCollection<Option> Groups { get; } = [];

    [ObservableProperty] private int _viewIndex = services.Settings.Current.Str("calendar.default_view", "week") == "month" ? 1 : 0;
    [ObservableProperty] private int _teacherIndex;
    [ObservableProperty] private int _roomIndex;
    [ObservableProperty] private int _groupIndex;
    [ObservableProperty] private string _rangeText = "";
    [ObservableProperty] private bool _isEmpty;

    public string Title => Settings.Nav("/dashboard/calendar", "Taqvim");
    public bool CanManageRooms => S.Session.Can(Permissions.GroupManage);

    partial void OnViewIndexChanged(int value) => _ = RefreshAsync();
    partial void OnTeacherIndexChanged(int value) { if (_filtersLoaded) _ = RefreshAsync(); }
    partial void OnRoomIndexChanged(int value) { if (_filtersLoaded) _ = RefreshAsync(); }
    partial void OnGroupIndexChanged(int value) { if (_filtersLoaded) _ = RefreshAsync(); }

    internal (DateTime From, DateTime To) Range()
    {
        if (ViewIndex == 1)
        {
            var first = new DateTime(_anchor.Year, _anchor.Month, 1);
            return (first, first.AddMonths(1).AddDays(-1));
        }
        var monday = _anchor.AddDays(-(Format.IsoWeekday(_anchor.DayOfWeek) - 1));
        return (monday, monday.AddDays(6));
    }

    private static int? Pick(ObservableCollection<Option> list, int index) =>
        index > 0 && index < list.Count && int.TryParse(list[index].Key, out var id) ? id : null;

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var (from, to) = Range();
        var r = await S.Api.CalendarAsync(DateOnly.FromDateTime(from), DateOnly.FromDateTime(to),
            Pick(Teachers, TeacherIndex), Pick(Rooms, RoomIndex), Pick(Groups, GroupIndex), ct);
        Track(r);
        if (!_filtersLoaded)
        {
            Fill(Teachers, "Barcha o'qituvchilar", r.Value.Filters.Teachers);
            Fill(Rooms, "Barcha xonalar", r.Value.Filters.Rooms);
            Fill(Groups, "Barcha guruhlar", r.Value.Filters.Groups);
            TeacherIndex = RoomIndex = GroupIndex = 0;
            _filtersLoaded = true;
        }
        RangeText = ViewIndex == 1
            ? $"{CultureInfo.InvariantCulture.TextInfo.ToTitleCase(Format.LongDate(new DateTimeOffset(from)).Split(',')[0].Split('-')[1])} {from.Year}"
            : $"{from:dd.MM} — {to:dd.MM.yyyy}";
        var byDay = r.Value.Data.GroupBy(e => ServerClock.ToServer(e.StartsAt).Date).ToDictionary(g => g.Key, g => g.OrderBy(e => e.StartsAt).ToList());
        Days.Clear();
        for (var d = from; d <= to; d = d.AddDays(1))
        {
            var items = byDay.TryGetValue(d, out var list) ? list.Select(e => new CalendarItem(e, Settings)).ToList() : [];
            // Oy ko'rinishida faqat darsi bor kunlar; haftada — hamma kunlar.
            if (ViewIndex == 0 || items.Count > 0) Days.Add(new CalendarDay(d, items));
        }
        IsEmpty = r.Value.Data.Count == 0;
    }

    private static void Fill(ObservableCollection<Option> target, string all, IEnumerable<IdName> items)
    {
        target.Clear();
        target.Add(new Option("", all));
        foreach (var i in items) target.Add(new Option(i.Id.ToString(CultureInfo.InvariantCulture), i.Name));
    }

    [RelayCommand]
    private Task PrevAsync()
    {
        _anchor = ViewIndex == 1 ? _anchor.AddMonths(-1) : _anchor.AddDays(-7);
        return RefreshAsync();
    }

    [RelayCommand]
    private Task NextAsync()
    {
        _anchor = ViewIndex == 1 ? _anchor.AddMonths(1) : _anchor.AddDays(7);
        return RefreshAsync();
    }

    [RelayCommand]
    private Task TodayAsync()
    {
        _anchor = ServerClock.Now.Date;
        return RefreshAsync();
    }

    [RelayCommand]
    private void Open(CalendarItem? item)
    {
        if (item is not null) S.Navigator.Navigate(Page.Sheet, item.Event.Id);
    }

    [RelayCommand]
    private void OpenRooms() => S.Navigator.Navigate(Page.Rooms);
}

// ── Xonalar ─────────────────────────────────────────────────

public sealed class RoomItem(Room r)
{
    public Room Room { get; } = r;
    public string Name => r.Name;
    public string Sub => string.Join(" · ", new[]
    {
        r.Capacity is { } c ? $"{c} o'rin" : null,
        $"{r.GroupsCount} faol guruh",
        r.Note,
    }.Where(x => !string.IsNullOrEmpty(x)));
    public bool Inactive => !r.Active;
}

public sealed partial class RoomsViewModel(AppServices services) : PageViewModel(services)
{
    public ObservableCollection<RoomItem> Items { get; } = [];

    [ObservableProperty] private bool _isEmpty;

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        var rooms = await S.Api.RoomsAsync(true, ct);
        Items.Clear();
        foreach (var r in rooms) Items.Add(new RoomItem(r));
        IsEmpty = Items.Count == 0;
    }

    private static List<FormField> Form(Room? r) =>
    [
        new("name", "Nomi", FieldKind.Text, r?.Name) { Required = true, MaxLength = 60 },
        new("capacity", "Sig'imi (o'rin)", FieldKind.Number, r?.Capacity?.ToString(CultureInfo.InvariantCulture)),
        new("note", "Izoh", FieldKind.Text, r?.Note) { MaxLength = 255 },
        FormField.Toggle("active", "Faol", r?.Active ?? true),
    ];

    private static Dictionary<string, object?> Body(FormValues v) => new()
    {
        ["name"] = v.Str("name"),
        ["capacity"] = v.Int("capacity"),
        ["note"] = v.StrOrNull("note"),
        ["active"] = v.Bool("active"),
    };

    [RelayCommand]
    private Task NewRoomAsync() => EditAsync("Yangi xona", Form(null), v => S.Api.CreateRoomAsync(Body(v)), "Xona qo'shildi.", "Qo'shish");

    [RelayCommand]
    private Task EditRoomAsync(RoomItem? r) => r is null ? Task.CompletedTask : EditAsync("Xonani tahrirlash", Form(r.Room), v => S.Api.UpdateRoomAsync(r.Room.Id, Body(v)), "Xona saqlandi.");

    [RelayCommand]
    private Task DeleteRoomAsync(RoomItem? r) => r is null ? Task.CompletedTask : ConfirmActAsync("Xonani o'chirish",
        $"«{r.Name}» o'chirilsinmi? Guruhlarga biriktirilgan xonani o'chirib bo'lmaydi — uni faolsizlantiring.", "O'chirish",
        () => S.Api.DeleteRoomAsync(r.Room.Id), "Xona o'chirildi.");
}

// ── Uyga vazifa ─────────────────────────────────────────────

internal static class HomeworkText
{
    public static string Status(string status, SiteSettings st) => st.ObjStr("homework.status_labels", status, status switch
    {
        "PENDING" => "Topshirilmagan",
        "SUBMITTED" => "Topshirildi",
        "LATE" => "Kechikib topshirildi",
        "GRADED" => "Baholandi",
        "MISSED" => "Muddati o'tgan",
        _ => status,
    });

    public static string Due(DateTimeOffset? due) => due is null ? "Muddatsiz" : "Muddat: " + Format.DateTime(due);
}

public sealed class AssignmentItem(Assignment a, bool teacher, SiteSettings st)
{
    public Assignment Assignment { get; } = a;
    public string Title => a.Title;
    public string Sub => string.Join(" · ", new[] { a.GroupName, a.CourseTitle, a.LessonTitle }.Where(x => !string.IsNullOrEmpty(x)));
    public string Due => HomeworkText.Due(a.DueAt);
    public bool Overdue => a.DueAt is { } d && d < DateTimeOffset.UtcNow;
    public string State => teacher
        ? a.Stats is { } s ? $"Topshirdi: {s.Submitted}/{s.Total} · baholandi: {s.Graded}" + (s.Late > 0 ? $" · kechikkan: {s.Late}" : "") : ""
        : a.My is { } m ? HomeworkText.Status(m.Status, st) + (m.Score is { } sc ? $" · {sc}/{a.MaxScore}" : "") : "";
    public bool NeedsAction => teacher ? a.Stats is { } s && s.Submitted > s.Graded : a.My is { Status: "PENDING" or "MISSED" };
    public bool HasFiles => a.Files.Count > 0;
}

public sealed class MarkEntryItem(MarkRow m)
{
    public string Title => m.Title ?? (m.Type == "homework" ? "Uyga vazifa" : "Dars");
    public string Sub => $"{m.GroupName} · {Format.Date(m.Date)} · {(m.Type == "homework" ? "uyga vazifa" : "darsdagi baho")}";
    public string Score => $"{m.Score}/{m.Max}";
    public string Note => m.Note ?? "";
    public bool HasNote => !string.IsNullOrWhiteSpace(m.Note);
}

/// <summary>Uyga vazifalar: o'qituvchi — o'z guruhlari (yaratish, topshirganlar), o'quvchi — vazifalar va baholar.</summary>
public sealed partial class HomeworkViewModel(AppServices services, string? tab) : PageViewModel(services)
{
    public ObservableCollection<AssignmentItem> Items { get; } = [];
    public ObservableCollection<MarkEntryItem> Marks { get; } = [];

    [ObservableProperty] private bool _onlyOpen = true;
    [ObservableProperty] private bool _isTeacher;
    [ObservableProperty] private bool _isStudent;
    [ObservableProperty] private bool _isEmpty;
    [ObservableProperty] private bool _hasMarks;
    [ObservableProperty] private string _averageText = "";
    [ObservableProperty] private int _tabIndex = tab == "marks" ? 1 : 0;

    public string Title => Settings.Nav("/dashboard/homework", "Uyga vazifa");

    partial void OnOnlyOpenChanged(bool value) => _ = RefreshAsync();

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var r = await S.Api.AssignmentsAsync(OnlyOpen ? "open" : "all", null, ct);
        Track(r);
        IsTeacher = r.Value.Mode == "teacher";
        IsStudent = !IsTeacher;
        Items.Clear();
        foreach (var a in r.Value.Data) Items.Add(new AssignmentItem(a, IsTeacher, Settings));
        IsEmpty = Items.Count == 0;

        Marks.Clear();
        if (IsStudent)
        {
            try
            {
                var m = await S.Api.MyMarksAsync(ct);
                foreach (var x in m.Value.Data) Marks.Add(new MarkEntryItem(x));
                AverageText = m.Value.AveragePercent is { } p ? $"O'rtacha natija: {Format.Percent(p)}" : "";
            }
            catch (ApiException) { }
        }
        HasMarks = Marks.Count > 0;
    }

    [RelayCommand]
    private void Open(AssignmentItem? item)
    {
        if (item is not null) S.Navigator.Navigate(Page.Assignment, item.Assignment.Id);
    }

    [RelayCommand]
    private async Task NewAssignmentAsync()
    {
        List<Group> groups;
        try { groups = (await S.Api.GroupsAsync()).Value.Data; }
        catch (ApiException ex) { ActionError = ex.Message; return; }
        var me = S.Session.User?.Id;
        var manage = S.Session.Can(Permissions.GroupManage);
        var options = groups.Where(g => g.Active && (manage || g.TeacherId == me))
            .Select(g => Editing.Opt(g.Id.ToString(CultureInfo.InvariantCulture), g.Name)).ToList();
        if (options.Count == 0) { ActionError = "Sizda faol guruh yo'q."; return; }
        int? created = null;
        var ok = await S.Forms.ShowAsync("Yangi uyga vazifa", AssignmentForms.Form(null, options, Settings), async v =>
        {
            var (body, error) = AssignmentForms.Body(v);
            if (body is null) return error;
            return await Submit(async () => created = (await S.Api.CreateAssignmentAsync(v.Int("group_id") ?? 0, body)).Assignment.Id);
        }, "Berish");
        if (ok && created is { } id) S.Navigator.Navigate(Page.Assignment, id);
    }
}

internal static class AssignmentForms
{
    public static List<FormField> Form(Assignment? a, IEnumerable<KeyValuePair<string, string>>? groups, SiteSettings st)
    {
        var due = a?.DueAt is { } d ? ServerClock.ToServer(d) : (DateTimeOffset?)null;
        var fields = new List<FormField>();
        if (groups is not null) fields.Add(FormField.Choice("group_id", "Guruh", groups, null));
        fields.AddRange(
        [
            new("title", "Mavzu", FieldKind.Text, a?.Title) { Required = true, MaxLength = 200 },
            new("body", "Topshiriq matni", FieldKind.Multiline, a?.Body),
            new("due_date", "Muddat (sana, ixtiyoriy)", FieldKind.Date, due?.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture)),
            new("due_time", "Muddat (vaqt, SS:DD)", FieldKind.Time, due?.ToString("HH:mm", CultureInfo.InvariantCulture) ?? "23:59") { Placeholder = "23:59" },
            new("max_score", $"Eng yuqori ball (bo'sh — {st.Int("marks.scale_max", 5)})", FieldKind.Number, a is null ? "" : a.MaxScore.ToString(CultureInfo.InvariantCulture)),
        ]);
        if (a is null) fields.Add(FormField.Toggle("notify", "O'quvchilarga Telegram'da xabar yuborish", true));
        return fields;
    }

    public static (Dictionary<string, object?>? Body, string? Error) Body(FormValues v)
    {
        var d = new Dictionary<string, object?> { ["title"] = v.Str("title"), ["body"] = v.StrOrNull("body") };
        if (v.StrOrNull("due_date") is { } date)
        {
            var time = v.Str("due_time");
            if (time.Length == 0) time = "23:59";
            if (time.Length == 4 && time[1] == ':') time = "0" + time;
            if (!Format.IsValidHhmm(time)) return (null, "Vaqt SS:DD ko'rinishida bo'lsin (masalan 18:00).");
            var local = DateTime.ParseExact($"{date} {time}", "yyyy-MM-dd HH:mm", CultureInfo.InvariantCulture);
            d["due_at"] = new DateTimeOffset(local, ServerClock.Zone.GetUtcOffset(local)).ToString("yyyy-MM-ddTHH:mm:sszzz", CultureInfo.InvariantCulture);
        }
        else
        {
            d["due_at"] = null;
        }
        if (v.Int("max_score") is { } m) d["max_score"] = m;
        if (v.Raw.ContainsKey("notify")) d["notify"] = v.Bool("notify");
        return (d, null);
    }
}

public sealed class FileItem(StoredFile f, bool canDelete)
{
    public StoredFile File { get; } = f;
    public string Name => f.Name;
    public string Size => Uploads.Size(f.Size);
    public bool CanDelete { get; } = canDelete;
}

public sealed class SubmissionItem(SubmissionRow r, int max, SiteSettings st)
{
    public SubmissionRow Row { get; } = r;
    public string Name => r.Name + (r.LeftGroup ? " (guruhdan chiqqan)" : "");
    public string Status => HomeworkText.Status(r.Status, st) + (r.SubmittedAt is { } t ? " · " + Format.DateTime(t) : "");
    public string Score => r.Score is { } s ? $"{s}/{max}" : "—";
    public string Body => string.Join("\n", new[] { r.Body, r.LinkUrl, r.Feedback is null ? null : "Izoh: " + r.Feedback }.Where(x => !string.IsNullOrWhiteSpace(x)));
    public bool HasBody => Body.Length > 0;
    public IReadOnlyList<FileItem> Files { get; } = r.Files.Select(f => new FileItem(f, false)).ToList();
    public bool HasFiles => r.Files.Count > 0;
    public bool Late => r.Status == "LATE";
}

/// <summary>Bitta vazifa: o'qituvchi — topshirganlar, baholash, fayllar; o'quvchi — topshirish (matn, havola, fayl) va natija.</summary>
public sealed partial class AssignmentViewModel(AppServices services, int assignmentId) : PageViewModel(services)
{
    private Assignment? _a;

    public ObservableCollection<FileItem> Files { get; } = [];
    public ObservableCollection<FileItem> MyFiles { get; } = [];
    public ObservableCollection<SubmissionItem> Submissions { get; } = [];

    [ObservableProperty] private string _title = "";
    [ObservableProperty] private string _sub = "";
    [ObservableProperty] private string _due = "";
    [ObservableProperty] private string _body = "";
    [ObservableProperty] private bool _canManage;
    [ObservableProperty] private bool _isStudent;
    [ObservableProperty] private string _statsText = "";
    [ObservableProperty] private string _myStatus = "";
    [ObservableProperty] private string _myResult = "";
    [ObservableProperty] private string _myAnswer = "";
    [ObservableProperty] private bool _canSubmit;
    [ObservableProperty] private bool _canAttach;
    [ObservableProperty] private bool _hasFiles;

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        var r = await S.Api.AssignmentAsync(assignmentId, ct);
        var a = _a = r.Assignment;
        Title = a.Title;
        Sub = string.Join(" · ", new[] { a.GroupName, a.CourseTitle, a.LessonTitle, a.Author }.Where(x => !string.IsNullOrEmpty(x)));
        Due = HomeworkText.Due(a.DueAt) + $" · eng yuqori ball: {a.MaxScore}";
        Body = a.Body ?? "";
        CanManage = a.CanManage;
        IsStudent = !a.CanManage;
        Files.Clear();
        foreach (var f in a.Files) Files.Add(new FileItem(f, a.CanManage));
        HasFiles = Files.Count > 0;

        Submissions.Clear();
        if (a.CanManage)
        {
            if (a.Stats is { } s) StatsText = $"Topshirdi: {s.Submitted}/{s.Total} · baholandi: {s.Graded} · kechikkan: {s.Late}";
            foreach (var sub in a.Submissions ?? []) Submissions.Add(new SubmissionItem(sub, a.MaxScore, Settings));
        }
        MyFiles.Clear();
        if (a.My is { } my)
        {
            MyStatus = HomeworkText.Status(my.Status, Settings) + (my.SubmittedAt is { } t ? " · " + Format.DateTime(t) : "");
            MyResult = my.Score is { } sc ? $"Baho: {sc}/{a.MaxScore}" + (string.IsNullOrWhiteSpace(my.Feedback) ? "" : $" · {my.Feedback}") : "";
            MyAnswer = string.Join("\n", new[] { my.Body, my.LinkUrl }.Where(x => !string.IsNullOrWhiteSpace(x)));
            CanSubmit = my.CanSubmit;
            CanAttach = my.CanSubmit && Settings.Bool("uploads.student_files", true) && Uploads.Enabled(Settings);
            foreach (var f in my.Files) MyFiles.Add(new FileItem(f, my.CanSubmit));
        }
    }

    [RelayCommand]
    private Task EditAsync() => _a is null ? Task.CompletedTask : EditAsync("Vazifani tahrirlash", AssignmentForms.Form(_a, null, Settings), async v =>
    {
        var (body, error) = AssignmentForms.Body(v);
        if (body is null) throw new ApiException(System.Net.HttpStatusCode.UnprocessableEntity, error!);
        await S.Api.UpdateAssignmentAsync(assignmentId, body);
    }, "Vazifa saqlandi.");

    [RelayCommand]
    private Task DeleteAsync() => ConfirmActAsync("Vazifani o'chirish", $"«{Title}» o'chirilsinmi? Topshirganlar bo'lsa o'chirib bo'lmaydi.", "O'chirish",
        async () => { await S.Api.DeleteAssignmentAsync(assignmentId); S.Navigator.GoBack(); }, "Vazifa o'chirildi.", reload: false);

    [RelayCommand]
    private async Task AttachAsync()
    {
        var file = await S.Platform.PickFileAsync(Uploads.PickerExtensions(Settings));
        if (file is null) return;
        if (Uploads.Validate(file, Settings) is { } problem) { ActionError = problem; return; }
        if (await ActAsync(() => CanManage
                ? S.Api.UploadAssignmentFileAsync(assignmentId, file.Name, file.Content, file.Mime)
                : S.Api.UploadSubmissionFileAsync(assignmentId, file.Name, file.Content, file.Mime)))
        {
            await RefreshAsync();
            Notice = CanManage ? "Fayl biriktirildi." : "Fayl biriktirildi. Javobni yuborish uchun «Topshirish»ni bosing.";
        }
    }

    [RelayCommand]
    private async Task OpenFileAsync(FileItem? f)
    {
        if (f is null) return;
        await ActAsync(async () =>
        {
            var link = await S.Api.FileLinkAsync(f.File.Id);
            S.Platform.OpenUrl(link.Url);
        });
    }

    [RelayCommand]
    private Task DeleteFileAsync(FileItem? f) => f is null ? Task.CompletedTask : ConfirmActAsync("Faylni o'chirish", $"«{f.Name}» o'chirilsinmi?", "O'chirish",
        () => S.Api.DeleteFileAsync(f.File.Id), "Fayl o'chirildi.");

    [RelayCommand]
    private Task SubmitAsync() => _a is null ? Task.CompletedTask : EditAsync("Javobni topshirish",
    [
        new FormField("body", "Javob", FieldKind.Multiline, _a.My?.Body),
        new FormField("link_url", "Havola (ixtiyoriy, https://…)", FieldKind.Text, _a.My?.LinkUrl),
    ], v => S.Api.SubmitAssignmentAsync(assignmentId, v.StrOrNull("body"), v.StrOrNull("link_url")), "Javob topshirildi.", "Topshirish");

    [RelayCommand]
    private Task GradeAsync(SubmissionItem? s)
    {
        if (s is null || _a is null) return Task.CompletedTask;
        var max = _a.MaxScore;
        return EditAsync($"{s.Row.Name} — baholash",
        [
            new FormField("score", $"Ball (0–{max}, bo'sh — bahoni olib tashlash)", FieldKind.Number, s.Row.Score?.ToString(CultureInfo.InvariantCulture)),
            new FormField("feedback", "Izoh", FieldKind.Multiline, s.Row.Feedback),
        ], v =>
        {
            var score = v.Int("score");
            if (score is < 0 || score > max) throw new ApiException(System.Net.HttpStatusCode.UnprocessableEntity, $"Ball 0–{max} oralig'ida bo'lsin.");
            return S.Api.GradeAsync(assignmentId, s.Row.StudentId, score, v.StrOrNull("feedback"));
        }, "Baho saqlandi.");
    }
}

// ── Bildirishnomalar markazi ────────────────────────────────

public sealed partial class NotificationVm(NotificationItem n) : ObservableObject
{
    private static readonly Regex Tags = new("<[^>]+>", RegexOptions.Compiled);

    public NotificationItem Item { get; } = n;
    public string Label => n.Label;
    public string Text => System.Net.WebUtility.HtmlDecode(Tags.Replace(n.Text, "")).Trim();
    public string When => Format.Ago(n.CreatedAt, DateTimeOffset.UtcNow);
    public bool HasLink => Links.Map(n.Url) is not null;

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsUnread))]
    private bool _isRead = n.Read;

    public bool IsUnread => !IsRead;

    public string Glyph => n.Type switch
    {
        "homework" => "",
        "mark" => "",
        "announcement" => "",
        "attendance" => "",
        "billing" => "",
        "certificate" => "",
        "lesson" => "",
        _ => "",
    };
}

public sealed partial class NotificationsViewModel(AppServices services) : PageViewModel(services)
{
    private int _page = 1;
    private int _pages = 1;

    public ObservableCollection<NotificationVm> Items { get; } = [];

    [ObservableProperty] private bool _onlyUnread;
    [ObservableProperty] private bool _isEmpty;
    [ObservableProperty] private bool _canLoadMore;
    [ObservableProperty] private int _unread;

    public string Title => Settings.Nav("/dashboard/notifications", "Bildirishnomalar");

    partial void OnOnlyUnreadChanged(bool value) => _ = RefreshAsync();

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var r = await S.Api.NotificationsAsync(OnlyUnread, 1, ct);
        Track(r);
        Items.Clear();
        Append(r.Value);
    }

    private void Append(NotificationPage p)
    {
        foreach (var n in p.Data) Items.Add(new NotificationVm(n));
        _page = p.Meta.Page;
        _pages = p.Meta.Pages;
        CanLoadMore = _page < _pages;
        Unread = p.Meta.Unread;
        S.Watcher.SetInboxUnread(p.Meta.Unread);
        S.Watcher.SetUnread(p.Meta.NewsUnread);
        IsEmpty = Items.Count == 0;
    }

    [RelayCommand]
    private Task LoadMoreAsync() => ActAsync(async () => Append((await S.Api.NotificationsAsync(OnlyUnread, _page + 1)).Value));

    [RelayCommand]
    private async Task MarkAllReadAsync()
    {
        if (await ActAsync(() => S.Api.MarkNotificationsReadAsync(null)))
        {
            foreach (var i in Items) i.IsRead = true;
            Unread = 0;
            S.Watcher.SetInboxUnread(0);
            S.Watcher.SetUnread(0);
        }
    }

    [RelayCommand]
    private async Task OpenAsync(NotificationVm? item)
    {
        if (item is null) return;
        if (item.IsUnread)
        {
            try
            {
                var r = await S.Api.MarkNotificationsReadAsync([item.Item.Id]);
                item.IsRead = true;
                if (r.TryGetProperty("unread", out var u) && u.TryGetInt32(out var n)) { Unread = n; S.Watcher.SetInboxUnread(n); }
            }
            catch (ApiException) { }
        }
        if (Links.Map(item.Item.Url) is { } target) S.Navigator.Navigate(target.Page, target.Parameter);
    }
}
