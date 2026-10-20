using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Controls;

public sealed partial class StatusBanner : UserControl
{
    public static readonly DependencyProperty ModelProperty = DependencyProperty.Register(
        nameof(Model), typeof(PageViewModel), typeof(StatusBanner),
        new PropertyMetadata(null, (d, _) => ((StatusBanner)d).Bindings.Update()));

    public StatusBanner() => InitializeComponent();

    public PageViewModel? Model
    {
        get => (PageViewModel?)GetValue(ModelProperty);
        set => SetValue(ModelProperty, value);
    }
}
