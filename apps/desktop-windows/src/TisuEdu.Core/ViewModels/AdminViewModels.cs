using System.Collections.ObjectModel;
using System.Globalization;
using System.Text.Json;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

// ── Foydalanuvchilar (user.manage) ──────────────────────────

public sealed class UserItem(UserRow u, SiteSettings st)
{
    public UserRow User { get; } = u;
    public string Name => u.FullName;
    public string Initials => string.Concat(new[] { u.FirstName, u.LastName }.Where(x => !string.IsNullOrWhiteSpace(x)).Select(x => char.ToUpperInvariant(x![0])));
    public string Phone => u.Phone ?? "";
    public string Role => st.RoleLabel(u.Role);
    public bool Blocked => !u.Active;
    public bool Pending => u.Pending;
    public string Telegram => u.Telegram is { Linked: true } t ? (t.Blocked ? "Telegram: botni bloklagan" : "Telegram" + (t.Username is { } n ? " · @" + n : "")) : "Telegram ulanmagan";
}

public sealed partial class UsersViewModel(AppServices services) : PageViewModel(services)
{
    public ObservableCollection<UserItem> Items { get; } = [];

    public IReadOnlyList<KeyValuePair<string, string>> RoleFilters { get; } = BuildRoleFilters(services.Settings.Current);

    [ObservableProperty] private string _search = "";
    [ObservableProperty] private int _roleIndex;
    [ObservableProperty] private bool _onlyPending;
    [ObservableProperty] private int _page = 1;
    [ObservableProperty] private int _pages = 1;
    [ObservableProperty] private string _summary = "";
    [ObservableProperty] private int _pendingCount;
    [ObservableProperty] private bool _hasPending;
    [ObservableProperty] private bool _isEmpty;

    public string Title => Settings.Nav("/dashboard/users", "Foydalanuvchilar");
    public bool CanPrev => Page > 1;
    public bool CanNext => Page < Pages;

    private static List<KeyValuePair<string, string>> BuildRoleFilters(SiteSettings st) =>
        [Editing.Opt("", "Barcha rollar"), .. Editing.Roles.Select(r => Editing.Opt(r, st.RoleLabel(r)))];

    partial void OnRoleIndexChanged(int value) { Page = 1; _ = RefreshAsync(); }
    partial void OnOnlyPendingChanged(bool value) { Page = 1; _ = RefreshAsync(); }
    partial void OnPageChanged(int value) { OnPropertyChanged(nameof(CanPrev)); OnPropertyChanged(nameof(CanNext)); }
    partial void OnPagesChanged(int value) => OnPropertyChanged(nameof(CanNext));

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        var role = RoleIndex > 0 && RoleIndex < RoleFilters.Count ? RoleFilters[RoleIndex].Key : null;
        var r = await S.Api.UsersAsync(Search.Trim().Length > 0 ? Search.Trim() : null, role, OnlyPending ? true : null, Page, ct);
        Items.Clear();
        foreach (var u in r.Data) Items.Add(new UserItem(u, Settings));
        Pages = Math.Max(1, r.Meta.Pages);
        Summary = $"Jami: {r.Meta.Total} · sahifa {r.Meta.Page}/{Pages}";
        PendingCount = r.Meta.Pending;
        HasPending = PendingCount > 0;
        IsEmpty = Items.Count == 0;
    }

    [RelayCommand]
    private Task SearchAsync()
    {
        Page = 1;
        return RefreshAsync();
    }

    [RelayCommand]
    private Task PrevAsync() { if (CanPrev) Page--; return RefreshAsync(); }

    [RelayCommand]
    private Task NextAsync() { if (CanNext) Page++; return RefreshAsync(); }

    [RelayCommand]
    private Task NewUserAsync() => EditAsync("Yangi foydalanuvchi", Editing.UserForm(null, Settings),
        v => S.Api.CreateUserAsync(Editing.UserBody(v, create: true)), "Foydalanuvchi qo'shildi.", "Qo'shish");

    [RelayCommand]
    private Task EditUserAsync(UserItem? u) => u is null ? Task.CompletedTask : EditAsync($"{u.Name} — tahrirlash", Editing.UserForm(u.User, Settings),
        v => S.Api.UpdateUserAsync(u.User.Id, Editing.UserBody(v, create: false)), "Saqlandi.");

    [RelayCommand]
    private Task SetPasswordAsync(UserItem? u) => u is null ? Task.CompletedTask : EditAsync($"{u.Name} — yangi parol",
        [new FormField("password", "Yangi parol", FieldKind.Password) { Required = true }],
        v => S.Api.UpdateUserAsync(u.User.Id, new { password = v.Str("password") }), "Parol o'rnatildi. Foydalanuvchining sessiyalari yopildi.");

    [RelayCommand]
    private Task ToggleBlockAsync(UserItem? u) => u is null ? Task.CompletedTask : ConfirmActAsync(u.Blocked ? "Blokdan chiqarish" : "Bloklash",
        u.Blocked ? $"{u.Name} yana tizimga kira oladi." : $"{u.Name} tizimga kira olmaydi, barcha sessiyalari yopiladi.",
        u.Blocked ? "Blokdan chiqarish" : "Bloklash", () => S.Api.UpdateUserAsync(u.User.Id, new { active = u.Blocked }), u.Blocked ? "Blokdan chiqarildi." : "Bloklandi.");

    [RelayCommand]
    private Task ApproveAsync(UserItem? u) => u is null ? Task.CompletedTask : ConfirmActAsync("Tasdiqlash",
        $"{u.Name} (botdan ro'yxatdan o'tgan) tasdiqlansinmi?", "Tasdiqlash", () => S.Api.ApproveUserAsync(u.User.Id), "Tasdiqlandi.");
}

