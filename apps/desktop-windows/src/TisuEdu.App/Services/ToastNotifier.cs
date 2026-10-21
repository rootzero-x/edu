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

    /// <summary>Bildirishnomalar markazidagi element bosildi — web manzil (ilova sahifasiga moslanadi).</summary>
    public event EventHandler<string>? UrlInvoked;

    public void Register()
    {
        try
        {
            var manager = AppNotificationManager.Default;
            manager.NotificationInvoked += (_, e) =>
            {
                if (TryGetAnnouncementId(e.Arguments, out var id)) Invoked?.Invoke(this, id);
                else if (TryGetUrl(e.Arguments, out var url)) UrlInvoked?.Invoke(this, url);
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

    public void ShowNotification(NotificationItem item, string siteName)
    {
        if (!_registered) return;
        try
        {
            var text = System.Net.WebUtility.HtmlDecode(System.Text.RegularExpressions.Regex.Replace(item.Text, "<[^>]+>", "")).Trim();
            if (text.Length > 200) text = text[..200].TrimEnd() + "…";
            var builder = new AppNotificationBuilder()
                .AddArgument("action", "open")
                .AddArgument("url", item.Url ?? "/dashboard/notifications")
                .AddText(item.Label)
                .AddText(text)
                .SetAttributionText(siteName);
            var n = builder.BuildNotification();
            n.Tag = "n-" + item.Id.ToString(CultureInfo.InvariantCulture);
            n.Group = item.Type;
            AppNotificationManager.Default.Show(n);
        }
        catch (Exception) { }
    }

    public static bool TryGetUrl(IDictionary<string, string> args, out string url)
    {
        url = "";
        return args.TryGetValue("action", out var action) && action == "open" && args.TryGetValue("url", out url!) && !string.IsNullOrEmpty(url);
    }

    public static bool TryGetAnnouncementId(IDictionary<string, string> args, out int id)
    {
        id = 0;
        return args.TryGetValue("action", out var action) && action == "news"
            && args.TryGetValue("id", out var raw) && int.TryParse(raw, NumberStyles.Integer, CultureInfo.InvariantCulture, out id);
    }
}
