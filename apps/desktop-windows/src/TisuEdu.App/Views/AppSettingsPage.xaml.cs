using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class AppSettingsPage : Page
{
    public AppSettingsPage()
    {
        ViewModel = new AppSettingsViewModel(App.Services, theme => App.Current?.Window?.ApplyTheme(theme), App.Version);
        InitializeComponent();
    }

    public AppSettingsViewModel ViewModel { get; }
}