// ── Tizim sozlamalari (settings.manage) ─────────────────────

public sealed class SettingRow(SettingItem item)
{
    private static readonly JsonSerializerOptions Pretty = new() { WriteIndented = true, Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping };

    public SettingItem Item { get; } = item;
    public string Key => item.Key;
    public string Label => item.Label;
    public string Type => item.Type;
    public bool Public => item.Public;
    public bool IsDefault => JsonElementEquals(item.Value, item.Default);
    public string Badge => (Public ? "ochiq · " : "") + (IsDefault ? "standart" : "o'zgartirilgan");

    public string Preview
    {
        get
        {
            var v = item.Value;
            var s = v.ValueKind switch
            {
                JsonValueKind.String => v.GetString() ?? "",
                JsonValueKind.True => "ha",
                JsonValueKind.False => "yo'q",
                JsonValueKind.Null or JsonValueKind.Undefined => "—",
                _ => v.GetRawText(),
            };
            s = s.Replace('\n', ' ');
            return s.Length > 140 ? s[..140] + "…" : s;
        }
    }

    /// <summary>Formadagi matn ko'rinishi.</summary>
    public string EditText => item.Value.ValueKind switch
    {
        JsonValueKind.String => item.Value.GetString() ?? "",
        JsonValueKind.Number => item.Value.GetRawText(),
        JsonValueKind.True => "1",
        JsonValueKind.False => "0",
        JsonValueKind.Undefined or JsonValueKind.Null => "",
        _ => JsonSerializer.Serialize(item.Value, Pretty),
    };

    public FormField Field() => item.Type switch
    {
        "bool" => FormField.Toggle("v", item.Label, item.Value.ValueKind == JsonValueKind.True),
        "int" => new FormField("v", item.Label, FieldKind.Number, EditText)
        {
            Hint = item.Min is not null || item.Max is not null ? $"Oraliq: {item.Min?.ToString(CultureInfo.InvariantCulture) ?? "…"} – {item.Max?.ToString(CultureInfo.InvariantCulture) ?? "…"}" : null,
        },
        "string" => new FormField("v", item.Label, FieldKind.Text, EditText),
        "text" => new FormField("v", item.Label, FieldKind.Multiline, EditText),
        _ => new FormField("v", item.Label, FieldKind.Multiline, EditText) { Hint = "JSON ko'rinishida (ro'yxat yoki obyekt)." },
    };

