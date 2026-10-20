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
