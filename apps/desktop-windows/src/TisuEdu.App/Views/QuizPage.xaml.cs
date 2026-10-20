using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class QuizPage : ViewPage
{
    public QuizPage() => InitializeComponent();

    public QuizViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new QuizViewModel(App.Services, IntParam(parameter));

    private void EditQuestion_Click(object sender, RoutedEventArgs e) => ViewModel.EditQuestionCommand.Execute((sender as FrameworkElement)?.Tag as QuestionKeyItem);

    private void DeleteQuestion_Click(object sender, RoutedEventArgs e) => ViewModel.DeleteQuestionCommand.Execute((sender as FrameworkElement)?.Tag as QuestionKeyItem);

    public static InfoBarSeverity ReviewSeverity(bool correct) => correct ? InfoBarSeverity.Success : InfoBarSeverity.Error;
}
