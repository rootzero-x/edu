using Microsoft.UI.Xaml.Media.Imaging;
using QRCoder;
using Windows.Storage.Streams;

namespace TisuEdu.App.Services;

/// <summary>QR-kod (PNG) → BitmapImage. Telegram deep-link'ini telefon kamerasi bilan ochish uchun.</summary>
internal static class QrCode
{
    public static async Task<BitmapImage?> RenderAsync(string text)
    {
        try
        {
            var png = await Task.Run(() =>
            {
                using var generator = new QRCodeGenerator();
                using var data = generator.CreateQrCode(text, QRCodeGenerator.ECCLevel.M);
                return new PngByteQRCode(data).GetGraphic(8);
            });
            using var stream = new InMemoryRandomAccessStream();
            using (var writer = new DataWriter(stream.GetOutputStreamAt(0)))
            {
                writer.WriteBytes(png);
                await writer.StoreAsync();
                await writer.FlushAsync();
                writer.DetachStream();
            }
            stream.Seek(0);
            var bitmap = new BitmapImage();
            await bitmap.SetSourceAsync(stream);
            return bitmap;
        }
        catch (Exception)
        {
            return null;
        }
    }
}
