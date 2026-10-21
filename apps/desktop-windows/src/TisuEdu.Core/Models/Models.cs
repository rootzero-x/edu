using System.Text.Json;
using System.Text.Json.Serialization;

namespace TisuEdu.Core.Models;

// Barcha modellar API javoblariga 1:1 mos (snake_case → PascalCase, Json.Options orqali).

public static class Permissions
{
    public const string CourseView = "course.view";
    public const string CourseManage = "course.manage";
    public const string GroupManage = "group.manage";
    public const string AttendanceMark = "attendance.mark";
    public const string QuizManage = "quiz.manage";
    public const string QuizTake = "quiz.take";
    public const string ReportView = "report.view";
    public const string UserManage = "user.manage";
    public const string SettingsManage = "settings.manage";
    public const string AnnouncementManage = "announcement.manage";
    public const string BillingManage = "billing.manage";
    public const string LeadManage = "lead.manage";
    public const string AuditView = "audit.view";
}

public static class AttendanceStatus
{
    public const string Present = "PRESENT";
    public const string Late = "LATE";
    public const string Absent = "ABSENT";
    public const string Excused = "EXCUSED";
    public static readonly string[] All = [Present, Late, Absent, Excused];
}

// ── Auth ─────────────────────────────────────────────────────

public sealed class TelegramLink
{
    public bool Linked { get; set; }
    public string? Username { get; set; }
    public DateTimeOffset? LinkedAt { get; set; }
}

