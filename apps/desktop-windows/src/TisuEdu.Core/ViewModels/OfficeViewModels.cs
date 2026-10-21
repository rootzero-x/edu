using System.Collections.ObjectModel;
using System.Globalization;
using System.Text.Json;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

// ── Audit jurnali ───────────────────────────────────────────

public sealed class AuditItem(AuditRow r)
{
    public string Header => $"{Format.DateTime(r.CreatedAt)} · {r.Actor?.Name ?? "tizim"}" + (r.Ip is null ? "" : $" · {r.Ip}");
    public string Action => r.ActionLabel ?? r.Action;
    public string Entity => string.Join(" ", new[] { r.EntityLabel ?? r.Entity, r.EntityId is { } id ? "#" + id.ToString(CultureInfo.InvariantCulture) : null, r.EntityName }
        .Where(x => !string.IsNullOrEmpty(x)));
    public string Diff => string.Join("\n", r.Diff.Select(d => $"{d.Field}: {Value(d.From)} → {Value(d.To)}"));
    public bool HasDiff => r.Diff.Count > 0;
    public string Note => r.Note is null ? "" : "Sabab: " + r.Note;
    public bool HasNote => r.Note is not null;

    internal static string Value(JsonElement v) => v.ValueKind switch
    {
        JsonValueKind.Undefined or JsonValueKind.Null => "—",
        JsonValueKind.String => v.GetString() ?? "",
        JsonValueKind.True => "ha",
        JsonValueKind.False => "yo'q",
        _ => v.GetRawText(),
    };
}

public sealed partial class AuditViewModel(AppServices services) : PageViewModel(services)
{
    private bool _facetsLoaded;

    public ObservableCollection<AuditItem> Items { get; } = [];
    public ObservableCollection<Option> Actions { get; } = [];
    public ObservableCollection<Option> Entities { get; } = [];
    public ObservableCollection<Option> Actors { get; } = [];

    [ObservableProperty] private int _actionIndex;
    [ObservableProperty] private int _entityIndex;
    [ObservableProperty] private int _actorIndex;
    [ObservableProperty] private DateTimeOffset? _from;
    [ObservableProperty] private DateTimeOffset? _to;
    [ObservableProperty] private int _page = 1;
    [ObservableProperty] private int _pages = 1;
    [ObservableProperty] private string _pageText = "";
    [ObservableProperty] private bool _isEmpty;

    public string Title => Settings.Nav("/dashboard/audit", "Audit jurnali");
    public bool CanPrev => Page > 1;
    public bool CanNext => Page < Pages;

    partial void OnActionIndexChanged(int value) => Filter();
    partial void OnEntityIndexChanged(int value) => Filter();
    partial void OnActorIndexChanged(int value) => Filter();
    partial void OnPageChanged(int value) { OnPropertyChanged(nameof(CanPrev)); OnPropertyChanged(nameof(CanNext)); }
    partial void OnPagesChanged(int value) => OnPropertyChanged(nameof(CanNext));

    private void Filter()
    {
        if (!_facetsLoaded) return;
        Page = 1;
        _ = RefreshAsync();
    }

    private static string? Key(ObservableCollection<Option> list, int i) =>
        i > 0 && i < list.Count ? list[i].Key : null;

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        if (!_facetsLoaded)
        {
            var f = await S.Api.AuditFacetsAsync(ct);
            Actions.Clear();
            Actions.Add(new Option("", "Barcha amallar"));
            // Prefiks bo'yicha guruhlar (masalan, "group" → group.*) va aniq amallar.
            foreach (var p in f.Actions.Select(a => a.Split('.')[0]).Distinct().OrderBy(x => x)) Actions.Add(new Option(p, p + ".*"));
            foreach (var a in f.Actions.OrderBy(x => x)) Actions.Add(new Option(a, f.ActionLabels.GetValueOrDefault(a) is { } l ? $"{l} ({a})" : a));
            Entities.Clear();
            Entities.Add(new Option("", "Barcha obyektlar"));
            foreach (var e in f.Entities.OrderBy(x => x)) Entities.Add(new Option(e, f.EntityLabels.GetValueOrDefault(e) ?? e));
            Actors.Clear();
            Actors.Add(new Option("", "Hamma"));
            foreach (var a in f.Actors) Actors.Add(new Option(a.Id.ToString(CultureInfo.InvariantCulture), a.Name + (a.Role is null ? "" : $" ({Settings.RoleLabel(a.Role)})")));
            ActionIndex = EntityIndex = ActorIndex = 0;
            _facetsLoaded = true;
        }
        var actor = Key(Actors, ActorIndex) is { } k && int.TryParse(k, out var id) ? id : (int?)null;
        var r = await S.Api.AuditAsync(Key(Actions, ActionIndex), Key(Entities, EntityIndex), actor,
            From is { } fr ? DateOnly.FromDateTime(fr.Date) : null, To is { } t ? DateOnly.FromDateTime(t.Date) : null, Page, ct);
        Items.Clear();
        foreach (var row in r.Data) Items.Add(new AuditItem(row));
        Pages = Math.Max(1, r.Meta.Pages);
        PageText = $"Jami: {r.Meta.Total} · sahifa {r.Meta.Page}/{Pages}";
        IsEmpty = Items.Count == 0;
    }

    [RelayCommand]
    private Task ApplyAsync() { Page = 1; return RefreshAsync(); }

    [RelayCommand]
    private Task PrevAsync() { if (CanPrev) Page--; return RefreshAsync(); }

    [RelayCommand]
    private Task NextAsync() { if (CanNext) Page++; return RefreshAsync(); }
}

