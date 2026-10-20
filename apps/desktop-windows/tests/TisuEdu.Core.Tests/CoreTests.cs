using System.Net;
using System.Text;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;
using TisuEdu.Core.ViewModels;
using Xunit;

namespace TisuEdu.Core.Tests;

public class JsonTests
{
    [Fact]
    public void Parses_dashboard_summary()
    {
        const string json = """
        {"role":"TEACHER","generated_at":"2026-10-20T09:00:00+05:00","today":[{"id":1,"group_id":2,"group_name":"G-1","course_title":"Python","teacher":"Ali","room":"12","topic":null,
          "starts_at":"2026-10-20T09:00:00+05:00","ends_at":"2026-10-20T10:30:00+05:00","cancelled":false,"marked_count":3,"students_count":10,"can_mark":true}],
         "stats":{"today_sessions":1,"today_marked":1,"attendance_rate":87.5,"attendance_days":30,"attendance_counts":{"PRESENT":7,"LATE":1,"ABSENT":1,"EXCUSED":0},
          "courses":4,"groups":2,"students":25,"unmarked_sessions":0}}
        """;
        var d = Json.Deserialize<DashboardSummary>(json)!;
        Assert.Equal("G-1", d.Today[0].GroupName);
        Assert.True(d.Today[0].CanMark);
        Assert.Equal(87.5, d.Stats.AttendanceRate);
        Assert.Equal(7, d.Stats.AttendanceCounts["PRESENT"]);
        Assert.Equal(0, d.Stats.UnmarkedSessions);
    }

    [Fact]
    public void Lenient_values_from_raw_db_rows()
    {
        // Xom DB qatorlari: raqam satr ko'rinishida, boolean 0/1, sana mintaqasiz.
        const string json = """{"data":[{"code":"TISU-1","issued_at":"2026-10-01 12:30:00","student_id":"15","first_name":"Ali","last_name":null,"course":"Python","group":"G-1"}]}""";
        var c = Json.Deserialize<DataList<Certificate>>(json)!.Data[0];
        Assert.Equal(15, c.StudentId);
        Assert.Equal(2026, c.IssuedAt.Year);
        Assert.Equal(30, c.IssuedAt.Minute);

        var q = Json.Deserialize<QuizReportRow>("""{"quiz_id":1,"title":"T","is_final":1,"attempts":"3","students":2,"avg_percent":"66.7","pass_rate":null}""")!;
        Assert.True(q.IsFinal);
        Assert.Equal(3, q.Attempts);
        Assert.Equal(66.7, q.AvgPercent);
        Assert.Null(q.PassRate);
    }

    [Fact]
    public void Parses_sheet_with_edit_window()
    {
        const string json = """
        {"session":{"id":5,"group_id":2,"starts_at":"2026-10-18T04:00:00.000000Z","ends_at":"2026-10-18T05:30:00.000000Z","topic":"Sikllar","cancelled":false},
         "group":{"id":2,"name":"G-1"},
         "rows":[{"student_id":11,"name":"Vali","status":"LATE","arrived_at":"2026-10-18 09:12:00","note":null},{"student_id":12,"name":"Hasan","status":null,"arrived_at":null,"note":null}],
         "edit":{"can_edit":true,"reason_required":true,"deadline":"2026-10-21T09:00:00+05:00"}}
        """;
        var s = Json.Deserialize<AttendanceSheet>(json)!;
        Assert.True(s.Edit.CanEdit);
        Assert.True(s.Edit.ReasonRequired);
        Assert.NotNull(s.Edit.Deadline);
        Assert.Equal("09:12", Format.ArrivedHhmm(s.Rows[0].ArrivedAt));
    }
}

