using System.Collections.ObjectModel;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

public sealed class CourseItem(Course c)
{
    public Course Course { get; } = c;
    public string Title => c.Title;
    public string Description => string.IsNullOrWhiteSpace(c.Description) ? "" : c.Description!.Trim();
    public bool HasDescription => Description.Length > 0;
    public string Meta => string.Join(" · ", new[]
    {
        $"{c.ModulesCount} modul",
        c.DurationMonths is { } m ? $"{m} oy" : null,
        c.Published ? null : "yashirin",
    }.Where(x => x is not null));
    public bool Locked => c.Access is { Granted: false };
    public string Initial => c.Title.Length > 0 ? c.Title[..1].ToUpperInvariant() : "?";
}

public sealed partial class CoursesViewModel(AppServices services) : PageViewModel(services)
{
    private List<CourseItem> _all = [];

    public ObservableCollection<CourseItem> Items { get; } = [];

    [ObservableProperty] private string _search = "";
    [ObservableProperty] private bool _isEmpty;

    public string Title => Settings.Nav("/dashboard/courses", "Kurslar");
    public string EmptyText => Settings.Text("courses_empty", "Kurslar topilmadi.");

    partial void OnSearchChanged(string value) => ApplyFilter();

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var r = await S.Api.CoursesAsync(ct);
        Track(r);
        _all = r.Value.Data.Select(c => new CourseItem(c)).ToList();
        ApplyFilter();
    }

    private void ApplyFilter()
    {
        var q = Search.Trim();
        Items.Clear();
        foreach (var c in _all.Where(c => q.Length == 0 || c.Title.Contains(q, StringComparison.CurrentCultureIgnoreCase)))
            Items.Add(c);
        IsEmpty = Items.Count == 0 && HasLoaded;
    }

    [RelayCommand]
    private void Open(CourseItem? item)
    {
        if (item is not null) S.Navigator.Navigate(Page.Course, item.Course.Id);
    }
}

public sealed class ModuleItem(CourseModule m, int index)
{
    public string Title => m.Title;
    public string Number => (index + 1).ToString(System.Globalization.CultureInfo.InvariantCulture);
    public string Meta => $"{m.Lessons.Count} dars";
    public IReadOnlyList<LessonItem> Lessons { get; } = m.Lessons.OrderBy(l => l.Position).Select((l, i) => new LessonItem(l, i)).ToList();
}

public sealed class LessonItem(LessonRef l, int index)
{
    public LessonRef Lesson { get; } = l;
    public string Title => l.Title;
    public string Number => (index + 1).ToString(System.Globalization.CultureInfo.InvariantCulture);
    public bool Hidden => !l.Published;
}

public sealed class QuizItem(QuizSummary q, SiteSettings st)
{
    public QuizSummary Quiz { get; } = q;
    public string Title => q.Title;
    public bool IsFinal => q.IsFinal;
    public string Meta => string.Join(" · ", new[]
    {
        st.Text("quiz_questions", "{n} ta savol", ("n", q.QuestionsCount)),
        q.TimeLimitSec is { } t ? st.Text("quiz_time", "{min} daqiqa", ("min", Math.Max(1, t / 60))) : st.Text("quiz_no_time", "Vaqt cheklanmagan"),
        st.Text("quiz_pass", "O'tish: {percent}%", ("percent", q.PassPercent)),
    });
    public string? Mine => q.MyAttempts is { } used
        ? st.Text("quiz_attempts", "Urinishlar: {used}/{max}", ("used", used), ("max", q.MaxAttempts))
          + (q.MyBestPercent is { } best ? " · " + st.Text("quiz_best", "Eng yaxshi natija: {percent}%", ("percent", best)) : "")
        : null;
    public bool HasMine => Mine is not null;
    public bool Passed => q.MyPassed == true;
    public bool Locked => q.Locked;
}

/// <summary>Kurs: modullar → darslar, testlar. Kirish yo'q bo'lsa — API'dagi rad etish matni.</summary>
public sealed partial class CourseViewModel(AppServices services, int courseId) : PageViewModel(services)
{
    public ObservableCollection<ModuleItem> Modules { get; } = [];
    public ObservableCollection<QuizItem> Quizzes { get; } = [];

    [ObservableProperty] private string _title = "";
    [ObservableProperty] private string _description = "";
    [ObservableProperty] private AccessInfo? _denied;
    [ObservableProperty] private bool _isDenied;
    [ObservableProperty] private bool _hasQuizzes;
    [ObservableProperty] private bool _noLessons;
    [ObservableProperty] private bool _canEdit;

    public string ModulesTitle => Settings.Text("modules", "Modullar");
    public string QuizzesTitle => Settings.Text("quizzes", "Testlar");
    public string LessonsEmpty => Settings.Text("lessons_empty", "Darslar hali qo'shilmagan.");
    public string QuizzesEmpty => Settings.Text("quizzes_empty", "Hozircha test yo'q.");
    public string OpenOnWebText => Settings.Text("open_on_web", "Saytda ochish");

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        var courseTask = S.Api.CourseAsync(courseId, ct);
        var quizTask = S.Api.CourseQuizzesAsync(courseId, ct);
        var r = await courseTask;
        Track(r);
        var d = r.Value;
        Title = d.Course.Title;
        Description = d.Course.Description?.Trim() ?? "";
        CanEdit = d.CanEdit;
        Denied = d.Access.Granted ? null : d.Access;
        IsDenied = Denied is not null;
        Modules.Clear();
        foreach (var (m, i) in d.Course.Modules.OrderBy(m => m.Position).Select((m, i) => (m, i))) Modules.Add(new ModuleItem(m, i));
        NoLessons = Modules.Count == 0 && !IsDenied;

