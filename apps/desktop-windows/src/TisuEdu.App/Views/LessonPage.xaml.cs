using System.ComponentModel;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class LessonPage : ViewPage
{
    public LessonPage() => InitializeComponent();

    public LessonViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter)
    {
        ViewModel = new LessonViewModel(App.Services, IntParam(parameter));
        ViewModel.PropertyChanged += OnModelChanged;
        return ViewModel;
    }

    protected override void OnNavigatedFrom(Microsoft.UI.Xaml.Navigation.NavigationEventArgs e)
    {
        // Video ovozi sahifadan chiqqach to'xtasin.
        try { VideoView.Close(); } catch (Exception) { }
        base.OnNavigatedFrom(e);
    }

    private void OnModelChanged(object? sender, PropertyChangedEventArgs e)
    {
        if (e.PropertyName != nameof(LessonViewModel.VideoUri)) return;
        try
        {
            VideoView.Source = ViewModel.VideoUri ?? new Uri("about:blank");
        }
        catch (Exception)
        {
            // WebView2 runtime yo'q — brauzerda ochamiz.
            if (ViewModel.VideoUri is { } u) App.Services.Platform.Open(u);
            ViewModel.CloseVideoCommand.Execute(null);
        }
    }

    private static MaterialItem? Item(object sender) => (sender as FrameworkElement)?.Tag as MaterialItem;

    private void Material_Click(object sender, RoutedEventArgs e) => ViewModel.OpenMaterialCommand.Execute(Item(sender));

    private void EditMaterial_Click(object sender, RoutedEventArgs e) => ViewModel.EditMaterialCommand.Execute(Item(sender));

    private void DeleteMaterial_Click(object sender, RoutedEventArgs e) => ViewModel.DeleteMaterialCommand.Execute(Item(sender));
}
