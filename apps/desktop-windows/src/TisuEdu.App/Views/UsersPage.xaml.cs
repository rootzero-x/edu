using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class UsersPage : ViewPage
{
    public UsersPage() => InitializeComponent();

    public UsersViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter)
    {
        ViewModel = new UsersViewModel(App.Services);
        RoleBox.ItemsSource = ViewModel.RoleFilters.Select(r => r.Value).ToList();
        return ViewModel;
    }

    private static UserItem? Item(object sender) => (sender as FrameworkElement)?.Tag as UserItem;

    private void Search_QuerySubmitted(AutoSuggestBox sender, AutoSuggestBoxQuerySubmittedEventArgs args) => ViewModel.SearchCommand.Execute(null);

    private void Edit_Click(object sender, RoutedEventArgs e) => ViewModel.EditUserCommand.Execute(Item(sender));

    private void Password_Click(object sender, RoutedEventArgs e) => ViewModel.SetPasswordCommand.Execute(Item(sender));

    private void Block_Click(object sender, RoutedEventArgs e) => ViewModel.ToggleBlockCommand.Execute(Item(sender));

    private void Approve_Click(object sender, RoutedEventArgs e) => ViewModel.ApproveCommand.Execute(Item(sender));
}
