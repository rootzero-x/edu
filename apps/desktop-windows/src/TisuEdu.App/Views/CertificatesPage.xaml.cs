using Microsoft.UI.Xaml;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class CertificatesPage : ViewPage
{
    public CertificatesPage() => InitializeComponent();

    public CertificatesViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter) => ViewModel = new CertificatesViewModel(App.Services);

    private void Open_Click(object sender, RoutedEventArgs e) => ViewModel.OpenCommand.Execute((sender as FrameworkElement)?.Tag as CertificateItem);

    private void Copy_Click(object sender, RoutedEventArgs e) => ViewModel.CopyCommand.Execute((sender as FrameworkElement)?.Tag as CertificateItem);
}
