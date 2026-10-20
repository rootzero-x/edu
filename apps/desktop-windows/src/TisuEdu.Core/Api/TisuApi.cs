using System.Globalization;
using System.Text.Json;
using TisuEdu.Core.Models;

namespace TisuEdu.Core.Api;

/// <summary>/v1 endpointlari (apps/backend/routes/api.php) uchun tiplangan qobiq.</summary>
public sealed class TisuApi(ApiClient http)
{
    public ApiClient Http { get; } = http;

    // ── Auth ─────────────────────────────────────────────────
    public Task<LoginResponse> LoginAsync(string phone, string password, string device, CancellationToken ct = default) =>
        Http.PostAsync<LoginResponse>("auth/login", new { phone, password, device }, ct);

    public Task<TelegramStart> TelegramStartAsync(CancellationToken ct = default) =>
        Http.PostAsync<TelegramStart>("auth/telegram/start", null, ct);

    public Task<TelegramPoll> TelegramPollAsync(string code, string pollSecret, CancellationToken ct = default) =>
        Http.PostAsync<TelegramPoll>("auth/telegram/poll", new { code, poll_secret = pollSecret }, ct);

    public async Task<User> MeAsync(CancellationToken ct = default) => (await Http.GetAsync<MeResponse>("auth/me", ct)).User;

    public async Task<User> UpdateMeAsync(string firstName, string? lastName, CancellationToken ct = default) =>
        (await Http.PatchAsync<MeResponse>("auth/me", new { first_name = firstName, last_name = lastName }, ct)).User;

    public Task LogoutAsync(CancellationToken ct = default) => Http.PostAsync<JsonElement>("auth/logout", null, ct);

