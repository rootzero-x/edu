using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class CoursesPage : ViewPage
{
    public CoursesPage() => InitializeComponent();

    public CoursesViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new CoursesViewModel(App.Services);

    private void Course_ItemClick(object sender, ItemClickEventArgs e) => ViewModel.OpenCommand.Execute(e.ClickedItem as CourseItem);
}
