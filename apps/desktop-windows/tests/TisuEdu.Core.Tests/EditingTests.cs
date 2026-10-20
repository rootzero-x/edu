using System.Net;
using System.Text.Json;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;
using TisuEdu.Core.ViewModels;
using Xunit;

namespace TisuEdu.Core.Tests;

public class EditingTests
{
    private static FormValues V(params (string K, string V)[] kv) => new(kv.ToDictionary(x => x.K, x => x.V));

    [Fact]
    public void Question_body_parses_options_with_star_for_correct()
    {
        var (body, error) = Editing.QuestionBody(V(("type", "SINGLE"), ("text", "2+2?"), ("points", "2"), ("options", "3\n*4\n5")), null);
        Assert.Null(error);
        var json = JsonSerializer.Serialize(body);
        Assert.Contains("\"type\":\"SINGLE\"", json);
        Assert.Contains("{\"text\":\"4\",\"is_correct\":true}", json);
        Assert.Contains("\"points\":2", json);
    }

    [Theory]
    [InlineData("SINGLE", "*a\n*b", "faqat bitta")]
    [InlineData("SINGLE", "a\nb", "*")]
    [InlineData("MULTIPLE", "*a", "ikkita")]
    public void Question_body_rejects_bad_options(string type, string options, string expected)
    {
        var (_, error) = Editing.QuestionBody(V(("type", type), ("text", "q"), ("options", options)), null);
        Assert.Contains(expected, error);
    }

    [Fact]
    public void Text_question_on_edit_keeps_type_and_sends_answers()
    {
        var (body, error) = Editing.QuestionBody(V(("text", "Poytaxt?"), ("accepted", "Toshkent\nTashkent")), "TEXT");
        Assert.Null(error);
        Assert.False(body!.ContainsKey("type"));
        Assert.Equal(["Toshkent", "Tashkent"], (List<string>)body["accepted_answers"]!);
    }

    [Fact]
    public void Schedule_lines_parse_with_weekday_names()
    {
        var (slots, error) = Editing.ParseSchedule(["Du 14:00-15:30", "cho 9:00-10:30", "6 10:00–11:00"], SiteSettings.Empty);
        Assert.Null(error);
        var json = JsonSerializer.Serialize(slots);
        Assert.Contains("{\"weekday\":1,\"start_time\":\"14:00\",\"end_time\":\"15:30\"}", json);
        Assert.Contains("{\"weekday\":3,\"start_time\":\"09:00\",\"end_time\":\"10:30\"}", json);
        Assert.Contains("{\"weekday\":6,\"start_time\":\"10:00\",\"end_time\":\"11:00\"}", json);
    }

    [Theory]
    [InlineData("Xy 10:00-11:00")]
    [InlineData("Du 11:00-10:00")]
    [InlineData("Du 10-11")]
    public void Schedule_rejects_bad_lines(string line) => Assert.NotNull(Editing.ParseSchedule([line], SiteSettings.Empty).Error);

    [Fact]
    public void Course_body_omits_empty_number()
    {
        var body = Editing.CourseBody(V(("title", "Python"), ("description", ""), ("duration_months", ""), ("cover_url", ""), ("published", "1")));
        Assert.False(body.ContainsKey("duration_months"));
        Assert.Equal(true, body["published"]);
        Assert.Null(body["description"]);
    }

    [Theory]
    [InlineData("https://www.youtube.com/watch?v=abc_123-X", "https://www.youtube.com/embed/abc_123-X")]
    [InlineData("https://youtu.be/abc123", "https://www.youtube.com/embed/abc123")]
    [InlineData("https://vimeo.com/123456", "https://player.vimeo.com/video/123456")]
    [InlineData("https://evil.com/watch?v=abc", null)]
    [InlineData("javascript:alert(1)", null)]
    public void Embed_urls_only_for_allowed_hosts(string url, string? expected) =>
        Assert.Equal(expected, Embed.Url(url, ["youtube.com", "youtu.be", "vimeo.com", "player.vimeo.com"])?.ToString());

    [Fact]
    public void Setting_row_parses_by_type()
    {
        SettingRow Row(string type, string json, double? min = null, double? max = null) => new(new SettingItem
        {
            Key = "k", Type = type, Label = "L", Min = min, Max = max,
            Value = JsonDocument.Parse(json).RootElement.Clone(), Default = JsonDocument.Parse(json).RootElement.Clone(),
        });

        Assert.Equal("15", Row("int", "10", 1, 20).Parse(V(("v", "15"))).Value.GetRawText());
        Assert.NotNull(Row("int", "10", 1, 20).Parse(V(("v", "50"))).Error);
        Assert.Equal("true", Row("bool", "false").Parse(V(("v", "1"))).Value.GetRawText());
        Assert.Equal("[\"a\",\"b\"]", Row("list", "[]").Parse(V(("v", "[\"a\",\"b\"]"))).Value.GetRawText());
        Assert.NotNull(Row("object", "{}").Parse(V(("v", "[1]"))).Error);
        Assert.NotNull(Row("list", "[]").Parse(V(("v", "[oops"))).Error);
        Assert.Contains("\n", Row("object", "{\"a\":1,\"b\":2}").EditText);
    }
}

