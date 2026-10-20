using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class ProfilePage : ViewPage
{
    public ProfilePage() => InitializeComponent();

    public ProfileViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new ProfileViewModel(App.Services);
}
