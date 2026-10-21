<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\User;
use App\Support\Settings;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Sprint 3 #13: fayl yuklash — tur fayl ichidan tekshiriladi, yuklab olish imzolangan havola va CourseAccess orqali. */
class UploadsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private User $outsider;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Uploads::DISK);
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->student = User::factory()->create();
        $this->outsider = User::factory()->create();
        $course = Course::create(['title' => 'Ingliz', 'created_by' => $this->teacher->id, 'published' => true]);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M1', 'position' => 1]);
        $this->lesson = Lesson::create(['module_id' => $module->id, 'title' => 'L1', 'position' => 1, 'published' => true]);
        Sanctum::actingAs($this->admin);
        $gid = $this->postJson('/v1/groups', ['name' => 'G1', 'course_id' => $course->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01'])->json('group.id');
        $this->postJson("/v1/groups/$gid/members", ['student_id' => $this->student->id])->assertCreated();
    }

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    public static function png(string $extra = ''): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==').$extra;
    }

    /** Siqilmagan (stored) ZIP — OOXML tekshiruvi uchun (zip kengaytmasi kerak emas). */
    public static function zip(array $files): string
    {
        $out = '';
        $central = '';
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $len = strlen($data);
            $offset = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $len, $len, strlen($name), 0).$name.$data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }

        return $out.$central.pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
    }

    private function upload(string $name, string $content): \Illuminate\Testing\TestResponse
    {
        return $this->post("/v1/lessons/{$this->lesson->id}/materials/upload",
            ['file' => UploadedFile::fake()->createWithContent($name, $content), 'title' => 'Material'], ['Accept' => 'application/json']);
    }

    public function test_teacher_uploads_pdf_and_enrolled_student_downloads_via_signed_link(): void
    {
        Sanctum::actingAs($this->teacher);
        $r = $this->upload('darslik.pdf', self::pdf())->assertCreated();
        $this->assertSame('FILE', $r->json('material.type'));
        $this->assertSame('darslik.pdf', $r->json('material.file.original_name'));
        $fileId = $r->json('material.file_id');
        $path = DB::table('files')->where('id', $fileId)->value('path');
        Storage::disk(Uploads::DISK)->assertExists($path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'file.upload']);

        // Dars sahifasida fayl haqida ma'lumot bor, yo'l (path) — yo'q.
        Sanctum::actingAs($this->student);
        $lesson = $this->getJson("/v1/lessons/{$this->lesson->id}")->assertOk();
        $this->assertSame($fileId, $lesson->json('lesson.materials.0.file.id'));
        $this->assertArrayNotHasKey('path', $lesson->json('lesson.materials.0.file'));

        $link = $this->getJson("/v1/files/$fileId/link")->assertOk();
        $this->assertStringContainsString('signature=', $link->json('url'));
        $relative = parse_url($link->json('url'), PHP_URL_PATH).'?'.parse_url($link->json('url'), PHP_URL_QUERY);
        $res = $this->get($relative)->assertOk();
        $this->assertSame(self::pdf(), $res->streamedContent());
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('inline', $res->headers->get('Content-Disposition'));

        // Imzo buzilsa yoki muddati o'tsa — 403.
        $this->get(str_replace('signature=', 'signature=x', $relative))->assertForbidden();
        $this->travel(31)->minutes();
        $this->get($relative)->assertForbidden();
    }

    public function test_link_respects_course_access_and_publication(): void
    {
        Sanctum::actingAs($this->teacher);
        $fileId = $this->upload('a.pdf', self::pdf())->assertCreated()->json('material.file_id');

        Sanctum::actingAs($this->outsider);
        $this->getJson("/v1/files/$fileId/link")->assertForbidden()->assertJsonPath('code', 'COURSE_ACCESS_DENIED');

        $this->lesson->update(['published' => false]);
        Sanctum::actingAs($this->student);
        $this->getJson("/v1/files/$fileId/link")->assertNotFound();
        Sanctum::actingAs($this->teacher);
        $this->getJson("/v1/files/$fileId/link")->assertOk();
    }

    public function test_content_is_checked_not_the_extension(): void
    {
        Sanctum::actingAs($this->teacher);
        // Windows dasturi .pdf nomi bilan.
        $this->upload('kitob.pdf', "MZ\x90\x00".str_repeat("\x00", 100))->assertUnprocessable()->assertJsonValidationErrors('file');
        // Skript .txt nomi bilan.
        $this->upload('run.txt', "#!/bin/sh\nrm -rf /\n")->assertUnprocessable();
        // PNG ichiga yashirilgan PHP.
        $this->upload('rasm.png', self::png('<?php system($_GET["c"]); ?>'))->assertUnprocessable();
        // Matn fayli .png nomi bilan.
        $this->upload('rasm.png', 'just text')->assertUnprocessable();
        // Ruxsat etilmagan va hech qachon qabul qilinmaydigan kengaytmalar.
        $this->upload('page.html', '<html></html>')->assertUnprocessable();
        $this->upload('shell.php', '<?php echo 1;')->assertUnprocessable();
        $this->assertSame(0, DB::table('files')->count());
        $this->assertSame([], Storage::disk(Uploads::DISK)->allFiles());

        $png = $this->upload('rasm.png', self::png())->assertCreated()->json('material.file_id');
        $this->upload('notes.txt', "Uy vazifasi: 1-10 mashqlar\n")->assertCreated();
        $url = $this->getJson("/v1/files/$png/link")->json('url');
        $res = $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertOk();
        $this->assertStringEndsWith('sandbox', $res->headers->get('Content-Security-Policy'));
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
    }

    public function test_office_documents_are_validated_by_their_structure(): void
    {
        Sanctum::actingAs($this->teacher);
        $docx = self::zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>']);
        $this->upload('referat.docx', $docx)->assertCreated();
        // Makrosli hujjat .docx nomi bilan.
        $macro = self::zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w/>', 'word/vbaProject.bin' => 'x']);
        $this->upload('referat.docx', $macro)->assertUnprocessable();
        // Oddiy zip .pptx nomi bilan.
        $this->upload('slides.pptx', self::zip(['readme.txt' => 'hi']))->assertUnprocessable();
    }

    public function test_svg_with_script_is_rejected_when_svg_is_allowed(): void
    {
        app(Settings::class)->set(['uploads.allowed_types' => ['svg']], null);
        Sanctum::actingAs($this->teacher);
        $this->upload('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')->assertUnprocessable();
        $this->upload('b.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>')->assertUnprocessable();
        $this->upload('c.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle r="4"/></svg>')->assertCreated();
        $this->upload('d.pdf', self::pdf())->assertUnprocessable(); // pdf endi ro'yxatda yo'q
    }

    public function test_size_limit_quota_and_usage(): void
    {
        app(Settings::class)->set(['uploads.max_mb' => 1], null);
        Sanctum::actingAs($this->teacher);
        $this->upload('big.pdf', self::pdf().str_repeat('A', 1024 * 1024 + 10))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload('ok.pdf', self::pdf())->assertCreated();

        app(Settings::class)->set(['uploads.quota_mb' => 1, 'uploads.max_mb' => 2], null);
        $r = $this->upload('two.pdf', self::pdf().str_repeat('B', 1024 * 1024 - 50))->assertUnprocessable();
        $this->assertStringContainsString("joy to'lgan", $r->json('errors.file.0'));

        $this->getJson('/v1/files/usage')->assertForbidden();
        $super = User::factory()->role(Role::SuperAdmin)->create();
        Sanctum::actingAs($super);
        $u = $this->getJson('/v1/files/usage')->assertOk();
        $this->assertSame(1, $u->json('files'));
        $this->assertSame(1024 * 1024, $u->json('quota_bytes'));
        $this->assertContains('pdf', $u->json('allowed_types'));
        $this->assertSame(Uploads::backupPaths(), $u->json('paths'));
    }

    public function test_deleting_material_or_file_removes_it_from_disk(): void
    {
        Sanctum::actingAs($this->teacher);
        $r = $this->upload('a.pdf', self::pdf())->assertCreated();
        $path = DB::table('files')->where('id', $r->json('material.file_id'))->value('path');
        $this->deleteJson('/v1/materials/'.$r->json('material.id'))->assertOk();
        Storage::disk(Uploads::DISK)->assertMissing($path);
        $this->assertSame(0, DB::table('files')->count());

        $r = $this->upload('b.pdf', self::pdf())->assertCreated();
        Sanctum::actingAs($this->student);
        $this->deleteJson('/v1/files/'.$r->json('material.file_id'))->assertForbidden();
        Sanctum::actingAs($this->teacher);
        $this->deleteJson('/v1/files/'.$r->json('material.file_id'))->assertOk();
        $this->assertSame(0, DB::table('lesson_materials')->count());
    }

    public function test_sweep_removes_orphaned_files(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->upload('a.pdf', self::pdf())->assertCreated();
        $this->upload('b.pdf', self::pdf())->assertCreated();
        // Dars o'chirilsa (kaskad bilan materiallar ham) — fayllar egasiz qoladi.
        DB::table('lessons')->where('id', $this->lesson->id)->delete();
        $this->travel(2)->days();
        $this->artisan('tisu:files-sweep')->assertSuccessful();
        $this->assertSame(0, DB::table('files')->count());
        $this->assertSame([], Storage::disk(Uploads::DISK)->allFiles());
    }

    public function test_upload_ticket_allows_one_direct_upload_to_its_own_path_only(): void
    {
        $this->postJson('/v1/uploads/ticket', ['path' => 'users/1'])->assertNotFound();
        $ticket = $this->postJson('/v1/uploads/ticket', ['path' => "lessons/{$this->lesson->id}/materials/upload"])->assertOk()->json();
        $this->assertStringEndsWith("/v1/lessons/{$this->lesson->id}/materials/upload", $ticket['url']);

        // Brauzer tokeni (cookie/Sanctum::actingAs) emas — faqat chipta bilan.
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => 'Bearer '.$ticket['token'], 'Accept' => 'application/json'];
        $this->withHeaders($headers)->getJson('/v1/auth/me')->assertForbidden();
        $this->withHeaders($headers)->post("/v1/lessons/{$this->lesson->id}/materials/upload",
            ['file' => UploadedFile::fake()->createWithContent('a.pdf', $this->pdf()), 'title' => 'Material'])->assertCreated();
        // Bir martalik: ikkinchi marta ishlamaydi.
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->post("/v1/lessons/{$this->lesson->id}/materials/upload",
            ['file' => UploadedFile::fake()->createWithContent('b.pdf', $this->pdf()), 'title' => 'Material'])->assertUnauthorized();
    }
}
