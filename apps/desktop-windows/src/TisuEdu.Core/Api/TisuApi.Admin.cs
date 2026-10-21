using System.Globalization;
using System.Text.Json;
using TisuEdu.Core.Models;

namespace TisuEdu.Core.Api;

/// <summary>Boshqaruv endpointlari: kontent tahrirlash, guruhlar, e'lonlar, sertifikatlar, foydalanuvchilar, sozlamalar, to'lovlar.</summary>
public sealed partial class TisuApi
{
    private static string Esc(string s) => Uri.EscapeDataString(s);

    private static string Num(int v) => v.ToString(CultureInfo.InvariantCulture);

    // ── Kurslar, modullar, darslar, materiallar ──────────────
    public Task<JsonElement> CreateCourseAsync(object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>("courses", body, ct);
    public Task<JsonElement> UpdateCourseAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"courses/{id}", body, ct);
    public Task<JsonElement> DeleteCourseAsync(int id, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"courses/{id}", null, ct);

    public Task<JsonElement> CreateModuleAsync(int courseId, object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>($"courses/{courseId}/modules", body, ct);
    public Task<JsonElement> UpdateModuleAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"modules/{id}", body, ct);
    public Task<JsonElement> DeleteModuleAsync(int id, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"modules/{id}", null, ct);

    public Task<JsonElement> CreateLessonAsync(int moduleId, object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>($"modules/{moduleId}/lessons", body, ct);
    public Task<JsonElement> UpdateLessonAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"lessons/{id}", body, ct);
    public Task<JsonElement> DeleteLessonAsync(int id, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"lessons/{id}", null, ct);

    public Task<JsonElement> CreateMaterialAsync(int lessonId, object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>($"lessons/{lessonId}/materials", body, ct);
    public Task<JsonElement> UpdateMaterialAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"materials/{id}", body, ct);
    public Task<JsonElement> DeleteMaterialAsync(int id, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"materials/{id}", null, ct);

    // ── Testlar va savollar ──────────────────────────────────
    public Task<JsonElement> CreateQuizAsync(int courseId, object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>($"courses/{courseId}/quizzes", body, ct);
    public Task<JsonElement> UpdateQuizAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"quizzes/{id}", body, ct);
    public Task<JsonElement> DeleteQuizAsync(int id, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"quizzes/{id}", null, ct);
    public Task<JsonElement> CreateQuestionAsync(int quizId, object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>($"quizzes/{quizId}/questions", body, ct);
    public Task<JsonElement> UpdateQuestionAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"questions/{id}", body, ct);
    public Task<JsonElement> DeleteQuestionAsync(int id, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"questions/{id}", null, ct);

    // ── E'lonlar ─────────────────────────────────────────────
    public Task<Fetched<AnnouncementPage>> ManagedAnnouncementsAsync(int page = 1, CancellationToken ct = default) =>
        Http.GetCachedAsync<AnnouncementPage>($"announcements?scope=manage&page={page}", ct);
    public Task<JsonElement> CreateAnnouncementAsync(object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>("announcements", body, ct);
    public Task<JsonElement> UpdateAnnouncementAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"announcements/{id}", body, ct);
    public Task<JsonElement> PublishAnnouncementAsync(int id, bool published, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"announcements/{id}/publish", new { published }, ct);
    public Task<JsonElement> DeleteAnnouncementAsync(int id, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"announcements/{id}", null, ct);

    // ── Guruhlarni boshqarish ────────────────────────────────
    public async Task<List<TeacherRow>> TeachersAsync(CancellationToken ct = default) => (await Http.GetAsync<DataList<TeacherRow>>("groups/teachers", ct)).Data;
    public Task<JsonElement> CreateGroupAsync(object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>("groups", body, ct);
    public Task<JsonElement> UpdateGroupAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"groups/{id}", body, ct);
    public Task<MembersResponse> MembersAsync(int groupId, CancellationToken ct = default) => Http.GetAsync<MembersResponse>($"groups/{groupId}/members", ct);
    public Task<JsonElement> AddMemberAsync(int groupId, int studentId, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"groups/{groupId}/members", new { student_id = studentId }, ct);
    public Task<JsonElement> LeaveMemberAsync(int groupId, int studentId, string? reason, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"groups/{groupId}/members/{studentId}/leave", new { reason }, ct);
    public Task<JsonElement> GenerateSessionsAsync(int groupId, CancellationToken ct = default) => Http.PostAsync<JsonElement>($"groups/{groupId}/sessions/generate", null, ct);
    public Task<JsonElement> CancelSessionAsync(int sessionId, bool cancelled, CancellationToken ct = default) =>
        Http.PatchAsync<JsonElement>($"sessions/{sessionId}", new { cancelled }, ct);

