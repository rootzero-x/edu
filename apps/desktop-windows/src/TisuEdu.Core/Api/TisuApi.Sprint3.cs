using System.Globalization;
using System.Text.Json;
using TisuEdu.Core.Models;

namespace TisuEdu.Core.Api;

/// <summary>Taqvim, xonalar, uyga vazifa, baholar, fayllar, bildirishnomalar, audit, KPI, tizim holati, qidiruv, profil, a'zolik, import, arizalar.</summary>
public sealed partial class TisuApi
{
    private static string? IntStr(int? v) => v?.ToString(CultureInfo.InvariantCulture);

    // ── Taqvim va xonalar ────────────────────────────────────
    public Task<Fetched<CalendarResponse>> CalendarAsync(DateOnly from, DateOnly to, int? teacherId, int? roomId, int? groupId, CancellationToken ct = default) =>
        Http.GetCachedAsync<CalendarResponse>("calendar" + Query(("from", Date(from)), ("to", Date(to)),
            ("teacher_id", IntStr(teacherId)), ("room_id", IntStr(roomId)), ("group_id", IntStr(groupId))), ct);

    public async Task<List<Room>> RoomsAsync(bool all = true, CancellationToken ct = default) =>
        (await Http.GetAsync<DataList<Room>>("rooms" + (all ? "?all=1" : ""), ct)).Data;
    public Task<JsonElement> CreateRoomAsync(object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>("rooms", body, ct);
    public Task<JsonElement> UpdateRoomAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"rooms/{id}", body, ct);
    public Task<JsonElement> DeleteRoomAsync(int id, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"rooms/{id}", null, ct);