public class ApiClientTests
{
    [Fact]
    public async Task Unauthorized_raises_event_and_signs_out()
    {
        using var app = new TestApp();
        app.SignIn();
        var signedOut = false;
        app.Session.SignedOut += (_, expired) => signedOut = expired;
        app.Handler.On("GET", "groups", HttpStatusCode.Unauthorized, """{"message":"Unauthenticated."}""");

        var ex = await Assert.ThrowsAsync<ApiException>(() => app.Api.GroupsAsync());
        Assert.True(ex.IsUnauthorized);
        Assert.Equal(Strings.SessionExpired, ex.Message);
        await Task.Delay(50);
        Assert.True(signedOut);
        Assert.Null(app.Tokens.Token);
        Assert.False(app.Session.IsSignedIn);
    }

    [Fact]
    public async Task Login_401_does_not_trigger_logout()
    {
        using var app = new TestApp();
        var fired = false;
        app.Http.Unauthorized += (_, _) => fired = true;
        app.Handler.On("POST", "auth/login", HttpStatusCode.Unauthorized, """{"message":"x"}""");
        await Assert.ThrowsAsync<ApiException>(() => app.Api.LoginAsync("+998", "p", "windows"));
        Assert.False(fired);
    }

    [Fact]
    public async Task Course_access_denied_is_parsed()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "lessons/9", HttpStatusCode.Forbidden,
            """{"code":"COURSE_ACCESS_DENIED","title":"Bu kurs faqat yozilgan o'quvchilar uchun","message":"Yozilish uchun @admin ga yozing.","button":"@admin ga yozish","contact":"@admin","contact_url":"https://t.me/admin","course":{"id":1,"title":"Python"}}""");
        var vm = new LessonViewModel(app.Services, 9);
        await vm.RefreshAsync();
        Assert.True(vm.IsDenied);
        Assert.Equal("Yozilish uchun @admin ga yozing.", vm.Denied!.Message);
        Assert.False(vm.HasError);
        vm.ContactCommand.Execute(null);
        Assert.Equal("https://t.me/admin", app.Platform.Opened.Single().ToString());
    }

    [Fact]
    public async Task Validation_error_uses_first_field_message()
    {
        using var app = new TestApp();
        app.Handler.On("POST", "auth/login", HttpStatusCode.UnprocessableEntity,
            """{"message":"The given data was invalid.","errors":{"phone":["Telefon yoki parol noto'g'ri."]}}""");
        var ex = await Assert.ThrowsAsync<ApiException>(() => app.Api.LoginAsync("+998", "x", "windows"));
        Assert.Equal("Telefon yoki parol noto'g'ri.", ex.Message);
    }

    [Fact]
    public async Task Offline_falls_back_to_cache()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "groups", HttpStatusCode.OK, """{"data":[{"id":1,"name":"G-1","course_id":1,"active":true,"students_count":3,"schedules":[]}]}""");
        var online = await app.Api.GroupsAsync();
        Assert.False(online.FromCache);
        await Task.Delay(20);

        app.Handler.Offline = true;
        var offline = await app.Api.GroupsAsync();
        Assert.True(offline.FromCache);
        Assert.Equal("G-1", offline.Value.Data[0].Name);
        Assert.False(app.Http.IsOnline);

        var vm = new GroupsViewModel(app.Services);
        await vm.RefreshAsync();
        Assert.True(vm.IsStale);
        Assert.Single(vm.Items);
    }

    [Fact]
    public async Task Offline_without_cache_shows_error()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.Offline = true;
        var vm = new CoursesViewModel(app.Services);
        await vm.RefreshAsync();
        Assert.Equal(Strings.Offline, vm.Error);
    }

    [Fact]
    public async Task Bearer_token_is_sent()
    {
        using var app = new TestApp();
        string? auth = null;
        var handler = new CaptureHandler(r => auth = r.Headers.Authorization?.ToString());
        var http = new ApiClient(new HttpClient(handler), null, "https://api.test") { Token = "abc" };
        await new TisuApi(http).UnreadAsync();
        Assert.Equal("Bearer abc", auth);
    }

    private sealed class CaptureHandler(Action<HttpRequestMessage> capture) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
        {
            capture(request);
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent("""{"unread":2}""", Encoding.UTF8, "application/json") });
        }
    }
}

