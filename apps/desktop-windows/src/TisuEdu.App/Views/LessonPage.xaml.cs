using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class LessonPage : ViewPage
{
    public LessonPage() => InitializeComponent();

    public LessonViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new LessonViewModel(App.Services, IntParam(parameter));

    private void Material_Click(object sender, RoutedEventArgs e) => ViewModel.OpenMaterialCommand.Execute((sender as FrameworkElement)?.Tag as MaterialItem);
}
