using System.Globalization;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;

namespace TisuEdu.Core.Services;

/// <summary>Joriy foydalanuvchi, token va ruxsatlar. 401 kelsa avtomatik chiqadi.</summary>
public sealed class Session
{
    private readonly ITokenStore _tokens;
    private readonly IResponseCache _cache;
    private readonly string _userCacheFile;
    private int _signingOut;

    public Session(TisuApi api, ITokenStore tokens, IResponseCache cache, string userCacheFile)
    {
        Api = api;
        _tokens = tokens;
        _cache = cache;
        _userCacheFile = userCacheFile;
        api.Http.Unauthorized += (_, _) => _ = SignOutAsync(expired: true, callServer: false);
    }

    public TisuApi Api { get; }

    public User? User { get; private set; }

    public bool IsSignedIn => User is not null;

    public event EventHandler<User>? SignedIn;

    /// <summary>Argument: true — sessiya muddati tugagani uchun (401).</summary>
    public event EventHandler<bool>? SignedOut;

    public event EventHandler<User>? UserChanged;

    public bool Can(string permission) => User?.Can(permission) == true;

    /// <summary>
    /// Saqlangan token bilan kirish. Server yetib bo'lmasa, oxirgi ma'lum foydalanuvchi bilan oflayn ochiladi.
    /// </summary>
    public async Task<bool> TryRestoreAsync(CancellationToken ct = default)
    {
        var token = _tokens.Load();
        if (string.IsNullOrEmpty(token)) return false;
        Api.Http.Token = token;
        try
        {
            var me = await Api.MeAsync(ct).ConfigureAwait(false);
            Activate(me);
            return true;
        }
        catch (ApiException ex) when (ex.IsOffline)
        {
            var cached = await LoadCachedUserAsync().ConfigureAwait(false);
            if (cached is null) return false;
            Activate(cached);
            return true;
        }
        catch (ApiException)
        {
            _tokens.Clear();
            Api.Http.Token = null;
            return false;
        }
    }

    public void Complete(string token, User user)
    {
        _tokens.Save(token);
        Api.Http.Token = token;
        Activate(user);
    }

    /// <summary>Profil o'zgarganda (ism) yangilash.</summary>
    public void Update(User user)
    {
        User = user;
        _ = SaveCachedUserAsync(user);
        UserChanged?.Invoke(this, user);
    }

    public async Task RefreshUserAsync(CancellationToken ct = default)
    {
        var me = await Api.MeAsync(ct).ConfigureAwait(false);
        Update(me);
    }

    public async Task SignOutAsync(bool expired = false, bool callServer = true)
    {
        if (User is null || Interlocked.Exchange(ref _signingOut, 1) == 1) return;
        try
        {
            if (callServer)
            {
                try { await Api.LogoutAsync().ConfigureAwait(false); }
                catch (ApiException) { /* token baribir o'chiriladi */ }
            }
            _tokens.Clear();
            Api.Http.Token = null;
            await _cache.ClearAsync(Api.Http.CacheScope).ConfigureAwait(false);
            try { File.Delete(_userCacheFile); } catch (IOException) { }
            Api.Http.CacheScope = "anon";
            User = null;
            SignedOut?.Invoke(this, expired);
        }
        finally
        {
            Interlocked.Exchange(ref _signingOut, 0);
        }
    }

    private void Activate(User user)
    {
        User = user;
        Api.Http.CacheScope = "u" + user.Id.ToString(CultureInfo.InvariantCulture);
        _ = SaveCachedUserAsync(user);
        SignedIn?.Invoke(this, user);
    }

    private async Task SaveCachedUserAsync(User user)
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_userCacheFile)!);
            await File.WriteAllTextAsync(_userCacheFile, Json.Serialize(user)).ConfigureAwait(false);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
    }

    private async Task<User?> LoadCachedUserAsync()
    {
        try
        {
            return File.Exists(_userCacheFile) ? Json.Deserialize<User>(await File.ReadAllTextAsync(_userCacheFile).ConfigureAwait(false)) : null;
        }
        catch (Exception) { return null; }
    }
}
