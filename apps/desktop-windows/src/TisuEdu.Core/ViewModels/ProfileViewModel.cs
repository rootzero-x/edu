using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

/// <summary>Profil: ism/familiya, parolni almashtirish (Telegram orqali kirganlarda birinchi parol), Telegram holati.</summary>
public sealed partial class ProfileViewModel(AppServices services) : PageViewModel(services)
{
    [ObservableProperty] private string _firstName = "";
    [ObservableProperty] private string _lastName = "";
    [ObservableProperty] private string _phone = "";
    [ObservableProperty] private string _role = "";
    [ObservableProperty] private string _initials = "";
    [ObservableProperty] private string _telegramText = "";
    [ObservableProperty] private bool _hasPassword = true;
    [ObservableProperty] private string _currentPassword = "";
    [ObservableProperty] private string _newPassword = "";
    [ObservableProperty] private string _confirmPassword = "";

    public string Title => Settings.Nav("/dashboard/profile", "Profil");
    public string RoleLabel => Settings.Text("profile_role", "Rol");
    public string LogoutText => Settings.Text("logout", "Chiqish");

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        try { await S.Session.RefreshUserAsync(ct); }
        catch (Api.ApiException ex) when (ex.IsOffline) { OfflineNote = Strings.OfflineCached; }
        var u = S.Session.User!;
        FirstName = u.FirstName;
        LastName = u.LastName ?? "";
        Phone = u.Phone ?? "";
        Role = Settings.RoleLabel(u.Role);
        Initials = u.Initials;
        HasPassword = u.HasPassword != false;
        TelegramText = u.Telegram?.Linked == true
            ? $"Telegram ulangan{(string.IsNullOrEmpty(u.Telegram.Username) ? "" : " · @" + u.Telegram.Username)}"
            : "Telegram ulanmagan";
    }

    [RelayCommand]
    private async Task SaveProfileAsync()
    {
        if (FirstName.Trim().Length == 0) { ActionError = "Ismni kiriting."; return; }
        await ActAsync(async () =>
        {
            var u = await S.Api.UpdateMeAsync(FirstName.Trim(), LastName.Trim().Length > 0 ? LastName.Trim() : null);
            S.Session.Update(u);
            Initials = u.Initials;
            Notice = Strings.ProfileSaved;
        });
    }

    [RelayCommand]
    private async Task ChangePasswordAsync()
    {
        if (NewPassword != ConfirmPassword) { ActionError = Strings.PasswordMismatch; return; }
        if (NewPassword.Length == 0) { ActionError = Strings.PasswordRequired; return; }
        await ActAsync(async () =>
        {
            await S.Api.ChangePasswordAsync(HasPassword ? CurrentPassword : null, NewPassword);
            CurrentPassword = NewPassword = ConfirmPassword = "";
            HasPassword = true;
            Notice = Strings.PasswordChanged;
        });
    }

    [RelayCommand]
    private Task LogoutAsync() => S.Session.SignOutAsync();
}

/// <summary>Ilova sozlamalari: mavzu, bildirishnomalar, server, versiya.</summary>
public sealed partial class AppSettingsViewModel : ObservableObject
{
    private readonly AppServices _s;
    private readonly Action<AppTheme> _applyTheme;

    public AppSettingsViewModel(AppServices services, Action<AppTheme> applyTheme, string version)
    {
        _s = services;
        _applyTheme = applyTheme;
        _themeIndex = (int)services.Preferences.Theme;
        _notifications = services.Preferences.Notifications;
        Version = version;
    }

    public string Version { get; }
    public string Server => _s.Api.Http.BaseUrl;
    public string SiteUrl => _s.Settings.Current.SiteUrl;
    public string SiteName => _s.Settings.Current.SiteName;

    [ObservableProperty] private int _themeIndex;
    [ObservableProperty] private bool _notifications;

    partial void OnThemeIndexChanged(int value)
    {
        var theme = (AppTheme)Math.Clamp(value, 0, 2);
        _s.Preferences.Theme = theme;
        _applyTheme(theme);
    }

    partial void OnNotificationsChanged(bool value)
    {
        _s.Preferences.Notifications = value;
        _s.Watcher.NotificationsEnabled = value;
    }

    [RelayCommand]
    private void OpenSite() => _s.Platform.Open(new Uri(SiteUrl));
}
