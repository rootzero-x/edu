using System.Text.Json;
using System.Text.Json.Serialization;

namespace TisuEdu.Core.Models;

// ── Taqvim va xonalar ────────────────────────────────────────

public sealed class CalendarEvent
{
    public int Id { get; set; }
    public int GroupId { get; set; }
    public string GroupName { get; set; } = "";
    public string? CourseTitle { get; set; }
    public int TeacherId { get; set; }
    public string? Teacher { get; set; }
    public int? RoomId { get; set; }
    public string? Room { get; set; }
    public string? Topic { get; set; }
    public DateTimeOffset StartsAt { get; set; }
    public DateTimeOffset EndsAt { get; set; }
    public bool Cancelled { get; set; }
    public int? MarkedCount { get; set; }
    public int StudentsCount { get; set; }
    public int HomeworkCount { get; set; }
    public bool CanMark { get; set; }
}

public sealed class IdName
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
}

public sealed class CalendarFilters
{
    public List<IdName> Groups { get; set; } = [];
    public List<IdName> Teachers { get; set; } = [];
    public List<IdName> Rooms { get; set; } = [];
}

public sealed class CalendarResponse
{
    public Period Period { get; set; } = new();
    public List<CalendarEvent> Data { get; set; } = [];
    public CalendarFilters Filters { get; set; } = new();
}

public sealed class Room
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public int? Capacity { get; set; }
    public string? Note { get; set; }
    public bool Active { get; set; }
    public int GroupsCount { get; set; }
}

// ── Fayllar ──────────────────────────────────────────────────

public sealed class StoredFile
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public string Mime { get; set; } = "";
    public long Size { get; set; }
    public DateTimeOffset? CreatedAt { get; set; }
}

public sealed class FileLink
{
    public string Url { get; set; } = "";
    public DateTimeOffset? ExpiresAt { get; set; }
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public string Mime { get; set; } = "";
    public long Size { get; set; }
}

public sealed class FileEnvelope
{
    public StoredFile File { get; set; } = new();
}

// ── Uyga vazifa va baholar ───────────────────────────────────

public sealed class HomeworkStats
{
    public int Total { get; set; }
    public int Submitted { get; set; }
    public int Graded { get; set; }
    public int Late { get; set; }
}

public sealed class MySubmission
{
    /// <summary>PENDING | SUBMITTED | LATE | GRADED | MISSED</summary>
    public string Status { get; set; } = "PENDING";
    public DateTimeOffset? SubmittedAt { get; set; }
    public string? Body { get; set; }
    public string? LinkUrl { get; set; }
    public int? Score { get; set; }
    public string? Feedback { get; set; }
    public DateTimeOffset? GradedAt { get; set; }
    public bool CanSubmit { get; set; }
    public List<StoredFile> Files { get; set; } = [];
}

public sealed class SubmissionRow
{
    public int StudentId { get; set; }
    public string Name { get; set; } = "";
    public bool LeftGroup { get; set; }
    public string Status { get; set; } = "";
    public DateTimeOffset? SubmittedAt { get; set; }
    public string? Body { get; set; }
    public string? LinkUrl { get; set; }
    public int? Score { get; set; }
    public string? Feedback { get; set; }
    public List<StoredFile> Files { get; set; } = [];
}

public sealed class Scale
{
    public int Min { get; set; } = 1;
    public int Max { get; set; } = 5;
}

public sealed class Assignment
{
    public int Id { get; set; }
    public int GroupId { get; set; }
    public string? GroupName { get; set; }
    public string? CourseTitle { get; set; }
    public int? SessionId { get; set; }
    public DateTimeOffset? SessionStartsAt { get; set; }
    public int? LessonId { get; set; }
    public string? LessonTitle { get; set; }
    public string Title { get; set; } = "";
    public string? Body { get; set; }
    public DateTimeOffset? DueAt { get; set; }
    public int MaxScore { get; set; }
    public string? Author { get; set; }
    public DateTimeOffset? CreatedAt { get; set; }
    public List<StoredFile> Files { get; set; } = [];
    public HomeworkStats? Stats { get; set; }
    public MySubmission? My { get; set; }
    public bool CanManage { get; set; }
    public List<SubmissionRow>? Submissions { get; set; }
}

public sealed class AssignmentList
{
    /// <summary>teacher | student</summary>
    public string Mode { get; set; } = "student";
    public List<Assignment> Data { get; set; } = [];
    public Scale Scale { get; set; } = new();
}

