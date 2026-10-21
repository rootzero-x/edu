using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class NotificationsPage : ViewPage
{
    public NotificationsPage() => InitializeComponent();

    public NotificationsViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new NotificationsViewModel(App.Services);

    private void Item_Click(object sender, ItemClickEventArgs e) => ViewModel.OpenCommand.Execute(e.ClickedItem as NotificationVm);
}
