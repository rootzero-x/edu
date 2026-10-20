using System.Security.Cryptography;
using System.Text;
using TisuEdu.Core.Services;
using Windows.Security.Credentials;

namespace TisuEdu.App.Services;

/// <summary>
/// Token Windows Credential Locker'da (PasswordVault) saqlanadi. Vault ishlamasa (masalan, cheklangan muhit) —
/// DPAPI (joriy foydalanuvchi kaliti) bilan shifrlangan fayl. Ochiq matnda hech qachon yozilmaydi.
/// </summary>
internal sealed class SecureTokenStore(string dataDir) : ITokenStore
{
    private const string Resource = "TISU Edu";
    private const string UserName = "api-token";
    private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("TisuEdu.Desktop.Token.v1");
    private readonly string _file = Path.Combine(dataDir, "token.bin");

    public string? Load()
    {
        try
        {
            var c = new PasswordVault().Retrieve(Resource, UserName);
            c.RetrievePassword();
            if (!string.IsNullOrEmpty(c.Password)) return c.Password;
        }
        catch (Exception) { /* yo'q yoki vault mavjud emas */ }

        try
        {
            if (!File.Exists(_file)) return null;
            var bytes = ProtectedData.Unprotect(File.ReadAllBytes(_file), Entropy, DataProtectionScope.CurrentUser);
            return Encoding.UTF8.GetString(bytes);
        }
        catch (Exception) { return null; }
    }

    public void Save(string token)
    {
        Clear();
        try
        {
            new PasswordVault().Add(new PasswordCredential(Resource, UserName, token));
            return;
        }
        catch (Exception) { /* DPAPI zaxirasi */ }

        try
        {
            File.WriteAllBytes(_file, ProtectedData.Protect(Encoding.UTF8.GetBytes(token), Entropy, DataProtectionScope.CurrentUser));
        }
        catch (Exception) { }
    }

    public void Clear()
    {
        try
        {
            var vault = new PasswordVault();
            foreach (var c in vault.FindAllByResource(Resource)) vault.Remove(c);
        }
        catch (Exception) { }
        try { File.Delete(_file); } catch (Exception) { }
    }
}
