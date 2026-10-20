using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class AttendancePage : ViewPage
{
    public AttendancePage() => InitializeComponent();

    public AttendanceViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new AttendanceViewModel(App.Services);

    private void Item_Click(object sender, ItemClickEventArgs e) => ViewModel.OpenCommand.Execute(e.ClickedItem as UnmarkedItem);
}
