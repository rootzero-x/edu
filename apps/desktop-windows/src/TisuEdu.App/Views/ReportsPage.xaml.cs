using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class ReportsPage : ViewPage
{
    public ReportsPage() => InitializeComponent();

    public ReportsViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new ReportsViewModel(App.Services);
}
