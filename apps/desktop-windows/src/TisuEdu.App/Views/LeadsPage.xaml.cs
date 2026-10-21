using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class LeadsPage : ViewPage
{
    public LeadsPage() => InitializeComponent();

    public LeadsViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new LeadsViewModel(App.Services);

    private void Search_QuerySubmitted(AutoSuggestBox sender, AutoSuggestBoxQuerySubmittedEventArgs args) => ViewModel.SearchCommand.Execute(null);

    private void Item_Click(object sender, ItemClickEventArgs e) => ViewModel.OpenCommand.Execute(e.ClickedItem as LeadItem);
}
