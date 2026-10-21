using System.Collections.ObjectModel;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

/// <summary>Davomat varaqasidagi bitta o'quvchi. Holat tugmalari yoki kelgan vaqt (server holatni o'zi aniqlaydi).</summary>
public sealed partial class SheetRowItem : ObservableObject
{
    private readonly SheetRow _row;
    private readonly SiteSettings _st;

    public SheetRowItem(SheetRow row, SiteSettings st, bool canEdit)
    {
        _row = row;
        _st = st;
        _status = row.Status;
        _arrived = Format.ArrivedHhmm(row.ArrivedAt);
        _note = row.Note ?? "";
        CanEdit = canEdit;
    }

    public int StudentId => _row.StudentId;
    public string Name => _row.Name;
    public bool CanEdit { get; }
    public bool ReadOnly => !CanEdit;

    public string PresentLabel => _st.StatusLabel(AttendanceStatus.Present);
    public string LateLabel => _st.StatusLabel(AttendanceStatus.Late);
    public string AbsentLabel => _st.StatusLabel(AttendanceStatus.Absent);
    public string ExcusedLabel => _st.StatusLabel(AttendanceStatus.Excused);

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsPresent), nameof(IsLate), nameof(IsAbsent), nameof(IsExcused), nameof(StatusText), nameof(IsDirty), nameof(HasValue))]
    private string? _status;

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsDirty), nameof(HasValue))]
    private string _arrived;

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsDirty))]
    private string _note;

    /// <summary>Foydalanuvchi holatni qo'lda tanladimi (aks holda kelgan vaqt bo'yicha server aniqlaydi).</summary>
    public bool StatusTouched { get; private set; }

    public string StatusText => _st.StatusLabel(Status);
    public bool HasValue => Status is not null || Arrived.Trim().Length > 0;

    // ── Darsdagi baho (marks.session_marks) ─────────────────
    private string _savedScore = "";

    [ObservableProperty] private string _score = "";
    [ObservableProperty] private bool _hasMarks;
    [ObservableProperty] private bool _canMark;
    [ObservableProperty] private string _scaleHint = "";

    public void SetMark(int? score, bool enabled, bool canEdit, Scale scale)
    {
        HasMarks = enabled;
        CanMark = enabled && canEdit;
        Score = _savedScore = score?.ToString(System.Globalization.CultureInfo.InvariantCulture) ?? "";
        ScaleHint = $"{scale.Min}–{scale.Max}";
    }

    public bool MarkChanged => Score.Trim() != _savedScore;

    public bool IsDirty => Status != _row.Status || Arrived.Trim() != Format.ArrivedHhmm(_row.ArrivedAt) || Note.Trim() != (_row.Note ?? "");

    public bool IsPresent { get => Status == AttendanceStatus.Present; set => Pick(AttendanceStatus.Present, value); }
    public bool IsLate { get => Status == AttendanceStatus.Late; set => Pick(AttendanceStatus.Late, value); }
    public bool IsAbsent { get => Status == AttendanceStatus.Absent; set => Pick(AttendanceStatus.Absent, value); }
    public bool IsExcused { get => Status == AttendanceStatus.Excused; set => Pick(AttendanceStatus.Excused, value); }

    private void Pick(string status, bool on)
    {
        if (!CanEdit) { OnPropertyChanged(string.Empty); return; }
        if (on) { StatusTouched = true; Status = status; }
        else if (Status == status) { OnPropertyChanged(status switch { AttendanceStatus.Present => nameof(IsPresent), AttendanceStatus.Late => nameof(IsLate), AttendanceStatus.Absent => nameof(IsAbsent), _ => nameof(IsExcused) }); }
    }

    partial void OnArrivedChanged(string value)
    {
        // Vaqt kiritilsa va holat qo'lda tanlanmagan bo'lsa — holatni server (attendance.grace_minutes) aniqlaydi.
        if (value.Trim().Length > 0 && !StatusTouched) Status = null;
    }

    public void SetPresentIfEmpty()
    {
        if (!HasValue) { StatusTouched = true; Status = AttendanceStatus.Present; }
    }

    /// <summary>API'ga yuboriladigan qator; hech narsa belgilanmagan bo'lsa null.</summary>
    public MarkItem? ToItem()
    {
        var arrived = Arrived.Trim();
        if (Status is null && arrived.Length == 0) return null;
        return new MarkItem
        {
            StudentId = StudentId,
            Status = arrived.Length > 0 && !StatusTouched ? null : Status,
            ArrivedAt = arrived.Length > 0 ? arrived : null,
            Note = Note.Trim().Length > 0 ? Note.Trim() : null,
        };
    }
}

