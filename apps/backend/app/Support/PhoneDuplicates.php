<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Takroriy akkauntlarni topish (Phone::normalize bo'yicha) va xavfsiz birlashtirish.
 * Birlashtirish faqat tarixsiz takroriy akkauntga ruxsat etiladi: tarix hech qachon ko'chirilmaydi va o'chirilmaydi.
 */
final class PhoneDuplicates
{
    /** Akkaunt tarixi: shu jadvallarda yozuvi bor akkaunt birlashtirilmaydi. */
    private const HISTORY = [
        ['group_members', 'student_id'], ['attendances', 'student_id'], ['attendances', 'marked_by'],
        ['quiz_attempts', 'student_id'], ['certificates', 'student_id'], ['billing_entries', 'student_id'],
        ['ledger_entries', 'student_id'], ['study_groups', 'teacher_id'], ['courses', 'created_by'], ['announcements', 'created_by'],
        ['enrollments', 'student_id'], ['leads', 'student_id'], ['lead_activities', 'user_id'], ['assignments', 'created_by'],
        ['assignment_submissions', 'student_id'], ['assignment_submissions', 'graded_by'], ['session_marks', 'student_id'],
        ['session_marks', 'marked_by'], ['files', 'uploaded_by'],
    ];

    /** @return array{phone_groups: list<array>, non_canonical: list<array>, name_groups: list<array>} */
    public function report(): array
    {
        $users = DB::table('users')->orderBy('id')
            ->get(['id', 'phone', 'first_name', 'last_name', 'role', 'active', 'pending_at', 'created_at']);
        $present = fn ($u) => [
            'id' => $u->id, 'phone' => $u->phone, 'name' => trim($u->first_name.' '.$u->last_name), 'role' => $u->role,
            'active' => (bool) $u->active, 'pending' => $u->pending_at !== null, 'created_at' => $u->created_at,
        ];
        $byPhone = [];
        $nonCanonical = [];
        $byName = [];
        foreach ($users as $u) {
            if (str_starts_with((string) $u->phone, 'merged:')) {
                continue;
            }
            // Xorijiy raqamlarni ham guruhlash uchun (sozlamadan qat'i nazar) normal shakl.
            $norm = Phone::normalize($u->phone, true);
            $byPhone[$norm ?? $u->phone][] = $u;
            if ($norm !== $u->phone) {
                $nonCanonical[] = $present($u) + ['normalized' => $norm];
            }
            if ($u->last_name) {
                $byName[mb_strtolower(trim($u->first_name).' '.trim($u->last_name))][] = $u;
            }
        }
        $groups = fn (array $map, string $keyName) => array_values(array_map(
            fn ($key, $list) => [$keyName => $key, 'users' => array_map(fn ($u) => $present($u) + ['history' => $this->hasHistory($u->id)], $list)],
            array_keys(array_filter($map, fn ($l) => count($l) > 1)), array_filter($map, fn ($l) => count($l) > 1)));

        return [
            'phone_groups' => $groups($byPhone, 'phone'),
            'non_canonical' => $nonCanonical,
            // Bir xil ism-familiya, turli raqam: ehtimoliy takror (faqat ko'rib chiqish uchun).
            'name_groups' => array_values(array_filter($groups($byName, 'name'),
                fn ($g) => count(array_unique(array_map(fn ($u) => Phone::normalize($u['phone'], true) ?? $u['phone'], $g['users']))) > 1)),
        ];
    }

    public function hasHistory(int $userId): bool
    {
        return $this->historyTables($userId) !== [];
    }

    /** @return list<string> */
    public function historyTables(int $userId): array
    {
        $found = [];
        foreach (self::HISTORY as [$table, $column]) {
            if (DB::table($table)->where($column, $userId)->exists()) {
                $found[] = $table;
            }
        }

        return array_values(array_unique($found));
    }

    /** $from (tarixsiz) → $into. @return array{from_phone: string, moved: array} */
    public function merge(User $from, User $into): array
    {
        $tables = $this->historyTables($from->id);
        abort_if($tables !== [], 409, "Bu akkauntda tarix bor (".implode(', ', $tables)."): birlashtirib bo'lmaydi. Asosiy akkaunt sifatida shu akkauntni tanlang.");

        return DB::transaction(function () use ($from, $into) {
            $moved = ['telegram' => false];
            // Telegram: asosiy akkauntda yo'q bo'lsa ko'chiriladi.
            if (! DB::table('telegram_links')->where('user_id', $into->id)->exists()) {
                $moved['telegram'] = (bool) DB::table('telegram_links')->where('user_id', $from->id)->update(['user_id' => $into->id]);
            } else {
                DB::table('telegram_links')->where('user_id', $from->id)->delete();
            }
            $fromPhone = $from->phone;
            // Arxiv: telefon bo'shatiladi (asosiy akkaunt normal raqamni olishi uchun), akkaunt faol emas, tokenlar bekor.
            $from->forceFill(['phone' => mb_substr('merged:'.$from->id.':'.$fromPhone, 0, 32), 'active' => false, 'pending_at' => null])->save();
            $from->tokens()->delete();
            $norm = Phone::normalize($into->phone, true);
            if ($norm && $norm !== $into->phone && ! User::where('phone', $norm)->exists()) {
                $into->forceFill(['phone' => $norm])->save();
            }

            return ['from_phone' => $fromPhone, 'moved' => $moved];
        });
    }

    /** Normal bo'lmagan raqamlarni to'g'rilash (to'qnashuv bo'lmasa). @return array{fixed: list, conflicts: list, invalid: list} */
    public function normalizeAll(bool $apply): array
    {
        $out = ['fixed' => [], 'conflicts' => [], 'invalid' => []];
        foreach ($this->report()['non_canonical'] as $u) {
            if ($u['normalized'] === null) {
                $out['invalid'][] = $u;
            } elseif (User::where('phone', $u['normalized'])->where('id', '!=', $u['id'])->exists()) {
                $out['conflicts'][] = $u;
            } else {
                if ($apply) {
                    DB::table('users')->where('id', $u['id'])->update(['phone' => $u['normalized'], 'updated_at' => now()]);
                }
                $out['fixed'][] = $u;
            }
        }

        return $out;
    }
}
