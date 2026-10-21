using System.Collections.ObjectModel;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;
using Group = TisuEdu.Core.Models.Group;

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

    public bool CanManage => S.Session.Can(Permissions.GroupManage);

    [RelayCommand]
    private async Task NewGroupAsync()
    {
        var (courses, teachers) = await GroupForms.OptionsAsync(S);
        if (courses is null) { ActionError = teachers is null ? Strings.Offline : null; return; }
        var ok = await S.Forms.ShowAsync("Yangi guruh", Editing.GroupForm(null, courses, teachers!, Settings), v =>
        {
            var (body, error) = Editing.GroupBody(v, Settings, edit: false);
            return body is null ? Task.FromResult(error) : Submit(() => S.Api.CreateGroupAsync(body));
        }, "Yaratish");
        if (ok) { await RefreshAsync(); Notice = "Guruh yaratildi. Darslarni jadvaldan yaratishni unutmang."; }
    }
}

internal static class GroupForms
{
    /// <summary>Kurslar va o'qituvchilar ro'yxati (parallel).</summary>
    public static async Task<(List<KeyValuePair<string, string>>? Courses, List<KeyValuePair<string, string>>? Teachers)> OptionsAsync(AppServices s)
    {
        try
        {
            var coursesTask = s.Api.CoursesAsync();
            var teachersTask = s.Api.TeachersAsync();
            await Task.WhenAll(coursesTask, teachersTask);
            var inv = System.Globalization.CultureInfo.InvariantCulture;
            return (coursesTask.Result.Value.Data.Select(c => Editing.Opt(c.Id.ToString(inv), c.Title)).ToList(),
                teachersTask.Result.Select(t => Editing.Opt(t.Id.ToString(inv), t.FullName)).ToList());
        }
        catch (ApiException) { return (null, null); }
    }
}

public sealed class CandidateItem(CertificateCandidate c)
{
    public CertificateCandidate Candidate { get; } = c;
    public string Name => c.Name + (c.Left ? " (chiqib ketgan)" : "");
    public string Meta => $"Davomat: {Format.Percent(c.AttendanceRate)} · {c.MarkedSessions} dars · yakuniy test: {(c.FinalQuizPassed ? "o'tgan" : "o'tmagan")}";
    public bool HasCertificate => c.Certificate is not null;
    public bool Eligible => c.Certificate is null && c.Blockers.Count == 0;
    public string Status => c.Certificate is { } code ? $"Berilgan: {code}"
        : c.Blockers.Count == 0 ? "Berish mumkin"
        : string.Join(", ", c.Blockers.Select(b => b switch
        {
            "NO_ATTENDANCE_DATA" => "davomat ma'lumoti kam",
            "LOW_ATTENDANCE" => "davomat past",
            "QUIZ_NOT_PASSED" => "yakuniy test topshirilmagan",
            _ => b,
        }));
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
    public bool CanManage { get; init; }
    /// <summary>ACTIVE | FROZEN (a'zolik holati, faqat xodimlarga).</summary>
    public string? Status { get; init; }
    public string StatusText { get; init; } = "";
    public bool Frozen => Status == "FROZEN";
    public bool CanOpenProfile { get; init; }
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
        _group = group;
        Name = group.Name;
        Course = group.Course?.Title ?? "";
        Teacher = group.Teacher is null ? "" : group.Teacher.FullName + (string.IsNullOrEmpty(group.Teacher.Phone) ? "" : " · " + group.Teacher.Phone);
        Schedule = GroupText.Schedule(group, Settings);
        Period = string.Join(" — ", new[] { group.StartDate, group.EndDate }.Where(x => !string.IsNullOrEmpty(x)));
        Room = group.Room ?? "";
        Active = group.Active;
        _studentsCount = group.Students.Count;

        Dictionary<int, StudentAttendance> rates = [];
        try { rates = (await attendanceTask).Value.Data.ToDictionary(a => a.StudentId); }
        catch (ApiException) { }

