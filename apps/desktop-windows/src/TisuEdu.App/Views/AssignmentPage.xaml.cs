using Microsoft.UI.Xaml;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class AssignmentPage : ViewPage
{
    public AssignmentPage() => InitializeComponent();

    public AssignmentViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new AssignmentViewModel(App.Services, IntParam(parameter));

    private void OpenFile_Click(object sender, RoutedEventArgs e) => ViewModel.OpenFileCommand.Execute((sender as FrameworkElement)?.Tag as FileItem);

    private void DeleteFile_Click(object sender, RoutedEventArgs e) => ViewModel.DeleteFileCommand.Execute((sender as FrameworkElement)?.Tag as FileItem);

    private void Grade_Click(object sender, RoutedEventArgs e) => ViewModel.GradeCommand.Execute((sender as FrameworkElement)?.Tag as SubmissionItem);
}
