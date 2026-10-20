using Microsoft.UI.Xaml.Input;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class SheetPage : ViewPage
{
    public SheetPage() => InitializeComponent();

    public SheetViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new SheetViewModel(App.Services, IntParam(parameter));

    private void Save_Invoked(KeyboardAccelerator sender, KeyboardAcceleratorInvokedEventArgs args)
    {
        if (ViewModel.CanEdit && !ViewModel.IsBusy) ViewModel.SaveCommand.Execute(null);
        args.Handled = true;
    }
}