public class LoginTests
{
    [Theory]
    [InlineData("+998 90 123-45-67", "+998901234567")]
    [InlineData("998901234567", "+998901234567")]
    [InlineData("90 123 45 67", "+998901234567")]
    [InlineData("", "")]
    public void Normalizes_phone(string input, string expected) => Assert.Equal(expected, LoginViewModel.NormalizePhone(input));

    [Fact]
    public async Task Password_login_completes_session()
    {
        using var app = new TestApp();
        app.Handler.On("POST", "auth/login", HttpStatusCode.OK, $$"""{"token":"T1","user":{{TestApp.UserJson}}}""");
        var vm = new LoginViewModel(app.Session, app.Settings, app.Platform, app.Preferences) { Phone = "+998 90 123 45 67", Password = "secret" };
        await vm.LoginCommand.ExecuteAsync(null);
        Assert.True(app.Session.IsSignedIn);
        Assert.Equal("T1", app.Tokens.Token);
        Assert.Contains("\"phone\":\"+998901234567\"", System.Text.RegularExpressions.Regex.Unescape(app.Handler.Requests[0].Body!));
        Assert.Contains("\"device\":\"windows\"", app.Handler.Requests[0].Body);
        Assert.Equal("+998901234567", app.Preferences.LastPhone);
    }

    [Fact]
    public async Task Telegram_login_polls_until_confirmed()
    {
        using var app = new TestApp();
        var code = new string('a', 32);
        app.Handler
            .On("POST", "auth/telegram/start", HttpStatusCode.OK, $$"""{"code":"{{code}}","poll_secret":"s","bot_url":"https://t.me/edu_tisu_bot?start=login_{{code}}","expires_in":300}""")
            .On("POST", "auth/telegram/poll", HttpStatusCode.OK, """{"status":"pending"}""")
            .On("POST", "auth/telegram/poll", HttpStatusCode.OK, $$"""{"status":"ok","token":"TG","user":{{TestApp.UserJson}}}""");
        var vm = new LoginViewModel(app.Session, app.Settings, app.Platform, app.Preferences) { PollInterval = TimeSpan.FromMilliseconds(5) };
        await vm.StartTelegramCommand.ExecuteAsync(null);
        Assert.Equal("https://t.me/edu_tisu_bot?start=login_" + code, app.Platform.Opened.Single().ToString());
        Assert.True(app.Session.IsSignedIn);
        Assert.Equal("TG", app.Tokens.Token);
        Assert.True(vm.IsConfirmed);
        Assert.Equal(2, app.Handler.Requests.Count(r => r.Path == "auth/telegram/poll"));
    }

    [Fact]
    public async Task Telegram_login_expired_shows_error()
    {
        using var app = new TestApp();
        app.Handler
            .On("POST", "auth/telegram/start", HttpStatusCode.OK, """{"code":"c","poll_secret":"s","bot_url":"https://t.me/x","expires_in":300}""")
            .On("POST", "auth/telegram/poll", HttpStatusCode.OK, """{"status":"expired"}""");
        var vm = new LoginViewModel(app.Session, app.Settings, app.Platform, app.Preferences) { PollInterval = TimeSpan.FromMilliseconds(5) };
        await vm.StartTelegramCommand.ExecuteAsync(null);
        Assert.False(app.Session.IsSignedIn);
        Assert.Equal(Strings.TelegramExpired, vm.Error);
        Assert.False(vm.IsWaitingTelegram);
    }
}

