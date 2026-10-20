using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Navigation;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

/// <summary>
/// Barcha ichki sahifalar uchun asos: ViewModel'ni navigatsiya parametri bilan yaratadi, yuklashni boshlaydi
/// (async — UI bloklanmaydi) va sahifadan chiqilganda so'rovlarni bekor qiladi.
/// </summary>
public partial class ViewPage : Page
{
    public PageViewModel? Model { get; private set; }

    // XAML asos turi abstrakt bo'lmasligi kerak — har bir sahifa buni qayta yozadi.
    protected virtual PageViewModel CreateModel(object? parameter) => throw new NotSupportedException();

    protected override void OnNavigatedTo(NavigationEventArgs e)
    {
        base.OnNavigatedTo(e);
        Model = CreateModel(e.Parameter);
        _ = Model.RefreshAsync();
    }

    protected override void OnNavigatedFrom(NavigationEventArgs e)
    {
        Model?.Leave();
        base.OnNavigatedFrom(e);
    }

    /// <summary>F5 / Ctrl+R</summary>
    public void Reload()
    {
        if (Model is not null) _ = Model.RefreshAsync();
    }

    protected static int IntParam(object? p) => p is int i ? i : 0;
}
