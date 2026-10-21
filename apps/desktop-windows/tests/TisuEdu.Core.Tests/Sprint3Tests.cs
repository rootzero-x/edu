using System.Net;
using System.Text;
using TisuEdu.Core.Api;
using TisuEdu.Core.Services;
using TisuEdu.Core.ViewModels;
using Xunit;

namespace TisuEdu.Core.Tests;

public class Sprint3Tests
{
    [Theory]
    [InlineData("/dashboard/homework/12", Page.Assignment, 12)]
    [InlineData("/dashboard/homework?tab=marks", Page.Homework, "marks")]
    [InlineData("/dashboard/sessions/7", Page.Sheet, 7)]
    [InlineData("/dashboard/leads?id=5", Page.Lead, 5)]
    [InlineData("/dashboard/users/3", Page.StudentProfile, 3)]
    [InlineData("/dashboard", Page.Dashboard, null)]
    [InlineData("https://tisu.uzstudents.uz/dashboard/calendar", Page.Calendar, null)]
    public void Links_map_web_routes_to_pages(string href, Page page, object? param)
    {
        var t = Links.Map(href);
        Assert.NotNull(t);
        Assert.Equal(page, t!.Value.Page);
        Assert.Equal(param, t.Value.Parameter);
    }

    [Fact]
    public void Unknown_links_are_null() => Assert.Null(Links.Map("/c/TISU-1"));

    [Fact]
    public void Upload_validation_uses_public_settings()
    {
        var st = SiteSettings.Empty;
        Assert.Null(Uploads.Validate(new PickedFile("a.pdf", new byte[100], "application/pdf"), st));
        Assert.Contains(".exe", Uploads.Validate(new PickedFile("a.exe", new byte[100], ""), st));
        Assert.Contains("katta", Uploads.Validate(new PickedFile("big.pdf", new byte[11 * 1024 * 1024], ""), st));
    }

    [Fact]
    public void Assignment_due_is_sent_with_server_offset()
    {
        ServerClock.SetZone("Asia/Tashkent");
        var (body, error) = AssignmentForms.Body(new FormValues(new Dictionary<string, string>
        {
            ["title"] = "Mashq", ["body"] = "", ["due_date"] = "2026-10-25", ["due_time"] = "9:30", ["max_score"] = "",
        }));
        Assert.Null(error);
        Assert.Equal("2026-10-25T09:30:00+05:00", body!["due_at"]);
        Assert.False(body.ContainsKey("max_score"));
    }

    [Fact]
    public void Calendar_week_range_starts_on_monday()
    {
        using var app = new TestApp();
        var vm = new CalendarViewModel(app.Services);
        var (from, to) = vm.Range();
        Assert.Equal(DayOfWeek.Monday, from.DayOfWeek);
        Assert.Equal(6, (to - from).Days);
    }