    /// <summary>Forma qiymatini sozlama turiga mos JSON'ga aylantiradi.</summary>
    public (JsonElement Value, string? Error) Parse(FormValues v)
    {
        var raw = v.Raw.TryGetValue("v", out var r) ? r : "";
        try
        {
            switch (item.Type)
            {
                case "bool":
                    return (JsonSerializer.SerializeToElement(raw == "1"), null);
                case "int":
                    if (!long.TryParse(raw.Trim(), NumberStyles.Integer, CultureInfo.InvariantCulture, out var n)) return (default, "Butun son kiriting.");
                    if (item.Min is { } min && n < min || item.Max is { } max && n > max) return (default, $"Qiymat {item.Min}–{item.Max} oralig'ida bo'lsin.");
                    return (JsonSerializer.SerializeToElement(n), null);
                case "string":
                case "text":
                    return (JsonSerializer.SerializeToElement(raw), null);
                default:
                    var el = JsonDocument.Parse(raw).RootElement.Clone();
                    if (item.Type == "list" && el.ValueKind != JsonValueKind.Array) return (default, "Ro'yxat ([ … ]) kiriting.");
                    if (item.Type == "object" && el.ValueKind != JsonValueKind.Object) return (default, "Obyekt ({ … }) kiriting.");
                    return (el, null);
            }
        }
        catch (JsonException ex)
        {
            return (default, "JSON xato: " + ex.Message);
        }
    }

    private static bool JsonElementEquals(JsonElement a, JsonElement b) =>
        a.ValueKind == b.ValueKind && (a.ValueKind is JsonValueKind.Undefined or JsonValueKind.Null || a.GetRawText() == b.GetRawText());
}

public sealed partial class AdminSettingsViewModel(AppServices services) : PageViewModel(services)
{
    private List<SettingRow> _all = [];
    private Dictionary<string, string> _groupNames = [];

    public ObservableCollection<SettingRow> Items { get; } = [];
    public ObservableCollection<KeyValuePair<string, string>> Groups { get; } = [];

    [ObservableProperty] private string _search = "";
    [ObservableProperty] private int _groupIndex;
    [ObservableProperty] private bool _isEmpty;

    public string Title => Settings.Nav("/dashboard/settings", "Sozlamalar");

    partial void OnSearchChanged(string value) => Filter();
    partial void OnGroupIndexChanged(int value) => Filter();

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        var r = await S.Api.AdminSettingsAsync(ct);
        _all = r.Data.Select(i => new SettingRow(i)).ToList();
        _groupNames = r.Groups;
        if (Groups.Count == 0)
        {
            Groups.Add(Editing.Opt("", "Barcha bo'limlar"));
            foreach (var g in r.Groups) Groups.Add(g);
            foreach (var g in _all.Select(x => x.Item.Group).Distinct().Where(g => !r.Groups.ContainsKey(g))) Groups.Add(Editing.Opt(g, g));
        }
        Filter();
    }

    private void Filter()
    {
        var group = GroupIndex > 0 && GroupIndex < Groups.Count ? Groups[GroupIndex].Key : null;
        var q = Search.Trim();
        Items.Clear();
        foreach (var x in _all.Where(x => (group is null || x.Item.Group == group)
                     && (q.Length == 0 || x.Key.Contains(q, StringComparison.OrdinalIgnoreCase) || x.Label.Contains(q, StringComparison.CurrentCultureIgnoreCase))))
            Items.Add(x);
        IsEmpty = Items.Count == 0 && HasLoaded;
    }

    [RelayCommand]
    private async Task EditAsync(SettingRow? row)
    {
        if (row is null) return;
        var ok = await S.Forms.ShowAsync(row.Key, [row.Field()], v =>
        {
            var (value, error) = row.Parse(v);
            return error is not null ? Task.FromResult<string?>(error) : Submit(() => S.Api.UpdateSettingAsync(row.Key, value));
        });
        if (!ok) return;
        await RefreshAsync();
        Notice = $"«{row.Label}» saqlandi.";
        if (row.Public) _ = S.Settings.RefreshAsync(force: true);
    }

    [RelayCommand]
    private Task ResetAsync(SettingRow? row) => row is null ? Task.CompletedTask : ConfirmActAsync("Standart qiymat",
        $"«{row.Label}» standart qiymatga qaytarilsinmi?", "Qaytarish", () => S.Api.ResetSettingAsync(row.Key), "Standart qiymat tiklandi.");
}

// ── To'lovlar ───────────────────────────────────────────────

public sealed class BalanceItem(BalanceRow r, SiteSettings st)
{
    public BalanceRow Row { get; } = r;
    public string Name => r.FullName;
    public string Sub => string.Join(" · ", new[] { r.Phone, string.Join(", ", r.Groups) }.Where(x => !string.IsNullOrEmpty(x)));
    public string Balance => Editing.Money(r.Balance, st);
    public bool InDebt => r.Balance < 0;
    public string LastPayment => r.LastPaymentDate is { } d ? "Oxirgi to'lov: " + d : "To'lov yo'q";
}