    public Task ChangePasswordAsync(string? current, string next, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>("auth/password", new { current_password = current, new_password = next }, ct);

    // ── Ochiq ────────────────────────────────────────────────
    public Task<JsonElement> PublicSettingsAsync(CancellationToken ct = default) =>
        Http.GetAsync<JsonElement>("public/settings", ct);

    // ── Bosh panel ───────────────────────────────────────────
    public Task<Fetched<DashboardSummary>> DashboardAsync(CancellationToken ct = default) =>
        Http.GetCachedAsync<DashboardSummary>("dashboard/summary", ct);

    // ── Kurslar ──────────────────────────────────────────────
    public Task<Fetched<DataList<Course>>> CoursesAsync(CancellationToken ct = default) =>
        Http.GetCachedAsync<DataList<Course>>("courses", ct);

    public Task<Fetched<CourseDetail>> CourseAsync(int id, CancellationToken ct = default) =>
        Http.GetCachedAsync<CourseDetail>($"courses/{id}", ct);

    public Task<Fetched<LessonDetail>> LessonAsync(int id, CancellationToken ct = default) =>
        Http.GetCachedAsync<LessonDetail>($"lessons/{id}", ct);

    // ── Testlar ──────────────────────────────────────────────
    public Task<Fetched<QuizList>> CourseQuizzesAsync(int courseId, CancellationToken ct = default) =>
        Http.GetCachedAsync<QuizList>($"courses/{courseId}/quizzes", ct);

    public Task<QuizDetail> QuizAsync(int id, CancellationToken ct = default) =>
        Http.GetAsync<QuizDetail>($"quizzes/{id}", ct);

    public async Task<Attempt> StartAttemptAsync(int quizId, CancellationToken ct = default) =>
        (await Http.PostAsync<AttemptEnvelope>($"quizzes/{quizId}/attempts", null, ct)).Attempt;

    public Task<SubmitResponse> SubmitAttemptAsync(int attemptId, IReadOnlyDictionary<string, List<string>> answers, CancellationToken ct = default) =>
        Http.PostAsync<SubmitResponse>($"attempts/{attemptId}/submit", new { answers }, ct);

    public Task<DataList<QuizResultRow>> QuizResultsAsync(int quizId, CancellationToken ct = default) =>
        Http.GetAsync<DataList<QuizResultRow>>($"quizzes/{quizId}/results", ct);

    // ── Guruhlar va davomat ──────────────────────────────────
    public Task<Fetched<DataList<Group>>> GroupsAsync(CancellationToken ct = default) =>
        Http.GetCachedAsync<DataList<Group>>("groups", ct);

    public async Task<Fetched<Group>> GroupAsync(int id, CancellationToken ct = default)
    {
        var r = await Http.GetCachedAsync<GroupEnvelope>($"groups/{id}", ct);
        return new Fetched<Group>(r.Value.Group, r.FromCache, r.SavedAt);
    }

    public Task<Fetched<DataList<ClassSession>>> GroupSessionsAsync(int groupId, DateOnly? from = null, DateOnly? to = null, CancellationToken ct = default) =>
        Http.GetCachedAsync<DataList<ClassSession>>($"groups/{groupId}/sessions{Query(("from", Date(from)), ("to", Date(to)))}", ct);

    public Task<Fetched<DataList<StudentAttendance>>> GroupAttendanceAsync(int groupId, CancellationToken ct = default) =>
        Http.GetCachedAsync<DataList<StudentAttendance>>($"groups/{groupId}/attendance", ct);

    public Task<AttendanceSheet> SheetAsync(int sessionId, CancellationToken ct = default) =>
        Http.GetAsync<AttendanceSheet>($"sessions/{sessionId}/attendance", ct);

    public Task<MarkResult> MarkAsync(int sessionId, MarkRequest request, CancellationToken ct = default) =>
        Http.PutAsync<MarkResult>($"sessions/{sessionId}/attendance", request, ct);

    public Task<DataList<AttendanceHistoryItem>> SheetHistoryAsync(int sessionId, CancellationToken ct = default) =>
        Http.GetAsync<DataList<AttendanceHistoryItem>>($"sessions/{sessionId}/attendance/history", ct);

    public Task<Fetched<DataList<UnmarkedSession>>> UnmarkedAsync(CancellationToken ct = default) =>
        Http.GetCachedAsync<DataList<UnmarkedSession>>("attendance/unmarked", ct);

    public Task<JsonElement> UpdateSessionTopicAsync(int sessionId, string? topic, CancellationToken ct = default) =>
        Http.PatchAsync<JsonElement>($"sessions/{sessionId}", new { topic }, ct);

    // ── Sertifikatlar, yangiliklar ───────────────────────────
    public Task<Fetched<DataList<Certificate>>> CertificatesAsync(CancellationToken ct = default) =>
        Http.GetCachedAsync<DataList<Certificate>>("certificates", ct);

    public Task<Fetched<AnnouncementPage>> AnnouncementsAsync(int page = 1, CancellationToken ct = default) =>
        Http.GetCachedAsync<AnnouncementPage>($"announcements?page={page}", ct);

    public async Task<int> UnreadAsync(CancellationToken ct = default) =>
        (await Http.GetAsync<UnreadCount>("announcements/unread", ct)).Unread;

    public async Task<int> MarkReadAsync(int id, CancellationToken ct = default) =>
        (await Http.PostAsync<UnreadCount>($"announcements/{id}/read", null, ct)).Unread;

    // ── Hisobotlar ───────────────────────────────────────────
    public Task<Fetched<ReportOverview>> ReportOverviewAsync(ReportFilter f, CancellationToken ct = default) =>
        Http.GetCachedAsync<ReportOverview>("reports/overview" + f.ToQuery(), ct);

    public Task<Fetched<AttendanceReport>> AttendanceReportAsync(ReportFilter f, CancellationToken ct = default) =>
        Http.GetCachedAsync<AttendanceReport>("reports/attendance" + f.ToQuery(), ct);

    public Task<Fetched<QuizReport>> QuizReportAsync(ReportFilter f, CancellationToken ct = default) =>
        Http.GetCachedAsync<QuizReport>("reports/quizzes" + f.ToQuery(), ct);

    /// <summary>Server tayyorlagan CSV (UTF-8 BOM, ";" ajratuvchi, formula-injection'dan himoyalangan).</summary>
    public Task<byte[]> AttendanceCsvAsync(ReportFilter f, CancellationToken ct = default) =>
        Http.GetBytesAsync("reports/attendance.csv" + f.ToQuery(), ct);

    // ── Yordamchilar ─────────────────────────────────────────
    private sealed class GroupEnvelope
    {
        public Group Group { get; set; } = new();
    }

    internal static string? Date(DateOnly? d) => d?.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);

    internal static string Query(params (string Key, string? Value)[] parts)
    {
        var q = string.Join("&", parts.Where(p => !string.IsNullOrEmpty(p.Value)).Select(p => $"{p.Key}={Uri.EscapeDataString(p.Value!)}"));
        return q.Length == 0 ? "" : "?" + q;
    }
}

public sealed record ReportFilter(DateOnly? From = null, DateOnly? To = null, int? GroupId = null)
{
    public string ToQuery() => TisuApi.Query(
        ("from", TisuApi.Date(From)), ("to", TisuApi.Date(To)),
        ("group_id", GroupId?.ToString(CultureInfo.InvariantCulture)));
}