// ── Ko'rsatkichlar (KPI) ────────────────────────────────────

public sealed class KpiMonthItem(KpiMonth m, KpiTargets targets)
{
    public string Month => m.Month + (m.Partial ? " (joriy)" : "");
    public string Attendance => Format.Percent(m.AttendanceRate);
    public double AttendanceValue => m.AttendanceRate ?? 0;
    public bool BelowTarget => m.AttendanceRate is { } r && r < targets.Attendance;
    public string Students => $"Faol: {m.ActiveStudents} · yangi: +{m.NewStudents} · ketgan: −{m.LeftStudents}" +
                              (m.GraduatedStudents > 0 ? $" · bitirgan: {m.GraduatedStudents}" : "") + (m.FrozenStudents > 0 ? $" · muzlatilgan: {m.FrozenStudents}" : "");
    public string Churn => "Ketish: " + Format.Percent(m.ChurnPercent);
    public string Quizzes => $"Testlar: {m.QuizAttempts} urinish, o'tish {Format.Percent(m.QuizPassRate)}";
    public string Homework => $"Uyga vazifa: topshirish {Format.Percent(m.HomeworkRate)}, o'rtacha {Format.Percent(m.HomeworkAvgPercent)}";
    public string Teaching => $"{m.Sessions} dars · {m.TeacherHours.ToString("0.#", CultureInfo.InvariantCulture)} soat";
}

public sealed class KpiTeacherItem(KpiTeacher t, IReadOnlyList<string> months)
{
    public string Name => t.Name;
    public string Hours => string.Join("   ", months.TakeLast(3).Select(m => $"{m}: {t.Hours.GetValueOrDefault(m).ToString("0.#", CultureInfo.InvariantCulture)} soat ({t.Sessions.GetValueOrDefault(m)} dars)"));
}

public sealed partial class KpiViewModel(AppServices services) : PageViewModel(services)
{
    public static readonly int[] MonthOptions = [3, 6, 12, 24];

    public ObservableCollection<KpiMonthItem> Months { get; } = [];
    public ObservableCollection<KpiTeacherItem> Teachers { get; } = [];
    public ObservableCollection<string> LeaveReasons { get; } = [];
    public ObservableCollection<string> HomeworkRows { get; } = [];

    [ObservableProperty] private int _monthsIndex = 1;
    [ObservableProperty] private string _targetsText = "";
    [ObservableProperty] private string _moneyText = "";
    [ObservableProperty] private bool _hasMoney;
    [ObservableProperty] private string _reachText = "";

    public string Title => "Ko'rsatkichlar";

    partial void OnMonthsIndexChanged(int value) => _ = RefreshAsync();

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var kpiTask = S.Api.KpiAsync(MonthOptions[Math.Clamp(MonthsIndex, 0, MonthOptions.Length - 1)], null, ct);
        var hwTask = S.Api.HomeworkReportAsync(new ReportFilter(), ct);
        var k = await kpiTask;
        Track(k);
        var kpi = k.Value;
        Months.Clear();
        foreach (var m in Enumerable.Reverse(kpi.Series)) Months.Add(new KpiMonthItem(m, kpi.Targets));
        Teachers.Clear();
        foreach (var t in kpi.Teachers) Teachers.Add(new KpiTeacherItem(t, kpi.Months));
        LeaveReasons.Clear();
        foreach (var r in kpi.LeaveReasons) LeaveReasons.Add($"{r.Reason ?? "Sababsiz"}: {r.Count}");
        TargetsText = $"Maqsadlar: davomat {kpi.Targets.Attendance}%, testdan o'tish {kpi.Targets.QuizPass}%";
        ReachText = kpi.TelegramReachPercent is { } p ? $"Telegram orqali yetib borish: {Format.Percent(p)}" : "";
        HasMoney = kpi.Money is not null;
        MoneyText = kpi.Money is { } money
            ? string.Join("   ", money.Series.TakeLast(3).Select(x => $"{x.Month}: tushum {Editing.Money(x.Income, Settings)}"))
              + $"\nJami qarz: {Editing.Money(money.DebtTotal, Settings)} ({money.Debtors} qarzdor)"
            : "";
        HomeworkRows.Clear();
        try
        {
            var hw = await hwTask;
            foreach (var row in hw.Value.Data)
                HomeworkRows.Add($"{row.Group} ({row.Teacher}): {row.Assignments} vazifa, topshirish {Format.Percent(row.SubmissionRate)}, o'rtacha {Format.Percent(row.AvgHomeworkPercent)}");
        }
        catch (ApiException) { }
    }
}

// ── Tizim holati ────────────────────────────────────────────

public sealed class JobItem(JobStatus j)
{
    public string Name => j.Job;
    public string Sub => $"har {Every(j.EveryMinutes)} · oxirgi: {Format.DateTime(j.LastRunAt)}" + (j.LastOkAt is { } ok ? $" · muvaffaqiyatli: {Format.DateTime(ok)}" : "");
    public string Error => j.LastError ?? "";
    public bool HasError => !string.IsNullOrEmpty(j.LastError);
    public bool Overdue => j.Overdue;
    public bool Ok => !j.Overdue && string.IsNullOrEmpty(j.LastError);