public sealed class AssignmentEnvelope
{
    public Assignment Assignment { get; set; } = new();
    public Scale Scale { get; set; } = new();
}

public sealed class MarkRow
{
    /// <summary>session | homework</summary>
    public string Type { get; set; } = "";
    public int? SessionId { get; set; }
    public int? AssignmentId { get; set; }
    public int GroupId { get; set; }
    public string GroupName { get; set; } = "";
    public DateTimeOffset Date { get; set; }
    public string? Title { get; set; }
    public int Score { get; set; }
    public int Max { get; set; }
    public double? Percent { get; set; }
    public string? Note { get; set; }
}

public sealed class MyMarks
{
    public List<MarkRow> Data { get; set; } = [];
    public double? AveragePercent { get; set; }
    public Scale Scale { get; set; } = new();
}

public sealed class SessionMarkRow
{
    public int StudentId { get; set; }
    public string Name { get; set; } = "";
    public int? Score { get; set; }
    public string? Note { get; set; }
}

public sealed class SessionMarks
{
    public bool Enabled { get; set; }
    public Scale Scale { get; set; } = new();
    public List<SessionMarkRow> Rows { get; set; } = [];
    public bool CanEdit { get; set; }
}

// ── Bildirishnomalar ─────────────────────────────────────────

public sealed class NotificationItem
{
    public int Id { get; set; }
    public string Kind { get; set; } = "";
    public string Type { get; set; } = "";
    public string Label { get; set; } = "";
    public string Text { get; set; } = "";
    public string? Url { get; set; }
    public string? Delivery { get; set; }
    public bool Read { get; set; }
    public DateTimeOffset CreatedAt { get; set; }
}

public sealed class NotificationMeta
{
    public int Page { get; set; }
    public int Pages { get; set; }
    public int Total { get; set; }
    public int Unread { get; set; }
    public int NewsUnread { get; set; }
}

public sealed class NotificationPage
{
    public List<NotificationItem> Data { get; set; } = [];
    public NotificationMeta Meta { get; set; } = new();
}

public sealed class NotificationUnread
{
    public int Unread { get; set; }
    public int NewsUnread { get; set; }
    public List<NotificationItem> Latest { get; set; } = [];
}

// ── Audit ────────────────────────────────────────────────────

public sealed class AuditActor
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public string? Role { get; set; }
}

public sealed class AuditDiff
{
    public string Field { get; set; } = "";
    public JsonElement From { get; set; }
    public JsonElement To { get; set; }
}

public sealed class AuditRow
{
    public int Id { get; set; }
    public DateTimeOffset CreatedAt { get; set; }
    public AuditActor? Actor { get; set; }
    public string Action { get; set; } = "";
    public string? ActionLabel { get; set; }
    public string Entity { get; set; } = "";
    public string? EntityLabel { get; set; }
    public int? EntityId { get; set; }
    public string? EntityName { get; set; }
    public string? Ip { get; set; }
    public List<AuditDiff> Diff { get; set; } = [];
    public string? Note { get; set; }
}

public sealed class AuditPage
{
    public List<AuditRow> Data { get; set; } = [];
    public PageMeta Meta { get; set; } = new();
}

public sealed class AuditFacets
{
    public List<string> Actions { get; set; } = [];
    public List<string> Entities { get; set; } = [];
    public List<AuditActor> Actors { get; set; } = [];
    public Dictionary<string, string> ActionLabels { get; set; } = [];
    public Dictionary<string, string> EntityLabels { get; set; } = [];
}

// ── KPI ──────────────────────────────────────────────────────

public sealed class KpiMonth
{
    public string Month { get; set; } = "";
    public bool Partial { get; set; }
    public double? AttendanceRate { get; set; }
    public int ActiveStudents { get; set; }
    public int NewStudents { get; set; }
    public int LeftStudents { get; set; }
    public int GraduatedStudents { get; set; }
    public int FrozenStudents { get; set; }
    public double? ChurnPercent { get; set; }
    public int QuizAttempts { get; set; }
    public double? QuizPassRate { get; set; }
    public double? HomeworkRate { get; set; }
    public double? HomeworkAvgPercent { get; set; }
    public double TeacherHours { get; set; }
    public int Sessions { get; set; }
}

public sealed class KpiTeacher
{
    public int TeacherId { get; set; }
    public string Name { get; set; } = "";
    public Dictionary<string, double> Hours { get; set; } = [];
    public Dictionary<string, int> Sessions { get; set; } = [];
}

