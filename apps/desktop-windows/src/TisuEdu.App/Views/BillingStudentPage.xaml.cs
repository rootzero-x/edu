using Microsoft.UI.Xaml;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class BillingStudentPage : ViewPage
{
    public BillingStudentPage() => InitializeComponent();

    public BillingStudentViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new BillingStudentViewModel(App.Services, IntParam(parameter));

    private void Void_Click(object sender, RoutedEventArgs e) => ViewModel.VoidCommand.Execute((sender as FrameworkElement)?.Tag as EntryItem);
}
