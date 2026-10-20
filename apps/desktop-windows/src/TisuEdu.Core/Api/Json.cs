using System.Globalization;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace TisuEdu.Core.Api;

/// <summary>
/// API (Laravel) JSON formatiga moslashgan sozlamalar: snake_case, raqamlar satr ko'rinishida kelishi,
/// MySQL'dan 0/1 ko'rinishidagi boolean va vaqt mintaqasisiz sanalar ("Y-m-d H:i:s").
/// </summary>
public static class Json
{
    public static readonly JsonSerializerOptions Options = Create();

    private static JsonSerializerOptions Create()
    {
        var o = new JsonSerializerOptions
        {
            PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
            PropertyNameCaseInsensitive = true,
            NumberHandling = JsonNumberHandling.AllowReadingFromString,
            DefaultIgnoreCondition = JsonIgnoreCondition.Never,
            ReadCommentHandling = JsonCommentHandling.Skip,
        };
        o.Converters.Add(new LenientBoolConverter());
        o.Converters.Add(new LenientDateTimeOffsetConverter());
        return o;
    }

    public static T? Deserialize<T>(string json) => JsonSerializer.Deserialize<T>(json, Options);

    public static string Serialize<T>(T value) => JsonSerializer.Serialize(value, Options);
}

/// <summary>Sana vaqt mintaqasisiz kelsa, server mintaqasi (app.timezone) deb hisoblanadi.</summary>
public static class ServerClock
{
    private static TimeZoneInfo _zone = TimeZoneInfo.Local;

    public static TimeZoneInfo Zone => _zone;

    /// <summary>/v1/public/settings dagi "app.timezone" (IANA) bo'yicha o'rnatadi; topilmasa — kompyuter mintaqasi.</summary>
    public static void SetZone(string? ianaId)
    {
        if (string.IsNullOrWhiteSpace(ianaId)) return;
        try { _zone = TimeZoneInfo.FindSystemTimeZoneById(ianaId); }
        catch (Exception) { /* noma'lum mintaqa — mahalliy vaqt qoladi */ }
    }

    /// <summary>Server mintaqasidagi vaqt.</summary>
    public static DateTimeOffset ToServer(DateTimeOffset value) => TimeZoneInfo.ConvertTime(value, _zone);

    public static DateTimeOffset Now => ToServer(DateTimeOffset.UtcNow);
}

internal sealed class LenientBoolConverter : JsonConverter<bool>
{
    public override bool Read(ref Utf8JsonReader reader, Type typeToConvert, JsonSerializerOptions options) =>
        reader.TokenType switch
        {
            JsonTokenType.True => true,
            JsonTokenType.False => false,
            JsonTokenType.Number => reader.TryGetInt64(out var n) ? n != 0 : reader.GetDouble() != 0,
            JsonTokenType.String => reader.GetString() is { } s && (s == "1" || s.Equals("true", StringComparison.OrdinalIgnoreCase)),
            JsonTokenType.Null => false,
            _ => throw new JsonException($"Boolean kutilgan edi: {reader.TokenType}"),
        };

    public override void Write(Utf8JsonWriter writer, bool value, JsonSerializerOptions options) => writer.WriteBooleanValue(value);
}

internal sealed class LenientDateTimeOffsetConverter : JsonConverter<DateTimeOffset>
{
    private static readonly string[] LocalFormats = ["yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd HH:mm", "yyyy-MM-dd'T'HH:mm:ss", "yyyy-MM-dd"];

    public override DateTimeOffset Read(ref Utf8JsonReader reader, Type typeToConvert, JsonSerializerOptions options)
    {
        var s = reader.GetString();
        if (string.IsNullOrEmpty(s)) return default;
        return Parse(s);
    }

    internal static DateTimeOffset Parse(string s)
    {
        if (DateTime.TryParseExact(s, LocalFormats, CultureInfo.InvariantCulture, DateTimeStyles.None, out var local))
        {
            var offset = ServerClock.Zone.GetUtcOffset(local);
            return new DateTimeOffset(local, offset);
        }
        return DateTimeOffset.Parse(s, CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal);
    }

    public override void Write(Utf8JsonWriter writer, DateTimeOffset value, JsonSerializerOptions options) =>
        writer.WriteStringValue(value.ToString("O", CultureInfo.InvariantCulture));
}
