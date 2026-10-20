using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Input;
using Microsoft.UI.Xaml.Navigation;
using TisuEdu.App.Services;
using TisuEdu.Core.ViewModels;
using AppPage = TisuEdu.Core.Services.Page;

namespace TisuEdu.App.Views;

/// <summary>Asosiy qobiq: NavigationView (ruxsatlarga qarab menyu), orqaga qaytish, o'qilmaganlar belgisi.</summary>
public sealed partial class ShellPage : Page
{
    public ShellPage()
    {
        ViewModel = new ShellViewModel(App.Services);
        InitializeComponent();
    }

    public ShellViewModel ViewModel { get; }

    private static NavigationService Navigation => App.Current!.Navigation;

    protected override void OnNavigatedTo(NavigationEventArgs e)
    {
        base.OnNavigatedTo(e);
        Navigation.Attach(ContentFrame);
        Navigation.Navigated += OnContentNavigated;
        ContentFrame.Navigated += (_, _) => Nav.IsBackEnabled = ContentFrame.CanGoBack;
        Navigation.NavigateRoot(AppPage.Dashboard);
        ViewModel.Unread = App.Services.Watcher.Unread;
    }

    protected override void OnNavigatedFrom(NavigationEventArgs e)
    {
        Navigation.Navigated -= OnContentNavigated;
        base.OnNavigatedFrom(e);
    }

    public void OnSettingsChanged()
    {
        ViewModel.RefreshAll();
        Bindings.Update();
    }

    public void SetUnread(int unread) => ViewModel.Unread = unread;

    private void OnContentNavigated(object? sender, AppPage page)
    {
        if (page == AppPage.AppSettings)
        {
            Nav.SelectedItem = Nav.SettingsItem;
            return;
        }
        NavigationViewItem? item = page switch
        {
            AppPage.Dashboard => HomeItem,
            AppPage.Courses => CoursesItem,
            AppPage.Groups => GroupsItem,
            AppPage.Attendance => AttendanceItem,
            AppPage.News => NewsItem,
            AppPage.Certificates => CertificatesItem,
            AppPage.Reports => ReportsItem,
            AppPage.Profile => ProfileItem,
            _ => null,
        };
        // Masalan, o'quvchi guruh sahifasidan davomat varaqasiga o'tsa — "Davomat" menyusi yo'q, guruh tanlangan qoladi.
        if (item is { Visibility: Visibility.Visible }) Nav.SelectedItem = item;
    }

    private void Nav_ItemInvoked(NavigationView sender, NavigationViewItemInvokedEventArgs args)
    {
        if (args.IsSettingsInvoked)
        {
            Navigation.NavigateRoot(AppPage.AppSettings);
            return;
        }
        if (args.InvokedItemContainer?.Tag is not string tag) return;
        if (tag == "logout")
        {
            ViewModel.LogoutCommand.Execute(null);
        }
        else if (tag.StartsWith("web:", StringComparison.Ordinal))
        {
            ViewModel.OpenWeb(tag[4..]);
        }
        else if (Enum.TryParse<AppPage>(tag, out var page))
        {
            Navigation.NavigateRoot(page);
        }
    }

    private void Nav_BackRequested(NavigationView sender, NavigationViewBackRequestedEventArgs args) => Navigation.GoBack();

    private void Back_Invoked(KeyboardAccelerator sender, KeyboardAcceleratorInvokedEventArgs args)
    {
        args.Handled = Navigation.GoBack();
    }

    private void Reload_Invoked(KeyboardAccelerator sender, KeyboardAcceleratorInvokedEventArgs args)
    {
        if (ContentFrame.Content is ViewPage p)
        {
            p.Reload();
            args.Handled = true;
        }
    }
}