    // ── Uyga vazifa va baholar ───────────────────────────────
    public Task<Fetched<AssignmentList>> AssignmentsAsync(string status, int? groupId = null, CancellationToken ct = default) =>
        Http.GetCachedAsync<AssignmentList>("assignments" + Query(("status", status), ("group_id", IntStr(groupId))), ct);
    public Task<AssignmentEnvelope> AssignmentAsync(int id, CancellationToken ct = default) => Http.GetAsync<AssignmentEnvelope>($"assignments/{id}", ct);
    public Task<AssignmentEnvelope> CreateAssignmentAsync(int groupId, object body, CancellationToken ct = default) => Http.PostAsync<AssignmentEnvelope>($"groups/{groupId}/assignments", body, ct);
    public Task<JsonElement> UpdateAssignmentAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"assignments/{id}", body, ct);
    public Task<JsonElement> DeleteAssignmentAsync(int id, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"assignments/{id}", null, ct);
    public Task<FileEnvelope> UploadAssignmentFileAsync(int id, string name, byte[] bytes, string mime, CancellationToken ct = default) =>
        Http.UploadAsync<FileEnvelope>($"assignments/{id}/files", name, bytes, mime, null, ct);
    public Task<JsonElement> GradeAsync(int id, int studentId, int? score, string? feedback, CancellationToken ct = default) =>
        Http.PutAsync<JsonElement>($"assignments/{id}/grades", new { items = new[] { new { student_id = studentId, score, feedback } } }, ct);
    public Task<JsonElement> SubmitAssignmentAsync(int id, string? body, string? linkUrl, CancellationToken ct = default) =>
        Http.PutAsync<JsonElement>($"assignments/{id}/submission", new { body, link_url = linkUrl }, ct);
    public Task<FileEnvelope> UploadSubmissionFileAsync(int id, string name, byte[] bytes, string mime, CancellationToken ct = default) =>
        Http.UploadAsync<FileEnvelope>($"assignments/{id}/submission/files", name, bytes, mime, null, ct);
    public Task<Fetched<MyMarks>> MyMarksAsync(CancellationToken ct = default) => Http.GetCachedAsync<MyMarks>("marks/me", ct);
    public Task<SessionMarks> SessionMarksAsync(int sessionId, CancellationToken ct = default) => Http.GetAsync<SessionMarks>($"sessions/{sessionId}/marks", ct);
    public Task<JsonElement> SaveSessionMarksAsync(int sessionId, IEnumerable<object> items, CancellationToken ct = default) =>
        Http.PutAsync<JsonElement>($"sessions/{sessionId}/marks", new { items }, ct);

    // ── Fayllar ──────────────────────────────────────────────
    public Task<FileLink> FileLinkAsync(int fileId, CancellationToken ct = default) => Http.GetAsync<FileLink>($"files/{fileId}/link", ct);
    public Task<JsonElement> DeleteFileAsync(int fileId, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"files/{fileId}", null, ct);
    public Task<JsonElement> UploadMaterialAsync(int lessonId, string name, byte[] bytes, string mime, string? title, CancellationToken ct = default) =>
        Http.UploadAsync<JsonElement>($"lessons/{lessonId}/materials/upload", name, bytes, mime,
            title is null ? null : new Dictionary<string, string> { ["title"] = title }, ct);

    // ── Bildirishnomalar ─────────────────────────────────────
    public Task<Fetched<NotificationPage>> NotificationsAsync(bool unreadOnly, int page, CancellationToken ct = default) =>
        Http.GetCachedAsync<NotificationPage>("notifications" + Query(("unread", unreadOnly ? "1" : null), ("page", Num(page))), ct);
    public Task<NotificationUnread> NotificationsUnreadAsync(CancellationToken ct = default) => Http.GetAsync<NotificationUnread>("notifications/unread", ct);
    public Task<JsonElement> MarkNotificationsReadAsync(IEnumerable<int>? ids, CancellationToken ct = default) =>
        ids is null ? Http.PostAsync<JsonElement>("notifications/read", new { all = true }, ct)
                    : Http.PostAsync<JsonElement>("notifications/read", new { ids = ids.ToArray() }, ct);

    // ── Audit, KPI, tizim holati ─────────────────────────────
    public Task<AuditPage> AuditAsync(string? action, string? entity, int? actorId, DateOnly? from, DateOnly? to, int page, CancellationToken ct = default) =>
        Http.GetAsync<AuditPage>("audit" + Query(("action", action), ("entity", entity), ("actor_id", IntStr(actorId)),
            ("from", Date(from)), ("to", Date(to)), ("page", Num(page))), ct);
    public Task<AuditFacets> AuditFacetsAsync(CancellationToken ct = default) => Http.GetAsync<AuditFacets>("audit/facets", ct);
    public Task<Fetched<KpiResponse>> KpiAsync(int months, int? groupId, CancellationToken ct = default) =>
        Http.GetCachedAsync<KpiResponse>("reports/kpi" + Query(("months", Num(months)), ("group_id", IntStr(groupId))), ct);
    public Task<Fetched<HomeworkReport>> HomeworkReportAsync(ReportFilter f, CancellationToken ct = default) =>
        Http.GetCachedAsync<HomeworkReport>("reports/homework" + f.ToQuery(), ct);
    public Task<SystemStatus> SystemStatusAsync(CancellationToken ct = default) => Http.GetAsync<SystemStatus>("system/status", ct);

    // ── Qidiruv va profil ────────────────────────────────────
    public Task<SearchResult> SearchAsync(string q, CancellationToken ct = default) => Http.GetAsync<SearchResult>("search" + Query(("q", q)), ct);
    public Task<Fetched<UserProfile>> UserProfileAsync(int userId, CancellationToken ct = default) => Http.GetCachedAsync<UserProfile>($"users/{userId}/profile", ct);

    // ── Guruh a'zoligi ───────────────────────────────────────
    public Task<MembersFull> MembersFullAsync(int groupId, CancellationToken ct = default) => Http.GetAsync<MembersFull>($"groups/{groupId}/members", ct);
    public Task<JsonElement> FreezeMemberAsync(int groupId, int studentId, object body, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"groups/{groupId}/members/{studentId}/freeze", body, ct);
    public Task<JsonElement> UnfreezeMemberAsync(int groupId, int studentId, string? date, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"groups/{groupId}/members/{studentId}/unfreeze", new { date }, ct);
    public Task<JsonElement> TransferMemberAsync(int groupId, int studentId, object body, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"groups/{groupId}/members/{studentId}/transfer", body, ct);
    public Task<JsonElement> GraduateMemberAsync(int groupId, int studentId, object body, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"groups/{groupId}/members/{studentId}/graduate", body, ct);
    public Task<JsonElement> LeaveMemberFullAsync(int groupId, int studentId, object body, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"groups/{groupId}/members/{studentId}/leave", body, ct);
    public Task<JsonElement> GraduateGroupAsync(int groupId, string? date, CancellationToken ct = default) =>
        Http.PostAsync<JsonElement>($"groups/{groupId}/graduate", new { date }, ct);

    // ── Import ───────────────────────────────────────────────
    public Task<byte[]> ImportTemplateAsync(string format, CancellationToken ct = default) => Http.GetBytesAsync($"imports/students/template?format={format}", ct);
    public Task<ImportPreview> ImportPreviewAsync(string fileName, byte[] content, int? groupId, CancellationToken ct = default) =>
        Http.PostAsync<ImportPreview>("imports/students/preview", new { filename = fileName, content = Convert.ToBase64String(content), group_id = groupId }, ct);
    public Task<ImportPreview> ImportCommitAsync(string token, bool skipInvalid, CancellationToken ct = default) =>
        Http.PostAsync<ImportPreview>("imports/students/commit", new { token, skip_invalid = skipInvalid }, ct);

    // ── Arizalar ─────────────────────────────────────────────
    public Task<LeadPage> LeadsAsync(string? status, string? q, string? due, int page, CancellationToken ct = default) =>
        Http.GetAsync<LeadPage>("leads" + Query(("status", status), ("q", q), ("due", due), ("page", Num(page))), ct);
    public Task<LeadDetail> LeadAsync(int id, CancellationToken ct = default) => Http.GetAsync<LeadDetail>($"leads/{id}", ct);
    public Task<JsonElement> CreateLeadAsync(object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>("leads", body, ct);
    public Task<JsonElement> UpdateLeadAsync(int id, object body, CancellationToken ct = default) => Http.PatchAsync<JsonElement>($"leads/{id}", body, ct);
    public Task<JsonElement> DeleteLeadAsync(int id, CancellationToken ct = default) => Http.DeleteAsync<JsonElement>($"leads/{id}", null, ct);
    public Task<JsonElement> LeadStatusAsync(int id, object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>($"leads/{id}/status", body, ct);
    public Task<JsonElement> LeadActivityAsync(int id, object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>($"leads/{id}/activities", body, ct);
    public Task<JsonElement> ConvertLeadAsync(int id, object body, CancellationToken ct = default) => Http.PostAsync<JsonElement>($"leads/{id}/convert", body, ct);
    public Task<LeadOptions> LeadOptionsAsync(CancellationToken ct = default) => Http.GetAsync<LeadOptions>("leads/options", ct);
    public async Task<List<TrialSession>> TrialSessionsAsync(int? courseId, CancellationToken ct = default) =>
        (await Http.GetAsync<DataList<TrialSession>>("leads/trial-sessions" + Query(("course_id", IntStr(courseId))), ct)).Data;
    public Task<LeadStats> LeadStatsAsync(CancellationToken ct = default) => Http.GetAsync<LeadStats>("leads/stats", ct);
}
