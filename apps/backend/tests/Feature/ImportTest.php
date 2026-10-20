<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Course;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Spreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Excel/CSV import: ko'rib chiqish, qator xatolari, dublikatlar, hammasi-yoki-hech-narsa, qayta import idempotent. */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StudyGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $course = Course::create(['title' => 'Ingliz tili', 'created_by' => $this->admin->id]);
        $teacher = User::factory()->role(Role::Teacher)->create(['phone' => '+998900000001']);
        $this->group = StudyGroup::create(['name' => 'ENG-1', 'course_id' => $course->id, 'teacher_id' => $teacher->id, 'start_date' => '2026-09-01', 'active' => true]);
        Sanctum::actingAs($this->admin);
    }

    private function preview(string $bytes, string $name, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/v1/imports/students/preview', ['filename' => $name, 'content' => base64_encode($bytes)] + $extra);
    }

    private function sheet(): array
    {
        return [
            ['Ism', 'Familiya', 'Telefon', 'Ota-ona ismi', 'Ota-ona telefoni', 'Guruh', "Qo'shilgan sana"],
            ['Ali', 'Valiyev', '90 123 45 67', 'Dilnoza', '93 555 44 33', 'eng-1', '15.10.2026'],
            ['Vali', 'Valiyev', '+998 90 123 45 68', '', '935554433', 'ENG-1', ''],   // aka-uka: bitta ota-ona
            ['Aziz', 'Karimov', '901234567', '', '', 'ENG-1', ''],                  // 1-qatordagi telefon takrori
            ['Bobur', '', '12345', '', '', '', ''],                                  // noto'g'ri telefon
            ['Ustoz', '', '900000001', '', '', '', ''],                              // o'qituvchi raqami
            ['Sardor', '', '901112233', '', '', 'Yo\'q guruh', ''],                  // guruh topilmadi
        ];
    }

    public function test_preview_reports_row_errors_and_commit_is_all_or_nothing(): void
    {
        $r = $this->preview(Spreadsheet::writeXlsx($this->sheet()), 'oquvchilar.xlsx')->assertOk();
        $this->assertSame(['rows' => 6, 'valid' => 2, 'errors' => 4, 'new_students' => 2, 'existing_students' => 0,
            'new_parents' => 1, 'links' => 2, 'memberships' => 2, 'already_members' => 0], $r->json('summary'));
        $rows = collect($r->json('rows'))->keyBy('line');
        $this->assertSame('+998901234567', $rows[2]['phone']);
        $this->assertSame('ENG-1', $rows[2]['group_name']);
        $this->assertSame('2026-10-15', $rows[2]['start_date']);
        $this->assertStringContainsString('2-qator', $rows[4]['errors'][0]);
        $this->assertStringContainsString("noto'g'ri", $rows[5]['errors'][0]);
        $this->assertStringContainsString("O'qituvchi", $rows[6]['errors'][0]);
        $this->assertStringContainsString('Guruh topilmadi', $rows[7]['errors'][0]);
        $this->assertSame(0, User::where('role', 'STUDENT')->count()); // ko'rib chiqish bazaga yozmaydi

        $token = $r->json('token');
        $this->postJson('/v1/imports/students/commit', ['token' => $token])->assertStatus(422)->assertJsonValidationErrors('token');
        $this->assertSame(0, User::where('role', 'STUDENT')->count());

        $this->postJson('/v1/imports/students/commit', ['token' => $token, 'skip_invalid' => true])->assertOk()
            ->assertJsonPath('result', ['students_created' => 2, 'students_updated' => 0, 'parents_created' => 1, 'links' => 2, 'memberships' => 2, 'skipped' => 4]);
        $parent = User::where('phone', '+998935554433')->sole();
        $this->assertSame(Role::Parent, $parent->role);
        $this->assertSame(2, DB::table('parent_child')->where('parent_id', $parent->id)->count());
        $this->assertSame(2, DB::table('group_members')->where('group_id', $this->group->id)->whereNull('left_at')->count());
        $this->assertSame('2026-10-15', substr((string) DB::table('enrollments')->where('student_id', User::where('phone', '+998901234567')->value('id'))->value('starts_on'), 0, 10));
        // Token bir martalik.
        $this->postJson('/v1/imports/students/commit', ['token' => $token])->assertStatus(410);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'import.students')->exists());
    }

    public function test_reimporting_the_same_file_creates_nothing_new(): void
    {
        $file = Spreadsheet::writeXlsx(array_slice($this->sheet(), 0, 3));
        $token = $this->preview($file, 'a.xlsx')->json('token');
        $this->postJson('/v1/imports/students/commit', ['token' => $token])->assertOk();
        $before = [User::count(), DB::table('parent_child')->count(), DB::table('enrollments')->count()];

        $r = $this->preview($file, 'a.xlsx')->assertOk();
        $this->assertEquals(['existing_students' => 2, 'new_students' => 0, 'new_parents' => 0, 'links' => 0, 'memberships' => 0, 'already_members' => 2],
            array_intersect_key($r->json('summary'), array_flip(['existing_students', 'new_students', 'new_parents', 'links', 'memberships', 'already_members'])));
        $this->postJson('/v1/imports/students/commit', ['token' => $r->json('token')])->assertOk()
            ->assertJsonPath('result.students_created', 0)->assertJsonPath('result.memberships', 0);
        $this->assertSame($before, [User::count(), DB::table('parent_child')->count(), DB::table('enrollments')->count()]);
    }

    public function test_csv_with_aliases_cp1251_default_group_and_excel_dates(): void
    {
        $csv = "\xEF\xBB\xBFFIO,Tel,Ota-ona telefoni,Sana\n\"Ali Valiyev\",=\"+998901234567\",,46300\nNodir,'901234599,,01.10.26\n";
        $r = $this->preview($csv, 'list.csv', ['group_id' => $this->group->id])->assertOk();
        $rows = $r->json('rows');
        $this->assertSame(['Ali', 'Valiyev', '+998901234567', 'ENG-1', '2026-10-05'], [$rows[0]['first_name'], $rows[0]['last_name'], $rows[0]['phone'], $rows[0]['group_name'], $rows[0]['start_date']]);
        $this->assertSame(['+998901234599', '2026-10-01'], [$rows[1]['phone'], $rows[1]['start_date']]);
        $this->assertSame(2, $r->json('summary.valid'));

        $cyr = mb_convert_encoding("Имя;Телефон\nАлишер;901234511\n", 'Windows-1251', 'UTF-8');
        $this->assertSame('Алишер', $this->preview($cyr, 'ru.csv')->assertOk()->json('rows.0.first_name'));

        $this->preview("Nomi;Raqam\nA;1\n", 'x.csv')->assertStatus(422)->assertJsonValidationErrors('content');
        $this->preview('hello', 'x.pdf')->assertStatus(422);
        $this->preview("PK\x03\x04garbage", 'x.xlsx')->assertStatus(422);
    }

    public function test_xlsx_with_shared_strings_numeric_phones_and_stored_entries(): void
    {
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $xlsx = Spreadsheet::zip([
            'xl/sharedStrings.xml' => "<sst $ns><si><t>Ism</t></si><si><t>Telefon</t></si><si><r><t>Ma</t></r><r><t>lika</t></r></si></sst>",
            'xl/worksheets/sheet1.xml' => "<worksheet $ns><sheetData>"
                .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row>'
                .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="C2"><v>9.98907776655E+11</v></c></row>'
                .'</sheetData></worksheet>',
        ], deflate: false);
        $r = $this->preview($xlsx, 'm.xlsx')->assertOk();
        $this->assertSame(['Malika', '+998907776655'], [$r->json('rows.0.first_name'), $r->json('rows.0.phone')]);
    }

    public function test_template_downloads_and_permissions(): void
    {
        $x = $this->get('/v1/imports/students/template')->assertOk();
        $rows = Spreadsheet::read($x->getContent(), 't.xlsx');
        $this->assertSame('Ism', $rows[0][0]);
        $this->assertSame('+998901234567', $rows[1][2]);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $this->get('/v1/imports/students/template?format=csv')->assertOk()->getContent());

        Sanctum::actingAs(User::factory()->role(Role::Teacher)->create());
        $this->preview(Spreadsheet::writeXlsx($this->sheet()), 'a.xlsx')->assertForbidden();
    }
}
