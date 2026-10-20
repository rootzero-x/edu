using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class QuizPage : ViewPage
{
    public QuizPage() => InitializeComponent();

    public QuizViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new QuizViewModel(App.Services, IntParam(parameter));

    public static InfoBarSeverity ReviewSeverity(bool correct) => correct ? InfoBarSeverity.Success : InfoBarSeverity.Error;
}
