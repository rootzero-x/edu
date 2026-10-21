using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class KpiPage : ViewPage
{
    public KpiPage() => InitializeComponent();

    public KpiViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new KpiViewModel(App.Services);
}
