<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1000+ o'quvchi va 2 yillik ma'lumot uchun indekslar (balanslar, KPI, eslatmalar, tozalash, hisobotlar).
 * Har biri faqat mavjud bo'lmasa qo'shiladi.
 */
return new class extends Migration
{
    private const INDEXES = [
        'billing_entries' => [['student_id', 'voided_at', 'amount'], ['entry_date']],
        'notifications_log' => [['created_at'], ['user_id', 'kind'], ['channel', 'created_at']],
        'telegram_outbox' => [['status', 'updated_at']],
        'class_sessions' => [['starts_at']],
        'audit_logs' => [['action'], ['created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($sets as $cols) {
                if (array_diff($cols, Schema::getColumnListing($table)) || Schema::hasIndex($table, $cols)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($cols, $this->name($table, $cols)));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $cols) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $this->name($table, $cols))) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($this->name($table, $cols)));
                }
            }
        }
    }

    private function name(string $table, array $cols): string
    {
        return substr('perf_'.$table.'_'.implode('_', $cols), 0, 60);
    }
};