        // Xodim: a'zolik holatlari bilan (muzlatilgan, qachondan) va sobiq a'zolar.
        MembersFull? members = null;
        if (CanManage || S.Session.Can(Permissions.AttendanceMark))
        {
            try { members = await S.Api.MembersFullAsync(groupId, ct); } catch (ApiException) { }
        }
        var staff = S.Session.Can(Permissions.AttendanceMark) || CanManage;
        Students.Clear();
        if (members is not null)
        {
            foreach (var m in members.Current)
                Students.Add(new StudentRow(m.Id, m.FullName, m.Phone, rates.GetValueOrDefault(m.Id), Settings)
                {
                    CanManage = CanManage,
                    Status = m.Status,
                    CanOpenProfile = staff,
                    StatusText = m.Status == "FROZEN"
                        ? Settings.ObjStr("enrollment.status_labels", "FROZEN", "Muzlatilgan") + (m.Until is { } u ? $" ({u} gacha)" : "") + (m.Reason is { } rs ? $" · {rs}" : "")
                        : m.FrozenFrom is { } ff ? $"Muzlatish rejalashtirilgan: {ff} — {m.FrozenTo}" : m.JoinedAt is { } j ? $"{j} dan" : "",
                });
            Former.Clear();
            foreach (var f in members.Former)
                Former.Add($"{f.FullName} · {Settings.ObjStr("enrollment.status_labels", f.Status, f.Status)}" +
                           (f.TransferGroup is { } tg ? $" → {tg.Name}" : "") + $" · {f.JoinedAt} — {f.LeftOn}" + (f.Reason is { } r ? $" · {r}" : ""));
            HasFormer = Former.Count > 0;
        }
        else
        {
            foreach (var s in group.Students)
                Students.Add(new StudentRow(s.Id, s.FullName, s.Phone, rates.GetValueOrDefault(s.Id), Settings) { CanManage = CanManage, CanOpenProfile = staff });
        }
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

    // ── Boshqarish (group.manage) ────────────────────────────
    private Group? _group;

    public bool CanManage => S.Session.Can(Permissions.GroupManage);

    public ObservableCollection<CandidateItem> Candidates { get; } = [];

    [ObservableProperty] private bool _hasCandidates;
    [ObservableProperty] private bool _canIssue;

    [RelayCommand]
    private async Task EditGroupAsync()
    {
        if (_group is null) return;
        var (courses, teachers) = await GroupForms.OptionsAsync(S);
        if (courses is null) { ActionError = Strings.Offline; return; }
        var g = _group;
        var ok = await S.Forms.ShowAsync("Guruhni tahrirlash", Editing.GroupForm(g, courses, teachers!, Settings), v =>
        {
            var (body, error) = Editing.GroupBody(v, Settings, edit: true);
            return body is null ? Task.FromResult(error) : Submit(() => S.Api.UpdateGroupAsync(groupId, body));
        });
        if (ok) { await RefreshAsync(); Notice = "Guruh saqlandi."; }
    }

    [RelayCommand]
    private Task GenerateSessionsAsync() => ConfirmActAsync("Darslarni yaratish",
        "Haftalik jadval bo'yicha darslar yaratilsinmi? Mavjud darslar takrorlanmaydi.", "Yaratish",
        () => S.Api.GenerateSessionsAsync(groupId), "Darslar jadvaldan yaratildi.");

    [RelayCommand]
    private async Task AddMemberAsync()
    {
        List<UserRow> found = [];
        var searched = await S.Forms.ShowAsync("O'quvchi qo'shish", [new FormField("q", "Ism yoki telefon raqami") { Required = true }], async v =>
        {
            try
            {
                found = (await S.Api.UsersAsync(v.Str("q"), "STUDENT", null, 1)).Data.Where(u => u.Active).ToList();
                return found.Count == 0 ? "O'quvchi topilmadi. Avval «Foydalanuvchilar» bo'limida qo'shing." : null;
            }
            catch (ApiException ex) { return ex.Message; }
        }, "Qidirish");
        if (!searched) return;
        var inv = System.Globalization.CultureInfo.InvariantCulture;
        var options = found.Select(u => Editing.Opt(u.Id.ToString(inv), $"{u.FullName} · {u.Phone}")).ToList();
        await EditAsync("O'quvchini tanlang", [FormField.Choice("student", "O'quvchi", options, options[0].Key)],
            v => S.Api.AddMemberAsync(groupId, v.Int("student") ?? 0), "O'quvchi guruhga qo'shildi.", "Qo'shish");
    }

