using Microsoft.UI;
using Microsoft.UI.Composition.SystemBackdrops;
using Microsoft.UI.Windowing;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Animation;
using TisuEdu.App.Views;
using TisuEdu.Core.Services;
using Windows.Graphics;

namespace TisuEdu.App;

/// <summary>Asosiy oyna: Mica fon, o'z sarlavha paneli, mavzu (yorug'/qorong'i/tizim).</summary>
public sealed partial class MainWindow : Window
{
    public MainWindow()
    {
        InitializeComponent();

        Title = "TISU Edu";
        ExtendsContentIntoTitleBar = true;
        SetTitleBar(AppTitleBar);
        SystemBackdrop = MicaController.IsSupported() ? new MicaBackdrop() : new DesktopAcrylicBackdrop();

        var tb = AppWindow.TitleBar;
        tb.PreferredHeightOption = TitleBarHeightOption.Tall;
        tb.ButtonBackgroundColor = Colors.Transparent;
        tb.ButtonInactiveBackgroundColor = Colors.Transparent;
        try { AppWindow.SetIcon(Path.Combine(AppContext.BaseDirectory, "Assets", "AppIcon.ico")); } catch (Exception) { }

        // Boshlang'ich o'lcham — ekran DPI'siga moslab, markazda.
        var scale = GetScale();
        AppWindow.Resize(new SizeInt32((int)(1280 * scale), (int)(820 * scale)));
        var area = DisplayArea.GetFromWindowId(AppWindow.Id, DisplayAreaFallback.Primary).WorkArea;
        AppWindow.Move(new PointInt32(area.X + Math.Max(0, (area.Width - AppWindow.Size.Width) / 2), area.Y + Math.Max(0, (area.Height - AppWindow.Size.Height) / 2)));

        Root.ActualThemeChanged += (_, _) => UpdateCaptionButtons();
        FatalBar.CloseButtonClick += (_, _) => FatalBar.IsOpen = false;
    }

    public ElementTheme CurrentTheme => Root.RequestedTheme;

    public void ApplyTheme(AppTheme theme)
    {
        Root.RequestedTheme = theme switch
        {
            AppTheme.Light => ElementTheme.Light,
            AppTheme.Dark => ElementTheme.Dark,
            _ => ElementTheme.Default,
        };
        UpdateCaptionButtons();
    }

    private void UpdateCaptionButtons()
    {
        var dark = Root.ActualTheme == ElementTheme.Dark;
        var tb = AppWindow.TitleBar;
        tb.ButtonForegroundColor = dark ? Colors.White : Colors.Black;
        tb.ButtonHoverForegroundColor = dark ? Colors.White : Colors.Black;
        tb.ButtonHoverBackgroundColor = dark ? Windows.UI.Color.FromArgb(0x15, 0xFF, 0xFF, 0xFF) : Windows.UI.Color.FromArgb(0x0F, 0, 0, 0);
        tb.ButtonPressedBackgroundColor = dark ? Windows.UI.Color.FromArgb(0x0B, 0xFF, 0xFF, 0xFF) : Windows.UI.Color.FromArgb(0x0A, 0, 0, 0);
        tb.ButtonInactiveForegroundColor = dark ? Windows.UI.Color.FromArgb(0x5D, 0xFF, 0xFF, 0xFF) : Windows.UI.Color.FromArgb(0x5C, 0, 0, 0);
    }

    public void ShowSplash()
    {
        var panel = new StackPanel { HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center, Spacing = 20 };
        panel.Children.Add(new Image
        {
            Source = new Microsoft.UI.Xaml.Media.Imaging.BitmapImage(new Uri("ms-appx:///Assets/Square44x44Logo.targetsize-256.png")),
            Width = 72,
            Height = 72,
        });
        panel.Children.Add(new ProgressRing { IsActive = true, Width = 28, Height = 28 });
        RootFrame.Content = panel;
        OnSettingsChanged();
    }

    public void ShowLogin(bool expired)
    {
        RootFrame.Navigate(typeof(LoginPage), expired, new DrillInNavigationTransitionInfo());
        OnSettingsChanged();
    }

    public void ShowShell()
    {
        if (RootFrame.Content is ShellPage) return;
        RootFrame.Navigate(typeof(ShellPage), null, new EntranceNavigationTransitionInfo());
    }

    public void OnSettingsChanged()
    {
        var name = App.Services?.Settings.Current.SiteName ?? "TISU Edu";
        Title = name;
        TitleText.Text = name;
        (RootFrame.Content as ShellPage)?.OnSettingsChanged();
        (RootFrame.Content as LoginPage)?.OnSettingsChanged();
    }

    public void SetOffline(bool offline) => OfflinePill.Visibility = offline ? Visibility.Visible : Visibility.Collapsed;

    public void SetUnread(int unread) => (RootFrame.Content as ShellPage)?.SetUnread(unread);

    public void BringToFront()
    {
        if (AppWindow.Presenter is OverlappedPresenter { State: OverlappedPresenterState.Minimized } p) p.Restore();
        Activate();
    }

    public void ShowFatal(string message)
    {
        FatalBar.Message = message;
        FatalBar.IsOpen = true;
    }

    private double GetScale()
    {
        try { return GetDpiForWindow(WinRT.Interop.WindowNative.GetWindowHandle(this)) / 96.0; }
        catch (Exception) { return 1.0; }
    }

    [System.Runtime.InteropServices.DllImport("user32.dll")]
    private static extern uint GetDpiForWindow(IntPtr hwnd);
}
