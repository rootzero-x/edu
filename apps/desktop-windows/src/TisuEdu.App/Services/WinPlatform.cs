using Microsoft.UI.Xaml.Controls;
using TisuEdu.Core.Services;
using Windows.ApplicationModel.DataTransfer;
using Windows.Storage;
using Windows.Storage.Pickers;

namespace TisuEdu.App.Services;

/// <summary>Windows xizmatlari: havola ochish, fayl saqlash oynasi, tasdiqlash dialogi, bufer.</summary>
internal sealed class WinPlatform(MainWindow window) : IPlatform
{
    public void Open(Uri uri) => _ = Windows.System.Launcher.LaunchUriAsync(uri);

    public async Task<bool> SaveFileAsync(string suggestedName, string extensionLabel, string extension, byte[] content)
    {
        var picker = new FileSavePicker
        {
            SuggestedStartLocation = PickerLocationId.DocumentsLibrary,
            SuggestedFileName = Path.GetFileNameWithoutExtension(suggestedName),
        };
        picker.FileTypeChoices.Add(extensionLabel, new List<string> { extension });
        // WinUI 3 desktop: picker oyna dastagiga bog'lanishi shart.
        WinRT.Interop.InitializeWithWindow.Initialize(picker, WinRT.Interop.WindowNative.GetWindowHandle(window));
        var file = await picker.PickSaveFileAsync();
        if (file is null) return false;
        await FileIO.WriteBytesAsync(file, content);
        return true;
    }

    public async Task<bool> ConfirmAsync(string title, string message, string primary, string close)
    {
        var dialog = new ContentDialog
        {
            Title = title,
            Content = new TextBlock { Text = message, TextWrapping = Microsoft.UI.Xaml.TextWrapping.Wrap },
            PrimaryButtonText = primary,
            CloseButtonText = close,
            DefaultButton = ContentDialogButton.Primary,
            XamlRoot = window.Content.XamlRoot,
            RequestedTheme = window.CurrentTheme,
        };
        return await dialog.ShowAsync() == ContentDialogResult.Primary;
    }

    public async Task<PickedFile?> PickFileAsync(IReadOnlyList<string> extensions)
    {
        var picker = new FileOpenPicker { SuggestedStartLocation = PickerLocationId.DocumentsLibrary, ViewMode = PickerViewMode.List };
        foreach (var e in extensions) picker.FileTypeFilter.Add(e);
        if (picker.FileTypeFilter.Count == 0) picker.FileTypeFilter.Add("*");
        WinRT.Interop.InitializeWithWindow.Initialize(picker, WinRT.Interop.WindowNative.GetWindowHandle(window));
        var file = await picker.PickSingleFileAsync();
        if (file is null) return null;
        var buffer = await FileIO.ReadBufferAsync(file);
        var bytes = new byte[buffer.Length];
        using (var reader = Windows.Storage.Streams.DataReader.FromBuffer(buffer)) reader.ReadBytes(bytes);
        return new PickedFile(file.Name, bytes, string.IsNullOrEmpty(file.ContentType) ? TisuEdu.Core.Services.Uploads.Mime(file.Name) : file.ContentType);
    }

    public void CopyText(string text)
    {
        var package = new DataPackage();
        package.SetText(text);
        Clipboard.SetContent(package);
    }
}
