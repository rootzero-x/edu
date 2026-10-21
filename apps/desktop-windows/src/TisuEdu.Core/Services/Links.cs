using System.Globalization;
using System.Text.RegularExpressions;

namespace TisuEdu.Core.Services;

/// <summary>
/// API qaytaradigan web manzillarni (bildirishnoma url, qidiruv href, sozlash ro'yxati) ilova sahifalariga moslaydi.
/// Mos sahifa bo'lmasa null — chaqiruvchi saytda ochadi.
/// </summary>
public static partial class Links
{
    [GeneratedRegex(@"^/dashboard/(?<section>[a-z]+)(?:/(?<id>\d+))?(?:[/?#].*)?$")]
    private static partial Regex DashboardRoute();

    public static (Page Page, object? Parameter)? Map(string? href)
    {
        if (string.IsNullOrWhiteSpace(href)) return null;
        var path = href.Trim();
        if (path.StartsWith("http", StringComparison.OrdinalIgnoreCase) && Uri.TryCreate(path, UriKind.Absolute, out var abs)) path = abs.PathAndQuery;
        if (path is "/dashboard" or "/dashboard/") return (Page.Dashboard, null);
        var m = DashboardRoute().Match(path);
        if (!m.Success) return null;
        int? id = m.Groups["id"].Success ? int.Parse(m.Groups["id"].Value, CultureInfo.InvariantCulture) : null;
        return m.Groups["section"].Value switch
        {
            "homework" when path.Contains("tab=marks", StringComparison.Ordinal) => (Page.Homework, "marks"),
            "homework" => id is { } a ? (Page.Assignment, a) : (Page.Homework, null),
            "news" => (Page.News, id),
            "calendar" => (Page.Calendar, null),
            "sessions" when id is { } s => (Page.Sheet, s),
            "groups" => id is { } g ? (Page.Group, g) : (Page.Groups, null),
            "courses" => id is { } c ? (Page.Course, c) : (Page.Courses, null),
            "lessons" when id is { } l => (Page.Lesson, l),
            "quizzes" when id is { } q => (Page.Quiz, q),
            "users" => id is { } u ? (Page.StudentProfile, u) : (Page.Users, null),
            "leads" => LeadFromQuery(path) is { } lead ? (Page.Lead, lead) : (Page.Leads, null),
            "profile" => (Page.Profile, null),
            "certificates" => (Page.Certificates, null),
            "billing" => (Page.Billing, null),
            "reports" => (Page.Reports, null),
            "settings" => (Page.AdminSettings, null),
            "notifications" => (Page.Notifications, null),
            "audit" => (Page.Audit, null),
            _ => null,
        };
    }

    private static int? LeadFromQuery(string path)
    {
        var i = path.IndexOf("id=", StringComparison.Ordinal);
        return i >= 0 && int.TryParse(new string(path[(i + 3)..].TakeWhile(char.IsAsciiDigit).ToArray()), out var n) ? n : null;
    }
}
