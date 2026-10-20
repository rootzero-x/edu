using System.Globalization;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Input;
using TisuEdu.Core.Services;

namespace TisuEdu.App.Services;

/// <summary>
/// Tahrirlash formalari — ContentDialog kod orqali quriladi. "Saqlash" bosilganda submit chaqiriladi;
/// server xato qaytarsa (masalan 422) dialog yopilmaydi va xato ko'rsatiladi.
/// </summary>
internal sealed class FormDialogService(MainWindow window) : IForms
{
    public async Task<bool> ShowAsync(string title, IReadOnlyList<FormField> fields, Func<FormValues, Task<string?>> submit, string primaryText = "Saqlash")
    {
        var read = new Dictionary<string, Func<string>>();
        var panel = new StackPanel { Spacing = 12, MinWidth = 440 };
        var error = new InfoBar { Severity = InfoBarSeverity.Error, IsClosable = false, IsOpen = false };
        Control? first = null;

        foreach (var f in fields)
        {
            Control editor;
            switch (f.Kind)
            {
                case FieldKind.Toggle:
                    var toggle = new ToggleSwitch { Header = f.Label, IsOn = f.Value == "1", OnContent = "Ha", OffContent = "Yo'q" };
                    read[f.Key] = () => toggle.IsOn ? "1" : "0";
                    editor = toggle;
                    break;

                case FieldKind.Choice:
                    var combo = new ComboBox { Header = f.Label, HorizontalAlignment = HorizontalAlignment.Stretch };
                    foreach (var o in f.Options) combo.Items.Add(o.Value);
                    var index = f.Options.ToList().FindIndex(o => o.Key == f.Value);
                    combo.SelectedIndex = index >= 0 ? index : f.Options.Count > 0 ? 0 : -1;
                    read[f.Key] = () => combo.SelectedIndex >= 0 && combo.SelectedIndex < f.Options.Count ? f.Options[combo.SelectedIndex].Key : "";
                    editor = combo;
                    break;

                case FieldKind.Date:
                    var date = new CalendarDatePicker { Header = f.Label, HorizontalAlignment = HorizontalAlignment.Stretch, DateFormat = "{day.integer(2)}.{month.integer(2)}.{year.full}" };
                    if (DateTime.TryParseExact(f.Value, "yyyy-MM-dd", CultureInfo.InvariantCulture, DateTimeStyles.None, out var d))
                        date.Date = new DateTimeOffset(d);
                    read[f.Key] = () => date.Date?.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture) ?? "";
                    editor = date;
                    break;

                case FieldKind.Password:
                    var pass = new PasswordBox { Header = f.Label, Password = f.Value };
                    read[f.Key] = () => pass.Password;
                    editor = pass;
                    break;

                default:
                    var box = new TextBox
                    {
                        Header = f.Label,
                        Text = f.Value,
                        PlaceholderText = f.Placeholder ?? "",
                    };
                    if (f.MaxLength is { } max) box.MaxLength = max;
                    if (f.Kind == FieldKind.Multiline)
                    {
                        box.AcceptsReturn = true;
                        box.TextWrapping = TextWrapping.Wrap;
                        box.MinHeight = 96;
                        box.MaxHeight = 240;
                        ScrollViewer.SetVerticalScrollBarVisibility(box, ScrollBarVisibility.Auto);
                    }
                    else if (f.Kind == FieldKind.Number)
                    {
                        box.InputScope = new InputScope { Names = { new InputScopeName(InputScopeNameValue.Number) } };
                    }
                    read[f.Key] = () => box.Text;
                    editor = box;
                    break;
            }

            first ??= editor;
            panel.Children.Add(editor);
            if (!string.IsNullOrEmpty(f.Hint))
            {
                panel.Children.Add(new TextBlock
                {
                    Text = f.Hint,
                    TextWrapping = TextWrapping.Wrap,
                    Margin = new Thickness(0, -6, 0, 0),
                    Style = (Style)Application.Current.Resources["CaptionTextBlockStyle"],
                    Opacity = 0.75,
                });
            }
        }
        panel.Children.Add(error);

        var dialog = new ContentDialog
        {
            Title = title,
            Content = new ScrollViewer { Content = panel, MaxHeight = 560, VerticalScrollBarVisibility = ScrollBarVisibility.Auto, Padding = new Thickness(0, 0, 12, 0) },
            PrimaryButtonText = primaryText,
            CloseButtonText = "Bekor qilish",
            DefaultButton = ContentDialogButton.Primary,
            XamlRoot = window.Content.XamlRoot,
            RequestedTheme = window.CurrentTheme,
        };

        var ok = false;
        dialog.PrimaryButtonClick += async (_, e) =>
        {
            var deferral = e.GetDeferral();
            try
            {
                var values = read.ToDictionary(kv => kv.Key, kv => kv.Value());
                var missing = fields.FirstOrDefault(f => f.Required && string.IsNullOrWhiteSpace(values[f.Key]));
                string? problem = missing is not null ? $"«{missing.Label}» to'ldirilishi kerak." : null;
                if (problem is null)
                {
                    dialog.IsPrimaryButtonEnabled = false;
                    problem = await submit(new FormValues(values));
                }
                if (problem is not null)
                {
                    e.Cancel = true;
                    error.Message = problem;
                    error.IsOpen = true;
                }
                else
                {
                    ok = true;
                }
            }
            catch (Exception ex)
            {
                e.Cancel = true;
                error.Message = ex.Message;
                error.IsOpen = true;
            }
            finally
            {
                dialog.IsPrimaryButtonEnabled = true;
                deferral.Complete();
            }
        };
        dialog.Opened += (_, _) => first?.Focus(FocusState.Programmatic);

        await dialog.ShowAsync();
        return ok;
    }
}