    private static string Every(int minutes) => minutes >= 1440 ? $"{minutes / 1440} kun" : minutes >= 60 ? $"{minutes / 60} soat" : $"{minutes} daqiqa";
}

public sealed partial class SystemViewModel(AppServices services) : PageViewModel(services)
{
    public ObservableCollection<JobItem> Jobs { get; } = [];
    public ObservableCollection<string> Warnings { get; } = [];

    [ObservableProperty] private string _scheduler = "";
    [ObservableProperty] private bool _schedulerOk;
    [ObservableProperty] private string _backup = "";
    [ObservableProperty] private bool _backupOk;
    [ObservableProperty] private string _telegram = "";
    [ObservableProperty] private string _users = "";
    [ObservableProperty] private bool _hasWarnings;

    public string Title => "Tizim holati";

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        var s = await S.Api.SystemStatusAsync(ct);
        SchedulerOk = !s.Scheduler.Stale && s.Scheduler.LastTick is not null;
        Scheduler = s.Scheduler.LastTick is null ? "Cron hali ishga tushmagan."
            : $"Oxirgi signal: {Format.DateTime(s.Scheduler.LastTick)} ({s.Scheduler.MinutesSince} daqiqa oldin)" + (s.Scheduler.Stale ? " — cron to'xtagan!" : "");
        Jobs.Clear();
        foreach (var j in s.Jobs) Jobs.Add(new JobItem(j));
        var b = s.Backup;
        BackupOk = b.Ok == true && b.Encrypted == true;
        Backup = b.At is null ? "Zaxira nusxa hali olinmagan."
            : $"Oxirgi zaxira: {Format.DateTime(b.At)} · {(b.Ok == true ? "muvaffaqiyatli" : "xato: " + b.Error)}"
              + (b.Size is { } size ? $" · {Uploads.Size(size)}" : "")
              + (b.Encrypted == true ? " · shifrlangan" : " · shifrlanmagan")
              + (b.Offsite is { } o ? (o.Ok == true ? " · Telegram'ga yuborildi" : o.Skipped is { } sk ? $" · tashqi nusxa: {sk}" : o.Error is { } e ? $" · tashqi nusxa xatosi: {e}" : "") : "");
        if (!b.EncryptionConfigured) Backup += "\nShifrlash kaliti (BACKUP_ENCRYPTION_KEY) sozlanmagan.";
        var t = s.Telegram;
        Telegram = $"Navbatda: {t.Pending} · 24 soatda yuborildi: {t.Sent24h}, xato: {t.Failed24h} · botni bloklaganlar: {t.BlockedChats} · ulanganlar: {t.Linked}";
        Users = $"Tasdiq kutayotganlar: {s.Users.Pending} · Telegram ulanmaganlar: {s.Users.NoTelegram}";
        Warnings.Clear();
        foreach (var w in s.Warnings) Warnings.Add(w switch
        {
            "cron_stale" => "Cron (rejalashtiruvchi) to'xtagan — eslatmalar, hisob-kitob va zaxira ishlamaydi.",
            "cron_never" => "Cron hali birorta marta ishlamagan — serverda cron sozlang.",
            "job_overdue" => "Ba'zi vazifalar o'z vaqtida bajarilmagan.",
            "backup_unencrypted" => "Zaxira nusxalar shifrlanmagan.",
            "teacher_course_manage" => "O'qituvchilarda barcha kurslarni boshqarish huquqi bor (course.manage) — tekshiring.",
            _ => w,
        });
        HasWarnings = Warnings.Count > 0;
    }
}

// ── O'quvchi profili ────────────────────────────────────────

public sealed class ProfileGroupItem(ProfileGroup g, SiteSettings st)
{
    public ProfileGroup Group { get; } = g;
    public string Name => g.Name;
    public string Sub => string.Join(" · ", new[] { g.CourseTitle, g.Teacher, g.Room }.Where(x => !string.IsNullOrEmpty(x)));
    public string Schedule => string.Join(", ", g.Schedule.Select(s => $"{Format.WeekdayShort(s.Weekday, st)} {s.Start}–{s.End}"))
        + (g.NextSession is { } n ? $" · keyingi dars: {Format.DateTime(n)}" : "");
    public string Status => st.ObjStr("enrollment.status_labels", g.Status, g.Status) + (g.FrozenUntil is { } u ? $" ({u} gacha)" : "") + (g.JoinedAt is { } j ? $" · {j} dan" : "");
    public bool Frozen => g.Status == "FROZEN";
}