public sealed class ReasonCount
{
    public string? Reason { get; set; }
    public int Count { get; set; }
}

public sealed class KpiTargets
{
    public int Attendance { get; set; }
    public int QuizPass { get; set; }
}

public sealed class KpiMoneyMonth
{
    public string Month { get; set; } = "";
    public int Income { get; set; }
    public int Charged { get; set; }
}

public sealed class KpiMoney
{
    public string Currency { get; set; } = "";
    public List<KpiMoneyMonth> Series { get; set; } = [];
    public int DebtTotal { get; set; }
    public int Debtors { get; set; }
}

public sealed class KpiResponse
{
    public List<string> Months { get; set; } = [];
    public List<KpiMonth> Series { get; set; } = [];
    public List<KpiTeacher> Teachers { get; set; } = [];
    public List<ReasonCount> LeaveReasons { get; set; } = [];
    public double? TelegramReachPercent { get; set; }
    public KpiTargets Targets { get; set; } = new();
    public KpiMoney? Money { get; set; }
}

public sealed class HomeworkReportRow
{
    public int GroupId { get; set; }
    public string Group { get; set; } = "";
    public string? Teacher { get; set; }
    public int Assignments { get; set; }
    public int Expected { get; set; }
    public int Submitted { get; set; }
    public int Late { get; set; }
    public double? SubmissionRate { get; set; }
    public double? AvgHomeworkPercent { get; set; }
    public double? AvgMarkPercent { get; set; }
}

public sealed class HomeworkReport
{
    public Period Period { get; set; } = new();
    public List<HomeworkReportRow> Data { get; set; } = [];
}

// ── Tizim holati ─────────────────────────────────────────────

public sealed class SchedulerStatus
{
    public DateTimeOffset? LastTick { get; set; }
    public int? MinutesSince { get; set; }
    public bool Stale { get; set; }
}

public sealed class JobStatus
{
    public string Job { get; set; } = "";
    public int EveryMinutes { get; set; }
    public DateTimeOffset? LastRunAt { get; set; }
    public DateTimeOffset? LastOkAt { get; set; }
    public DateTimeOffset? LastFailedAt { get; set; }
    public string? LastError { get; set; }
    public bool Overdue { get; set; }
}

public sealed class OffsiteStatus
{
    public bool? Ok { get; set; }
    public string? Skipped { get; set; }
    public string? Error { get; set; }
}

public sealed class BackupStatus
{
    public bool EncryptionConfigured { get; set; }
    public DateTimeOffset? At { get; set; }
    public bool? Ok { get; set; }
    public long? Size { get; set; }
    public bool? Encrypted { get; set; }
    public string? Error { get; set; }
    public OffsiteStatus? Offsite { get; set; }
}

public sealed class TelegramStatus
{
    public int Pending { get; set; }
    public int Sent24h { get; set; }
    public int Failed24h { get; set; }
    public int BlockedChats { get; set; }
    public int Linked { get; set; }
    public string? OldestPendingAt { get; set; }
}

public sealed class UsersStatus
{
    public int Pending { get; set; }
    public int NoTelegram { get; set; }
}

public sealed class SystemStatus
{
    public SchedulerStatus Scheduler { get; set; } = new();
    public List<JobStatus> Jobs { get; set; } = [];
    public BackupStatus Backup { get; set; } = new();
    public TelegramStatus Telegram { get; set; } = new();
    public UsersStatus Users { get; set; } = new();
    public List<string> Warnings { get; set; } = [];
    public DateTimeOffset? Time { get; set; }
}

// ── Qidiruv ──────────────────────────────────────────────────

public sealed class SearchHit
{
    public string Type { get; set; } = "";
    /// <summary>int, sertifikat uchun — kod (satr).</summary>
    public JsonElement Id { get; set; }
    public string Title { get; set; } = "";
    public string? Subtitle { get; set; }
    public string? Href { get; set; }
    public bool Active { get; set; } = true;

    [JsonIgnore]
    public int? IntId => Id.ValueKind == JsonValueKind.Number && Id.TryGetInt32(out var n) ? n
        : Id.ValueKind == JsonValueKind.String && int.TryParse(Id.GetString(), out var m) ? m : null;

    [JsonIgnore]
    public string StrId => Id.ValueKind == JsonValueKind.String ? Id.GetString() ?? "" : Id.GetRawText();
}

