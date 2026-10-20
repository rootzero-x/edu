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
}