public sealed class HistoryEntry(AttendanceHistoryItem h, SiteSettings st)
{
    public string Header => $"{Format.DateTime(h.At)} · {h.By}";
    public string Reason => string.IsNullOrWhiteSpace(h.Reason) ? "" : $"Sabab: {h.Reason}";
    public bool HasReason => Reason.Length > 0;
    public string Changes => string.Join("\n", h.Changes.Select(c => $"{c.Name}: {st.StatusLabel(c.From)} → {st.StatusLabel(c.To)}"));
}

/// <summary>
/// Davomat varaqasi. Tahrirlash huquqi, muddat va sabab majburiyligi — server javobidagi <c>edit</c> oynasi bo'yicha
/// (AttendanceController::editWindow): ilova o'zi qoida o'ylab topmaydi.
/// </summary>
public sealed partial class SheetViewModel(AppServices services, int sessionId) : PageViewModel(services)
{
    public ObservableCollection<SheetRowItem> Rows { get; } = [];
    public ObservableCollection<HistoryEntry> History { get; } = [];

    [ObservableProperty] private string _title = "";
    [ObservableProperty] private string _when = "";
    [ObservableProperty] private int _groupId;
    [ObservableProperty] private bool _canEdit;
    [ObservableProperty] private bool _reasonRequired;
    [ObservableProperty] private string? _windowMessage;
    [ObservableProperty] private bool _hasWindowMessage;
    [ObservableProperty] private string _deadlineText = "";
    [ObservableProperty] private bool _hasDeadline;
    [ObservableProperty] private string _reason = "";
    [ObservableProperty] private string _topic = "";
    [ObservableProperty] private bool _cancelled;
    [ObservableProperty] private string _markedText = "";
    [ObservableProperty] private bool _isEmpty;
    [ObservableProperty] private bool _canSeeHistory;
    [ObservableProperty] private bool _hasHistory;

    private string _savedTopic = "";

    public string ReasonLabel => ReasonRequired
        ? $"{Settings.Text("sheet_reason", "O'zgartirish sababi")} (majburiy)"
        : $"{Settings.Text("sheet_reason", "O'zgartirish sababi")} (ixtiyoriy)";
    public string ReasonHint => Settings.Text("sheet_reason_hint", "Masalan: ma'lumotnoma keltirdi");
    public string SaveText => Settings.Text("sheet_save", "Saqlash");
    public string AllPresentText => $"Qolganlarni «{Settings.StatusLabel(AttendanceStatus.Present)}»";
    public string TopicLabel => Settings.Text("sheet_topic", "Dars mavzusi");
    public string EmptyText => Settings.Text("sheet_empty", "Guruhda o'quvchi yo'q.");
    public string CancelledText => Settings.Text("cancelled", "bekor qilindi");

    partial void OnReasonRequiredChanged(bool value) => OnPropertyChanged(nameof(ReasonLabel));

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        var d = await S.Api.SheetAsync(sessionId, ct);
        Title = d.Group.Name ?? "";
        GroupId = d.Group.Id;
        When = $"{Format.LongDate(d.Session.StartsAt)} · {Format.TimeRange(d.Session.StartsAt, d.Session.EndsAt)}";
        Cancelled = d.Session.Cancelled;
        Topic = _savedTopic = d.Session.Topic ?? "";

        var canMark = S.Session.Can(Permissions.AttendanceMark);
        CanEdit = canMark && d.Edit.CanEdit;
        ReasonRequired = d.Edit.ReasonRequired;
        WindowMessage = canMark && !d.Edit.CanEdit ? d.Edit.Message : null;
        HasWindowMessage = !string.IsNullOrEmpty(WindowMessage);
        HasDeadline = CanEdit && d.Edit.Deadline is not null;
        DeadlineText = HasDeadline ? $"Tahrirlash muddati: {Format.DateTime(d.Edit.Deadline)} gacha." : "";
        Reason = "";

        Rows.Clear();
        foreach (var r in d.Rows)
        {
            var item = new SheetRowItem(r, Settings, CanEdit);
            item.PropertyChanged += (_, e) => { if (e.PropertyName == nameof(SheetRowItem.HasValue)) UpdateMarked(); };
            Rows.Add(item);
        }
        IsEmpty = Rows.Count == 0;
        UpdateMarked();
        await LoadMarksAsync(ct);