public sealed class User
{
    public int Id { get; set; }
    public string? Phone { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string Role { get; set; } = "";
    public int? BranchId { get; set; }
    public bool Active { get; set; }
    public string? Locale { get; set; }
    public List<string> Permissions { get; set; } = [];
    public TelegramLink? Telegram { get; set; }
    public bool? HasPassword { get; set; }

    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
    [JsonIgnore] public string Initials =>
        string.Concat(new[] { FirstName, LastName }.Where(s => !string.IsNullOrWhiteSpace(s)).Select(s => char.ToUpperInvariant(s![0]))).PadRight(1, '?');

    public bool Can(string permission) => Permissions.Contains(permission);
}

public sealed class LoginResponse
{
    public string Token { get; set; } = "";
    public User User { get; set; } = new();
}

public sealed class MeResponse
{
    public User User { get; set; } = new();
}

public sealed class TelegramStart
{
    public string Code { get; set; } = "";
    public string PollSecret { get; set; } = "";
    public string BotUrl { get; set; } = "";
    public int ExpiresIn { get; set; }
}

public sealed class TelegramPoll
{
    /// <summary>pending | expired | ok</summary>
    public string Status { get; set; } = "";
    public string? Token { get; set; }
    public User? User { get; set; }
}

// ── Dashboard ────────────────────────────────────────────────

public sealed class TodaySession
{
    public int Id { get; set; }
    public int GroupId { get; set; }
    public string GroupName { get; set; } = "";
    public string? CourseTitle { get; set; }
    public string? Teacher { get; set; }
    public string? Room { get; set; }
    public string? Topic { get; set; }
    public DateTimeOffset StartsAt { get; set; }
    public DateTimeOffset EndsAt { get; set; }
    public bool Cancelled { get; set; }
    public int MarkedCount { get; set; }
    public int StudentsCount { get; set; }
    public bool CanMark { get; set; }
}

public sealed class DashboardStats
{
    public int TodaySessions { get; set; }
    public int TodayMarked { get; set; }
    public double? AttendanceRate { get; set; }
    public int AttendanceDays { get; set; }
    public Dictionary<string, int> AttendanceCounts { get; set; } = [];
    public int? Courses { get; set; }
    public int Groups { get; set; }
    public int? Students { get; set; }
    public int? UnmarkedSessions { get; set; }
}

public sealed class DashboardSummary
{
    public string Role { get; set; } = "";
    public DateTimeOffset GeneratedAt { get; set; }
    public List<TodaySession> Today { get; set; } = [];
    public DashboardStats Stats { get; set; } = new();
    public SetupChecklist? Setup { get; set; }
}

// ── Kurslar ──────────────────────────────────────────────────

/// <summary>Kurs kontentiga kirish. granted=false bo'lsa — sozlamalardagi rad etish matni.</summary>
public sealed class AccessInfo
{
    public bool Granted { get; set; } = true;
    public string? Code { get; set; }
    public string? Title { get; set; }
    public string? Message { get; set; }
    public string? Button { get; set; }
    public string? Contact { get; set; }
    public string? ContactUrl { get; set; }
}

public sealed class LessonRef
{
    public int Id { get; set; }
    public int ModuleId { get; set; }
    public string Title { get; set; } = "";
    public int Position { get; set; }
    public bool Published { get; set; } = true;
}

public sealed class CourseModule
{
    public int Id { get; set; }
    public int CourseId { get; set; }
    public string Title { get; set; } = "";
    public int Position { get; set; }
    public List<LessonRef> Lessons { get; set; } = [];
}

public sealed class Course
{
    public int Id { get; set; }
    public string Title { get; set; } = "";
    public string? Description { get; set; }
    public string? CoverUrl { get; set; }
    public int? DurationMonths { get; set; }
    public bool Published { get; set; }
    public int? CreatedBy { get; set; }
    public int ModulesCount { get; set; }
    public AccessInfo? Access { get; set; }
    public List<CourseModule> Modules { get; set; } = [];
}

public sealed class CourseDetail
{
    public Course Course { get; set; } = new();
    public bool CanEdit { get; set; }
    public AccessInfo Access { get; set; } = new();
}

public sealed class Material
{
    public int Id { get; set; }
    public int LessonId { get; set; }
    /// <summary>VIDEO | FILE | LINK | TEXT</summary>
    public string Type { get; set; } = "";
    public string Title { get; set; } = "";
    public string? Url { get; set; }
    public string? Content { get; set; }
    public int Position { get; set; }
    public int? FileId { get; set; }
}

public sealed class LessonModuleRef
{
    public int Id { get; set; }
    public string Title { get; set; } = "";
    public Course? Course { get; set; }
}

public sealed class Lesson
{
    public int Id { get; set; }
    public int ModuleId { get; set; }
    public string Title { get; set; } = "";
    public string? Body { get; set; }
    public bool Published { get; set; }
    public List<Material> Materials { get; set; } = [];
    public LessonModuleRef? Module { get; set; }
}

public sealed class LessonNav
{
    public int? Prev { get; set; }
    public int? Next { get; set; }
    public int? Index { get; set; }
    public int Total { get; set; }
}

public sealed class LessonDetail
{
    public Lesson Lesson { get; set; } = new();
    public bool CanEdit { get; set; }
    public LessonNav Nav { get; set; } = new();
}

// ── Guruhlar va davomat ──────────────────────────────────────

public sealed class Person
{
    public int Id { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string? Phone { get; set; }
    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
}

public sealed class NamedRef
{
    public int Id { get; set; }
    public string? Name { get; set; }
    public string? Title { get; set; }
}

public sealed class GroupSchedule
{
    public int Id { get; set; }
    /// <summary>1=Du … 7=Ya</summary>
    public int Weekday { get; set; }
    public string StartTime { get; set; } = "";
    public string EndTime { get; set; } = "";
}

public sealed class Group
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public int CourseId { get; set; }
    public int? BranchId { get; set; }
    public int? TeacherId { get; set; }
    public string? StartDate { get; set; }
    public string? EndDate { get; set; }
    public string? Room { get; set; }
    public bool Active { get; set; }
    public int StudentsCount { get; set; }
    public NamedRef? Course { get; set; }
    public Person? Teacher { get; set; }
    public NamedRef? Branch { get; set; }
    public List<GroupSchedule> Schedules { get; set; } = [];
    public List<Person> Students { get; set; } = [];
}

public sealed class ClassSession
{
    public int Id { get; set; }
    public int GroupId { get; set; }
    public DateTimeOffset StartsAt { get; set; }
    public DateTimeOffset EndsAt { get; set; }
    public string? Topic { get; set; }
    public bool Cancelled { get; set; }
    public int MarkedCount { get; set; }
}

public sealed class SheetRow
{
    public int StudentId { get; set; }
    public string Name { get; set; } = "";
    public string? Status { get; set; }
    public string? ArrivedAt { get; set; }
    public string? Note { get; set; }
}

/// <summary>Davomatni tahrirlash oynasi — server qoidasi (AttendanceController::editWindow).</summary>
public sealed class EditWindow
{
    public bool CanEdit { get; set; }
    public bool ReasonRequired { get; set; }
    public string? Message { get; set; }
    public DateTimeOffset? Deadline { get; set; }
}

public sealed class AttendanceSheet
{
    public ClassSession Session { get; set; } = new();
    public NamedRef Group { get; set; } = new();
    public List<SheetRow> Rows { get; set; } = [];
    public EditWindow Edit { get; set; } = new();
}

public sealed class MarkItem
{
    public int StudentId { get; set; }
    public string? Status { get; set; }
    public string? ArrivedAt { get; set; }
    public string? Note { get; set; }
}

public sealed class MarkRequest
{
    public List<MarkItem> Items { get; set; } = [];
    public string? Reason { get; set; }
}

public sealed class MarkResult
{
    public bool Ok { get; set; }
    public int Changed { get; set; }
}

public sealed class StudentAttendance
{
    public int StudentId { get; set; }
    public string Name { get; set; } = "";
    public Dictionary<string, int> Counts { get; set; } = [];
    public double? Rate { get; set; }
}

public sealed class UnmarkedSession
{
    public int Id { get; set; }
    public DateTimeOffset StartsAt { get; set; }
    public NamedRef Group { get; set; } = new();
    public int Members { get; set; }
    public int Marked { get; set; }
}

public sealed class AttendanceChange
{
    public int StudentId { get; set; }
    public string? Name { get; set; }
    public string? From { get; set; }
    public string? To { get; set; }
}

public sealed class AttendanceHistoryItem
{
    public int Id { get; set; }
    public DateTimeOffset At { get; set; }
    public string? By { get; set; }
    public string? Reason { get; set; }
    public List<AttendanceChange> Changes { get; set; } = [];
}

// ── Testlar ──────────────────────────────────────────────────

public sealed class QuizSummary
{
    public int Id { get; set; }
    public int CourseId { get; set; }
    public int? LessonId { get; set; }
    public string Title { get; set; } = "";
    public bool IsFinal { get; set; }
    public int PassPercent { get; set; }
    public int MaxAttempts { get; set; }
    public int? TimeLimitSec { get; set; }
    public bool Published { get; set; } = true;
    public bool Shuffle { get; set; }
    public int QuestionsCount { get; set; }
    public bool Locked { get; set; }
    public int? MyAttempts { get; set; }
    public double? MyBestPercent { get; set; }
    public bool? MyPassed { get; set; }
}

public sealed class QuizList
{
    public List<QuizSummary> Data { get; set; } = [];
    public AccessInfo Access { get; set; } = new();
}

public sealed class QuizOption
{
    public int Id { get; set; }
    public string Text { get; set; } = "";
    public bool? IsCorrect { get; set; }
}

public sealed class QuizQuestion
{
    public int Id { get; set; }
    /// <summary>SINGLE | MULTIPLE | TEXT</summary>
    public string Type { get; set; } = "";
    public string Text { get; set; } = "";
    public int Points { get; set; }
    public List<QuizOption> Options { get; set; } = [];
    public List<string>? AcceptedAnswers { get; set; }
}

public sealed class QuizInfo
{
    public int Id { get; set; }
    public string Title { get; set; } = "";
    public bool IsFinal { get; set; }
    public int PassPercent { get; set; }
    public int MaxAttempts { get; set; }
    public int? TimeLimitSec { get; set; }
    public int CourseId { get; set; }
    public int? LessonId { get; set; }
    public bool Shuffle { get; set; }
    public bool Published { get; set; } = true;
    public int QuestionsCount { get; set; }
    public NamedRef? Course { get; set; }
    /// <summary>Faqat boshqaruvchiga (javob kaliti bilan).</summary>
    public List<QuizQuestion>? Questions { get; set; }
}

public sealed class AttemptSummary
{
    public int Id { get; set; }
    public DateTimeOffset? StartedAt { get; set; }
    public DateTimeOffset? SubmittedAt { get; set; }
    public int? Score { get; set; }
    public int? MaxScore { get; set; }
    public double? Percent { get; set; }
    public bool? Passed { get; set; }
}

public sealed class QuizDetail
{
    public QuizInfo Quiz { get; set; } = new();
    public List<AttemptSummary> Attempts { get; set; } = [];
    /// <summary>null — boshqaruvchi ko'rinishi (o'quvchi emas).</summary>
    public bool? CanTake { get; set; }
    public bool? CanEdit { get; set; }
}

public sealed class Attempt
{
    public int Id { get; set; }
    public DateTimeOffset StartedAt { get; set; }
    public DateTimeOffset? Deadline { get; set; }
    public QuizInfo Quiz { get; set; } = new();
    public List<QuizQuestion> Questions { get; set; } = [];
}

public sealed class AttemptEnvelope
{
    public Attempt Attempt { get; set; } = new();
}

public sealed class QuizResult
{
    public int Score { get; set; }
    public int MaxScore { get; set; }
    public double Percent { get; set; }
    public bool Passed { get; set; }
    public bool TimeExpired { get; set; }
}

public sealed class ReviewItem
{
    public int QuestionId { get; set; }
    public List<JsonElement> Correct { get; set; } = [];
    public int Earned { get; set; }
    public int Points { get; set; }
}

public sealed class SubmitResponse
{
    public QuizResult Result { get; set; } = new();
    public List<ReviewItem>? Review { get; set; }
}

public sealed class QuizResultRow
{
    public int Id { get; set; }
    public int StudentId { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string? Phone { get; set; }
    public DateTimeOffset? SubmittedAt { get; set; }
    public int? Score { get; set; }
    public int? MaxScore { get; set; }
    public double? Percent { get; set; }
    public bool? Passed { get; set; }
    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
    [JsonIgnore] public string ScoreText => $"{Score}/{MaxScore}";
    [JsonIgnore] public string PercentText => Services.Format.Percent(Percent);
}

// ── Sertifikatlar, yangiliklar ───────────────────────────────

public sealed class Certificate
{
    public string Code { get; set; } = "";
    public DateTimeOffset IssuedAt { get; set; }
    public int StudentId { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string? Course { get; set; }
    public string? Group { get; set; }
    public DateTimeOffset? RevokedAt { get; set; }
    public string? RevokedReason { get; set; }
    [JsonIgnore] public string StudentName => $"{FirstName} {LastName}".Trim();
    [JsonIgnore] public bool Revoked => RevokedAt is not null;
}

public sealed class Announcement
{
    public int Id { get; set; }
    public string Title { get; set; } = "";
    public string Body { get; set; } = "";
    public string? ImageUrl { get; set; }
    public string? Audience { get; set; }
    public string? AudienceRole { get; set; }
    public NamedRef? Group { get; set; }
    public bool Pinned { get; set; }
    public bool IsPublic { get; set; }
    public bool NotifyTelegram { get; set; }
    public DateTimeOffset? PublishedAt { get; set; }
    public string? Author { get; set; }
    public int? CreatedBy { get; set; }
    public bool Read { get; set; }
}

public sealed class PageMeta
{
    public int Page { get; set; }
    public int Pages { get; set; }
    public int Total { get; set; }
    public int Unread { get; set; }
}

public sealed class AnnouncementPage
{
    public List<Announcement> Data { get; set; } = [];
    public PageMeta Meta { get; set; } = new();
}

public sealed class UnreadCount
{
    public int Unread { get; set; }
}

// ── Hisobotlar ───────────────────────────────────────────────

public sealed class Period
{
    public string From { get; set; } = "";
    public string To { get; set; } = "";
}

public sealed class AttendanceTotal
{
    public Dictionary<string, int> Counts { get; set; } = [];
    public double? Rate { get; set; }
    public int Sessions { get; set; }
    public int Students { get; set; }
    public int UnmarkedSessions { get; set; }
}

public sealed class DayRow
{
    public string Date { get; set; } = "";
    public Dictionary<string, int> Counts { get; set; } = [];
    public double? Rate { get; set; }
}

public sealed class GroupReportRow
{
    public int GroupId { get; set; }
    public string Group { get; set; } = "";
    public string? Teacher { get; set; }
    public string? Course { get; set; }
    public int Sessions { get; set; }
    public Dictionary<string, int> Counts { get; set; } = [];
    public double? Rate { get; set; }
}

public sealed class RiskRow
{
    public int StudentId { get; set; }
    public string Name { get; set; } = "";
    public string? Phone { get; set; }
    public List<string> Groups { get; set; } = [];
    public Dictionary<string, int> Counts { get; set; } = [];
    public double? Rate { get; set; }
}

public sealed class AttendanceReport
{
    public Period Period { get; set; } = new();
    public AttendanceTotal Total { get; set; } = new();
    public List<DayRow> ByDay { get; set; } = [];
    public List<GroupReportRow> ByGroup { get; set; } = [];
    public List<RiskRow> Risk { get; set; } = [];
    public int RiskThreshold { get; set; }
}

public sealed class QuizReportRow
{
    public int QuizId { get; set; }
    public string Title { get; set; } = "";
    public string? Course { get; set; }
    public bool IsFinal { get; set; }
    public int Attempts { get; set; }
    public int Students { get; set; }
    public double AvgPercent { get; set; }
    public double? PassRate { get; set; }
}

public sealed class QuizReport
{
    public Period Period { get; set; } = new();
    public List<QuizReportRow> Data { get; set; } = [];
}

public sealed class ReportOverview
{
    public Period Period { get; set; } = new();
    public int Groups { get; set; }
    public int Students { get; set; }
    public int Courses { get; set; }
    public int Sessions { get; set; }
    public int NewStudents { get; set; }
    public int Certificates { get; set; }
}

public sealed class DataList<T>
{
    public List<T> Data { get; set; } = [];
}