public sealed class TimelineItem(TimelineRow r, SiteSettings st)
{
    public string Title => $"{r.GroupName}" + (r.CourseTitle is null ? "" : $" · {r.CourseTitle}");
    public string Period => $"{r.StartsOn} — {r.EndsOn ?? "hozir"}";
    public string State => r.Outcome is { } o
        ? st.ObjStr("enrollment.status_labels", o, o) + (r.TransferGroup is { } tg ? $" → {tg.Name}" : "")
        : st.ObjStr("enrollment.status_labels", r.Status, r.Status);
    public string Detail => string.Join(" · ", new[] { r.Reason, r.Note, r.CreatedBy is null ? null : "kiritdi: " + r.CreatedBy }.Where(x => !string.IsNullOrEmpty(x)));
    public bool HasDetail => Detail.Length > 0;
}

public sealed partial class StudentProfileViewModel(AppServices services, int userId) : PageViewModel(services)
{
    public ObservableCollection<ProfileGroupItem> Groups { get; } = [];
    public ObservableCollection<string> Recent { get; } = [];
    public ObservableCollection<string> Quizzes { get; } = [];
    public ObservableCollection<string> Certificates { get; } = [];
    public ObservableCollection<TimelineItem> Timeline { get; } = [];

    [ObservableProperty] private string _name = "";
    [ObservableProperty] private string _initials = "";
    [ObservableProperty] private string _sub = "";
    [ObservableProperty] private string _attendance = "";
    [ObservableProperty] private string _balance = "";
    [ObservableProperty] private bool _hasBalance;
    [ObservableProperty] private bool _isStudent;
    [ObservableProperty] private bool _hasTimeline;
    [ObservableProperty] private bool _canBilling;

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var r = await S.Api.UserProfileAsync(userId, ct);
        Track(r);
        var p = r.Value;
        var u = p.User;
        Name = u.FullName;
        Initials = string.Concat(new[] { u.FirstName, u.LastName }.Where(x => !string.IsNullOrWhiteSpace(x)).Select(x => char.ToUpperInvariant(x![0])));
        Sub = string.Join(" · ", new[] { Settings.RoleLabel(u.Role), u.Phone, u.Telegram ? "Telegram ulangan" : "Telegram ulanmagan",
            u.Active ? null : "bloklangan", u.Pending ? "tasdiq kutmoqda" : null, u.CreatedAt is { } c ? $"{Format.Date(c)} dan" : null }.Where(x => !string.IsNullOrEmpty(x)));
        IsStudent = p.Overview is not null;
        CanBilling = IsStudent && S.Session.Can(Permissions.BillingManage);
        Groups.Clear();
        Recent.Clear();
        Quizzes.Clear();
        Certificates.Clear();
        if (p.Overview is { } o)
        {
            foreach (var g in o.Groups) Groups.Add(new ProfileGroupItem(g, Settings));
            Attendance = $"So'nggi {o.Attendance.Days} kun: {Format.Percent(o.Attendance.Rate)} · " +
                         string.Join("  ", AttendanceStatus.All.Select(s => $"{Settings.StatusLabel(s)}: {o.Attendance.Counts.GetValueOrDefault(s)}"));
            foreach (var x in o.Attendance.Recent)
                Recent.Add($"{Format.DateTime(x.StartsAt)} · {x.GroupName} · {Settings.StatusLabel(x.Status)}" + (string.IsNullOrEmpty(x.Note) ? "" : $" · {x.Note}"));
            foreach (var q in o.Quizzes)
                Quizzes.Add($"{q.Title}{(q.IsFinal ? " (yakuniy)" : "")} · {Format.Percent(q.Percent)} · {(q.Passed ? "o'tdi" : "o'tmadi")} · {Format.Date(q.SubmittedAt)}");
            foreach (var cert in o.Certificates) Certificates.Add($"{cert.CourseTitle} · {cert.Code} · {cert.IssuedAt}");
            HasBalance = o.Balance is not null;
            Balance = o.Balance is { } b ? $"Balans: {Editing.Money(b.Balance, Settings)}" + (b.Debt > 0 ? $" · qarz: {Editing.Money(b.Debt, Settings)}" : "") : "";
        }
        Timeline.Clear();
        foreach (var t in p.Timeline) Timeline.Add(new TimelineItem(t, Settings));
        HasTimeline = Timeline.Count > 0;
    }

    [RelayCommand]
    private void OpenBilling() => S.Navigator.Navigate(Page.BillingStudent, userId);
}

// ── Arizalar (CRM) ──────────────────────────────────────────

internal static class LeadText
{
    public static readonly string[] Statuses = ["NEW", "CONTACTED", "TRIAL", "ENROLLED", "LOST"];

    public static string Status(string s, SiteSettings st) => st.ObjStr("leads.status_labels", s, s switch
    {
        "NEW" => "Yangi",
        "CONTACTED" => "Bog'lanildi",
        "TRIAL" => "Sinov darsi",
        "ENROLLED" => "Yozildi",
        "LOST" => "Rad etdi",
        _ => s,
    });

    public static string Source(string s, SiteSettings st) => st.ObjStr("leads.sources", s, s);

