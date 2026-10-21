using Microsoft.UI.Xaml;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class RoomsPage : ViewPage
{
    public RoomsPage() => InitializeComponent();

    public RoomsViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new RoomsViewModel(App.Services);

    private void Edit_Click(object sender, RoutedEventArgs e) => ViewModel.EditRoomCommand.Execute((sender as FrameworkElement)?.Tag as RoomItem);

    private void Delete_Click(object sender, RoutedEventArgs e) => ViewModel.DeleteRoomCommand.Execute((sender as FrameworkElement)?.Tag as RoomItem);
}
