using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class LeadPage : ViewPage
{
    public LeadPage() => InitializeComponent();

    public LeadViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new LeadViewModel(App.Services, IntParam(parameter));
}