    public static List<KeyValuePair<string, string>> Sources(SiteSettings st) =>
        st.TryGet("leads.sources", out var v) && v.ValueKind == JsonValueKind.Object
            ? v.EnumerateObject().Select(p => Editing.Opt(p.Name, p.Value.GetString() ?? p.Name)).ToList()
            : [Editing.Opt("phone", "Telefon"), Editing.Opt("walk_in", "O'zi keldi"), Editing.Opt("other", "Boshqa")];
}

public sealed class LeadItem(Lead l, SiteSettings st)
{
    public Lead Lead { get; } = l;
    public string Name => l.Name;
    public string Phone => l.Phone;
    public string Status => LeadText.Status(l.Status, st);
    public string Sub => string.Join(" · ", new[] { LeadText.Source(l.Source, st), l.Course?.Title, l.Owner?.Name }.Where(x => !string.IsNullOrEmpty(x)));
    public string Next => l.NextContactOn is { } d ? "Bog'lanish: " + d : "";
    public bool Due => l.NextContactOn is { } d && string.CompareOrdinal(d, ServerClock.Now.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture)) <= 0
                       && l.Status is "NEW" or "CONTACTED" or "TRIAL";
    public string Comment => l.Comment ?? "";
    public bool Closed => l.Status is "ENROLLED" or "LOST";
}

public sealed partial class LeadsViewModel(AppServices services) : PageViewModel(services)
{
    public ObservableCollection<LeadItem> Items { get; } = [];
    public ObservableCollection<Option> StatusFilters { get; } = [];

    [ObservableProperty] private int _statusIndex = 1;
    [ObservableProperty] private string _search = "";
    [ObservableProperty] private bool _dueOnly;
    [ObservableProperty] private int _page = 1;
    [ObservableProperty] private int _pages = 1;
    [ObservableProperty] private string _pageText = "";
    [ObservableProperty] private string _dueText = "";
    [ObservableProperty] private string _funnel = "";
    [ObservableProperty] private bool _isEmpty;

    public string Title => Settings.Nav("/dashboard/leads", "Arizalar");
    public bool CanPrev => Page > 1;
    public bool CanNext => Page < Pages;

    partial void OnStatusIndexChanged(int value) { Page = 1; _ = RefreshAsync(); }
    partial void OnDueOnlyChanged(bool value) { Page = 1; _ = RefreshAsync(); }
    partial void OnPageChanged(int value) { OnPropertyChanged(nameof(CanPrev)); OnPropertyChanged(nameof(CanNext)); }
    partial void OnPagesChanged(int value) => OnPropertyChanged(nameof(CanNext));

    private void BuildFilters(Dictionary<string, int> counts)
    {
        var index = StatusIndex;
        var items = new List<Option>
        {
            new("", "Hammasi"),
            new("NEW,CONTACTED,TRIAL", $"Ochiq ({counts.GetValueOrDefault("NEW") + counts.GetValueOrDefault("CONTACTED") + counts.GetValueOrDefault("TRIAL")})"),
        };
        items.AddRange(LeadText.Statuses.Select(s => new Option(s, $"{LeadText.Status(s, Settings)} ({counts.GetValueOrDefault(s)})")));
        if (StatusFilters.Count == items.Count)
        {
            for (var i = 0; i < items.Count; i++) if (StatusFilters[i].Label != items[i].Label) StatusFilters[i] = items[i];
        }
        else
        {
            StatusFilters.Clear();
            foreach (var x in items) StatusFilters.Add(x);
        }
        if (StatusIndex != index) StatusIndex = index;
    }

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        string? status = StatusIndex switch
        {
            0 => null,
            1 => "NEW,CONTACTED,TRIAL",
            var i when i - 2 < LeadText.Statuses.Length => LeadText.Statuses[i - 2],
            _ => null,
        };
        var r = await S.Api.LeadsAsync(status, Search.Trim().Length > 0 ? Search.Trim() : null, DueOnly ? "today" : null, Page, ct);
        BuildFilters(r.Counts);
        Items.Clear();
        foreach (var l in r.Data) Items.Add(new LeadItem(l, Settings));
        Pages = Math.Max(1, r.Meta.Pages);
        PageText = $"Jami: {r.Meta.Total} · sahifa {r.Meta.Page}/{Pages}";
        DueText = r.DueToday > 0 ? $"Bugun bog'lanish kerak: {r.DueToday}" : "";
        IsEmpty = Items.Count == 0;
        try
        {
            var st = await S.Api.LeadStatsAsync(ct);
            var t = st.Total;
            Funnel = $"So'nggi 30 kun: {t.Total} ariza → bog'lanildi {t.Contacted} → sinov {t.Trial} → yozildi {t.Enrolled} (konversiya {Format.Percent(t.Conversion)}), rad etdi {t.Lost}";
        }
        catch (ApiException) { Funnel = ""; }
    }

    [RelayCommand]
    private Task SearchAsync() { Page = 1; return RefreshAsync(); }

    [RelayCommand]
    private Task PrevAsync() { if (CanPrev) Page--; return RefreshAsync(); }

    [RelayCommand]
    private Task NextAsync() { if (CanNext) Page++; return RefreshAsync(); }

    [RelayCommand]
    private void Open(LeadItem? item)
    {
        if (item is not null) S.Navigator.Navigate(Services.Page.Lead, item.Lead.Id);
    }

    [RelayCommand]
    private async Task NewLeadAsync()
    {
        LeadOptions options;
        try { options = await S.Api.LeadOptionsAsync(); }
        catch (ApiException ex) { ActionError = ex.Message; return; }
        int? created = null;
        var ok = await S.Forms.ShowAsync("Yangi ariza", LeadForms.Form(null, options, Settings), async v =>
        {
            try
            {
                var r = await S.Api.CreateLeadAsync(LeadForms.Body(v));
                created = r.GetProperty("lead").GetProperty("id").GetInt32();
                return null;
            }
            catch (ApiException ex) { return ex.Message; }
        }, "Qo'shish");
        if (ok && created is { } id) S.Navigator.Navigate(Services.Page.Lead, id);
    }
}

