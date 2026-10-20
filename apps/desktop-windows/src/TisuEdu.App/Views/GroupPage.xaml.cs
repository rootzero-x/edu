using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class GroupPage : ViewPage
{
    public GroupPage() => InitializeComponent();

    public GroupViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new GroupViewModel(App.Services, IntParam(parameter));

    private void Session_ItemClick(object sender, ItemClickEventArgs e) => ViewModel.OpenSessionCommand.Execute(e.ClickedItem as SessionItem);
}
