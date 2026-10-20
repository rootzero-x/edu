namespace TisuEdu.App.Services;

/// <summary>%LOCALAPPDATA%\TisuEdu — paketli ilovada Windows uni avtomatik ravishda paket papkasiga yo'naltiradi.</summary>
internal sealed record AppPaths(string Data, string Cache)
{
    public static AppPaths Create()
    {
        var root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TisuEdu");
        var paths = new AppPaths(root, Path.Combine(root, "cache"));
        Directory.CreateDirectory(paths.Data);
        Directory.CreateDirectory(paths.Cache);
        return paths;
    }
}
