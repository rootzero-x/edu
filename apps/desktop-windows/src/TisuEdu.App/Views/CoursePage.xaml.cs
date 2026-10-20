using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class CoursePage : ViewPage
{
    public CoursePage() => InitializeComponent();

    public CourseViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new CourseViewModel(App.Services, IntParam(parameter));

    private void Lesson_ItemClick(object sender, ItemClickEventArgs e) => ViewModel.OpenLessonCommand.Execute(e.ClickedItem as LessonItem);

    private void Quiz_ItemClick(object sender, ItemClickEventArgs e) => ViewModel.OpenQuizCommand.Execute(e.ClickedItem as QuizItem);

    public static bool Not(bool value) => !value;

    private static ModuleItem? Module(object sender) => (sender as FrameworkElement)?.Tag as ModuleItem;

    private void AddLesson_Click(object sender, RoutedEventArgs e) => ViewModel.AddLessonCommand.Execute(Module(sender));

    private void EditModule_Click(object sender, RoutedEventArgs e) => ViewModel.EditModuleCommand.Execute(Module(sender));

    private void DeleteModule_Click(object sender, RoutedEventArgs e) => ViewModel.DeleteModuleCommand.Execute(Module(sender));
}
