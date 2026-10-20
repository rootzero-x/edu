using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class BillingPage : ViewPage
{
    public BillingPage() => InitializeComponent();

    public BillingViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter)
    {
        ViewModel = new BillingViewModel(App.Services);
        StatusBox.ItemsSource = ViewModel.StatusFilters.Select(s => s.Value).ToList();
        SortBox.ItemsSource = ViewModel.Sorts.Select(s => s.Value).ToList();
        return ViewModel;
    }

    private void Search_QuerySubmitted(AutoSuggestBox sender, AutoSuggestBoxQuerySubmittedEventArgs args) => ViewModel.SearchCommand.Execute(null);

    private void Balance_ItemClick(object sender, ItemClickEventArgs e) => ViewModel.OpenStudentCommand.Execute(e.ClickedItem as BalanceItem);
}
