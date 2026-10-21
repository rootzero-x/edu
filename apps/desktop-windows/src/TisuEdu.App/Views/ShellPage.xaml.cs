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
    private readonly SearchService _search = new(App.Services);

    public ShellPage()
    {
        ViewModel = new ShellViewModel(App.Services);
        InitializeComponent();
    }

    public void SetInboxUnread(int unread) => ViewModel.InboxUnread = unread;

    // ── Global qidiruv (Ctrl+K) ──────────────────────────────
    private void Search_Invoked(KeyboardAccelerator sender, KeyboardAcceleratorInvokedEventArgs args)
    {
        if (!Nav.IsPaneOpen && Nav.DisplayMode != NavigationViewDisplayMode.Expanded) Nav.IsPaneOpen = true;
        SearchBox.Focus(FocusState.Keyboard);
        args.Handled = true;
    }

    private async void Search_TextChanged(AutoSuggestBox sender, AutoSuggestBoxTextChangedEventArgs args)
    {
        if (args.Reason != AutoSuggestionBoxTextChangeReason.UserInput) return;
        var text = sender.Text;
        var hits = await _search.SearchAsync(text);
        if (sender.Text == text) sender.ItemsSource = hits.Count > 0 ? hits : null;
    }

    private void Search_SuggestionChosen(AutoSuggestBox sender, AutoSuggestBoxSuggestionChosenEventArgs args)
    {
        // Tanlangan matn qidiruv qatoriga yozilmasin — QuerySubmitted ochadi.
    }

    private void Search_QuerySubmitted(AutoSuggestBox sender, AutoSuggestBoxQuerySubmittedEventArgs args)
    {
        var item = args.ChosenSuggestion as SearchHitItem
                   ?? (sender.ItemsSource as IReadOnlyList<SearchHitItem>)?.FirstOrDefault();
        if (item is null) return;
        sender.Text = "";
        sender.ItemsSource = null;
        _search.Open(item);
    }

    // ── Ctrl+1…9 — menyu bo'limlari (Linux ilovasidagi kabi) ──
    private void Section_Invoked(KeyboardAccelerator sender, KeyboardAcceleratorInvokedEventArgs args)
    {
        var index = (int)sender.Key - (int)Windows.System.VirtualKey.Number1;
        var items = Nav.MenuItems.OfType<NavigationViewItem>().Where(i => i.Visibility == Visibility.Visible).ToList();
        if (index >= 0 && index < items.Count && items[index].Tag is string tag && Enum.TryParse<AppPage>(tag, out var page))
        {
            Navigation.NavigateRoot(page);
            args.Handled = true;
        }
    }

    private async void Shortcuts_Invoked(KeyboardAccelerator sender, KeyboardAcceleratorInvokedEventArgs args)
    {
        args.Handled = true;
        var text = string.Join("\n", new[]
        {
            "Ctrl+K — global qidiruv",
            "Ctrl+1 … Ctrl+9 — menyu bo'limlari",
            "Ctrl+R, F5 — sahifani yangilash",
            "Alt+←, Esc — orqaga",
            "Ctrl+S — davomatni saqlash",
            "Ctrl+Enter — testni topshirish",
            "F1 — shu oyna",
        });
        await App.Services.Platform.ConfirmAsync("Klaviatura", text, "Yopish", "");
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
        ViewModel.InboxUnread = App.Services.Watcher.InboxUnread;
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
            AppPage.Billing => BillingItem,
            AppPage.Users => UsersItem,
            AppPage.Calendar => CalendarItem,
            AppPage.Homework => HomeworkItem,
            AppPage.Notifications => NotificationsItem,
            AppPage.Kpi => KpiItem,
            AppPage.Leads => LeadsItem,
            AppPage.Audit => AuditItem,
            AppPage.System => SystemItem,
            AppPage.AdminSettings => AdminSettingsItem,
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
        // Esc matn kiritish paytida orqaga o'tmasin (kiritilganlar yo'qolmasin).
        if (sender.Key == Windows.System.VirtualKey.Escape
            && FocusManager.GetFocusedElement(XamlRoot) is TextBox or PasswordBox or AutoSuggestBox or ComboBox or CalendarDatePicker) return;
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
