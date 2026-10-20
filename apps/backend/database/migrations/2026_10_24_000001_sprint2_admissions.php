<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2 — "Qabuldan o'qishgacha":
 *  - enrollments: a'zolik tarixi (davrlar). Har bir qatorda holat oralig'i: ACTIVE yoki FROZEN (sanalar bilan).
 *    Davrning oxirgi qatorida natija: LEFT (sabab) / GRADUATED / TRANSFERRED (qaysi guruhga).
 *    group_members — "hozirgi a'zolik" ko'rsatkichi sifatida qoladi (joined_at/left_at ma'nosi o'zgarmaydi).
 *  - leads / lead_activities: arizalar (sayt, bot, telefon...) va ular bo'yicha qo'ng'iroq/izohlar.
 *  - lead.manage huquqi: saqlangan matritsada ADMIN ga avtomatik qo'shiladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained('study_groups')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $t->string('status', 16); // ACTIVE | FROZEN
            $t->date('starts_on');
            $t->date('ends_on')->nullable(); // null — davom etmoqda
            $t->string('outcome', 16)->nullable(); // LEFT | GRADUATED | TRANSFERRED — davr shu qator bilan yopilgan
            $t->string('reason', 255)->nullable(); // muzlatish/chiqish sababi
            $t->foreignId('transfer_group_id')->nullable()->constrained('study_groups')->nullOnDelete();
            $t->string('note', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['group_id', 'student_id', 'starts_on']);
            $t->index(['student_id', 'starts_on']);
        });

        // Mavjud a'zoliklardan tarix: bitta ACTIVE davr (chiqib ketganlar — LEFT bilan yopilgan).
        $now = now();
        DB::table('group_members')->orderBy('group_id')->orderBy('student_id')->chunk(500, function ($rows) use ($now) {
            DB::table('enrollments')->insert($rows->map(fn ($m) => [
                'group_id' => $m->group_id, 'student_id' => $m->student_id, 'status' => 'ACTIVE',
                'starts_on' => substr((string) $m->joined_at, 0, 10),
                'ends_on' => $m->left_at ? substr((string) $m->left_at, 0, 10) : null,
                'outcome' => $m->left_at ? 'LEFT' : null,
                'created_at' => $now, 'updated_at' => $now,
            ])->all());
        });

        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('name', 128);
            $t->string('phone', 32)->index();
            $t->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $t->foreignId('group_id')->nullable()->constrained('study_groups')->nullOnDelete();
            $t->string('source', 32); // leads.sources kalitlari: site, bot, phone, referral, instagram, other
            $t->string('status', 16)->default('NEW'); // NEW | CONTACTED | TRIAL | ENROLLED | LOST
            $t->string('lost_reason', 255)->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('next_contact_on')->nullable();
            $t->foreignId('trial_session_id')->nullable()->constrained('class_sessions')->nullOnDelete();
            $t->text('comment')->nullable(); // arizachining o'z izohi (forma/bot)
            $t->text('notes')->nullable(); // xodim izohi
            $t->bigInteger('telegram_id')->nullable();
            $t->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete(); // yozilgan o'quvchi
            $t->timestamp('converted_at')->nullable();
            $t->timestamp('consent_at')->nullable();
            $t->json('meta')->nullable(); // ip, user-agent, utm
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'next_contact_on']);
            $t->index(['source', 'created_at']);
        });

        Schema::create('lead_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('type', 16); // NOTE | CALL | STATUS | SYSTEM
            $t->text('text')->nullable();
            $t->json('data')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['lead_id', 'created_at']);
        });

        $row = DB::table('settings')->where('key', 'roles.permissions')->first();
        if ($row) {
            $matrix = json_decode($row->value, true) ?: [];
            if (isset($matrix['ADMIN']) && is_array($matrix['ADMIN']) && ! in_array('lead.manage', $matrix['ADMIN'], true)) {
                $matrix['ADMIN'][] = 'lead.manage';
                DB::table('settings')->where('key', 'roles.permissions')
                    ->update(['value' => json_encode($matrix, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                Cache::forget('tisu.settings.v1');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('enrollments');
    }
};
