using System.Collections.ObjectModel;
using System.Globalization;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

public sealed class GroupOption(int? id, string name)
{
    public int? Id { get; } = id;
    public string Name { get; } = name;
    public override string ToString() => Name;
}

public sealed class GroupRateRow(GroupReportRow r, SiteSettings st)
{
    public string Group => r.Group;
    public string Sub => string.Join(" · ", new[] { r.Course, r.Teacher }.Where(x => !string.IsNullOrEmpty(x)));
    public string Sessions => $"{r.Sessions} dars";
    public string Rate => Format.Percent(r.Rate);
    public double RateValue => r.Rate ?? 0;
    public string Counts => string.Join("  ", AttendanceStatus.All.Select(s => $"{st.StatusLabel(s)}: {r.Counts.GetValueOrDefault(s)}"));
}

public sealed class RiskItem(RiskRow r, SiteSettings st)
{
    public string Name => r.Name;
    public string Sub => string.Join(" · ", new[] { r.Phone, string.Join(", ", r.Groups) }.Where(x => !string.IsNullOrEmpty(x)));
    public string Rate => Format.Percent(r.Rate);
    public string Absent => $"{st.StatusLabel(AttendanceStatus.Absent)}: {r.Counts.GetValueOrDefault(AttendanceStatus.Absent)}";
}

public sealed class DayBar(DayRow d)
{
    public string Date => d.Date.Length >= 10 ? d.Date[8..10] + "." + d.Date[5..7] : d.Date;
    public double Rate => d.Rate ?? 0;
    public string Tooltip => $"{d.Date}: {Format.Percent(d.Rate)}";
}

public sealed class QuizRow(QuizReportRow q)
{
    public QuizReportRow Data { get; } = q;
    public string Title => q.Title + (q.IsFinal ? " (yakuniy)" : "");
    public string Course => q.Course ?? "";
    public string Attempts => $"{q.Attempts} urinish · {q.Students} o'quvchi";
    public string Avg => Format.Percent(q.AvgPercent);
    public string PassRate => Format.Percent(q.PassRate);
}

/// <summary>Hisobotlar (report.view): umumiy ko'rsatkichlar, davomat (guruh/kun/xavf ostida) va testlar; CSV eksport.</summary>
public sealed partial class ReportsViewModel(AppServices services) : PageViewModel(services)
{
    public ObservableCollection<StatCard> Overview { get; } = [];
    public ObservableCollection<GroupRateRow> ByGroup { get; } = [];
    public ObservableCollection<RiskItem> Risk { get; } = [];
    public ObservableCollection<DayBar> Days { get; } = [];
    public ObservableCollection<QuizRow> Quizzes { get; } = [];
    public ObservableCollection<GroupOption> Groups { get; } = [];

    private AttendanceReport? _attendance;
    private QuizReport? _quizzes;

    [ObservableProperty] private DateTimeOffset _from;
    [ObservableProperty] private DateTimeOffset _to;
    [ObservableProperty] private GroupOption? _group;
    [ObservableProperty] private string _periodText = "";
    [ObservableProperty] private string _totalRate = "—";
    [ObservableProperty] private string _totalCounts = "";
    [ObservableProperty] private string _riskTitle = "";
    [ObservableProperty] private bool _hasRisk;
    [ObservableProperty] private bool _hasQuizzes;
    [ObservableProperty] private bool _hasGroups;

    public string Title => Settings.Nav("/dashboard/reports", "Hisobotlar");

    private bool _initialized;

    private ReportFilter Filter() => new(
        DateOnly.FromDateTime(From.Date), DateOnly.FromDateTime(To.Date), Group?.Id);

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        if (!_initialized)
        {
            var today = ServerClock.Now;
            To = today;
            From = today.AddDays(-(Settings.Int("reports.default_days", 30) - 1));
            _initialized = true;
            _ = LoadGroupsAsync();
        }
        ResetStale();
        var f = Filter();
        // Uchala hisobot parallel so'raladi.
        var overviewTask = S.Api.ReportOverviewAsync(f, ct);
        var attendanceTask = S.Api.AttendanceReportAsync(f, ct);
        var quizTask = S.Api.QuizReportAsync(f, ct);
        await Task.WhenAll(overviewTask, attendanceTask, quizTask);

        var o = overviewTask.Result;
        var a = attendanceTask.Result;
        var q = quizTask.Result;
        Track(o);
        Track(a);
        _attendance = a.Value;
        _quizzes = q.Value;