internal static class LeadForms
{
    public static List<FormField> Form(Lead? l, LeadOptions o, SiteSettings st) =>
    [
        new("name", "Ism", FieldKind.Text, l?.Name) { Required = true, MaxLength = 128 },
        new("phone", "Telefon", FieldKind.Text, l?.Phone ?? "+998") { Required = true },
        FormField.Choice("source", "Manba", LeadText.Sources(st), l?.Source ?? "phone"),
        FormField.Choice("course_id", "Kurs", [Editing.Opt("", "—"), .. o.Courses.Select(c => Editing.Opt(c.Id.ToString(CultureInfo.InvariantCulture), c.Title))],
            l?.Course?.Id.ToString(CultureInfo.InvariantCulture) ?? ""),
        FormField.Choice("owner_id", "Mas'ul", [Editing.Opt("", "—"), .. o.Data.Select(u => Editing.Opt(u.Id.ToString(CultureInfo.InvariantCulture), u.Name))],
            l?.Owner?.Id.ToString(CultureInfo.InvariantCulture) ?? ""),
        new("next_contact_on", "Keyingi bog'lanish", FieldKind.Date, l?.NextContactOn ?? Editing.Today),
        new("comment", "Izoh", FieldKind.Multiline, l?.Comment) { MaxLength = 2000 },
    ];

    public static Dictionary<string, object?> Body(FormValues v) => new()
    {
        ["name"] = v.Str("name"),
        ["phone"] = LoginViewModel.NormalizePhone(v.Str("phone")),
        ["source"] = v.Str("source"),
        ["course_id"] = v.Int("course_id"),
        ["owner_id"] = v.Int("owner_id"),
        ["next_contact_on"] = v.StrOrNull("next_contact_on"),
        ["comment"] = v.StrOrNull("comment"),
    };
}

public sealed class ActivityItem(LeadActivity a, SiteSettings st)
{
    public string Header => $"{Format.DateTime(a.CreatedAt)} · {a.User ?? "tizim"}";
    public string Title => a.Type switch
    {
        "CALL" => "Qo'ng'iroq",
        "NOTE" => "Izoh",
        "STATUS" => "Holat: " + Status("from") + " → " + Status("to"),
        _ => a.Data.ValueKind == JsonValueKind.Object && a.Data.TryGetProperty("event", out var e) ? e.GetString() switch
        {
            "created" => "Ariza yaratildi",
            "updated" => "Ariza o'zgartirildi",
            "duplicate" => "Takroriy ariza",
            var x => x ?? "",
        } : "Tizim",
    };
    public string Text => a.Text ?? "";
    public bool HasText => !string.IsNullOrWhiteSpace(a.Text);

    private string Status(string key) =>
        a.Data.ValueKind == JsonValueKind.Object && a.Data.TryGetProperty(key, out var v) && v.ValueKind == JsonValueKind.String
            ? LeadText.Status(v.GetString()!, st) : "—";
}

public sealed partial class LeadViewModel(AppServices services, int leadId) : PageViewModel(services)
{
    private Lead? _lead;

    public ObservableCollection<ActivityItem> Activities { get; } = [];

    [ObservableProperty] private string _name = "";
    [ObservableProperty] private string _sub = "";
    [ObservableProperty] private string _status = "";
    [ObservableProperty] private string _details = "";
    [ObservableProperty] private string _existing = "";
    [ObservableProperty] private bool _hasExisting;
    [ObservableProperty] private bool _isOpen;
    [ObservableProperty] private bool _canConvert;
    [ObservableProperty] private bool _hasStudent;

