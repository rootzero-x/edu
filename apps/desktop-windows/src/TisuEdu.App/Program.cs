using System.Runtime.InteropServices;
using Microsoft.UI.Dispatching;
using Microsoft.UI.Xaml;
using Microsoft.Windows.AppLifecycle;

namespace TisuEdu.App;

/// <summary>
/// O'z Main'imiz: ilova bitta nusxada ishlaydi — ikkinchi marta ochilsa (yoki bildirishnoma bosilsa)
/// faollashtirish birinchi nusxaga yo'naltiriladi va uning oynasi oldinga chiqadi.
/// </summary>
public static class Program
{
    private const string InstanceKey = "TisuEdu.Main";

    [STAThread]
    private static int Main(string[] args)
    {
        WinRT.ComWrappersSupport.InitializeComWrappers();
        if (RedirectToMainInstance())
        {
            return 0;
        }

        Application.Start(p =>
        {
            var context = new DispatcherQueueSynchronizationContext(DispatcherQueue.GetForCurrentThread());
            SynchronizationContext.SetSynchronizationContext(context);
            _ = new App();
        });
        return 0;
    }

    private static bool RedirectToMainInstance()
    {
        AppActivationArguments args;
        AppInstance main;
        try
        {
            args = AppInstance.GetCurrent().GetActivatedEventArgs();
            main = AppInstance.FindOrRegisterForKey(InstanceKey);
        }
        catch (COMException)
        {
            return false;
        }

        if (main.IsCurrent)
        {
            main.Activated += (_, e) => App.Current?.OnRedirectedActivation(e);
            return false;
        }

        // Yo'naltirish STA oqimini bloklamasligi uchun alohida oqimda; asosiy oqim COM xabarlarini kutadi.
        var done = CreateEvent(IntPtr.Zero, true, false, null);
        Task.Run(() =>
        {
            try { main.RedirectActivationToAsync(args).AsTask().Wait(); }
            catch (Exception) { /* birinchi nusxa yopilayotgan bo'lishi mumkin */ }
            SetEvent(done);
        });
        _ = CoWaitForMultipleObjects(0, 0xFFFFFFFF, 1, [done], out _);
        return true;
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr CreateEvent(IntPtr lpEventAttributes, bool bManualReset, bool bInitialState, string? lpName);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool SetEvent(IntPtr hEvent);

    [DllImport("ole32.dll")]
    private static extern uint CoWaitForMultipleObjects(uint dwFlags, uint dwMilliseconds, ulong nHandles, IntPtr[] pHandles, out uint dwIndex);
}
