using System.Text.Json;
using System.Text.Json.Serialization;

namespace TisuEdu.Core.Models;

// ── To'lovlar (billing) ──────────────────────────────────────

public sealed class StudentRef
{
    public int Id { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string? Phone { get; set; }
    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
}

public sealed class LastPayment
{
    public int Amount { get; set; }
    public string? Date { get; set; }
    public string? Method { get; set; }
}

public sealed class BillingEntry
{
    public int Id { get; set; }
    public int StudentId { get; set; }
    public string? Group { get; set; }
    /// <summary>CHARGE | PAYMENT | ADJUSTMENT</summary>
    public string Type { get; set; } = "";
    public int Amount { get; set; }
    public string EntryDate { get; set; } = "";
    public string? Method { get; set; }
    public string? Note { get; set; }
    public bool Voided { get; set; }
    public string? VoidReason { get; set; }
}

public sealed class BillingSummary
{
    public StudentRef Student { get; set; } = new();
    public int Balance { get; set; }
    public int Debt { get; set; }
    public int MonthCharged { get; set; }
    public LastPayment? LastPayment { get; set; }
    public List<BillingEntry> Recent { get; set; } = [];
}

public sealed class BillingMe
{
    public bool Enabled { get; set; }
    public List<BillingSummary> Students { get; set; } = [];
}

public sealed class BillingMonth
{
    public string Month { get; set; } = "";
    public int Charged { get; set; }
    public int Paid { get; set; }
    public int Adjusted { get; set; }
}

public sealed class BillingTotals
{
    public int Charged { get; set; }
    public int Paid { get; set; }
    public int Adjusted { get; set; }
}

public sealed class BillingGroup
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public bool Active { get; set; }
    public string? JoinedAt { get; set; }
    public int DailyCharge { get; set; }
}

public sealed class BillingStudent
{
    public StudentRef Student { get; set; } = new();
    public int Balance { get; set; }
    public int Debt { get; set; }
    public int MonthCharged { get; set; }
    public LastPayment? LastPayment { get; set; }
    public List<BillingGroup> Groups { get; set; } = [];
    public int MonthlyFee { get; set; }
    public BillingTotals Totals { get; set; } = new();
    public List<BillingMonth> Months { get; set; } = [];
    public List<BillingEntry> Entries { get; set; } = [];
    public PageMeta Meta { get; set; } = new();
    public bool CanManage { get; set; }
}

public sealed class BalanceRow
{
    public int StudentId { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string? Phone { get; set; }
    public bool Active { get; set; }
    public int Balance { get; set; }
    public string? LastPaymentDate { get; set; }
    public List<string> Groups { get; set; } = [];
    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
}

public sealed class BalanceSummary
{
    public int Students { get; set; }
    public int Debtors { get; set; }
    public int TotalDebt { get; set; }
    public int TotalCredit { get; set; }
}

public sealed class BalancePage
{
    public List<BalanceRow> Data { get; set; } = [];
    public PageMeta Meta { get; set; } = new();
    public BalanceSummary Summary { get; set; } = new();
}

public sealed class MethodRow
{
    public string? Method { get; set; }
    public int Count { get; set; }
    public int Amount { get; set; }
}

public sealed class IncomeDay
{
    public string Date { get; set; } = "";
    public int Count { get; set; }
    public int Amount { get; set; }
}

public sealed class VoidedTotals
{
    public int Count { get; set; }
    public int Amount { get; set; }
}

public sealed class BillingReport
{
    public Period Period { get; set; } = new();
    public int Income { get; set; }
    public int PaymentsCount { get; set; }
    public int Charged { get; set; }
    public int Adjusted { get; set; }
    public VoidedTotals Voided { get; set; } = new();
    public List<MethodRow> ByMethod { get; set; } = [];
    public List<IncomeDay> ByDay { get; set; } = [];
    public int Debtors { get; set; }
    public int TotalDebt { get; set; }
}

// ── Foydalanuvchilar ─────────────────────────────────────────

public sealed class UserTelegram
{
    public bool Linked { get; set; }
    public string? Username { get; set; }
    public bool Blocked { get; set; }
}

public sealed class UserRow
{
    public int Id { get; set; }
    public string? Phone { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string Role { get; set; } = "";
    public bool Active { get; set; }
    public bool Pending { get; set; }
    public UserTelegram? Telegram { get; set; }
    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
}

public sealed class UserPageMeta
{
    public int Page { get; set; }
    public int Pages { get; set; }
    public int Total { get; set; }
    public int Pending { get; set; }
}

public sealed class UserPage
{
    public List<UserRow> Data { get; set; } = [];
    public UserPageMeta Meta { get; set; } = new();
}

public sealed class UserEnvelope
{
    public UserRow User { get; set; } = new();
}

public sealed class TeacherRow
{
    public int Id { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string? Role { get; set; }
    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
}

// ── Tizim sozlamalari ────────────────────────────────────────

public sealed class SettingItem
{
    public string Key { get; set; } = "";
    public string Group { get; set; } = "";
    /// <summary>int | bool | string | text | list | object</summary>
    public string Type { get; set; } = "";
    public string Label { get; set; } = "";
    public bool Public { get; set; }
    public double? Min { get; set; }
    public double? Max { get; set; }
    public JsonElement Default { get; set; }
    public JsonElement Value { get; set; }
}

public sealed class SettingsIndex
{
    public List<SettingItem> Data { get; set; } = [];
    public List<string> Permissions { get; set; } = [];
    public Dictionary<string, string> Groups { get; set; } = [];
}

// ── Guruh a'zolari, sertifikat berish ────────────────────────

public sealed class MemberRow
{
    public int Id { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string? Phone { get; set; }
    public string? JoinedAt { get; set; }
    public string? Status { get; set; }
    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
}

public sealed class MembersResponse
{
    public List<MemberRow> Current { get; set; } = [];
}

public sealed class CertificateCandidate
{
    public int StudentId { get; set; }
    public string Name { get; set; } = "";
    public bool Left { get; set; }
    public double? AttendanceRate { get; set; }
    public int MarkedSessions { get; set; }
    public bool FinalQuizPassed { get; set; }
    public string? Certificate { get; set; }
    public List<string> Blockers { get; set; } = [];
}

public sealed class IssueResult
{
    public List<JsonElement> Issued { get; set; } = [];
    public List<CertificateCandidate> Data { get; set; } = [];
}