    private int? _existingUserId;

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        var d = await S.Api.LeadAsync(leadId, ct);
        var l = _lead = d.Lead;
        Name = l.Name;
        Sub = $"{l.Phone} · {LeadText.Source(l.Source, Settings)}" + (l.Telegram ? " · Telegram" : "");
        Status = LeadText.Status(l.Status, Settings) + (l.LostReason is { } lr ? $" — {lr}" : "");
        Details = string.Join("\n", new[]
        {
            l.Course is { } c ? "Kurs: " + c.Title : null,
            l.Group is { } g ? "Guruh: " + g.Name : null,
            l.Owner is { } o ? "Mas'ul: " + o.Name : null,
            l.NextContactOn is { } n ? "Keyingi bog'lanish: " + n : null,
            l.Trial is { } t ? $"Sinov darsi: {t.GroupName} · {Format.DateTime(t.StartsAt)}" + (t.Cancelled ? " (bekor qilingan)" : "") : null,
            string.IsNullOrWhiteSpace(l.Comment) ? null : "Izoh: " + l.Comment,
            l.ConvertedAt is { } cv ? "O'quvchiga aylantirildi: " + Format.DateTime(cv) : null,
        }.Where(x => x is not null));
        IsOpen = l.Status is "NEW" or "CONTACTED" or "TRIAL";
        CanConvert = l.StudentId is null && l.Status != "LOST";
        HasStudent = l.StudentId is not null;
        _existingUserId = d.ExistingUser?.Id;
        HasExisting = d.ExistingUser is not null && l.StudentId is null;
        Existing = d.ExistingUser is { } eu ? $"Bu raqam bilan foydalanuvchi bor: {eu.Name} ({Settings.RoleLabel(eu.Role)})" : "";
        Activities.Clear();
        foreach (var a in d.Activities) Activities.Add(new ActivityItem(a, Settings));
    }

    [RelayCommand]
    private async Task EditAsync()
    {
        if (_lead is null) return;
        LeadOptions o;
        try { o = await S.Api.LeadOptionsAsync(); }
        catch (ApiException ex) { ActionError = ex.Message; return; }
        await EditAsync("Arizani tahrirlash", LeadForms.Form(_lead, o, Settings), v => S.Api.UpdateLeadAsync(leadId, LeadForms.Body(v)), "Ariza saqlandi.");
    }

    [RelayCommand]
    private Task DeleteAsync() => ConfirmActAsync("Arizani o'chirish", $"«{Name}» arizasi butunlay o'chirilsinmi?", "O'chirish",
        async () => { await S.Api.DeleteLeadAsync(leadId); S.Navigator.GoBack(); }, "Ariza o'chirildi.", reload: false);

    [RelayCommand]
    private async Task ChangeStatusAsync()
    {
        if (_lead is null) return;
        List<TrialSession> trials = [];
        try { trials = await S.Api.TrialSessionsAsync(_lead.Course?.Id); } catch (ApiException) { }
        var lost = Settings.List("leads.lost_reasons");
        var statuses = LeadText.Statuses.Where(s => s != "ENROLLED").Select(s => Editing.Opt(s, LeadText.Status(s, Settings)));
        var fields = new List<FormField>
        {
            FormField.Choice("status", "Yangi holat", statuses, _lead.Status == "NEW" ? "CONTACTED" : _lead.Status),
            FormField.Choice("trial_session_id", "Sinov darsi («Sinov darsi» uchun)",
                [Editing.Opt("", "—"), .. trials.Select(t => Editing.Opt(t.Id.ToString(CultureInfo.InvariantCulture), $"{Format.DateTime(t.StartsAt)} · {t.GroupName} · {t.Teacher}"))], ""),
            lost.Count > 0
                ? FormField.Choice("lost_reason", "Rad etish sababi («Rad etdi» uchun)", lost.Select(x => Editing.Opt(x, x)), lost[0])
                : new FormField("lost_reason", "Rad etish sababi («Rad etdi» uchun)"),
            new("note", "Izoh", FieldKind.Multiline),
            new("next_contact_on", "Keyingi bog'lanish (ixtiyoriy)", FieldKind.Date),
        };
        await EditAsync("Holatni o'zgartirish", fields, v =>
        {
            var status = v.Str("status");
            var body = new Dictionary<string, object?> { ["status"] = status, ["note"] = v.StrOrNull("note") };
            if (status == "LOST") body["lost_reason"] = v.StrOrNull("lost_reason");
            if (status == "TRIAL" && v.Int("trial_session_id") is { } t) body["trial_session_id"] = t;
            if (v.StrOrNull("next_contact_on") is { } n && status is not "LOST") body["next_contact_on"] = n;
            return S.Api.LeadStatusAsync(leadId, body);
        }, "Holat o'zgartirildi.");
    }

    [RelayCommand]
    private Task AddActivityAsync() => EditAsync("Qo'ng'iroq yoki izoh",
    [
        FormField.Choice("type", "Turi", [Editing.Opt("CALL", "Qo'ng'iroq"), Editing.Opt("NOTE", "Izoh")], "CALL"),
        new FormField("text", "Matn", FieldKind.Multiline) { Required = true, MaxLength = 2000 },
        new FormField("next_contact_on", "Keyingi bog'lanish (ixtiyoriy)", FieldKind.Date),
    ], v =>
    {
        var body = new Dictionary<string, object?> { ["type"] = v.Str("type"), ["text"] = v.Str("text") };
        if (v.StrOrNull("next_contact_on") is { } n) body["next_contact_on"] = n;
        return S.Api.LeadActivityAsync(leadId, body);
    }, "Qo'shildi.");

    [RelayCommand]
    private async Task ConvertAsync()
    {
        if (_lead is null) return;
        List<Group> groups = [];
        try { groups = (await S.Api.GroupsAsync()).Value.Data.Where(g => g.Active).ToList(); } catch (ApiException) { }
        var parts = _lead.Name.Split(' ', 2, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);
        var preferred = _lead.Group?.Id ?? _lead.Trial?.GroupId;
        int? studentId = null;
        var ok = await S.Forms.ShowAsync("O'quvchiga aylantirish",
        [
            new FormField("first_name", "Ism", FieldKind.Text, parts.ElementAtOrDefault(0)) { Required = true, MaxLength = 64 },
            new FormField("last_name", "Familiya", FieldKind.Text, parts.ElementAtOrDefault(1)) { MaxLength = 64 },
            new FormField("phone", "Telefon", FieldKind.Text, _lead.Phone) { Required = true },
            FormField.Choice("group_id", "Guruhga qo'shish", [Editing.Opt("", "— (keyinroq)"), .. groups.Select(g => Editing.Opt(g.Id.ToString(CultureInfo.InvariantCulture), g.Name))],
                preferred?.ToString(CultureInfo.InvariantCulture) ?? ""),
            new FormField("start_date", "Boshlanish sanasi", FieldKind.Date, Editing.Today),
        ], async v =>
        {
            try
            {
                var r = await S.Api.ConvertLeadAsync(leadId, new Dictionary<string, object?>
                {
                    ["first_name"] = v.Str("first_name"),
                    ["last_name"] = v.StrOrNull("last_name"),
                    ["phone"] = LoginViewModel.NormalizePhone(v.Str("phone")),
                    ["group_id"] = v.Int("group_id"),
                    ["start_date"] = v.StrOrNull("start_date"),
                });
                studentId = r.GetProperty("student").GetProperty("id").GetInt32();
                return null;
            }
            catch (ApiException ex) { return ex.Message; }
        }, "Aylantirish");
        if (!ok) return;
        await RefreshAsync();
        Notice = "Ariza o'quvchiga aylantirildi.";
        if (studentId is { } id) S.Navigator.Navigate(Services.Page.StudentProfile, id);
    }

    [RelayCommand]
    private void OpenStudent()
    {
        var id = _lead?.StudentId ?? _existingUserId;
        if (id is { } u) S.Navigator.Navigate(Services.Page.StudentProfile, u);
    }

    [RelayCommand]
    private void Call()
    {
        if (_lead is not null) S.Platform.OpenUrl("tel:" + _lead.Phone);
    }
}

