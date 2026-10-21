using Microsoft.UI.Xaml;
using Windows.UI.Text;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class CalendarPage : ViewPage
{
    public CalendarPage() => InitializeComponent();

    public CalendarViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new CalendarViewModel(App.Services);

    /// <summary>Bekor qilingan dars chizib qo'yiladi.</summary>
    public static TextDecorations Strike(bool cancelled) => cancelled ? TextDecorations.Strikethrough : TextDecorations.None;

    private void Event_Click(object sender, RoutedEventArgs e) => ViewModel.OpenCommand.Execute((sender as FrameworkElement)?.Tag as CalendarItem);
}
