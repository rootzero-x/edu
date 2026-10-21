using System.Net;
using TisuEdu.Core.Api;
using TisuEdu.Core.Services;
using TisuEdu.Core.ViewModels;
using Xunit;

namespace TisuEdu.Core.Tests;

public class AuditFixTests
{
    private const string Ticket = """{"url":"https://api.test/v1/lessons/3/materials/upload","token":"TICKET","expires_at":"2026-10-21T10:00:00+05:00"}""";

    [Fact]
    public async Task Small_upload_uses_ticket_token_and_single_file_field()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("POST", "uploads/ticket", HttpStatusCode.OK, Ticket)
                   .On("POST", "lessons/3/materials/upload", HttpStatusCode.Created, """{"material":{"id":1}}""");
        await app.Api.UploadMaterialAsync(3, "a.pdf", new byte[1000], "application/pdf", "Konspekt");

        Assert.Contains("\"path\":\"lessons/3/materials/upload\"", app.Handler.Requests[0].Body);
        Assert.Equal("Bearer tok", app.Handler.Headers[0]["Authorization"]);       // chipta — foydalanuvchi tokeni bilan
        var upload = app.Handler.Requests[1];
        Assert.Equal("Bearer TICKET", app.Handler.Headers[1]["Authorization"]);    // fayl — chipta tokeni bilan
        Assert.Contains("name=file; filename=a.pdf", upload.Body);
        Assert.Contains("name=title", upload.Body);
        Assert.False(app.Handler.Headers[1].ContainsKey("X-Chunk-Total"));
    }

    [Fact]
    public async Task Large_upload_is_chunked_with_fields_on_last_chunk()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("POST", "uploads/ticket", HttpStatusCode.OK, Ticket)
                   .On("POST", "lessons/3/materials/upload", HttpStatusCode.Accepted, """{"ok":true,"chunk":0}""")
                   .On("POST", "lessons/3/materials/upload", HttpStatusCode.Accepted, """{"ok":true,"chunk":1}""")
                   .On("POST", "lessons/3/materials/upload", HttpStatusCode.Created, """{"material":{"id":1}}""");
        var size = ApiClient.UploadChunkSize * 2 + 10;   // 3 bo'lak
        await app.Api.UploadMaterialAsync(3, "video.mp4", new byte[size], "video/mp4", "Video");

        var uploads = Enumerable.Range(0, app.Handler.Requests.Count).Where(i => app.Handler.Requests[i].Path == "lessons/3/materials/upload").ToList();
        Assert.Equal(3, uploads.Count);
        var ids = uploads.Select(i => app.Handler.Headers[i]["X-Upload-Id"]).Distinct().ToList();
        Assert.Single(ids);
        Assert.Matches("^[A-Za-z0-9_-]{8,64}$", ids[0]);
        Assert.Equal(["0", "1", "2"], uploads.Select(i => app.Handler.Headers[i]["X-Chunk-Index"]));
        Assert.All(uploads, i => Assert.Equal("3", app.Handler.Headers[i]["X-Chunk-Total"]));
        Assert.All(uploads, i => Assert.Contains("name=chunk", app.Handler.Requests[i].Body));
        Assert.DoesNotContain("name=filename", app.Handler.Requests[uploads[0]].Body);
        Assert.DoesNotContain("name=title", app.Handler.Requests[uploads[1]].Body);
        Assert.Contains("name=filename", app.Handler.Requests[uploads[2]].Body);
        Assert.Contains("video.mp4", app.Handler.Requests[uploads[2]].Body);
        Assert.Contains("name=title", app.Handler.Requests[uploads[2]].Body);
    }

    [Fact]
    public async Task Ticket_401_does_not_sign_out()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("POST", "uploads/ticket", HttpStatusCode.OK, Ticket)
                   .On("POST", "lessons/3/materials/upload", HttpStatusCode.Forbidden, """{"message":"Yuklash chiptasi bu so'rov uchun emas yoki muddati o'tgan."}""");
        var ex = await Assert.ThrowsAsync<ApiException>(() => app.Api.UploadMaterialAsync(3, "a.pdf", new byte[10], "application/pdf", null));
        Assert.Contains("chiptasi", ex.Message);
        Assert.True(app.Session.IsSignedIn);
    }

    [Theory]
    [InlineData("150000", 150000)]
    [InlineData("150 000", 150000)]
    [InlineData("150000.00", 150000)]
    [InlineData("150 000,00", 150000)]
    [InlineData("150.000", 150000)]
    [InlineData("1,500,000", 1500000)]
    public void Amount_parses_whole_sums(string input, int expected) => Assert.Equal((expected, (string?)null), FormValues.ParseAmount(input));

    [Theory]
    [InlineData("150000.50")]
    [InlineData("150 000,5")]
    [InlineData("abc")]
    public void Amount_rejects_fractions_and_text(string input)
    {
        var (value, error) = FormValues.ParseAmount(input);
        Assert.Null(value);
        Assert.NotNull(error);
    }

    [Fact]
    public void Adjustment_amount_keeps_sign() => Assert.Equal(-25000, FormValues.ParseAmount("-25 000", allowSign: true).Value);

    [Fact]
    public async Task Adjustment_sends_one_request_id_per_dialog()
    {
        using var app = new TestApp();
        app.SignIn();
        app.Handler.On("POST", "billing/students/9/adjustments", HttpStatusCode.UnprocessableEntity, """{"message":"x","errors":{"reason":["Sababni yozing."]}}""")
                   .On("POST", "billing/students/9/adjustments", HttpStatusCode.Created, """{"entry":{"id":3},"balance":0}""");
        var vm = new BillingStudentViewModel(app.Services, 9);
        // Bir dialogda ikki urinish (birinchisi xato) — ikkalasida ham bir xil request_id.
        var forms = new RetryForms(new() { ["amount"] = "-10 000", ["reason"] = "x", ["date"] = "2026-10-21" },
                                   new() { ["amount"] = "-10 000", ["reason"] = "Chegirma", ["date"] = "2026-10-21" });
        var services = new AppServices(app.Session, app.Settings, app.Platform, app.Navigator, app.Watcher, app.Preferences, forms);
        await new BillingStudentViewModel(services, 9).AddAdjustmentCommand.ExecuteAsync(null);
        var bodies = app.Handler.Requests.Where(r => r.Path == "billing/students/9/adjustments").Select(r => r.Body!).ToList();
        Assert.Equal(2, bodies.Count);
        var id = System.Text.RegularExpressions.Regex.Match(bodies[0], "\"request_id\":\"([0-9a-f]{32})\"").Groups[1].Value;
        Assert.NotEmpty(id);
        Assert.Contains(id, bodies[1]);
        Assert.Contains("\"amount\":-10000", bodies[1]);
    }

    [Theory]
    [InlineData("https://t.me/admin", true)]
    [InlineData("tg://resolve?domain=admin", true)]
    [InlineData("tel:+998901112233", true)]
    [InlineData("http://example.uz", true)]
    [InlineData("", false)]
    [InlineData(null, false)]
    [InlineData("/dashboard", false)]
    [InlineData("javascript:alert(1)", false)]
    [InlineData("file:///etc/passwd", false)]
    [InlineData("ms-settings:", false)]
    public void External_links_allow_only_safe_schemes(string? url, bool ok)
    {
        Assert.Equal(ok, ExternalLinks.TryCreate(url, out _));
        var p = new FakePlatform();
        Assert.Equal(ok, p.OpenUrl(url));
        Assert.Equal(ok ? 1 : 0, p.Opened.Count);
    }

    [Fact]
    public void Contact_falls_back_to_telegram_handle_when_url_is_unsafe()
    {
        var p = new FakePlatform();
        var vmType = typeof(CourseViewModel).Assembly.GetType("TisuEdu.Core.ViewModels.AccessActions")!;
        vmType.GetMethod("Contact")!.Invoke(null, [p, new Models.AccessInfo { ContactUrl = "javascript:x", Contact = "@admin" }]);
        Assert.Equal("https://t.me/admin", p.Opened.Single().ToString());
    }

    /// <summary>Birinchi submit xato qaytarsa, dialog ochiq qoladi va ikkinchi qiymatlar bilan qayta yuboriladi.</summary>
    private sealed class RetryForms(params Dictionary<string, string>[] attempts) : IForms
    {
        public async Task<bool> ShowAsync(string title, IReadOnlyList<FormField> fields, Func<FormValues, Task<string?>> submit, string primaryText = "Saqlash")
        {
            foreach (var a in attempts)
            {
                var values = fields.ToDictionary(f => f.Key, f => f.Value);
                foreach (var (k, v) in a) values[k] = v;
                if (await submit(new FormValues(values)) is null) return true;
            }
            return false;
        }
    }
}
