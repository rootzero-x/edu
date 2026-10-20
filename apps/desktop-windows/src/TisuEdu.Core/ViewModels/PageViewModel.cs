using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

/// <summary>Ilova xizmatlari — har bir ViewModel shu orqali API, sessiya va platformaga murojaat qiladi.</summary>
public sealed class AppServices(Session session, SettingsService settings, IPlatform platform, INavigator navigator, AnnouncementWatcher watcher, Preferences preferences)
{
    public Session Session { get; } = session;
    public TisuApi Api => Session.Api;
    public SettingsService Settings { get; } = settings;
    public IPlatform Platform { get; } = platform;
    public INavigator Navigator { get; } = navigator;
    public AnnouncementWatcher Watcher { get; } = watcher;
    public Preferences Preferences { get; } = preferences;
}

/// <summary>
/// Sahifa ViewModel'i uchun umumiy holatlar: yuklanmoqda, xato (qayta urinish bilan), oflayn kesh belgisi.
/// So'rovlar async — UI oqimi hech qachon bloklanmaydi.
/// </summary>
public abstract partial class PageViewModel : ObservableObject
{
    private CancellationTokenSource? _cts;

    protected PageViewModel(AppServices services) => S = services;

    protected AppServices S { get; }

    public SiteSettings Settings => S.Settings.Current;

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(ShowContent))]
    private bool _isLoading;

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(HasError), nameof(ShowContent))]
    private string? _error;

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsStale))]
    private string? _offlineNote;

    /// <summary>Muvaffaqiyatli bildirish (InfoBar Success).</summary>
    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(HasNotice))]
    private string? _notice;

    [ObservableProperty]
    private bool _hasLoaded;

    public bool HasError => Error is not null;
    public bool HasNotice => Notice is not null;
    public bool IsStale => OfflineNote is not null;
    public bool ShowContent => !HasError && (HasLoaded || !IsLoading);

    public string RetryText => Settings.Text("retry", "Qayta urinish");

    [RelayCommand]
    public async Task RefreshAsync()
    {
        _cts?.Cancel();
        _cts = new CancellationTokenSource();
        var ct = _cts.Token;
        IsLoading = true;
        Error = null;
        try
        {
            await LoadCoreAsync(ct);
            HasLoaded = true;
            OnPropertyChanged(nameof(ShowContent));
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { }
        catch (ApiException ex) when (!ct.IsCancellationRequested)
        {
            Error = ex.Message;
            OnLoadFailed(ex);
        }
        finally
        {
            if (!ct.IsCancellationRequested) IsLoading = false;
        }
    }

    /// <summary>Sahifadan chiqilganda — yarim qolgan so'rovlar bekor qilinadi.</summary>
    public virtual void Leave() => _cts?.Cancel();

    protected abstract Task LoadCoreAsync(CancellationToken ct);

    protected virtual void OnLoadFailed(ApiException ex) { }

    protected void Track<T>(Fetched<T> f)
    {
        if (f.FromCache)
        {
            OfflineNote = $"{Strings.OfflineCached} · {Format.DateTime(f.SavedAt)}";
        }
    }

    protected void ResetStale() => OfflineNote = null;

    /// <summary>Amal (saqlash va h.k.) — xatoni Error'ga emas, Notice/ActionError'ga chiqaradi.</summary>
    protected async Task<bool> ActAsync(Func<Task> action)
    {
        ActionError = null;
        Notice = null;
        IsBusy = true;
        try
        {
            await action();
            return true;
        }
        catch (ApiException ex)
        {
            ActionError = ex.Message;
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(HasActionError))]
    private string? _actionError;

    [ObservableProperty]
    private bool _isBusy;

    public bool HasActionError => ActionError is not null;

    partial void OnIsBusyChanged(bool value) => OnBusyChanged();

    /// <summary>Buyruqlarning CanExecute holatini yangilash uchun.</summary>
    protected virtual void OnBusyChanged() { }

    [RelayCommand]
    private void DismissNotice() => Notice = null;
}
