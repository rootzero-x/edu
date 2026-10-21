using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

/// <summary>
/// Kirish: telefon+parol yoki Telegram deep-link (start → t.me havolasi → poll). Matnlar login.* sozlamalaridan.
/// </summary>
public sealed partial class LoginViewModel : ObservableObject
{
    private readonly Session _session;
    private readonly SettingsService _settings;
    private readonly IPlatform _platform;
    private readonly Preferences _prefs;
    private CancellationTokenSource? _pollCts;

    public LoginViewModel(Session session, SettingsService settings, IPlatform platform, Preferences prefs)
    {
        _session = session;
        _settings = settings;
        _platform = platform;
        _prefs = prefs;
        _phone = string.IsNullOrEmpty(prefs.LastPhone) ? "+998" : prefs.LastPhone!;
    }

    /// <summary>Telegram polling oralig'i (testlarda qisqartiriladi).</summary>
    internal TimeSpan PollInterval { get; set; } = TimeSpan.FromSeconds(2);

    public SiteSettings Settings => _settings.Current;

    // ── Sozlamadagi matnlar ──────────────────────────────────
    public string SiteName => Settings.SiteName;
    public string Tagline => Settings.Tagline;
    public string Title => Settings.Login("title", "Xush kelibsiz");
    public string Subtitle => Settings.Login("subtitle", "Telegram orqali bir zumda kiring — parol shart emas.");
    public string PasswordSubtitle => Settings.Login("subtitle_password", "Telefon raqamingiz va parolingiz bilan kiring.");
    public string PasswordTitle => Settings.Login("password_title", "Telefon va parol bilan kirish");
    public string TelegramButton => Settings.Login("telegram_button", "Telegram orqali kirish");
    public string WaitingText => Settings.Login("waiting_text", "Telegram'da botni oching, «Start» ni bosing va kirishni tasdiqlang.");
    public string SuccessText => Settings.Login("success_text", "Tasdiqlandi!");
    public string QrHint => Settings.Login("qr_hint", "Telefon kamerasi bilan skanerlang");
    public string RegisterTitle => Settings.Login("register_title", "Ro'yxatdan o'tish");
    public string RegisterText => Settings.Login("register_text", "");
    public string RegisterButton => Settings.Login("register_button", "Telegram orqali ro'yxatdan o'tish");
    public string SideTitle => Settings.Login("side_title", "");
    public IReadOnlyList<string> SidePoints => Settings.List("login.side_points");
    public bool HasRegister => !string.IsNullOrEmpty(Settings.BotUsername);

    // ── Holat ────────────────────────────────────────────────
    [ObservableProperty]
    [NotifyCanExecuteChangedFor(nameof(LoginCommand))]
    private string _phone;

    [ObservableProperty]
    [NotifyCanExecuteChangedFor(nameof(LoginCommand))]
    private string _password = "";

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(HasError))]
    private string? _error;

    [ObservableProperty]
    [NotifyCanExecuteChangedFor(nameof(LoginCommand), nameof(StartTelegramCommand))]
    private bool _isBusy;

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsIdle))]
    private bool _isWaitingTelegram;

    [ObservableProperty]
    private string? _telegramUrl;

    [ObservableProperty]
    private bool _isConfirmed;

    /// <summary>Qolgan vaqt (soniya) — havola eskirishiga.</summary>
    [ObservableProperty]
    private int _secondsLeft;

    public bool HasError => Error is not null;
    public bool IsIdle => !IsWaitingTelegram;

    private bool CanLogin() => !IsBusy && Phone.Trim().Length > 4 && Password.Length > 0;

    [RelayCommand(CanExecute = nameof(CanLogin))]
    private async Task LoginAsync()
    {
        Error = null;
        var phone = NormalizePhone(Phone);
        if (phone.Length < 5) { Error = Strings.PhoneRequired; return; }
        if (Password.Length == 0) { Error = Strings.PasswordRequired; return; }
        IsBusy = true;
        try
        {
            var r = await _session.Api.LoginAsync(phone, Password, "windows");
            _prefs.LastPhone = phone;
            Password = "";
            _session.Complete(r.Token, r.User);
        }
        catch (ApiException ex)
        {
            Error = ex.Message;
        }
        finally
        {
            IsBusy = false;
        }
    }

    [RelayCommand(CanExecute = nameof(CanStartTelegram))]
    private async Task StartTelegramAsync()
    {
        Error = null;
        CancelTelegram();
        IsBusy = true;
        TelegramStart start;
        try
        {
            start = await _session.Api.TelegramStartAsync();
        }
        catch (ApiException ex)
        {
            Error = ex.Message;
            IsBusy = false;
            return;
        }
        IsBusy = false;
        TelegramUrl = start.BotUrl;
        IsWaitingTelegram = true;
        IsConfirmed = false;
        _platform.OpenUrl(start.BotUrl);

        _pollCts = new CancellationTokenSource();
        await PollAsync(start, _pollCts.Token);
    }

    private bool CanStartTelegram() => !IsBusy;

    internal async Task PollAsync(TelegramStart start, CancellationToken ct)
    {
        var expires = DateTimeOffset.UtcNow.AddSeconds(Math.Max(30, start.ExpiresIn));
        while (!ct.IsCancellationRequested)
        {
            SecondsLeft = Math.Max(0, (int)(expires - DateTimeOffset.UtcNow).TotalSeconds);
            if (SecondsLeft == 0) { Expire(); return; }
            try { await Task.Delay(PollInterval, ct); }
            catch (OperationCanceledException) { return; }

            TelegramPoll r;
            try
            {
                r = await _session.Api.TelegramPollAsync(start.Code, start.PollSecret, ct);
            }
            catch (OperationCanceledException) { return; }
            catch (ApiException ex) when (ex.IsOffline || (int?)ex.Status == 429)
            {
                continue; // vaqtinchalik — keyingi urinishda
            }
            catch (ApiException ex)
            {
                Error = ex.Message;
                IsWaitingTelegram = false;
                return;
            }

            switch (r.Status)
            {
                case "ok" when r.Token is not null && r.User is not null:
                    IsConfirmed = true;
                    IsWaitingTelegram = false;
                    _session.Complete(r.Token, r.User);
                    return;
                case "expired":
                    Expire();
                    return;
            }
        }
    }

    private void Expire()
    {
        IsWaitingTelegram = false;
        Error = Strings.TelegramExpired;
    }

    [RelayCommand]
    private void OpenTelegramAgain()
    {
        if (TelegramUrl is not null) _platform.OpenUrl(TelegramUrl);
    }

    [RelayCommand]
    public void CancelTelegram()
    {
        _pollCts?.Cancel();
        _pollCts = null;
        IsWaitingTelegram = false;
    }

    [RelayCommand]
    private void Register()
    {
        if (HasRegister) _platform.OpenUrl($"https://t.me/{Settings.BotUsername}");
    }

    /// <summary>"+998 90 123-45-67" → "+998901234567" (bazadagi format).</summary>
    public static string NormalizePhone(string input)
    {
        var digits = new string(input.Where(char.IsAsciiDigit).ToArray());
        if (digits.Length == 0) return "";
        if (digits.Length == 9) digits = "998" + digits; // mahalliy yozuv: 90 123 45 67
        return "+" + digits;
    }
}