public class SheetTests
{
    private const string Sheet = """
        {"session":{"id":5,"group_id":2,"starts_at":"2026-10-01T04:00:00Z","ends_at":"2026-10-01T05:00:00Z","topic":null,"cancelled":false},
         "group":{"id":2,"name":"G-1"},
         "rows":[{"student_id":11,"name":"Vali","status":"PRESENT","arrived_at":null,"note":null},{"student_id":12,"name":"Hasan","status":null,"arrived_at":null,"note":null},{"student_id":13,"name":"Olim","status":null,"arrived_at":null,"note":null}],
         "edit":{"can_edit":true,"reason_required":true,"deadline":null}}
        """;

    [Fact]
    public async Task Reason_is_required_by_edit_window()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "sessions/5/attendance", HttpStatusCode.OK, Sheet)
                   .On("GET", "sessions/5/attendance/history", HttpStatusCode.OK, """{"data":[]}""");
        var vm = new SheetViewModel(app.Services, 5);
        await vm.RefreshAsync();
        Assert.True(vm.CanEdit);
        Assert.True(vm.ReasonRequired);

        await vm.SaveCommand.ExecuteAsync(null);
        Assert.Equal(Strings.ReasonRequired, vm.ActionError);
        Assert.DoesNotContain(app.Handler.Requests, r => r.Method == "PUT");
    }

    [Fact]
    public async Task Arrival_time_lets_server_classify_status()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "sessions/5/attendance", HttpStatusCode.OK, Sheet)
                   .On("GET", "sessions/5/attendance/history", HttpStatusCode.OK, """{"data":[]}""")
                   .On("PUT", "sessions/5/attendance", HttpStatusCode.OK, """{"ok":true,"changed":2,"notified":1}""");
        var vm = new SheetViewModel(app.Services, 5);
        await vm.RefreshAsync();

        vm.Rows[1].Arrived = "09:12";       // holat server tomonidan aniqlanadi
        vm.MarkRestPresentCommand.Execute(null); // faqat bo'shlari (Olim)
        Assert.Null(vm.Rows[1].Status);
        Assert.Equal(AttendanceStatus.Present, vm.Rows[2].Status);
        vm.Reason = "ma'lumotnoma";

        await vm.SaveCommand.ExecuteAsync(null);
        var put = app.Handler.Requests.Single(r => r.Method == "PUT");
        Assert.Contains("\"reason\":\"ma'lumotnoma\"", put.Body!.Replace("\\u0027", "'"));
        Assert.Contains("{\"student_id\":12,\"status\":null,\"arrived_at\":\"09:12\",\"note\":null}", put.Body);
        Assert.Contains("{\"student_id\":13,\"status\":\"PRESENT\"", put.Body);
        Assert.Contains("1", vm.Notice);
    }

    [Fact]
    public async Task Closed_window_is_read_only_with_server_message()
    {
        using var app = new TestApp();
        app.SignIn();
        var closed = Sheet.Replace("\"edit\":{\"can_edit\":true,\"reason_required\":true,\"deadline\":null}",
            "\"edit\":{\"can_edit\":false,\"reason_required\":true,\"message\":\"Bu dars 19 kun oldin bo'lgan.\"}");
        app.Handler.On("GET", "sessions/5/attendance", HttpStatusCode.OK, closed)
                   .On("GET", "sessions/5/attendance/history", HttpStatusCode.OK, """{"data":[]}""");
        var vm = new SheetViewModel(app.Services, 5);
        await vm.RefreshAsync();
        Assert.False(vm.CanEdit);
        Assert.True(vm.HasWindowMessage);
        Assert.Equal("Bu dars 19 kun oldin bo'lgan.", vm.WindowMessage);
        vm.Rows[1].IsAbsent = true;
        Assert.Null(vm.Rows[1].Status);
    }

    [Fact]
    public void Invalid_arrival_format_is_rejected()
    {
        var row = new SheetRowItem(new SheetRow { StudentId = 1, Name = "A" }, SiteSettings.Empty, true) { Arrived = "9.5" };
        Assert.False(Format.IsValidHhmm(row.Arrived));
        Assert.True(Format.IsValidHhmm("09:05"));
    }
}

