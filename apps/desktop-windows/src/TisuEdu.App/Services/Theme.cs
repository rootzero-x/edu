using Microsoft.UI;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Media;
using Windows.UI;

namespace TisuEdu.App.Services;

internal static class Theme
{
    /// <summary>site.primary_color — ilova urg'u rangi (tugmalar, tanlov). Faqat oyna yaratilishidan oldin ta'sir qiladi.</summary>
    public static void ApplyAccent(string hex)
    {
        if (!TryParse(hex, out var c)) return;
        var res = Application.Current.Resources;
        res["SystemAccentColor"] = c;
        res["SystemAccentColorLight1"] = Mix(c, Colors.White, 0.2);
        res["SystemAccentColorLight2"] = Mix(c, Colors.White, 0.4);
        res["SystemAccentColorLight3"] = Mix(c, Colors.White, 0.6);
        res["SystemAccentColorDark1"] = Mix(c, Colors.Black, 0.2);
        res["SystemAccentColorDark2"] = Mix(c, Colors.Black, 0.4);
        res["SystemAccentColorDark3"] = Mix(c, Colors.Black, 0.6);
    }

    public static bool TryParse(string? hex, out Color color)
    {
        color = default;
        if (string.IsNullOrWhiteSpace(hex)) return false;
        var h = hex.Trim().TrimStart('#');
        if (h.Length != 6 || !uint.TryParse(h, System.Globalization.NumberStyles.HexNumber, null, out var v)) return false;
        color = ColorHelper.FromArgb(255, (byte)(v >> 16), (byte)(v >> 8), (byte)v);
        return true;
    }

    public static SolidColorBrush Brush(string hex, Color fallback) => new(TryParse(hex, out var c) ? c : fallback);

    private static Color Mix(Color a, Color b, double t) => ColorHelper.FromArgb(255,
        (byte)(a.R + (b.R - a.R) * t), (byte)(a.G + (b.G - a.G) * t), (byte)(a.B + (b.B - a.B) * t));
}