    // ── Sertifikat berish ────────────────────────────────────
    public async Task<List<CertificateCandidate>> CertificateCandidatesAsync(int groupId, CancellationToken ct = default) =>
        (await Http.GetAsync<DataList<CertificateCandidate>>($"groups/{groupId}/certificates", ct)).Data;
    public Task<IssueResult> IssueCertificatesAsync(int groupId, CancellationToken ct = default) => Http.PostAsync<IssueResult>($"groups/{groupId}/certificates", null, ct);
    public Task<JsonElement> RevokeCertificateAsync(string code, string? reason, CancellationToken ct = default) =>
        Http.DeleteAsync<JsonElement>($"certificates/{Esc(code)}", new { reason }, ct);

    // ── Foydalanuvchilar ─────────────────────────────────────
    public Task<UserPage> UsersAsync(string? q, string? role, bool? pending, int page, CancellationToken ct = default) =>
        Http.GetAsync<UserPage>("users" + Query(("q", q), ("role", role), ("pending", pending is null ? null : pending.Value ? "1" : "0"), ("page", Num(page))), ct);
    public async Task<UserRow> CreateUserAsync(object body, CancellationToken ct = default) => (await Http.PostAsync<UserEnvelope>("users", body, ct)).User;
    public async Task<UserRow> UpdateUserAsync(int id, object body, CancellationToken ct = default) => (await Http.PatchAsync<UserEnvelope>($"users/{id}", body, ct)).User;
    public Task<JsonElement> ApproveUserAsync(int id, CancellationToken ct = default) => Http.PostAsync<JsonElement>($"users/{id}/approve", null, ct);

    // ── Tizim sozlamalari ────────────────────────────────────
    public Task<SettingsIndex> AdminSettingsAsync(CancellationToken ct = default) => Http.GetAsync<SettingsIndex>("settings", ct);
    public Task<JsonElement> UpdateSettingAsync(string key, JsonElement value, CancellationToken ct = default) =>
        Http.PatchAsync<JsonElement>("settings", new { values = new Dictionary<string, JsonElement> { [key] = value } }, ct);
    public Task<JsonElement> ResetSettingAsync(string key, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"settings/{Esc(key)}", null, ct);

    // ── To'lovlar ────────────────────────────────────────────
    public Task<Fetched<BillingMe>> BillingMeAsync(CancellationToken ct = default) => Http.GetCachedAsync<BillingMe>("billing/me", ct);
    public Task<BalancePage> BalancesAsync(string? q, string? status, string? sort, int page, CancellationToken ct = default) =>
        Http.GetAsync<BalancePage>("billing/balances" + Query(("q", q), ("status", status), ("sort", sort), ("page", Num(page))), ct);
    public Task<byte[]> BalancesCsvAsync(string? q, string? status, string? sort, CancellationToken ct = default) =>
        Http.GetBytesAsync("billing/balances.csv" + Query(("q", q), ("status", status), ("sort", sort)), ct);
    public Task<BillingStudent> BillingStudentAsync(int studentId, int page = 1, CancellationToken ct = default) =>
        Http.GetAsync<BillingStudent>($"billing/students/{studentId}?page={page}", ct);
    public Task<BillingReport> BillingReportAsync(DateOnly? from, DateOnly? to, CancellationToken ct = default) =>
        Http.GetAsync<BillingReport>("billing/report" + Query(("from", Date(from)), ("to", Date(to))), ct);
    public Task<byte[]> BillingEntriesCsvAsync(DateOnly? from, DateOnly? to, CancellationToken ct = default) =>
        Http.GetBytesAsync("billing/entries.csv" + Query(("from", Date(from)), ("to", Date(to))), ct);
    public Task<JsonElement> AddPaymentAsync(int studentId, int amount, string method, string date, string? note, string requestId, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"billing/students/{studentId}/payments", new { amount, method, date, note, request_id = requestId }, ct);
    public Task<JsonElement> AddAdjustmentAsync(int studentId, int amount, string reason, string date, string requestId, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"billing/students/{studentId}/adjustments", new { amount, reason, date, request_id = requestId }, ct);
    public Task<JsonElement> VoidEntryAsync(int entryId, string reason, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"billing/entries/{entryId}/void", new { reason }, ct);
}