public class QuizTests
{
    [Fact]
    public async Task Student_takes_and_submits_quiz()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler
            .On("GET", "quizzes/3", HttpStatusCode.OK, """{"quiz":{"id":3,"title":"Kirish testi","is_final":false,"pass_percent":60,"max_attempts":3,"time_limit_sec":null,"course_id":1,"questions_count":3,"course":{"id":1,"title":"Python"}},"attempts":[],"can_take":true}""")
            .On("POST", "quizzes/3/attempts", HttpStatusCode.OK, """
{"attempt":{"id":40,"started_at":"2026-10-20T04:00:00Z","deadline":null,"quiz":{"id":3,"title":"Kirish testi","time_limit_sec":null,"pass_percent":60},
               "questions":[{"id":1,"type":"SINGLE","text":"2+2?","points":1,"options":[{"id":10,"text":"3"},{"id":11,"text":"4"}]},
                            {"id":2,"type":"MULTIPLE","text":"Juft sonlar","points":2,"options":[{"id":20,"text":"2"},{"id":21,"text":"3"},{"id":22,"text":"4"}]},
                            {"id":3,"type":"TEXT","text":"Poytaxt?","points":1,"options":[]}]}}
""")
            .On("POST", "attempts/40/submit", HttpStatusCode.OK, """
{"result":{"score":3,"max_score":4,"percent":75.0,"passed":true,"time_expired":false},
               "review":[{"question_id":1,"correct":["11"],"earned":1,"points":1},{"question_id":2,"correct":["20","22"],"earned":2,"points":2},{"question_id":3,"correct":["Toshkent"],"earned":0,"points":1}]}
""");

        var vm = new QuizViewModel(app.Services, 3);
        await vm.RefreshAsync();
        Assert.True(vm.IsStudent);
        Assert.True(vm.CanTake);

        await vm.StartCommand.ExecuteAsync(null);
        Assert.True(vm.IsTaking);
        Assert.Equal(3, vm.Questions.Count);
        vm.Questions[0].SelectedIndex = 1;
        vm.Questions[1].Options[0].IsSelected = true;
        vm.Questions[1].Options[2].IsSelected = true;
        Assert.False(vm.Questions[2].IsAnswered);

        await vm.SubmitCommand.ExecuteAsync(null);
        var body = app.Handler.Requests.Single(r => r.Path == "attempts/40/submit").Body!;
        Assert.Contains("\"1\":[\"11\"]", body);
        Assert.Contains("\"2\":[\"20\",\"22\"]", body);
        Assert.Contains("\"3\":[]", body);
        Assert.True(vm.HasResult);
        Assert.True(vm.ResultPassed);
        Assert.True(vm.Questions[0].IsCorrect);
        Assert.False(vm.Questions[2].IsCorrect);
        Assert.Contains("Toshkent", vm.Questions[2].ReviewText);
    }

    [Fact]
    public async Task Manager_sees_results_instead_of_take()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler
            .On("GET", "quizzes/3", HttpStatusCode.OK, """{"quiz":{"id":3,"title":"T","pass_percent":60,"max_attempts":3,"course_id":1,"questions":[{"id":1,"type":"SINGLE","text":"q","points":1,"options":[{"id":1,"text":"a","is_correct":true}]}],"course":{"id":1,"title":"P"}},"can_edit":true}""")
            .On("GET", "quizzes/3/results", HttpStatusCode.OK, """{"data":[{"id":1,"quiz_id":3,"student_id":5,"first_name":"Ali","last_name":"V","phone":"+998","submitted_at":"2026-10-01T10:00:00Z","score":1,"max_score":1,"percent":100,"passed":true}]}""");
        var vm = new QuizViewModel(app.Services, 3);
        await vm.RefreshAsync();
        Assert.True(vm.IsManager);
        Assert.False(vm.CanTake);
        Assert.Single(vm.Results);
    }
}

