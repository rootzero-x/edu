using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class SystemPage : ViewPage
{
    public SystemPage() => InitializeComponent();

    public SystemViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new SystemViewModel(App.Services);
}
