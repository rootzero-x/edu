using System.ComponentModel;
using Microsoft.UI.Xaml.Media.Imaging;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class NewsPage : ViewPage
{
    private int? _focusId;

    public NewsPage() => InitializeComponent();

    public NewsViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter)
    {
        _focusId = parameter as int?;
        ViewModel = new NewsViewModel(App.Services);
        ViewModel.PropertyChanged += OnModelChanged;
        return ViewModel;
    }

    private void OnModelChanged(object? sender, PropertyChangedEventArgs e)
    {
        // Bildirishnoma yoki bosh sahifadan kelgan e'lonni yuklangach tanlash.
        if (e.PropertyName == nameof(ViewModel.HasLoaded) && ViewModel.HasLoaded && _focusId is { } id)
        {
            _focusId = null;
            ViewModel.Select(id);
            if (ViewModel.Selected is not null) NewsList.ScrollIntoView(ViewModel.Selected);
        }
        else if (e.PropertyName == nameof(ViewModel.Selected))
        {
            var url = ViewModel.Selected?.ImageUrl;
            NewsImage.Source = url is not null && Uri.TryCreate(url, UriKind.Absolute, out var u) ? new BitmapImage(u) : null;
            NewsImage.Visibility = NewsImage.Source is null ? Microsoft.UI.Xaml.Visibility.Collapsed : Microsoft.UI.Xaml.Visibility.Visible;
        }
    }
}