public class WatcherTests
{
    private static string Page(params (int Id, bool Read)[] items) =>
        "{\"data\":[" + string.Join(",", items.Select(i => $"{{\"id\":{i.Id},\"title\":\"E{i.Id}\",\"body\":\"b\",\"pinned\":false,\"read\":{(i.Read ? "true" : "false")}}}")) +
        $"],\"meta\":{{\"page\":1,\"pages\":1,\"total\":{items.Length},\"unread\":{items.Count(i => !i.Read)}}}}}";

    [Fact]
    public async Task Notifies_only_new_announcements_after_baseline()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("GET", "announcements/unread", HttpStatusCode.OK, """{"unread":1}""")
                   .On("GET", "announcements/unread", HttpStatusCode.OK, """{"unread":2}""")
                   .On("GET", "announcements?page=1", HttpStatusCode.OK, Page((5, false)))
                   .On("GET", "announcements?page=1", HttpStatusCode.OK, Page((6, false), (5, false)));
        app.Watcher.Reset();
        var unreadEvents = new List<int>();
        app.Watcher.UnreadChanged += (_, n) => unreadEvents.Add(n);

        await app.Watcher.CheckNowAsync(); // birinchi: chegarani eslaydi, bildirishnoma yo'q
        Assert.Empty(app.Notifier.Shown);
        await app.Watcher.CheckNowAsync(); // yangi e'lon #6
        Assert.Equal(6, app.Notifier.Shown.Single().Id);
        Assert.Equal([1, 2], unreadEvents);
    }
}

public class CsvTests
{
    [Theory]
    [InlineData("=SUM(A1)", "'=SUM(A1)")]
    [InlineData("+998901234567", "+998901234567")]
    [InlineData("-cmd", "'-cmd")]
    [InlineData("a;b", "\"a;b\"")]
    [InlineData("Ali \"Vali\"", "\"Ali \"\"Vali\"\"\"")]
    public void Escapes_cells(string input, string expected) => Assert.Equal(expected, Csv.Cell(input));

    [Fact]
    public void Has_bom_and_semicolons()
    {
        var bytes = Csv.Build(["A", "B"], [["1", "2"]]);
        Assert.Equal(new byte[] { 0xEF, 0xBB, 0xBF }, bytes[..3]);
        Assert.Equal("A;B\r\n1;2\r\n", Encoding.UTF8.GetString(bytes[3..]));
    }
}

public class SettingsTests
{
    [Fact]
    public async Task Reads_texts_from_public_settings_with_fallbacks()
    {
        using var app = new TestApp();
        app.Handler.On("GET", "public/settings", HttpStatusCode.OK,
            """{"settings":{"site.name":"Akademiya","ui.nav_labels":{"/dashboard/groups":"Sinflar"},"attendance.status_labels":{"PRESENT":"bor"},"miniapp.texts":{"hello":"Assalomu alaykum, {name}!"},"app.timezone":"Asia/Tashkent"}}""");
        var st = await app.Settings.RefreshAsync(force: true);
        Assert.Equal("Akademiya", st.SiteName);
        Assert.Equal("Sinflar", st.Nav("/dashboard/groups", "Guruhlar"));
        Assert.Equal("Kurslar", st.Nav("/dashboard/courses", "Kurslar"));
        Assert.Equal("bor", st.StatusLabel("PRESENT"));
        Assert.Equal("ABSENT", st.StatusLabel("ABSENT"));
        Assert.Equal("Assalomu alaykum, Ali!", st.Text("hello", "Salom, {name}!", ("name", "Ali")));

        // Disk keshidan qayta yuklanadi (oflayn ishga tushirish).
        var again = new SettingsService(app.Api, Path.Combine(app.Dir, "settings.json"));
        await again.LoadCachedAsync();
        Assert.Equal("Akademiya", again.Current.SiteName);
    }
}
