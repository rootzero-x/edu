using System.Net;
using Microsoft.UI.Dispatching;
using Microsoft.UI.Xaml;
using Microsoft.Windows.AppLifecycle;
using Microsoft.Windows.AppNotifications;
using TisuEdu.App.Services;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App;

/// <summary>Kompozitsiya ildizi: xizmatlarni yaratadi, sessiyani tiklaydi va oynani ko'rsatadi.</summary>
public partial class App : Application
{
    private MainWindow? _window;
    private readonly DispatcherQueue _dispatcher;

    public App()
    {
        InitializeComponent();
        Current = this;
        _dispatcher = DispatcherQueue.GetForCurrentThread();
        UnhandledException += (_, e) =>
        {
            // Kutilmagan xato ilovani yopmasin — foydalanuvchiga ko'rsatiladi.
            e.Handled = true;
            _window?.ShowFatal(e.Exception?.Message ?? e.Message);
        };
    }

    public static new App? Current { get; private set; }

    public static AppServices Services { get; private set; } = null!;

    public static string Version =>
        typeof(App).Assembly.GetName().Version?.ToString(3) ?? "1.0.0";

    public MainWindow? Window => _window;

    internal WinPlatform Platform { get; private set; } = null!;

    internal NavigationService Navigation { get; private set; } = null!;

    protected override async void OnLaunched(LaunchActivatedEventArgs args)
    {
        var paths = AppPaths.Create();
        var prefs = new Preferences(Path.Combine(paths.Data, "preferences.json"));

        var handler = new SocketsHttpHandler
        {
            AutomaticDecompression = DecompressionMethods.All,
            PooledConnectionLifetime = TimeSpan.FromMinutes(5),
            ConnectTimeout = TimeSpan.FromSeconds(10),
        };
        var cache = new FileResponseCache(paths.Cache);
        var http = new ApiClient(new HttpClient(handler), cache, Environment.GetEnvironmentVariable("TISU_API_URL"))
        {
            UserAgent = $"TisuEdu-Windows/{Version}",
        };
        var api = new TisuApi(http);
        var settings = new SettingsService(api, Path.Combine(paths.Data, "public-settings.json"));
        var session = new Session(api, new SecureTokenStore(paths.Data), cache, Path.Combine(paths.Data, "user.json"));

        // Ochiq sozlamalar keshdan darhol (oflayn ham ishlaydi) — brend rangi oyna yaratilishidan oldin.
        await settings.LoadCachedAsync();
        Theme.ApplyAccent(settings.Current.PrimaryColor);

        _window = new MainWindow();
        Platform = new WinPlatform(_window);
        Navigation = new NavigationService();
        var notifier = new ToastNotifier();
        var watcher = new AnnouncementWatcher(session, settings, notifier, paths.Data) { NotificationsEnabled = prefs.Notifications };
        Services = new AppServices(session, settings, Platform, Navigation, watcher, prefs, new FormDialogService(_window));

        notifier.Invoked += (_, id) => _dispatcher.TryEnqueue(() => OpenAnnouncement(id));
        notifier.UrlInvoked += (_, url) => _dispatcher.TryEnqueue(() => OpenLink(url));
        notifier.Register();

        session.SignedIn += (_, _) => _dispatcher.TryEnqueue(OnSignedIn);
        session.SignedOut += (_, expired) => _dispatcher.TryEnqueue(() => OnSignedOut(expired));
        settings.Changed += (_, _) => _dispatcher.TryEnqueue(() => _window?.OnSettingsChanged());
        http.ConnectivityChanged += (_, online) => _dispatcher.TryEnqueue(() => _window?.SetOffline(!online));
        watcher.UnreadChanged += (_, n) => _dispatcher.TryEnqueue(() => _window?.SetUnread(n));
        watcher.InboxUnreadChanged += (_, n) => _dispatcher.TryEnqueue(() => _window?.SetInboxUnread(n));

        _window.ApplyTheme(prefs.Theme);
        _window.ShowSplash();
        _window.Activate();

        // Tarmoq so'rovlari parallel: sozlamalarni yangilash va sessiyani tiklash.
        var refresh = settings.RefreshAsync();
        var restored = await session.TryRestoreAsync();
        if (!restored)
        {
            await Task.WhenAny(refresh, Task.Delay(1500));
            _window.ShowLogin(expired: false);
        }

        // Bildirishnoma bosilib ishga tushirilgan bo'lsa.
        HandleActivation(AppInstance.GetCurrent().GetActivatedEventArgs());
    }

    private void OnSignedIn()
    {
        _window?.ShowShell();
        Services.Watcher.Start();
    }

    private void OnSignedOut(bool expired)
    {
        Services.Watcher.Stop();
        Navigation.Reset();
        _window?.ShowLogin(expired);
    }

    /// <summary>Ikkinchi nusxa ochilganda yoki bildirishnoma bosilganda (Program.cs dan, boshqa oqimda).</summary>
    internal void OnRedirectedActivation(AppActivationArguments args) =>
        _dispatcher.TryEnqueue(() =>
        {
            _window?.BringToFront();
            HandleActivation(args);
        });

    private void HandleActivation(AppActivationArguments args)
    {
        if (args.Kind == ExtendedActivationKind.AppNotification && args.Data is AppNotificationActivatedEventArgs n
            && ToastNotifier.TryGetAnnouncementId(n.Arguments, out var id))
        {
            OpenAnnouncement(id);
        }
        else if (args.Kind == ExtendedActivationKind.AppNotification && args.Data is AppNotificationActivatedEventArgs n2
                 && ToastNotifier.TryGetUrl(n2.Arguments, out var url))
        {
            OpenLink(url);
        }
    }

    private void OpenLink(string url)
    {
        _window?.BringToFront();
        if (!Services.Session.IsSignedIn) return;
        if (Links.Map(url) is { } t) Navigation.Navigate(t.Page, t.Parameter);
        else Navigation.Navigate(Page.Notifications);
    }

    private void OpenAnnouncement(int id)
    {
        _window?.BringToFront();
        if (Services.Session.IsSignedIn) Navigation.Navigate(Page.News, id);
    }
}