    public ObservableCollection<string> Former { get; } = [];

    [ObservableProperty] private bool _hasFormer;

    [RelayCommand]
    private void OpenProfile(StudentRow? row)
    {
        if (row is { CanOpenProfile: true }) S.Navigator.Navigate(Page.StudentProfile, row.Id);
    }

    private FormField ReasonField(string key, string label, bool freeze)
    {
        var reasons = Settings.List(freeze ? "enrollment.freeze_reasons" : "enrollment.leave_reasons");
        return reasons.Count > 0 ? FormField.Choice(key, label, reasons.Select(r => Editing.Opt(r, r)), reasons[0]) : new FormField(key, label);
    }

    /// <summary>O'quvchi holati: muzlatish, qayta faollashtirish, boshqa guruhga o'tkazish, bitirish, chiqarish.</summary>
    [RelayCommand]
    private async Task ManageMemberAsync(StudentRow? row)
    {
        if (row is null) return;
        var actions = new List<KeyValuePair<string, string>>();
        actions.Add(row.Frozen ? Editing.Opt("unfreeze", "Muzlatishni tugatish") : Editing.Opt("freeze", "Muzlatish (ta'til)"));
        actions.AddRange([Editing.Opt("transfer", "Boshqa guruhga o'tkazish"), Editing.Opt("graduate", "Bitirdi"), Editing.Opt("leave", "Guruhdan chiqarish"), Editing.Opt("profile", "Profil va tarix")]);
        string? action = null;
        var picked = await S.Forms.ShowAsync(row.Name, [FormField.Choice("action", "Amal", actions, actions[0].Key)], v =>
        {
            action = v.Str("action");
            return Task.FromResult<string?>(null);
        }, "Davom etish");
        if (!picked || action is null) return;
        var today = Editing.Today;
        switch (action)
        {
            case "profile":
                OpenProfile(row);
                break;
            case "freeze":
                await EditAsync($"{row.Name} — muzlatish",
                [
                    new FormField("from", "Qachondan", FieldKind.Date, today),
                    new FormField("to", "Qachongacha", FieldKind.Date, ServerClock.Now.Date.AddDays(14).ToString("yyyy-MM-dd", System.Globalization.CultureInfo.InvariantCulture)),
                    ReasonField("reason", "Sabab", freeze: true),
                    new FormField("note", "Izoh", FieldKind.Multiline) { MaxLength = 500 },
                ], v => S.Api.FreezeMemberAsync(groupId, row.Id, new { from = v.Str("from"), to = v.Str("to"), reason = v.StrOrNull("reason"), note = v.StrOrNull("note") }),
                    "O'quvchi muzlatildi.", "Muzlatish");
                break;
            case "unfreeze":
                await EditAsync($"{row.Name} — muzlatishni tugatish", [new FormField("date", "Qaytgan sana", FieldKind.Date, today)],
                    v => S.Api.UnfreezeMemberAsync(groupId, row.Id, v.StrOrNull("date")), "O'quvchi yana faol.", "Tasdiqlash");
                break;
            case "transfer":
                List<Group> groups = [];
                try { groups = (await S.Api.GroupsAsync()).Value.Data.Where(g => g.Active && g.Id != groupId).ToList(); } catch (ApiException) { }
                if (groups.Count == 0) { ActionError = "O'tkazish uchun boshqa faol guruh yo'q."; return; }
                var options = groups.Select(g => Editing.Opt(g.Id.ToString(System.Globalization.CultureInfo.InvariantCulture), $"{g.Name} · {g.Course?.Title}")).ToList();
                await EditAsync($"{row.Name} — boshqa guruhga o'tkazish",
                [
                    FormField.Choice("to_group_id", "Yangi guruh", options, options[0].Key),
                    new FormField("date", "Yangi guruhdagi birinchi kun", FieldKind.Date, today),
                    new FormField("reason", "Sabab"),
                    new FormField("note", "Izoh", FieldKind.Multiline) { MaxLength = 500 },
                ], v => S.Api.TransferMemberAsync(groupId, row.Id, new { to_group_id = v.Int("to_group_id"), date = v.StrOrNull("date"), reason = v.StrOrNull("reason"), note = v.StrOrNull("note") }),
                    "O'quvchi boshqa guruhga o'tkazildi.", "O'tkazish");
                break;
            case "graduate":
                await EditAsync($"{row.Name} — bitirdi",
                [
                    new FormField("date", "Sana", FieldKind.Date, today),
                    new FormField("note", "Izoh", FieldKind.Multiline) { MaxLength = 500 },
                ], v => S.Api.GraduateMemberAsync(groupId, row.Id, new { date = v.StrOrNull("date"), note = v.StrOrNull("note") }), "Bitirgan deb belgilandi.", "Tasdiqlash");
                break;
            case "leave":
                await RemoveMemberAsync(row);
                break;
        }
    }

