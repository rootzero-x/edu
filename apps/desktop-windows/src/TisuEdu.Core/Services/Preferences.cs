using TisuEdu.Core.Api;

namespace TisuEdu.Core.Services;

public enum AppTheme
{
    System,
    Light,
    Dark,
}

/// <summary>Ilova sozlamalari (mavzu, bildirishnomalar). Paketli va paketsiz rejimda bir xil ishlashi uchun JSON faylda.</summary>
public sealed class Preferences
{
    private readonly string _file;

    public Preferences(string file)
    {
        _file = file;
        try
        {
            if (File.Exists(file) && Json.Deserialize<Data>(File.ReadAllText(file)) is { } d) _data = d;
        }
        catch (Exception) { /* standart qiymatlar */ }
    }

    private Data _data = new();

    public AppTheme Theme
    {
        get => _data.Theme;
        set { _data.Theme = value; Save(); }
    }

    public bool Notifications
    {
        get => _data.Notifications;
        set { _data.Notifications = value; Save(); }
    }

    public string? LastPhone
    {
        get => _data.LastPhone;
        set { _data.LastPhone = value; Save(); }
    }

    private void Save()
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_file)!);
            File.WriteAllText(_file, Json.Serialize(_data));
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
    }

    private sealed class Data
    {
        public AppTheme Theme { get; set; } = AppTheme.System;
        public bool Notifications { get; set; } = true;
        public string? LastPhone { get; set; }
    }
}