public sealed class EntryItem(BillingEntry e, SiteSettings st)
{
    public BillingEntry Entry { get; } = e;
    public string Date => e.EntryDate;
    public string Type => st.ObjStr("billing.type_labels", e.Type, e.Type);
    public string Amount => (e.Amount > 0 ? "+" : "") + Editing.Money(e.Amount, st);
    public bool Positive => e.Amount > 0;
    public string Sub => string.Join(" · ", new[] { e.Method is { } m ? st.ObjStr("billing.method_labels", m, m) : null, e.Group, e.Note }.Where(x => !string.IsNullOrEmpty(x)));
    public bool Voided => e.Voided;
    public string VoidText => "Bekor qilingan" + (string.IsNullOrEmpty(e.VoidReason) ? "" : ": " + e.VoidReason);
    public bool CanVoid => !e.Voided && e.Type != "CHARGE";
}

/// <summary>
/// To'lovlar: billing.manage — balanslar/qarzdorlar, hisobot, CSV; o'quvchi — o'z balansi (billing/me).
/// </summary>
public sealed partial class BillingViewModel(AppServices services) : PageViewModel(services)
{
    public ObservableCollection<BalanceItem> Balances { get; } = [];
    public ObservableCollection<EntryItem> MyEntries { get; } = [];
    public ObservableCollection<StatCard> Cards { get; } = [];

    public IReadOnlyList<KeyValuePair<string, string>> StatusFilters { get; } =
        [Editing.Opt("all", "Hammasi"), Editing.Opt("debt", "Qarzdorlar"), Editing.Opt("credit", "Oldindan to'laganlar"), Editing.Opt("zero", "Nol balans")];

    public IReadOnlyList<KeyValuePair<string, string>> Sorts { get; } =
        [Editing.Opt("balance", "Qarz bo'yicha"), Editing.Opt("-balance", "Balans (kamayish)"), Editing.Opt("name", "Ism bo'yicha"), Editing.Opt("last_payment", "Oxirgi to'lov")];

    [ObservableProperty] private string _search = "";
    [ObservableProperty] private int _statusIndex;
    [ObservableProperty] private int _sortIndex;
    [ObservableProperty] private int _page = 1;
    [ObservableProperty] private int _pages = 1;
    [ObservableProperty] private string _pageText = "";
    [ObservableProperty] private bool _isManager;
    [ObservableProperty] private bool _isStudent;
    [ObservableProperty] private string _myBalance = "";
    [ObservableProperty] private string _myInfo = "";
    [ObservableProperty] private bool _myInDebt;
    [ObservableProperty] private bool _isDisabled;

    // Hisobot
    [ObservableProperty] private DateTimeOffset? _from;
    [ObservableProperty] private DateTimeOffset? _to;
    [ObservableProperty] private string _reportText = "";
    [ObservableProperty] private string _methodsText = "";

    public string Title => Settings.Nav("/dashboard/billing", "To'lovlar");
    public bool CanPrev => Page > 1;
    public bool CanNext => Page < Pages;

    partial void OnStatusIndexChanged(int value) { Page = 1; _ = RefreshAsync(); }
    partial void OnSortIndexChanged(int value) { Page = 1; _ = RefreshAsync(); }
    partial void OnPageChanged(int value) { OnPropertyChanged(nameof(CanPrev)); OnPropertyChanged(nameof(CanNext)); }
    partial void OnPagesChanged(int value) => OnPropertyChanged(nameof(CanNext));

    private string? Status => StatusFilters[Math.Clamp(StatusIndex, 0, StatusFilters.Count - 1)].Key is var s && s != "all" ? s : null;
    private string Sort => Sorts[Math.Clamp(SortIndex, 0, Sorts.Count - 1)].Key;
    private string? Query => Search.Trim().Length > 0 ? Search.Trim() : null;

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        IsManager = S.Session.Can(Permissions.BillingManage);
        IsStudent = !IsManager;
        if (!IsManager)
        {
            ResetStale();
            var me = await S.Api.BillingMeAsync(ct);
            Track(me);
            IsDisabled = !me.Value.Enabled;
            var s = me.Value.Students.FirstOrDefault();
            MyEntries.Clear();
            if (s is null) { MyBalance = "—"; MyInfo = "To'lov ma'lumoti yo'q."; return; }
            MyBalance = Editing.Money(s.Balance, Settings);
            MyInDebt = s.Balance < 0;
            MyInfo = (s.Debt > 0 ? $"Qarz: {Editing.Money(s.Debt, Settings)}" : "Qarz yo'q")
                     + $" · Bu oy hisoblandi: {Editing.Money(s.MonthCharged, Settings)}"
                     + (s.LastPayment is { } lp ? $" · Oxirgi to'lov: {Editing.Money(lp.Amount, Settings)} ({lp.Date})" : "");
            foreach (var e in s.Recent) MyEntries.Add(new EntryItem(e, Settings));
            return;
        }