public sealed class SearchResult
{
    public List<SearchHit> Data { get; set; } = [];
    public int Total { get; set; }
}

// ── O'quvchi profili va a'zolik tarixi ───────────────────────

public sealed class ProfileUser
{
    public int Id { get; set; }
    public string? Phone { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string Role { get; set; } = "";
    public bool Active { get; set; }
    public bool Pending { get; set; }
    public DateTimeOffset? CreatedAt { get; set; }
    public bool Telegram { get; set; }
    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
}

public sealed class ProfileSlot
{
    public int Weekday { get; set; }
    public string Start { get; set; } = "";
    public string End { get; set; } = "";
}

public sealed class ProfileGroup
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public string? Room { get; set; }
    public string? CourseTitle { get; set; }
    public string? Teacher { get; set; }
    public string? JoinedAt { get; set; }
    public string Status { get; set; } = "ACTIVE";
    public string? FrozenUntil { get; set; }
    public List<ProfileSlot> Schedule { get; set; } = [];
    public DateTimeOffset? NextSession { get; set; }
}

public sealed class ProfileRecent
{
    public DateTimeOffset StartsAt { get; set; }
    public string GroupName { get; set; } = "";
    public string? Topic { get; set; }
    public string Status { get; set; } = "";
    public string? Note { get; set; }
}

public sealed class ProfileAttendance
{
    public int Days { get; set; }
    public double? Rate { get; set; }
    public Dictionary<string, int> Counts { get; set; } = [];
    public List<ProfileRecent> Recent { get; set; } = [];
}

public sealed class ProfileQuiz
{
    public int QuizId { get; set; }
    public string Title { get; set; } = "";
    public bool IsFinal { get; set; }
    public string? CourseTitle { get; set; }
    public double? Percent { get; set; }
    public bool Passed { get; set; }
    public DateTimeOffset SubmittedAt { get; set; }
}

public sealed class ProfileCertificate
{
    public string Code { get; set; } = "";
    public string CourseTitle { get; set; } = "";
    public string IssuedAt { get; set; } = "";
}

public sealed class ProfileBalance
{
    public int Balance { get; set; }
    public int Debt { get; set; }
    public string Currency { get; set; } = "";
}

public sealed class StudentOverview
{
    public List<ProfileGroup> Groups { get; set; } = [];
    public ProfileAttendance Attendance { get; set; } = new();
    public List<ProfileQuiz> Quizzes { get; set; } = [];
    public List<ProfileCertificate> Certificates { get; set; } = [];
    public ProfileBalance? Balance { get; set; }
}

public sealed class TimelineRow
{
    public int Id { get; set; }
    public int GroupId { get; set; }
    public string GroupName { get; set; } = "";
    public string? CourseTitle { get; set; }
    public string Status { get; set; } = "";
    public string StartsOn { get; set; } = "";
    public string? EndsOn { get; set; }
    public string? Outcome { get; set; }
    public string? Reason { get; set; }
    public string? Note { get; set; }
    public IdName? TransferGroup { get; set; }
    public string? CreatedBy { get; set; }
}

public sealed class ProfileLead
{
    public int Id { get; set; }
    public string Status { get; set; } = "";
    public string Source { get; set; } = "";
    public DateTimeOffset? CreatedAt { get; set; }
}

public sealed class UserProfile
{
    public ProfileUser User { get; set; } = new();
    public bool CanManage { get; set; }
    public StudentOverview? Overview { get; set; }
    public List<TimelineRow> Timeline { get; set; } = [];
    public List<ProfileLead> Leads { get; set; } = [];
}