    [RelayCommand]
    private Task GraduateAllAsync() => EditAsync("Guruhni bitirish",
        [new FormField("date", "Bitirish sanasi", FieldKind.Date, Editing.Today) { Hint = "Barcha faol o'quvchilar «bitirgan» deb belgilanadi." }],
        v => S.Api.GraduateGroupAsync(groupId, v.StrOrNull("date")), "Guruh bitirildi.", "Bitirish");

    [RelayCommand]
    private async Task RemoveMemberAsync(StudentRow? row)
    {
        if (row is null) return;
        var reasons = Settings.List("enrollment.leave_reasons");
        var fields = new List<FormField>();
        if (reasons.Count > 0) fields.Add(FormField.Choice("reason", "Sabab", reasons.Select(r => Editing.Opt(r, r)), reasons[0]));
        else fields.Add(new FormField("reason", "Sabab"));
        fields.Add(new FormField("date", "Oxirgi qatnashgan kun", FieldKind.Date, Editing.Today));
        fields.Add(new FormField("note", "Izoh", FieldKind.Multiline) { MaxLength = 500 });
        await EditAsync($"{row.Name} — guruhdan chiqarish", fields,
            v => S.Api.LeaveMemberFullAsync(groupId, row.Id, new { reason = v.StrOrNull("reason"), date = v.StrOrNull("date"), note = v.StrOrNull("note") }),
            "O'quvchi guruhdan chiqarildi.", "Chiqarish");
    }

    [RelayCommand]
    private async Task LoadCandidatesAsync()
    {
        await ActAsync(async () =>
        {
            var list = await S.Api.CertificateCandidatesAsync(groupId);
            Candidates.Clear();
            foreach (var c in list) Candidates.Add(new CandidateItem(c));
            HasCandidates = Candidates.Count > 0;
            CanIssue = Candidates.Any(c => c.Eligible);
        });
    }

    [RelayCommand]
    private Task IssueCertificatesAsync() => ConfirmActAsync("Sertifikat berish",
        $"Shartlarga mos {Candidates.Count(c => c.Eligible)} ta o'quvchiga sertifikat berilsinmi? O'quvchilarga Telegram'da xabar boradi.", "Berish",
        async () =>
        {
            var r = await S.Api.IssueCertificatesAsync(groupId);
            Candidates.Clear();
            foreach (var c in r.Data) Candidates.Add(new CandidateItem(c));
            CanIssue = Candidates.Any(c => c.Eligible);
        }, "Sertifikatlar berildi.", reload: false);
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
