using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media.Animation;
using TisuEdu.App.Views;
using TisuEdu.Core.Services;
using AppPage = TisuEdu.Core.Services.Page;

namespace TisuEdu.App.Services;

/// <summary>ViewModel'dagi sahifa kalitlarini WinUI sahifalariga bog'laydi (ShellPage'dagi Frame orqali).</summary>
internal sealed class NavigationService : INavigator
{
    private Frame? _frame;

    public event EventHandler<AppPage>? Navigated;

    public Frame? Frame => _frame;

    public void Attach(Frame frame)
    {
        _frame = frame;
        _frame.Navigated += (_, e) =>
        {
            if (PageFor(e.SourcePageType) is { } key) Navigated?.Invoke(this, key);
        };
    }

    public void Reset()
    {
        _frame?.BackStack.Clear();
        _frame = null;
    }

    public void Navigate(AppPage page, object? parameter = null)
    {
        if (_frame is null) return;
        var type = TypeFor(page);
        // Bir xil sahifaga bir xil parametr bilan qayta o'tilmaydi (tugmani ikki marta bosish).
        if (_frame.Content?.GetType() == type && Equals(_frame.Tag, parameter)) return;
        _frame.Tag = parameter;
        _frame.Navigate(type, parameter, new DrillInNavigationTransitionInfo());
    }

    /// <summary>Menyu bo'limiga o'tish: tarix tozalanadi (Settings ilovasidagi kabi).</summary>
    public void NavigateRoot(AppPage page)
    {
        if (_frame is null) return;
        _frame.Tag = null;
        _frame.Navigate(TypeFor(page), null, new EntranceNavigationTransitionInfo());
        _frame.BackStack.Clear();
    }

    public bool GoBack()
    {
        if (_frame is not { CanGoBack: true }) return false;
        _frame.Tag = null;
        _frame.GoBack();
        return true;
    }

    public static Type TypeFor(AppPage page) => page switch
    {
        AppPage.Dashboard => typeof(DashboardPage),
        AppPage.Courses => typeof(CoursesPage),
        AppPage.Course => typeof(CoursePage),
        AppPage.Lesson => typeof(LessonPage),
        AppPage.Quiz => typeof(QuizPage),
        AppPage.Groups => typeof(GroupsPage),
        AppPage.Group => typeof(GroupPage),
        AppPage.Sheet => typeof(SheetPage),
        AppPage.Attendance => typeof(AttendancePage),
        AppPage.News => typeof(NewsPage),
        AppPage.Certificates => typeof(CertificatesPage),
        AppPage.Reports => typeof(ReportsPage),
        AppPage.Profile => typeof(ProfilePage),
        AppPage.AppSettings => typeof(AppSettingsPage),
        _ => typeof(DashboardPage),
    };

    /// <summary>Ichki sahifalar menyuda ota bo'limini belgilaydi.</summary>
    public static AppPage? PageFor(Type t) =>
        t == typeof(DashboardPage) ? AppPage.Dashboard
        : t == typeof(CoursesPage) || t == typeof(CoursePage) || t == typeof(LessonPage) || t == typeof(QuizPage) ? AppPage.Courses
        : t == typeof(GroupsPage) || t == typeof(GroupPage) ? AppPage.Groups
        : t == typeof(SheetPage) || t == typeof(AttendancePage) ? AppPage.Attendance
        : t == typeof(NewsPage) ? AppPage.News
        : t == typeof(CertificatesPage) ? AppPage.Certificates
        : t == typeof(ReportsPage) ? AppPage.Reports
        : t == typeof(ProfilePage) ? AppPage.Profile
        : t == typeof(AppSettingsPage) ? AppPage.AppSettings
        : null;
}
