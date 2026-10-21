using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.Models;
using TisuEdu.Core.ViewModels;
using AppPage = TisuEdu.Core.Services.Page;

namespace TisuEdu.App.Views;

public sealed partial class DashboardPage : ViewPage
{
    public DashboardPage() => InitializeComponent();

    public DashboardViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new DashboardViewModel(App.Services);

    public static bool Not(bool value) => !value;

    private void Setup_Click(object sender, RoutedEventArgs e) => ViewModel.OpenSetupStepCommand.Execute((sender as FrameworkElement)?.Tag as SetupStepItem);

    private void Stat_ItemClick(object sender, ItemClickEventArgs e) => ViewModel.OpenStatCommand.Execute(e.ClickedItem as StatCard);

    private void Today_ItemClick(object sender, ItemClickEventArgs e) => ViewModel.OpenSessionCommand.Execute(e.ClickedItem as TodayItem);

    private void News_ItemClick(object sender, ItemClickEventArgs e)
    {
        if (e.ClickedItem is Announcement a) App.Services.Navigator.Navigate(AppPage.News, a.Id);
    }
}
