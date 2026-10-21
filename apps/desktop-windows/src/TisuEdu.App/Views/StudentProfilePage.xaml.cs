using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class StudentProfilePage : ViewPage
{
    public StudentProfilePage() => InitializeComponent();

    public StudentProfileViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new StudentProfileViewModel(App.Services, IntParam(parameter));
}
