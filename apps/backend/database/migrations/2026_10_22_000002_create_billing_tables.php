<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // To'lovlar jurnali — o'zgarmas: yozuv o'chirilmaydi va tahrirlanmaydi, faqat bekor qilinadi (voided_*).
        // amount ishorali: CHARGE < 0, PAYMENT > 0, ADJUSTMENT ±. Balans = SUM(amount) WHERE voided_at IS NULL.
        Schema::create('billing_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('users');
            $t->foreignId('group_id')->nullable()->constrained('study_groups')->nullOnDelete();
            $t->string('type', 16); // CHARGE | PAYMENT | ADJUSTMENT
            $t->bigInteger('amount'); // so'm
            $t->date('entry_date');
            $t->string('method', 32)->nullable(); // faqat PAYMENT: billing.method_labels kalitlari
            $t->string('note', 500)->nullable();
            $t->json('meta')->nullable();
            // Takrorlanishdan himoya: CHARGE uchun "c:{student}:{group}:{sana}" (kunlik hisoblash idempotent),
            // PAYMENT uchun mijoz yuborgan so'rov kaliti (ikki marta bosilsa ikkinchi yozuv yaratilmaydi).
            $t->string('dedupe_key', 96)->nullable()->unique();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('void_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['student_id', 'entry_date']);
            $t->index(['type', 'entry_date']);
        });

        // Oylik narx tarixi: narx o'zgarsa, faqat o'zgargan kundan boshlab qo'llanadi (orqaga qaytmaydi).
        Schema::create('billing_fee_history', function (Blueprint $t) {
            $t->id();
            $t->date('effective_from')->unique();
            $t->unsignedBigInteger('monthly_fee');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        // Huquqlar matritsasi oldin saqlangan bo'lsa, ADMIN yangi billing.manage huquqini avtomatik oladi.
        $row = DB::table('settings')->where('key', 'roles.permissions')->first();
        if ($row) {
            $matrix = json_decode($row->value, true) ?: [];
            if (isset($matrix['ADMIN']) && is_array($matrix['ADMIN']) && ! in_array('billing.manage', $matrix['ADMIN'], true)) {
                $matrix['ADMIN'][] = 'billing.manage';
                DB::table('settings')->where('key', 'roles.permissions')
                    ->update(['value' => json_encode($matrix, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                Cache::forget('tisu.settings.v1');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_fee_history');
        Schema::dropIfExists('billing_entries');
    }
};
