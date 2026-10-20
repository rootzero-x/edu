using System.Security.Cryptography;
using System.Text;
using TisuEdu.Core.Api;

namespace TisuEdu.Core.Services;

/// <summary>GET javoblarining disk keshi (oflayn rejim). Har foydalanuvchi uchun alohida papka.</summary>
public sealed class FileResponseCache(string root) : IResponseCache
{
    public async Task<(string Json, DateTimeOffset SavedAt)?> ReadAsync(string scope, string key, CancellationToken ct = default)
    {
        var file = PathFor(scope, key);
        try
        {
            if (!File.Exists(file)) return null;
            var json = await File.ReadAllTextAsync(file, ct).ConfigureAwait(false);
            return (json, new DateTimeOffset(File.GetLastWriteTimeUtc(file), TimeSpan.Zero));
        }
        catch (IOException)
        {
            return null;
        }
    }

    public async Task WriteAsync(string scope, string key, string json, CancellationToken ct = default)
    {
        var file = PathFor(scope, key);
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(file)!);
            // Atomar yozish: avval vaqtinchalik faylga, keyin almashtirish.
            var tmp = file + "." + Guid.NewGuid().ToString("N") + ".tmp";
            await File.WriteAllTextAsync(tmp, json, ct).ConfigureAwait(false);
            File.Move(tmp, file, overwrite: true);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
    }

    public Task ClearAsync(string? scope = null)
    {
        try
        {
            var dir = scope is null ? root : Path.Combine(root, Safe(scope));
            if (Directory.Exists(dir)) Directory.Delete(dir, recursive: true);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
        return Task.CompletedTask;
    }

    private string PathFor(string scope, string key)
    {
        var hash = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(key)))[..32].ToLowerInvariant();
        return Path.Combine(root, Safe(scope), hash + ".json");
    }

    private static string Safe(string s) => string.Concat(s.Select(c => char.IsLetterOrDigit(c) ? c : '_'));
}
