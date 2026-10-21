using System.Globalization;

namespace TisuEdu.Core.Services;

public enum FieldKind
{
    Text,
    Multiline,
    Password,
    Number,
    Toggle,
    Choice,
    Date,
    Time,
}

/// <summary>Tahrirlash formasi maydoni. Qiymatlar satr ko'rinishida (Toggle — "1"/"0", Date — "yyyy-MM-dd").</summary>
public sealed class FormField
{
    public FormField(string key, string label, FieldKind kind = FieldKind.Text, string? value = null)
    {
        Key = key;
        Label = label;
        Kind = kind;
        Value = value ?? "";
    }

    public string Key { get; }
    public string Label { get; }
    public FieldKind Kind { get; }
    public string Value { get; set; }
    public string? Placeholder { get; init; }
    public string? Hint { get; init; }
    public bool Required { get; init; }
    public int? MaxLength { get; init; }

    /// <summary>Choice uchun: (qiymat, ko'rinadigan nom).</summary>
    public IReadOnlyList<KeyValuePair<string, string>> Options { get; init; } = [];

    public static FormField Toggle(string key, string label, bool value) => new(key, label, FieldKind.Toggle, value ? "1" : "0");

    public static FormField Choice(string key, string label, IEnumerable<KeyValuePair<string, string>> options, string? value) =>
        new(key, label, FieldKind.Choice, value) { Options = options.ToList() };
}

/// <summary>Forma natijasi — tiplangan o'qish.</summary>
public sealed class FormValues(IReadOnlyDictionary<string, string> values)
{
    public IReadOnlyDictionary<string, string> Raw => values;

    public string Str(string key) => values.TryGetValue(key, out var v) ? v.Trim() : "";

    public string? StrOrNull(string key) => Str(key) is { Length: > 0 } s ? s : null;

    public int? Int(string key) =>
        int.TryParse(Str(key).Replace(" ", ""), NumberStyles.Integer, CultureInfo.InvariantCulture, out var n) ? n : null;

    public bool Bool(string key) => Str(key) == "1";

    /// <summary>
    /// Pul summasi (so'm, butun): "150000", "150 000", "150000.00", "150 000,00" → 150000; "150000.50" → xato.
    /// Web'dagi parseAmount (EntryDialog.tsx) bilan bir xil. allowSign — tuzatish uchun "-"/"+".
    /// </summary>
    public (int? Value, string? Error) Amount(string key, bool allowSign = false) => ParseAmount(Str(key), allowSign);

    public static (int? Value, string? Error) ParseAmount(string input, bool allowSign = false)
    {
        var s = input.Trim();
        var sign = 1;
        if (allowSign && s.Length > 0 && (s[0] == '-' || s[0] == '+' || s[0] == '−'))
        {
            sign = s[0] == '+' ? 1 : -1;
            s = s[1..].TrimStart();
        }
        var m = System.Text.RegularExpressions.Regex.Match(s, @"[.,](\d{1,2})$");
        if (m.Success)
        {
            if (m.Groups[1].Value.Any(c => c is >= '1' and <= '9')) return (null, Strings.AmountWhole);
            s = s[..^m.Length];
        }
        s = System.Text.RegularExpressions.Regex.Replace(s, @"[\s,.'’\u00A0\u202F]", "");
        if (s.Length == 0) return (null, "Summani kiriting.");
        if (!s.All(char.IsAsciiDigit)) return (null, Strings.AmountWhole);
        return long.TryParse(s, NumberStyles.None, CultureInfo.InvariantCulture, out var n) && n <= int.MaxValue
            ? ((int)(n * sign), null)
            : (null, "Summa juda katta.");
    }

    /// <summary>Qatorlar (bo'sh qatorlarsiz).</summary>
    public List<string> Lines(string key) =>
        Str(key).Split('\n').Select(l => l.Trim()).Where(l => l.Length > 0).ToList();
}

/// <summary>
/// Forma ko'rsatish (WinUI'da ContentDialog). <paramref name="submit"/> xato matni qaytarsa dialog yopilmaydi va
/// xato ko'rsatiladi (web'dagi usePrompt kabi); null qaytarsa — muvaffaqiyat.
/// </summary>
public interface IForms
{
    Task<bool> ShowAsync(string title, IReadOnlyList<FormField> fields, Func<FormValues, Task<string?>> submit, string primaryText = "Saqlash");
}

public static class FormsExtensions
{
    /// <summary>Bitta matn so'rash (masalan, bekor qilish sababi).</summary>
    public static async Task<string?> PromptAsync(this IForms forms, string title, string label, bool required = true, string? hint = null, string primary = "Tasdiqlash")
    {
        string? result = null;
        var ok = await forms.ShowAsync(title, [new FormField("v", label, FieldKind.Multiline) { Required = required, Hint = hint }], v =>
        {
            result = v.Str("v");
            return Task.FromResult<string?>(required && result.Length == 0 ? $"«{label}» to'ldirilishi kerak." : null);
        }, primary);
        return ok ? result : null;
    }
}

/// <summary>Ro'yxatdagi tanlov (ComboBox elementlari ToString() orqali ko'rsatiladi).</summary>
public sealed record Option(string Key, string Label)
{
    public override string ToString() => Label;
}