        CanSeeHistory = canMark;
        if (canMark) _ = LoadHistoryAsync(ct);
    }

    private Scale _scale = new();
    private bool _marksEditable;

    private async Task LoadMarksAsync(CancellationToken ct)
    {
        try
        {
            var m = await S.Api.SessionMarksAsync(sessionId, ct);
            _scale = m.Scale;
            _marksEditable = m.Enabled && m.CanEdit;
            var byStudent = m.Rows.ToDictionary(r => r.StudentId);
            foreach (var row in Rows) row.SetMark(byStudent.GetValueOrDefault(row.StudentId)?.Score, m.Enabled, _marksEditable, m.Scale);
            HasMarks = m.Enabled;
        }
        catch (ApiException) { HasMarks = false; }
    }

    [ObservableProperty] private bool _hasMarks;

    /// <summary>O'zgargan baholar (bo'sh — baho o'chiriladi). Xato bo'lsa matn qaytaradi.</summary>
    internal (List<object> Items, string? Error) MarkItems()
    {
        var items = new List<object>();
        foreach (var r in Rows.Where(r => r.MarkChanged))
        {
            var text = r.Score.Trim();
            int? score = null;
            if (text.Length > 0)
            {
                if (!int.TryParse(text, out var n) || n < _scale.Min || n > _scale.Max) return ([], $"{r.Name}: baho {_scale.Min}–{_scale.Max} oralig'ida bo'lsin.");
                score = n;
            }
            items.Add(new { student_id = r.StudentId, score });
        }
        return (items, null);
    }

    private void UpdateMarked() =>
        MarkedText = Settings.Text("sheet_marked", "Belgilangan: {marked}/{total}", ("marked", Rows.Count(r => r.HasValue)), ("total", Rows.Count));

    private async Task LoadHistoryAsync(CancellationToken ct)
    {
        try
        {
            var h = await S.Api.SheetHistoryAsync(sessionId, ct);
            History.Clear();
            foreach (var x in h.Data) History.Add(new HistoryEntry(x, Settings));
            HasHistory = History.Count > 0;
        }
        catch (ApiException) { HasHistory = false; }
        catch (OperationCanceledException) { }
    }

    [RelayCommand]
    private void MarkRestPresent()
    {
        if (!CanEdit) return;
        foreach (var r in Rows) r.SetPresentIfEmpty();
    }

    /// <summary>Saqlashdan oldingi tekshiruv; xato bo'lsa matn qaytaradi.</summary>
    internal string? Validate(out MarkRequest request)
    {
        request = new MarkRequest { Reason = Reason.Trim().Length > 0 ? Reason.Trim() : null };
        foreach (var r in Rows)
        {
            var arrived = r.Arrived.Trim();
            if (arrived.Length > 0 && !Format.IsValidHhmm(arrived)) return $"{r.Name}: {Strings.ArrivedFormat}";
            if (r.ToItem() is { } item) request.Items.Add(item);
        }
        if (_marksEditable && MarkItems().Error is { } markError) return markError;
        if (request.Items.Count == 0) return Strings.NoStatusOrTime;
        if (ReasonRequired && request.Reason is null) return Strings.ReasonRequired;
        return null;
    }

    [RelayCommand]
    private async Task SaveAsync()
    {
        if (!CanEdit) return;
        if (Validate(out var request) is { } problem)
        {
            ActionError = problem;
            return;
        }
        var ok = await ActAsync(async () =>
        {
            await S.Api.MarkAsync(sessionId, request);
            if (_marksEditable)
            {
                var (marks, markError) = MarkItems();
                if (markError is not null) throw new ApiException(System.Net.HttpStatusCode.UnprocessableEntity, markError);
                if (marks.Count > 0) await S.Api.SaveSessionMarksAsync(sessionId, marks);
            }
            if (Topic.Trim() != _savedTopic)
            {
                await S.Api.UpdateSessionTopicAsync(sessionId, Topic.Trim().Length > 0 ? Topic.Trim() : null);
            }
            Notice = Settings.Text("sheet_saved", "Saqlandi");
        });
        if (ok)
        {
            var notice = Notice;
            await RefreshAsync();
            Notice = notice;
        }
    }

    public bool CanCancel => S.Session.Can(Permissions.GroupManage);

    public string CancelActionText => Cancelled ? "Darsni tiklash" : "Darsni bekor qilish";

    partial void OnCancelledChanged(bool value) => OnPropertyChanged(nameof(CancelActionText));

    [RelayCommand]
    private Task ToggleCancelAsync() => ConfirmActAsync(CancelActionText,
        Cancelled ? "Dars qayta faollashtirilsinmi?" : "Dars bekor qilinsinmi? Bekor qilingan darsda davomat belgilanmaydi.",
        Cancelled ? "Tiklash" : "Bekor qilish", () => S.Api.CancelSessionAsync(sessionId, !Cancelled), Cancelled ? "Dars tiklandi." : "Dars bekor qilindi.");

    [RelayCommand]
    private void OpenGroup()
    {
        if (GroupId > 0) S.Navigator.Navigate(Page.Group, GroupId);
    }
}
