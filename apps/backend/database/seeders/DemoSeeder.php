<?php

namespace Database\Seeders;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\SessionGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FAQAT LOKAL ishlab chiqish va dizaynni tekshirish uchun namunaviy ma'lumotlar.
 * Production'da ishlamaydi.
 *
 * Test akkauntlar (parol hammasi uchun: demo-parol-123):
 *   +998900000001 — Super-admin
 *   +998900000002 — O'qituvchi
 *   +998900000010 — O'quvchi
 *   +998900000020 — Ota-ona
 *
 *   php artisan db:seed --class=Database\\Seeders\\DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder production muhitida ishlatilmaydi.');
        }
        $pw = 'demo-parol-123';
        $mk = fn (string $phone, string $first, string $last, Role $role) => User::updateOrCreate(['phone' => $phone],
            ['first_name' => $first, 'last_name' => $last, 'role' => $role, 'password' => $pw, 'active' => true]);

        $admin = $mk('+998900000001', 'Javohir', 'Karimov', Role::SuperAdmin);
        $teacher = $mk('+998900000002', 'Dilnoza', 'Rahimova', Role::Teacher);
        $teacher2 = $mk('+998900000003', 'Bekzod', 'Tursunov', Role::Teacher);
        $names = [['Aziz', 'Qodirov'], ['Laylo', 'Nazarova'], ['Sardor', 'Aliyev'], ['Malika', 'Yusupova'], ['Jasur', 'Ergashev'],
            ['Nilufar', 'Saidova'], ['Otabek', 'Mirzaev'], ['Zarina', 'Hasanova']];
        $students = [];
        foreach ($names as $i => [$f, $l]) {
            $students[] = $mk('+9989000000'.(10 + $i), $f, $l, Role::Student);
        }
        $parent = $mk('+998900000020', 'Gulnora', 'Qodirova', Role::Parent);
        DB::table('parent_child')->insertOrIgnore(['parent_id' => $parent->id, 'child_id' => $students[0]->id]);

        $branch = Branch::firstOrCreate(['name' => 'Markaziy filial'], ['address' => 'Toshkent, Chilonzor 7', 'phone' => '+998712000000']);
        Branch::firstOrCreate(['name' => 'Yunusobod filiali'], ['address' => 'Toshkent, Yunusobod 4']);

        $english = Course::firstOrCreate(['title' => 'Ingliz tili — Elementary'], [
            'description' => "Grammatika, so'z boyligi va og'zaki nutq. Haftasiga 3 dars.", 'duration_months' => 4, 'published' => true, 'created_by' => $teacher->id,
        ]);
        $python = Course::firstOrCreate(['title' => 'Python dasturlash asoslari'], [
            'description' => "Noldan boshlab: o'zgaruvchilar, sikllar, funksiyalar va kichik loyihalar.", 'duration_months' => 3, 'published' => true, 'created_by' => $teacher2->id,
        ]);
        if (! $english->modules()->exists()) {
            foreach (['Kirish va salomlashish' => ['Alifbo va talaffuz', 'Salomlashish iboralari', "O'zingiz haqingizda"],
                'Present Simple' => ['Tasdiq gaplar', "So'roq va inkor", 'Kundalik hayot']] as $mt => $lessons) {
                $m = $english->modules()->create(['title' => $mt, 'position' => $english->modules()->count() + 1]);
                foreach ($lessons as $i => $lt) {
                    $l = $m->lessons()->create(['title' => $lt, 'position' => $i + 1, 'published' => true,
                        'body' => "Bu darsda «{$lt}» mavzusini o'rganamiz.\n\n1. Yangi so'zlar\n2. Mashqlar\n3. Uyga vazifa"]);
                    $l->materials()->create(['type' => 'VIDEO', 'title' => 'Video dars', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'position' => 1]);
                    $l->materials()->create(['type' => 'LINK', 'title' => "Qo'shimcha mashqlar", 'url' => 'https://learnenglish.britishcouncil.org', 'position' => 2]);
                }
            }
        }
        if (! $python->modules()->exists()) {
            $m = $python->modules()->create(['title' => 'Asoslar', 'position' => 1]);
            foreach (["O'rnatish va birinchi dastur", "O'zgaruvchilar", 'Shartlar'] as $i => $lt) {
                $m->lessons()->create(['title' => $lt, 'position' => $i + 1, 'published' => true, 'body' => "«{$lt}» mavzusi."]);
            }
        }

        $group = StudyGroup::firstOrCreate(['name' => 'ENG-E1 (ertalabki)'], [
            'course_id' => $english->id, 'branch_id' => $branch->id, 'teacher_id' => $teacher->id,
            'start_date' => now()->subWeeks(5)->toDateString(), 'room' => '204',
        ]);
        if (! $group->schedules()->exists()) {
            foreach ([1, 3, 5] as $wd) {
                $group->schedules()->create(['weekday' => $wd, 'start_time' => '09:00', 'end_time' => '10:30']);
            }
        }
        $group2 = StudyGroup::firstOrCreate(['name' => 'PY-1 (kechki)'], [
            'course_id' => $python->id, 'branch_id' => $branch->id, 'teacher_id' => $teacher2->id,
            'start_date' => now()->subWeeks(3)->toDateString(), 'room' => 'Lab-1',
        ]);
        if (! $group2->schedules()->exists()) {
            foreach ([2, 4] as $wd) {
                $group2->schedules()->create(['weekday' => $wd, 'start_time' => '18:00', 'end_time' => '19:30']);
            }
        }
        foreach ($students as $i => $s) {
            DB::table('group_members')->insertOrIgnore(['group_id' => $i < 6 ? $group->id : $group2->id, 'student_id' => $s->id, 'joined_at' => now()->subWeeks(5)]);
        }
        $gen = app(SessionGenerator::class);
        foreach ([$group, $group2] as $g) {
            $gen->generate($g->load('schedules'), now()->subWeeks(5)->toDateString(), now()->addWeeks(2)->toDateString());
        }

        // O'tgan darslar davomati (oxirgisi belgilanmagan qoladi — "belgilanmaganlar" ko'rinsin)
        $pattern = ['PRESENT', 'PRESENT', 'PRESENT', 'LATE', 'PRESENT', 'ABSENT', 'PRESENT', 'EXCUSED', 'PRESENT', 'PRESENT'];
        foreach ([$group, $group2] as $g) {
            $past = DB::table('class_sessions')->where('group_id', $g->id)->where('starts_at', '<', now())->orderBy('starts_at')->get();
            $members = DB::table('group_members')->where('group_id', $g->id)->pluck('student_id');
            foreach ($past->slice(0, max(0, $past->count() - 1)) as $k => $sess) {
                foreach ($members as $j => $sid) {
                    DB::table('attendances')->insertOrIgnore(['session_id' => $sess->id, 'student_id' => $sid,
                        'status' => $pattern[($k * 3 + $j * 7) % count($pattern)], 'marked_by' => $g->teacher_id, 'marked_at' => $sess->starts_at]);
                }
            }
        }

        if (! DB::table('quizzes')->where('course_id', $english->id)->exists()) {
            $quizId = DB::table('quizzes')->insertGetId(['course_id' => $english->id, 'title' => 'Yakuniy test — Present Simple', 'is_final' => true,
                'pass_percent' => 60, 'max_attempts' => 3, 'time_limit_sec' => 900, 'shuffle' => true, 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([['He ___ to school every day.', ['go', 'goes', 'going'], 1], ['They ___ football on Sundays.', ['play', 'plays', 'played'], 0],
                ['___ she like tea?', ['Do', 'Does', 'Is'], 1]] as $i => [$text, $opts, $right]) {
                $qid = DB::table('questions')->insertGetId(['quiz_id' => $quizId, 'type' => 'SINGLE', 'text' => $text, 'points' => 1, 'position' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
                foreach ($opts as $j => $o) {
                    DB::table('question_options')->insert(['question_id' => $qid, 'text' => $o, 'is_correct' => $j === $right, 'position' => $j + 1]);
                }
            }
        }

        if (! DB::table('announcements')->exists()) {
            DB::table('announcements')->insert([
                ['title' => "Yangi Python guruhi ochildi", 'body' => "Noyabr oyidan kechki Python guruhiga qabul boshlandi. Joylar soni cheklangan!", 'audience' => 'ALL',
                    'audience_role' => null, 'group_id' => null, 'is_public' => true, 'pinned' => true, 'notify_telegram' => false,
                    'published_at' => now()->subDays(1), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
                ['title' => "Juma kungi dars ko'chirildi", 'body' => "ENG-E1 guruhining juma kungi darsi shanba 10:00 ga ko'chirildi.", 'audience' => 'GROUP',
                    'audience_role' => null, 'group_id' => $group->id, 'is_public' => false, 'pinned' => false, 'notify_telegram' => false,
                    'published_at' => now()->subHours(3), 'created_by' => $teacher->id, 'created_at' => now(), 'updated_at' => now()],
                ['title' => "Ota-onalar yig'ilishi", 'body' => "26-oktabr kuni soat 18:00 da markaziy filialda ota-onalar yig'ilishi bo'lib o'tadi.", 'audience' => 'ROLE',
                    'audience_role' => 'PARENT', 'group_id' => null, 'is_public' => false, 'pinned' => false, 'notify_telegram' => false,
                    'published_at' => now()->subDays(2), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }
}