public class ManagementFlowTests
{
    [Fact]
    public async Task New_course_via_form_posts_and_reloads()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "courses", HttpStatusCode.OK, """{"data":[]}""")
                   .On("POST", "courses", HttpStatusCode.Created, """{"course":{"id":5,"title":"Python"}}""");
        var vm = new CoursesViewModel(app.Services);
        app.Forms.Answers.Enqueue(new() { ["title"] = "Python", ["published"] = "1" });
        await vm.NewCourseCommand.ExecuteAsync(null);
        var post = app.Handler.Requests.Single(r => r.Method == "POST");
        Assert.Contains("\"title\":\"Python\"", post.Body);
        Assert.Equal("Kurs yaratildi.", vm.Notice);
    }

    [Fact]
    public async Task Validation_error_keeps_form_open()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("POST", "courses", HttpStatusCode.UnprocessableEntity, """{"message":"x","errors":{"title":["Nomi majburiy."]}}""");
        var vm = new CoursesViewModel(app.Services);
        app.Forms.Answers.Enqueue(new() { ["title"] = "" });
        await vm.NewCourseCommand.ExecuteAsync(null);
        Assert.Equal("Nomi majburiy.", app.Forms.Errors.Single());
        Assert.Null(vm.Notice);
    }

    [Fact]
    public async Task Payment_is_deduplicated_with_request_id()
    {
        using var app = new TestApp();
        app.SignIn();
        const string student = """
{"student":{"id":9,"first_name":"Ali"},"balance":-50000,"debt":50000,"month_charged":50000,"groups":[],"monthly_fee":300000,
            "totals":{"charged":50000,"paid":0,"adjusted":0},"months":[],"entries":[{"id":1,"student_id":9,"type":"CHARGE","amount":-50000,"entry_date":"2026-10-01","voided":false}],
            "meta":{"page":1,"pages":1,"total":1},"can_manage":true}
""";
        app.Handler.On("GET", "billing/students/9?page=1", HttpStatusCode.OK, student)
                   .On("POST", "billing/students/9/payments", HttpStatusCode.Created, """{"entry":{"id":2},"balance":0}""");
        var vm = new BillingStudentViewModel(app.Services, 9);
        await vm.RefreshAsync();
        Assert.True(vm.InDebt);
        Assert.False(vm.Entries[0].CanVoid); // hisoblashni bekor qilib bo'lmaydi

        app.Forms.Answers.Enqueue(new() { ["amount"] = "50 000", ["method"] = "CASH", ["date"] = "2026-10-20" });
        await vm.AddPaymentCommand.ExecuteAsync(null);
        var body = app.Handler.Requests.Single(r => r.Method == "POST").Body!;
        Assert.Contains("\"amount\":50000", body);
        Assert.Matches("\"request_id\":\"[0-9a-f]{32}\"", body);
        Assert.Equal("To'lov saqlandi.", vm.Notice);
    }

    [Fact]
    public async Task Empty_payment_amount_is_rejected_locally()
    {
        using var app = new TestApp();
        app.SignIn();
        var vm = new BillingStudentViewModel(app.Services, 9);
        app.Forms.Answers.Enqueue(new() { ["amount"] = "", ["method"] = "CASH" });
        await vm.AddPaymentCommand.ExecuteAsync(null);
        Assert.Equal("Summani kiriting.", app.Forms.Errors.Single());
        Assert.DoesNotContain(app.Handler.Requests, r => r.Method == "POST");
    }

    [Fact]
    public async Task Users_page_lists_and_blocks()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "users?page=1", HttpStatusCode.OK,
                """{"data":[{"id":3,"phone":"+998901112233","first_name":"Vali","role":"TEACHER","active":true,"pending":false,"permissions":[]}],"meta":{"page":1,"pages":1,"total":1,"pending":2}}""")
            .On("PATCH", "users/3", HttpStatusCode.OK, """{"user":{"id":3,"first_name":"Vali","role":"TEACHER","active":false}}""");
        var vm = new UsersViewModel(app.Services);
        await vm.RefreshAsync();
        Assert.Single(vm.Items);
        Assert.True(vm.HasPending);
        await vm.ToggleBlockCommand.ExecuteAsync(vm.Items[0]);
        Assert.Contains("\"active\":false", app.Handler.Requests.Single(r => r.Method == "PATCH").Body);
    }

    [Fact]
    public async Task Group_create_sends_parsed_schedule()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "groups", HttpStatusCode.OK, """{"data":[]}""")
                   .On("GET", "courses", HttpStatusCode.OK, """{"data":[{"id":1,"title":"Python"}]}""")
                   .On("GET", "groups/teachers", HttpStatusCode.OK, """{"data":[{"id":7,"first_name":"Ali"}]}""")
                   .On("POST", "groups", HttpStatusCode.Created, """{"group":{"id":3}}""");
        var vm = new GroupsViewModel(app.Services);
        app.Forms.Answers.Enqueue(new() { ["name"] = "P-1", ["course_id"] = "1", ["teacher_id"] = "7", ["start_date"] = "2026-11-01", ["schedule"] = "Du 14:00-15:30\nCho 14:00-15:30" });
        await vm.NewGroupCommand.ExecuteAsync(null);
        var body = app.Handler.Requests.Single(r => r.Method == "POST").Body!;
        Assert.Contains("\"schedules\":[{\"weekday\":1", body);
        Assert.Contains("\"teacher_id\":7", body);
    }
}
