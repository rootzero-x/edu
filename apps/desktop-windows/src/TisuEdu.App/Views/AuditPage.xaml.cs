using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class AuditPage : ViewPage
{
    public AuditPage() => InitializeComponent();

    public AuditViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new AuditViewModel(App.Services);
}
