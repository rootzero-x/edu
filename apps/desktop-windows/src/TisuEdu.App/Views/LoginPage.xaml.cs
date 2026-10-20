using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Input;
using Microsoft.UI.Xaml.Navigation;
using TisuEdu.Core;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class LoginPage : Page
{
    public LoginPage()
    {
        var s = App.Services;
        ViewModel = new LoginViewModel(s.Session, s.Settings, s.Platform, s.Preferences);
        InitializeComponent();
    }

    public LoginViewModel ViewModel { get; }

    protected override void OnNavigatedTo(NavigationEventArgs e)
    {
        base.OnNavigatedTo(e);
        if (e.Parameter is true)
        {
            ExpiredBar.Message = Strings.SessionExpired;
            ExpiredBar.IsOpen = true;
        }
        PhoneBox.Focus(Microsoft.UI.Xaml.FocusState.Programmatic);
    }

    protected override void OnNavigatedFrom(NavigationEventArgs e)
    {
        ViewModel.CancelTelegram();
        base.OnNavigatedFrom(e);
    }

    public void OnSettingsChanged() => Bindings.Update();

    private void Password_KeyDown(object sender, KeyRoutedEventArgs e)
    {
        if (e.Key == Windows.System.VirtualKey.Enter && ViewModel.LoginCommand.CanExecute(null))
        {
            ViewModel.LoginCommand.Execute(null);
            e.Handled = true;
        }
    }
}