public sealed class CurrentMember
{
    public int Id { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string? Phone { get; set; }
    public string? JoinedAt { get; set; }
    public string Status { get; set; } = "ACTIVE";
    public string? Since { get; set; }
    public string? Until { get; set; }
    public string? Reason { get; set; }
    public string? FrozenFrom { get; set; }
    public string? FrozenTo { get; set; }
    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
}

public sealed class FormerMember
{
    public int Id { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string? JoinedAt { get; set; }
    public string? LeftOn { get; set; }
    public string Status { get; set; } = "";
    public string? Reason { get; set; }
    public IdName? TransferGroup { get; set; }
    [JsonIgnore] public string FullName => $"{FirstName} {LastName}".Trim();
}

public sealed class MembersFull
{
    public List<CurrentMember> Current { get; set; } = [];
    public List<FormerMember> Former { get; set; } = [];
}

// ── Import ───────────────────────────────────────────────────

public sealed class ImportSummary
{
    public int Rows { get; set; }
    public int Valid { get; set; }
    public int Errors { get; set; }
    public int NewStudents { get; set; }
    public int ExistingStudents { get; set; }
    public int Memberships { get; set; }
    public int AlreadyMembers { get; set; }
}

public sealed class ImportRow
{
    public int Line { get; set; }
    public string FirstName { get; set; } = "";
    public string? LastName { get; set; }
    public string? Phone { get; set; }
    public string? PhoneRaw { get; set; }
    public string? GroupName { get; set; }
    public string Status { get; set; } = "";
    public string? Action { get; set; }
    public List<string> Errors { get; set; } = [];
    public List<string> Warnings { get; set; } = [];
}

public sealed class ImportResultCounts
{
    public int StudentsCreated { get; set; }
    public int StudentsUpdated { get; set; }
    public int Memberships { get; set; }
    public int Skipped { get; set; }
}

public sealed class ImportPreview
{
    public string? Token { get; set; }
    public ImportSummary Summary { get; set; } = new();
    public List<string> UnknownColumns { get; set; } = [];
    public List<ImportRow> Rows { get; set; } = [];
    public ImportResultCounts? Result { get; set; }
}

// ── Arizalar (leads) ─────────────────────────────────────────

public sealed class LeadCourse
{
    public int Id { get; set; }
    public string Title { get; set; } = "";
}

public sealed class LeadTrial
{
    public int SessionId { get; set; }
    public int GroupId { get; set; }
    public string? GroupName { get; set; }
    public DateTimeOffset StartsAt { get; set; }
    public bool Cancelled { get; set; }
}

public sealed class Lead
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public string Phone { get; set; } = "";
    public string Source { get; set; } = "";
    public string Status { get; set; } = "NEW";
    public string? LostReason { get; set; }
    public LeadCourse? Course { get; set; }
    public IdName? Group { get; set; }
    public IdName? Owner { get; set; }
    public string? NextContactOn { get; set; }
    public LeadTrial? Trial { get; set; }
    public string? Comment { get; set; }
    public string? Notes { get; set; }
    public int? StudentId { get; set; }
    public DateTimeOffset? ConvertedAt { get; set; }
    public bool Telegram { get; set; }
    public DateTimeOffset? CreatedAt { get; set; }
}

public sealed class LeadActivity
{
    public int Id { get; set; }
    /// <summary>SYSTEM | STATUS | CALL | NOTE</summary>
    public string Type { get; set; } = "";
    public string? Text { get; set; }
    public JsonElement Data { get; set; }
    public string? User { get; set; }
    public DateTimeOffset? CreatedAt { get; set; }
}

public sealed class LeadPage
{
    public List<Lead> Data { get; set; } = [];
    public PageMeta Meta { get; set; } = new();
    public Dictionary<string, int> Counts { get; set; } = [];
    public int DueToday { get; set; }
}

public sealed class ExistingUser
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public string Role { get; set; } = "";
}

public sealed class LeadDetail
{
    public Lead Lead { get; set; } = new();
    public List<LeadActivity> Activities { get; set; } = [];
    public ExistingUser? ExistingUser { get; set; }
}

public sealed class LeadOptions
{
    public List<IdName> Data { get; set; } = [];
    public List<LeadCourse> Courses { get; set; } = [];
}

public sealed class TrialSession
{
    public int Id { get; set; }
    public int GroupId { get; set; }
    public string GroupName { get; set; } = "";
    public string? Room { get; set; }
    public string? Teacher { get; set; }
    public DateTimeOffset StartsAt { get; set; }
}

public sealed class FunnelRow
{
    public int Total { get; set; }
    public int Contacted { get; set; }
    public int Trial { get; set; }
    public int Enrolled { get; set; }
    public int Lost { get; set; }
    public double? Conversion { get; set; }
}

public sealed class LeadStats
{
    public FunnelRow Total { get; set; } = new();
}

// ── Sozlash ro'yxati (dashboard/summary.setup) ───────────────

public sealed class SetupStep
{
    public string Key { get; set; } = "";
    public bool Done { get; set; }
    public string? Href { get; set; }
}

public sealed class SetupChecklist
{
    public List<SetupStep> Steps { get; set; } = [];
    public int Done { get; set; }
    public int Total { get; set; }
}