        if (From is null)
        {
            var today = ServerClock.Now;
            To = today;
            From = new DateTimeOffset(today.Year, today.Month, 1, 0, 0, 0, today.Offset);
        }
        var balancesTask = S.Api.BalancesAsync(Query, Status, Sort, Page, ct);
        var reportTask = S.Api.BillingReportAsync(DateOnly.FromDateTime(From!.Value.Date), DateOnly.FromDateTime(To!.Value.Date), ct);
        await Task.WhenAll(balancesTask, reportTask);
        var b = balancesTask.Result;
        var rep = reportTask.Result;

        Balances.Clear();
        foreach (var r in b.Data) Balances.Add(new BalanceItem(r, Settings));
        Pages = Math.Max(1, b.Meta.Pages);
        PageText = $"Jami: {b.Meta.Total} · sahifa {b.Meta.Page}/{Pages}";

        Cards.Clear();
        Cards.Add(new StatCard("Tushum (davr)", Editing.Money(rep.Income, Settings), $"{rep.PaymentsCount} ta to'lov", ""));
        Cards.Add(new StatCard("Qarzdorlar", Format.Number(b.Summary.Debtors), "Jami qarz: " + Editing.Money(b.Summary.TotalDebt, Settings), ""));
        Cards.Add(new StatCard("Oldindan to'langan", Editing.Money(b.Summary.TotalCredit, Settings), $"{b.Summary.Students} o'quvchi", ""));
        Cards.Add(new StatCard("Hisoblandi (davr)", Editing.Money(rep.Charged, Settings), "Tuzatishlar: " + Editing.Money(rep.Adjusted, Settings), ""));
        ReportText = $"{rep.Period.From} — {rep.Period.To}: tushum {Editing.Money(rep.Income, Settings)}, bekor qilingan {rep.Voided.Count} ta ({Editing.Money(rep.Voided.Amount, Settings)})";
        MethodsText = string.Join("   ", rep.ByMethod.Select(m => $"{(m.Method is { } x ? Settings.ObjStr("billing.method_labels", x, x) : "—")}: {Editing.Money(m.Amount, Settings)} ({m.Count})"));
    }

    [RelayCommand]
    private Task SearchAsync() { Page = 1; return RefreshAsync(); }

    [RelayCommand]
    private Task PrevAsync() { if (CanPrev) Page--; return RefreshAsync(); }

    [RelayCommand]
    private Task NextAsync() { if (CanNext) Page++; return RefreshAsync(); }

    [RelayCommand]
    private void OpenStudent(BalanceItem? item)
    {
        if (item is not null) S.Navigator.Navigate(Services.Page.BillingStudent, item.Row.StudentId);
    }

    [RelayCommand]
    private async Task ExportBalancesAsync()
    {
        await ActAsync(async () =>
        {
            var bytes = await S.Api.BalancesCsvAsync(Query, Status, Sort);
            if (await S.Platform.SaveFileAsync($"balanslar_{DateTime.Today:yyyyMMdd}.csv", "CSV (Excel)", ".csv", bytes)) Notice = Strings.CsvSaved;
        });
    }

    [RelayCommand]
    private async Task ExportEntriesAsync()
    {
        if (From is null || To is null) return;
        var f = DateOnly.FromDateTime(From.Value.Date);
        var t = DateOnly.FromDateTime(To.Value.Date);
        await ActAsync(async () =>
        {
            var bytes = await S.Api.BillingEntriesCsvAsync(f, t);
            if (await S.Platform.SaveFileAsync($"tolovlar_{f:yyyyMMdd}_{t:yyyyMMdd}.csv", "CSV (Excel)", ".csv", bytes)) Notice = Strings.CsvSaved;
        });
    }
}

