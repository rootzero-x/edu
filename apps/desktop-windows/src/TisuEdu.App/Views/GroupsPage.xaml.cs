using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class GroupsPage : ViewPage
{
    public GroupsPage() => InitializeComponent();

    public GroupsViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new GroupsViewModel(App.Services);

    private void Group_ItemClick(object sender, ItemClickEventArgs e) => ViewModel.OpenCommand.Execute(e.ClickedItem as GroupCard);
}