// ── Global qidiruv ──────────────────────────────────────────

public sealed class SearchHitItem(SearchHit h, SiteSettings st)
{
    public SearchHit Hit { get; } = h;
    public string Title => h.Title;
    public string Sub => string.Join(" · ", new[] { TypeLabel(h.Type), h.Subtitle }.Where(x => !string.IsNullOrEmpty(x)));
    public string Glyph => h.Type switch
    {
        "student" or "user" => "",
        "group" => "",
        "course" => "",
        "lead" => "",
        "certificate" => "",
        _ => "",
    };

    public override string ToString() => Title;

    private static string TypeLabel(string t) => t switch
    {
        "student" => "O'quvchi",
        "user" => "Foydalanuvchi",
        "group" => "Guruh",
        "course" => "Kurs",
        "lead" => "Ariza",
        "certificate" => "Sertifikat",
        _ => t,
    };
}

/// <summary>Ctrl+K qidiruv: odamlar, guruhlar, kurslar, arizalar, sertifikatlar (rolga qarab server cheklaydi).</summary>
public sealed class SearchService(AppServices s)
{
    private CancellationTokenSource? _cts;

    public async Task<IReadOnlyList<SearchHitItem>> SearchAsync(string q)
    {
        _cts?.Cancel();
        _cts = new CancellationTokenSource();
        var ct = _cts.Token;
        q = q.Trim();
        if (q.Length < 2) return [];
        try
        {
            await Task.Delay(250, ct); // yozish paytida har harfga so'rov yubormaslik
            var r = await s.Api.SearchAsync(q, ct);
            return r.Data.Select(h => new SearchHitItem(h, s.Settings.Current)).ToList();
        }
        catch (OperationCanceledException) { return []; }
        catch (ApiException) { return []; }
    }

    public void Open(SearchHitItem item)
    {
        var h = item.Hit;
        switch (h.Type)
        {
            case "student" or "user" when h.IntId is { } u:
                s.Navigator.Navigate(Page.StudentProfile, u);
                break;
            case "group" when h.IntId is { } g:
                s.Navigator.Navigate(Page.Group, g);
                break;
            case "course" when h.IntId is { } c:
                s.Navigator.Navigate(Page.Course, c);
                break;
            case "lead" when h.IntId is { } l:
                s.Navigator.Navigate(Page.Lead, l);
                break;
            case "certificate":
                s.Platform.OpenUrl(s.Settings.Current.CertificateBaseUrl.TrimEnd('/') + "/" + Uri.EscapeDataString(h.StrId));
                break;
            default:
                if (Links.Map(h.Href) is { } t) s.Navigator.Navigate(t.Page, t.Parameter);
                break;
        }
    }
}