/// <summary>Bitta o'quvchining to'lovlari: balans, oylar, jurnal; to'lov/tuzatish kiritish va bekor qilish.</summary>
public sealed partial class BillingStudentViewModel(AppServices services, int studentId) : PageViewModel(services)
{
    public ObservableCollection<EntryItem> Entries { get; } = [];
    public ObservableCollection<string> Months { get; } = [];

    [ObservableProperty] private string _name = "";
    [ObservableProperty] private string _phone = "";
    [ObservableProperty] private string _balance = "";
    [ObservableProperty] private bool _inDebt;
    [ObservableProperty] private string _info = "";
    [ObservableProperty] private string _groupsText = "";
    [ObservableProperty] private bool _canManage;
    [ObservableProperty] private int _page = 1;
    [ObservableProperty] private int _pages = 1;
    [ObservableProperty] private string _pageText = "";

    public bool CanPrev => Page > 1;
    public bool CanNext => Page < Pages;

    partial void OnPageChanged(int value) { OnPropertyChanged(nameof(CanPrev)); OnPropertyChanged(nameof(CanNext)); }
    partial void OnPagesChanged(int value) => OnPropertyChanged(nameof(CanNext));

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        var d = await S.Api.BillingStudentAsync(studentId, Page, ct);
        Name = d.Student.FullName;
        Phone = d.Student.Phone ?? "";
        Balance = Editing.Money(d.Balance, Settings);
        InDebt = d.Balance < 0;
        CanManage = d.CanManage;
        Info = $"Jami hisoblandi: {Editing.Money(d.Totals.Charged, Settings)} · to'langan: {Editing.Money(d.Totals.Paid, Settings)} · tuzatishlar: {Editing.Money(d.Totals.Adjusted, Settings)} · oylik to'lov: {Editing.Money(d.MonthlyFee, Settings)}";
        GroupsText = string.Join(", ", d.Groups.Select(g => g.Name + (g.Active ? "" : " (faol emas)")));
        Months.Clear();
        foreach (var m in d.Months)
            Months.Add($"{m.Month}: hisoblandi {Editing.Money(m.Charged, Settings)}, to'landi {Editing.Money(m.Paid, Settings)}" + (m.Adjusted != 0 ? $", tuzatish {Editing.Money(m.Adjusted, Settings)}" : ""));
        Entries.Clear();
        foreach (var e in d.Entries) Entries.Add(new EntryItem(e, Settings));
        Pages = Math.Max(1, d.Meta.Pages);
        PageText = $"sahifa {d.Meta.Page}/{Pages}";
    }

    [RelayCommand]
    private Task PrevAsync() { if (CanPrev) Page--; return RefreshAsync(); }

    [RelayCommand]
    private Task NextAsync() { if (CanNext) Page++; return RefreshAsync(); }

    [RelayCommand]
    private async Task AddPaymentAsync()
    {
        // Takroriy bosishdan himoya: bitta dialog uchun bitta request_id (server dedupe qiladi).
        var requestId = Guid.NewGuid().ToString("N");
        await EditAsync($"{Name} — to'lov", Editing.PaymentForm(Settings), v =>
        {
            if (v.Int("amount") is not > 0) throw new ApiException(System.Net.HttpStatusCode.UnprocessableEntity, "Summani kiriting.");
            return S.Api.AddPaymentAsync(studentId, v.Int("amount")!.Value, v.Str("method"), v.Str("date"), v.StrOrNull("note"), requestId);
        }, "To'lov saqlandi.", "Saqlash");
    }

    [RelayCommand]
    private Task AddAdjustmentAsync() => EditAsync($"{Name} — tuzatish", Editing.AdjustmentForm(Settings), v =>
    {
        if (v.Int("amount") is null or 0) throw new ApiException(System.Net.HttpStatusCode.UnprocessableEntity, "Summani kiriting (0 dan farqli).");
        return S.Api.AddAdjustmentAsync(studentId, v.Int("amount")!.Value, v.Str("reason"), v.Str("date"));
    }, "Tuzatish saqlandi.");

    [RelayCommand]
    private async Task VoidAsync(EntryItem? e)
    {
        if (e is null || !e.CanVoid) return;
        await EditAsync($"{e.Type} {e.Amount} — bekor qilish", [new FormField("reason", "Sabab") { Required = true }],
            v => S.Api.VoidEntryAsync(e.Entry.Id, v.Str("reason")), "Yozuv bekor qilindi.", "Bekor qilish");
    }
}
