using System.Globalization;
using Microsoft.Windows.AppNotifications;
using Microsoft.Windows.AppNotifications.Builder;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.App.Services;

/// <summary>Yangi e'lonlar uchun Windows bildirishnomalari (Windows App SDK AppNotifications).</summary>
internal sealed class ToastNotifier : INotifier
{
    private bool _registered;

    /// <summary>Bildirishnoma bosildi — e'lon id'si.</summary>
    public event EventHandler<int>? Invoked;

    public void Register()
    {
        try
        {
            var manager = AppNotificationManager.Default;
            manager.NotificationInvoked += (_, e) =>
            {
                if (TryGetAnnouncementId(e.Arguments, out var id)) Invoked?.Invoke(this, id);
            };
            manager.Register();
            _registered = true;
        }
        catch (Exception)
        {
            // Bildirishnomalar tizimda o'chirilgan yoki qo'llab-quvvatlanmaydi — ilova bildirishnomasiz ishlaydi.
            _registered = false;
        }
    }

    public void ShowAnnouncement(Announcement a, string siteName)
    {
        if (!_registered) return;
        try
        {
            var body = a.Body.Length > 180 ? a.Body[..180].TrimEnd() + "…" : a.Body;
            var n = new AppNotificationBuilder()
                .AddArgument("action", "news")
                .AddArgument("id", a.Id.ToString(CultureInfo.InvariantCulture))
                .AddText(a.Title)
                .AddText(body)
                .SetAttributionText(siteName)
                .BuildNotification();
            n.Tag = "news-" + a.Id.ToString(CultureInfo.InvariantCulture);
            n.Group = "news";
            AppNotificationManager.Default.Show(n);
        }
        catch (Exception) { }
    }

    public static bool TryGetAnnouncementId(IDictionary<string, string> args, out int id)
    {
        id = 0;
        return args.TryGetValue("action", out var action) && action == "news"
            && args.TryGetValue("id", out var raw) && int.TryParse(raw, NumberStyles.Integer, CultureInfo.InvariantCulture, out id);
    }
}