    [Fact]
    public async Task Watcher_uses_inbox_and_toasts_new_items()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "notifications/unread", HttpStatusCode.OK, """{"unread":1,"news_unread":0,"latest":[{"id":10,"kind":"homework.new:1","type":"homework","label":"Uyga vazifa","text":"Yangi","read":false,"created_at":"2026-10-21T10:00:00+05:00"}]}""")
                   .On("GET", "notifications/unread", HttpStatusCode.OK, """{"unread":2,"news_unread":1,"latest":[{"id":11,"kind":"mark:5","type":"mark","label":"Baho","text":"5","read":false,"created_at":"2026-10-21T11:00:00+05:00"},{"id":10,"kind":"homework.new:1","type":"homework","label":"Uyga vazifa","text":"Yangi","read":false,"created_at":"2026-10-21T10:00:00+05:00"}]}""");
        app.Watcher.Reset();
        await app.Watcher.CheckNowAsync();
        Assert.Empty(app.Notifier.ShownItems);
        await app.Watcher.CheckNowAsync();
        Assert.Equal(11, app.Notifier.ShownItems.Single().Id);
        Assert.Equal(2, app.Watcher.InboxUnread);
        Assert.Equal(1, app.Watcher.Unread);
    }

    [Fact]
    public async Task Homework_student_attaches_file_and_submits()
    {
        using var app = new TestApp();
        app.SignIn();
        const string a = """
            {"assignment":{"id":4,"group_id":2,"title":"Mashq","max_score":5,"files":[],"can_manage":false,
             "my":{"status":"PENDING","can_submit":true,"files":[]}},"scale":{"min":1,"max":5}}
            """;
        app.Handler.On("GET", "assignments/4", HttpStatusCode.OK, a)
                   .On("POST", "assignments/4/submission/files", HttpStatusCode.Created, """{"file":{"id":9,"name":"javob.pdf","mime":"application/pdf","size":10}}""")
                   .On("PUT", "assignments/4/submission", HttpStatusCode.OK, """{"my":{"status":"SUBMITTED","can_submit":true}}""");
        var vm = new AssignmentViewModel(app.Services, 4);
        await vm.RefreshAsync();
        Assert.True(vm.IsStudent);
        Assert.True(vm.CanAttach);

        app.Platform.NextFile = new PickedFile("javob.pdf", Encoding.UTF8.GetBytes("%PDF-1.4"), "application/pdf");
        await vm.AttachCommand.ExecuteAsync(null);
        Assert.Contains(app.Handler.Requests, r => r.Path == "assignments/4/submission/files" && r.Method == "POST");

        app.Forms.Answers.Enqueue(new() { ["body"] = "Tayyor" });
        await vm.SubmitCommand.ExecuteAsync(null);
        Assert.Contains("\"body\":\"Tayyor\"", app.Handler.Requests.Single(r => r.Method == "PUT").Body);
    }

    [Fact]
    public async Task Freezing_member_posts_dates_and_reason()
    {
        using var app = new TestApp();
        app.SignIn();
        var vm = new GroupViewModel(app.Services, 2);
        var row = new StudentRow(11, "Vali", null, null, SiteSettings.Empty) { CanManage = true, Status = "ACTIVE" };
        app.Handler.On("POST", "groups/2/members/11/freeze", HttpStatusCode.OK, """{"ok":true,"id":1,"days":14,"refunded":0}""");
        app.Forms.Answers.Enqueue(new() { ["action"] = "freeze" });
        app.Forms.Answers.Enqueue(new() { ["from"] = "2026-10-21", ["to"] = "2026-11-04", ["reason"] = "Kasallik" });
        await vm.ManageMemberCommand.ExecuteAsync(row);
        var body = app.Handler.Requests.Single(r => r.Method == "POST").Body!;
        Assert.Contains("\"from\":\"2026-10-21\"", body);
        Assert.Contains("\"to\":\"2026-11-04\"", body);
        Assert.Contains("Kasallik", body);
    }

    [Fact]
    public async Task Import_sends_base64_and_commits_with_token()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "groups", HttpStatusCode.OK, """{"data":[]}""")
                   .On("POST", "imports/students/preview", HttpStatusCode.OK,
                       """{"token":"0123456789012345678901234567890123456789","summary":{"rows":2,"valid":1,"errors":1,"new_students":1},"unknown_columns":[],"rows":[{"line":3,"first_name":"X","phone_raw":"12","status":"error","errors":["Telefon noto'g'ri"]}]}""")
                   .On("POST", "imports/students/commit", HttpStatusCode.OK, """{"result":{"students_created":1,"students_updated":0,"memberships":0,"skipped":1},"summary":{"rows":2}}""")
                   .On("GET", "users?page=1", HttpStatusCode.OK, """{"data":[],"meta":{"page":1,"pages":1,"total":0,"pending":0}}""");
        var vm = new UsersViewModel(app.Services);
        app.Platform.NextFile = new PickedFile("talabalar.csv", Encoding.UTF8.GetBytes("Ism;Telefon\nAli;+998901112233"), "text/csv");
        app.Forms.Answers.Enqueue(new());
        await vm.ImportCommand.ExecuteAsync(null);
        var preview = app.Handler.Requests.Single(r => r.Path == "imports/students/preview").Body!;
        Assert.Contains("\"filename\":\"talabalar.csv\"", preview);
        Assert.Contains("\"content\":\"" + Convert.ToBase64String(Encoding.UTF8.GetBytes("Ism;Telefon\nAli;+998901112233")).Replace("+", "\\u002B"), preview);
        Assert.Contains("\"skip_invalid\":true", app.Handler.Requests.Single(r => r.Path == "imports/students/commit").Body);
        Assert.Contains("yangi 1", vm.Notice);
    }

    [Fact]
    public async Task Lead_convert_navigates_to_student_profile()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "leads/5", HttpStatusCode.OK, """{"lead":{"id":5,"name":"Ali Valiyev","phone":"+998901112233","source":"phone","status":"TRIAL"},"activities":[]}""")
                   .On("GET", "groups", HttpStatusCode.OK, """{"data":[{"id":2,"name":"P-1","course_id":1,"active":true,"students_count":3,"schedules":[]}]}""")
                   .On("POST", "leads/5/convert", HttpStatusCode.OK, """{"lead":{"id":5},"student":{"id":77},"created":true,"joined":"added"}""");
        var vm = new LeadViewModel(app.Services, 5);
        await vm.RefreshAsync();
        Assert.True(vm.CanConvert);
        app.Forms.Answers.Enqueue(new() { ["group_id"] = "2" });
        await vm.ConvertCommand.ExecuteAsync(null);
        var body = app.Handler.Requests.Single(r => r.Path == "leads/5/convert").Body!;
        Assert.Contains("\"first_name\":\"Ali\"", body);
        Assert.Contains("\"last_name\":\"Valiyev\"", body);
        Assert.Contains("\"group_id\":2", body);
        Assert.Equal((Page.StudentProfile, (object?)77), app.Navigator.History.Last());
    }

    [Fact]
    public async Task Invalid_session_mark_blocks_save()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "sessions/5/attendance", HttpStatusCode.OK, """
            {"session":{"id":5,"group_id":2,"starts_at":"2026-10-01T04:00:00Z","ends_at":"2026-10-01T05:00:00Z","cancelled":false},"group":{"id":2,"name":"G"},
             "rows":[{"student_id":11,"name":"Vali","status":"PRESENT"}],"edit":{"can_edit":true,"reason_required":false}}
            """)
            .On("GET", "sessions/5/marks", HttpStatusCode.OK, """{"enabled":true,"scale":{"min":1,"max":5},"rows":[{"student_id":11,"name":"Vali","score":null}],"can_edit":true}""")
            .On("GET", "sessions/5/attendance/history", HttpStatusCode.OK, """{"data":[]}""");
        var vm = new SheetViewModel(app.Services, 5);
        await vm.RefreshAsync();
        Assert.True(vm.HasMarks);
        vm.Rows[0].Score = "9";
        await vm.SaveCommand.ExecuteAsync(null);
        Assert.Contains("1–5", vm.ActionError);
        Assert.DoesNotContain(app.Handler.Requests, r => r.Method == "PUT");
    }
}