        Quizzes.Clear();
        try
        {
            var q = await quizTask;
            foreach (var quiz in q.Value.Data) Quizzes.Add(new QuizItem(quiz, Settings));
        }
        catch (ApiException) { /* testlar ixtiyoriy bo'lim */ }
        HasQuizzes = Quizzes.Count > 0;
    }

    [RelayCommand]
    private void OpenLesson(LessonItem? item)
    {
        if (item is not null) S.Navigator.Navigate(Page.Lesson, item.Lesson.Id);
    }

    [RelayCommand]
    private void OpenQuiz(QuizItem? item)
    {
        if (item is not null) S.Navigator.Navigate(Page.Quiz, item.Quiz.Id);
    }

    [RelayCommand]
    private void Contact() => AccessActions.Contact(S.Platform, Denied);

    [RelayCommand]
    private void OpenOnWeb() => S.Platform.Open(new Uri($"{Settings.SiteUrl}/dashboard/courses/{courseId}"));
}

internal static class AccessActions
{
    public static void Contact(IPlatform platform, AccessInfo? denied)
    {
        if (denied is null) return;
        var url = !string.IsNullOrWhiteSpace(denied.ContactUrl) ? denied.ContactUrl
            : denied.Contact?.StartsWith('@') == true ? "https://t.me/" + denied.Contact[1..] : null;
        if (url is not null && Uri.TryCreate(url, UriKind.Absolute, out var u)) platform.Open(u);
    }
}

public sealed class MaterialItem(Material m)
{
    public Material Material { get; } = m;
    public string Title => m.Title;
    public string Type => m.Type;
    public bool IsText => m.Type == "TEXT";
    public bool HasUrl => !string.IsNullOrWhiteSpace(m.Url);
    public string Content => m.Content ?? "";
    public string Host => Uri.TryCreate(m.Url, UriKind.Absolute, out var u) ? u.Host : "";

    /// <summary>Segoe Fluent Icons</summary>
    public string Glyph => m.Type switch
    {
        "VIDEO" => "",
        "FILE" => "",
        "LINK" => "",
        _ => "",
    };

    public string ActionText => m.Type switch
    {
        "VIDEO" => "Ko'rish",
        "FILE" => "Yuklab olish",
        _ => "Ochish",
    };
}

/// <summary>Dars: matn, materiallar, oldingi/keyingi dars. 403 — kursga kirish rad etildi (sozlamadagi matn).</summary>
public sealed partial class LessonViewModel(AppServices services, int lessonId) : PageViewModel(services)
{
    private int _lessonId = lessonId;

    public ObservableCollection<MaterialItem> Materials { get; } = [];

    [ObservableProperty] private string _title = "";
    [ObservableProperty] private string _body = "";
    [ObservableProperty] private bool _hasBody;
    [ObservableProperty] private string _courseTitle = "";
    [ObservableProperty] private string _position = "";
    [ObservableProperty] private int? _prev;
    [ObservableProperty] private int? _next;
    [ObservableProperty] private bool _hasPrev;
    [ObservableProperty] private bool _hasNext;
    [ObservableProperty] private AccessInfo? _denied;
    [ObservableProperty] private bool _isDenied;
    [ObservableProperty] private bool _isEmpty;

    public string MaterialsTitle => Settings.Text("materials", "Materiallar");

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        ResetStale();
        Denied = null;
        IsDenied = false;
        Fetched<LessonDetail> r;
        try
        {
            r = await S.Api.LessonAsync(_lessonId, ct);
        }
        catch (ApiException ex) when (ex.Denied is not null)
        {
            Denied = ex.Denied;
            IsDenied = true;
            Title = ex.Denied.Title ?? "";
            Materials.Clear();
            HasBody = false;
            return;
        }
        Track(r);
        var d = r.Value;
        Title = d.Lesson.Title;
        Body = d.Lesson.Body?.Trim() ?? "";
        HasBody = Body.Length > 0;
        CourseTitle = d.Lesson.Module?.Course?.Title ?? "";
        Position = d.Nav.Index is { } i ? $"{i}/{d.Nav.Total}" : "";
        Prev = d.Nav.Prev;
        Next = d.Nav.Next;
        HasPrev = Prev is not null;
        HasNext = Next is not null;
        Materials.Clear();
        foreach (var m in d.Lesson.Materials.OrderBy(m => m.Position)) Materials.Add(new MaterialItem(m));
        IsEmpty = Materials.Count == 0 && !HasBody;
    }

    [RelayCommand]
    private void OpenMaterial(MaterialItem? m)
    {
        if (m?.Material.Url is { } url && Uri.TryCreate(url, UriKind.Absolute, out var u) && (u.Scheme == Uri.UriSchemeHttps || u.Scheme == Uri.UriSchemeHttp))
            S.Platform.Open(u);
    }

    [RelayCommand]
    private async Task GoPrevAsync()
    {
        if (Prev is { } p) { _lessonId = p; await RefreshAsync(); }
    }

    [RelayCommand]
    private async Task GoNextAsync()
    {
        if (Next is { } n) { _lessonId = n; await RefreshAsync(); }
    }

    [RelayCommand]
    private void Contact() => AccessActions.Contact(S.Platform, Denied);
}
