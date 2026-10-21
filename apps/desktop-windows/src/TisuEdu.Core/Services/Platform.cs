using TisuEdu.Core.Models;

namespace TisuEdu.Core.Services;

/// <summary>Tokenni xavfsiz saqlash (Windows: Credential Locker, zaxira — DPAPI).</summary>
public interface ITokenStore
{
    string? Load();
    void Save(string token);
    void Clear();
}

/// <summary>Platforma xizmatlari — WinUI ilovasi amalga oshiradi, testlarda soxta (fake) bilan almashtiriladi.</summary>
public interface IPlatform
{
    /// <summary>Brauzer yoki Telegram'da ochish.</summary>
    void Open(Uri uri);

    /// <summary>Faylni saqlash oynasi. Bekor qilinsa false.</summary>
    Task<bool> SaveFileAsync(string suggestedName, string extensionLabel, string extension, byte[] content);

    Task<bool> ConfirmAsync(string title, string message, string primary, string close);

    void CopyText(string text);

    /// <summary>Fayl tanlash oynasi (kengaytmalar: ".pdf" …). Bekor qilinsa null.</summary>
    Task<PickedFile?> PickFileAsync(IReadOnlyList<string> extensions);
}

public sealed record PickedFile(string Name, byte[] Content, string Mime);

public interface INotifier
{
    void ShowAnnouncement(Announcement announcement, string siteName);

    /// <summary>Bildirishnomalar markazidagi yangi element (uyga vazifa, baho, to'lov, eslatma …).</summary>
    void ShowNotification(NotificationItem item, string siteName);
}

public enum Page
{
    Dashboard,
    Courses,
    Course,
    Lesson,
    Quiz,
    Groups,
    Group,
    Sheet,
    Attendance,
    News,
    Certificates,
    Reports,
    Profile,
    AppSettings,
    Users,
    AdminSettings,
    Billing,
    BillingStudent,
    Calendar,
    Rooms,
    Homework,
    Assignment,
    Leads,
    Lead,
    StudentProfile,
    Notifications,
    Audit,
    Kpi,
    System,
}

public interface INavigator
{
    void Navigate(Page page, object? parameter = null);
    bool GoBack();
}
