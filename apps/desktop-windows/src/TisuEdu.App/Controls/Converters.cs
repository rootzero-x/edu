using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Data;

namespace TisuEdu.App.Controls;

/// <summary>bool → Visibility (Invert=True bo'lsa teskari). x:Bind to'g'ridan-to'g'ri bool→Visibility qiladi; bu faqat teskarisi uchun.</summary>
public sealed partial class BoolToVisibility : IValueConverter
{
    public bool Invert { get; set; }

    public object Convert(object value, Type targetType, object parameter, string language)
    {
        var on = value is true;
        return on ^ Invert ? Visibility.Visible : Visibility.Collapsed;
    }

    public object ConvertBack(object value, Type targetType, object parameter, string language) =>
        value is Visibility v && (v == Visibility.Visible) ^ Invert;
}

/// <summary>null yoki bo'sh satr → Collapsed.</summary>
public sealed partial class EmptyToCollapsed : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, string language) =>
        value is null || value is string { Length: 0 } ? Visibility.Collapsed : Visibility.Visible;

    public object ConvertBack(object value, Type targetType, object parameter, string language) => throw new NotSupportedException();
}

/// <summary>x:Bind TwoWay SelectedItem (object ↔ aniq tur) uchun o'tkazgich.</summary>
public sealed partial class ItemPassThrough : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, string language) => value;

    public object ConvertBack(object value, Type targetType, object parameter, string language) => value;
}
