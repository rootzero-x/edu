using System.Collections.Specialized;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.ViewModels;

namespace TisuEdu.App.Views;

public sealed partial class AdminSettingsPage : ViewPage
{
    public AdminSettingsPage() => InitializeComponent();

    public AdminSettingsViewModel ViewModel { get; private set; } = null!;

    protected override PageViewModel CreateModel(object? parameter)
    {
        ViewModel = new AdminSettingsViewModel(App.Services);
        // Bo'limlar ro'yxati birinchi yuklashda to'ladi.
        ViewModel.Groups.CollectionChanged += (_, _) =>
        {
            var index = ViewModel.GroupIndex;
            GroupBox.ItemsSource = ViewModel.Groups.Select(g => g.Value).ToList();
            GroupBox.SelectedIndex = Math.Max(0, index);
        };
        return ViewModel;
    }

    private void Setting_ItemClick(object sender, ItemClickEventArgs e) => ViewModel.EditCommand.Execute(e.ClickedItem as SettingRow);

    private void Reset_Click(object sender, RoutedEventArgs e) => ViewModel.ResetCommand.Execute((sender as FrameworkElement)?.Tag as SettingRow);
}
