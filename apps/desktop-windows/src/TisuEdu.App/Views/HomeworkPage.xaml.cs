using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class HomeworkPage : ViewPage
{
    public HomeworkPage() => InitializeComponent();

    public HomeworkViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new HomeworkViewModel(App.Services, parameter as string);

    private void Item_Click(object sender, ItemClickEventArgs e) => ViewModel.OpenCommand.Execute(e.ClickedItem as AssignmentItem);
}
