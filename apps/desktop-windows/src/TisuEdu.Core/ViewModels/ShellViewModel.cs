using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

/// <summary>Asosiy oyna: menyu (ruxsatlar bo'yicha), foydalanuvchi, o'qilmagan yangiliklar, oflayn holat.</summary>
public sealed partial class ShellViewModel : ObservableObject
{
    private readonly AppServices _s;

    public ShellViewModel(AppServices services)
    {
        _s = services;
        _unread = services.Watcher.Unread;
    }

    public SiteSettings Settings => _s.Settings.Current;
    private User? User => _s.Session.User;

    public string SiteName => Settings.SiteName;
    public string UserName => User?.FullName ?? "";
    public string UserInitials => User?.Initials ?? "";
    public string RoleLabel => User is null ? "" : Settings.RoleLabel(User.Role);
    public string? UserPhone => User?.Phone;

    // ── Menyu nomlari (ui.nav_labels) ────────────────────────
    public string NavHome => Settings.Nav("/dashboard", "Bosh sahifa");
    public string NavCourses => Settings.Nav("/dashboard/courses", "Kurslar");
    public string NavGroups => Settings.Nav("/dashboard/groups", "Guruhlar");
    public string NavAttendance => Settings.Text("nav_attendance", "Davomat");
    public string NavNews => Settings.Nav("/dashboard/news", "Yangiliklar");
    public string NavCertificates => Settings.Nav("/dashboard/certificates", "Sertifikatlar");
    public string NavReports => Settings.Nav("/dashboard/reports", "Hisobotlar");
    public string NavProfile => Settings.Nav("/dashboard/profile", "Profil");
    public string NavUsers => Settings.Nav("/dashboard/users", "Foydalanuvchilar");
    public string NavBranches => Settings.Nav("/dashboard/branches", "Filiallar");
    public string NavSettings => Settings.Nav("/dashboard/settings", "Sozlamalar");
    public string NavBilling => Settings.Nav("/dashboard/billing", "To'lovlar");
    public string LogoutText => Settings.Text("logout", "Chiqish");

    // ── Ruxsatlar ────────────────────────────────────────────
    public bool CanCourses => _s.Session.Can(Permissions.CourseView);
    public bool CanAttendance => _s.Session.Can(Permissions.AttendanceMark);
    public bool CanReports => _s.Session.Can(Permissions.ReportView);
    public bool CanUsers => _s.Session.Can(Permissions.UserManage);
    public bool CanSettings => _s.Session.Can(Permissions.SettingsManage);
    public bool HasAdmin => CanUsers || CanSettings;
    /// <summary>To'lovlar: boshqaruvchi — hammasi, o'quvchi — o'z balansi (billing.enabled bo'lsa).</summary>
    public bool CanBilling => _s.Session.Can(Permissions.BillingManage)
        || (_s.Session.User?.Role == "STUDENT" && Settings.Bool("billing.enabled", false));

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(HasUnread), nameof(UnreadText))]
    private int _unread;

    public bool HasUnread => Unread > 0;
    public string UnreadText => Unread > 99 ? "99+" : Unread.ToString(System.Globalization.CultureInfo.InvariantCulture);

    [ObservableProperty]
    private bool _isOffline;

    /// <summary>Sozlamalar yoki foydalanuvchi o'zgarganda butun menyu qayta chiziladi.</summary>
    public void RefreshAll() => OnPropertyChanged(string.Empty);

    /// <summary>Admin bo'limlari (foydalanuvchilar, filiallar, tizim sozlamalari) — saytdagi boshqaruv panelida.</summary>
    public void OpenWeb(string path) => _s.Platform.Open(new Uri(Settings.SiteUrl + path));

    [RelayCommand]
    private Task LogoutAsync() => _s.Session.SignOutAsync();
}