        PeriodText = $"{a.Value.Period.From} — {a.Value.Period.To}";
        Overview.Clear();
        Overview.Add(new StatCard(Settings.Nav("/dashboard/groups", "Guruhlar"), Format.Number(o.Value.Groups), null, ""));
        Overview.Add(new StatCard("O'quvchilar", Format.Number(o.Value.Students), $"+{o.Value.NewStudents} yangi", ""));
        Overview.Add(new StatCard("Darslar", Format.Number(o.Value.Sessions), $"{a.Value.Total.UnmarkedSessions} belgilanmagan", ""));
        Overview.Add(new StatCard(Settings.Nav("/dashboard/certificates", "Sertifikatlar"), Format.Number(o.Value.Certificates), null, ""));

        TotalRate = Format.Percent(a.Value.Total.Rate);
        TotalCounts = string.Join("   ", AttendanceStatus.All.Select(s => $"{Settings.StatusLabel(s)}: {a.Value.Total.Counts.GetValueOrDefault(s)}"));

        ByGroup.Clear();
        foreach (var g in a.Value.ByGroup) ByGroup.Add(new GroupRateRow(g, Settings));
        HasGroups = ByGroup.Count > 0;
        Risk.Clear();
        foreach (var r in a.Value.Risk) Risk.Add(new RiskItem(r, Settings));
        HasRisk = Risk.Count > 0;
        RiskTitle = $"Davomati {a.Value.RiskThreshold}% dan past";
        Days.Clear();
        foreach (var d in a.Value.ByDay) Days.Add(new DayBar(d));

        Quizzes.Clear();
        foreach (var row in q.Value.Data) Quizzes.Add(new QuizRow(row));
        HasQuizzes = Quizzes.Count > 0;
    }

    private async Task LoadGroupsAsync()
    {
        try
        {
            var g = await S.Api.GroupsAsync();
            Groups.Clear();
            Groups.Add(new GroupOption(null, "Barcha guruhlar"));
            foreach (var x in g.Value.Data.OrderByDescending(x => x.Active).ThenBy(x => x.Name)) Groups.Add(new GroupOption(x.Id, x.Name));
            Group ??= Groups[0];
        }
        catch (ApiException) { }
    }

    [RelayCommand]
    private Task ApplyAsync() => RefreshAsync();

    /// <summary>Davomat CSV — serverning o'zi tayyorlaydi (attendance.csv).</summary>
    [RelayCommand]
    private async Task ExportAttendanceAsync()
    {
        var f = Filter();
        await ActAsync(async () =>
        {
            var bytes = await S.Api.AttendanceCsvAsync(f);
            var name = $"davomat_{f.From:yyyyMMdd}_{f.To:yyyyMMdd}.csv";
            if (await S.Platform.SaveFileAsync(name, "CSV (Excel)", ".csv", bytes)) Notice = Strings.CsvSaved;
        });
    }

    /// <summary>Guruhlar kesimidagi davomat (ekrandagi jadval) CSV.</summary>
    [RelayCommand]
    private async Task ExportGroupsAsync()
    {
        if (_attendance is null) return;
        var header = new[] { "Guruh", "Kurs", "O'qituvchi", "Darslar" }.Concat(AttendanceStatus.All.Select(Settings.StatusLabel)).Append("Davomat %");
        var rows = _attendance.ByGroup.Select(g => new[] { g.Group, g.Course, g.Teacher, g.Sessions.ToString(CultureInfo.InvariantCulture) }
            .Concat(AttendanceStatus.All.Select(s => g.Counts.GetValueOrDefault(s).ToString(CultureInfo.InvariantCulture)))
            .Append(g.Rate?.ToString("0.#", CultureInfo.InvariantCulture) ?? ""));
        await SaveCsvAsync($"guruhlar_{_attendance.Period.From}_{_attendance.Period.To}.csv", Csv.Build(header, rows));
    }

    [RelayCommand]
    private async Task ExportQuizzesAsync()
    {
        if (_quizzes is null) return;
        var header = new[] { "Test", "Kurs", "Yakuniy", "Urinishlar", "O'quvchilar", "O'rtacha %", "O'tganlar %" };
        var rows = _quizzes.Data.Select(q => new[]
        {
            q.Title, q.Course, q.IsFinal ? "ha" : "yo'q", q.Attempts.ToString(CultureInfo.InvariantCulture), q.Students.ToString(CultureInfo.InvariantCulture),
            q.AvgPercent.ToString("0.#", CultureInfo.InvariantCulture), q.PassRate?.ToString("0.#", CultureInfo.InvariantCulture) ?? "",
        });
        await SaveCsvAsync($"testlar_{_quizzes.Period.From}_{_quizzes.Period.To}.csv", Csv.Build(header, rows));
    }

    private async Task SaveCsvAsync(string name, byte[] bytes)
    {
        Notice = null;
        ActionError = null;
        if (await S.Platform.SaveFileAsync(name, "CSV (Excel)", ".csv", bytes)) Notice = Strings.CsvSaved;
    }
}
