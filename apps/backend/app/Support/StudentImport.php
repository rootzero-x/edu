<?php

namespace App\Support;

use App\Domain\Role;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O'quvchilarni Excel/CSV dan import qilish: ota-ona telefoni bilan bog'lash va guruhga qo'shish.
 *
 *  - plan(): fayl qatorlarini tekshiradi va har bir qator uchun reja tuzadi (bazaga yozmaydi — "quruq ishga tushirish").
 *  - commit(): rejani bitta tranzaksiyada bajaradi (yoki xatoli qatorlarni tashlab).
 *  - Dublikatlar telefon (normallashtirilgan) bo'yicha topiladi; qayta import hech narsani ikki marta yaratmaydi.
 */
final class StudentImport
{
    public const FIELDS = ['first_name', 'last_name', 'phone', 'parent_name', 'parent_phone', 'group', 'start_date'];

    public function __construct(private Settings $settings, private Enrollments $enrollments) {}

    /** Shablon sarlavhasi (sozlamadagi nomlar). @return list<string> */
    public function headers(): array
    {
        $labels = (array) $this->settings->get('import.columns');

        return array_map(fn ($f) => (string) ($labels[$f] ?? $f), self::FIELDS);
    }

    /**
     * @param  list<list<string>>  $rows  birinchi qator — sarlavha
     * @return array{columns: array<string,int>, unknown: list<string>, rows: list<array>, summary: array}
     */
    public function plan(array $rows, ?int $defaultGroupId = null, ?string $defaultDate = null): array
    {
        $header = array_shift($rows) ?? [];
        $columns = $this->mapHeader($header);
        if (! isset($columns['phone']) || ! isset($columns['first_name'])) {
            throw new \RuntimeException('Sarlavhada kamida «'.$this->headers()[0].'» va «'.$this->headers()[2]."» ustunlari bo'lishi kerak. Shablonni yuklab oling.");
        }
        $max = $this->settings->int('import.max_rows');
        if (count($rows) > $max) {
            throw new \RuntimeException("Faylda $max tadan ko'p qator bor.");
        }
        $unknown = array_values(array_filter($header, fn ($h, $i) => trim((string) $h) !== '' && ! in_array($i, $columns, true), ARRAY_FILTER_USE_BOTH));

        $groups = StudyGroup::query()->get(['id', 'name', 'active']);
        $defaultGroup = $defaultGroupId ? $groups->firstWhere('id', $defaultGroupId) : null;
        $out = [];
        $studentPhones = [];
        $parentPhones = [];
        foreach ($rows as $i => $cells) {
            $line = $i + 2;
            $get = fn (string $f) => isset($columns[$f]) ? $this->clean($cells[$columns[$f]] ?? '') : '';
            $r = ['line' => $line, 'first_name' => $get('first_name'), 'last_name' => $get('last_name'), 'phone_raw' => $get('phone'),
                'parent_name' => $get('parent_name'), 'parent_phone_raw' => $get('parent_phone'), 'group_raw' => $get('group'), 'start_date_raw' => $get('start_date'),
                'errors' => [], 'warnings' => []];
            if ($r['last_name'] === '' && ! isset($columns['last_name']) && str_contains($r['first_name'], ' ')) {
                [$r['first_name'], $r['last_name']] = array_pad(preg_split('/\s+/u', $r['first_name'], 2), 2, '');
            }
            if ($r['first_name'] === '') {
                $r['errors'][] = 'Ism kiritilmagan.';
            }
            $r['phone'] = PhoneNormalizer::normalize($r['phone_raw']);
            if (! $r['phone']) {
                $r['errors'][] = $r['phone_raw'] === '' ? 'Telefon kiritilmagan.' : "Telefon noto'g'ri: {$r['phone_raw']}";
            } elseif (isset($studentPhones[$r['phone']])) {
                $r['errors'][] = "Bu telefon faylda takrorlangan ({$studentPhones[$r['phone']]}-qator).";
            } else {
                $studentPhones[$r['phone']] = $line;
            }
            $r['parent_phone'] = null;
            if ($r['parent_phone_raw'] !== '') {
                $r['parent_phone'] = PhoneNormalizer::normalize($r['parent_phone_raw']);
                if (! $r['parent_phone']) {
                    $r['errors'][] = "Ota-ona telefoni noto'g'ri: {$r['parent_phone_raw']}";
                } elseif ($r['parent_phone'] === $r['phone']) {
                    $r['errors'][] = "O'quvchi va ota-ona telefoni bir xil.";
                } else {
                    $parentPhones[$r['parent_phone']][] = $line;
                }
            }
            // Guruh: nomi yoki ID bo'yicha; ustun bo'sh bo'lsa — tanlangan standart guruh.
            $r['group_id'] = null;
            $r['group_name'] = null;
            if ($r['group_raw'] !== '') {
                $needle = mb_strtolower($r['group_raw']);
                $match = $groups->filter(fn ($g) => mb_strtolower(trim($g->name)) === $needle || (ctype_digit($r['group_raw']) && (int) $r['group_raw'] === $g->id));
                if ($match->count() > 1) {
                    $match = $match->where('active', true);
                }
                if ($match->count() === 1) {
                    $g = $match->first();
                    [$r['group_id'], $r['group_name']] = [$g->id, $g->name];
                    if (! $g->active) {
                        $r['errors'][] = "«{$g->name}» guruhi faol emas.";
                    }
                } else {
                    $r['errors'][] = $match->isEmpty() ? "Guruh topilmadi: {$r['group_raw']}" : "Bu nomdagi guruh bir nechta: {$r['group_raw']}. ID raqamini yozing.";
                }
            } elseif ($defaultGroup) {
                [$r['group_id'], $r['group_name']] = [$defaultGroup->id, $defaultGroup->name];
            }
            $r['start_date'] = null;
            $rawDate = $r['start_date_raw'] !== '' ? $r['start_date_raw'] : (string) $defaultDate;
            if ($rawDate !== '') {
                $r['start_date'] = $this->parseDate($rawDate);
                $maxBack = $this->settings->int('enrollment.max_backdate_days');
                if (! $r['start_date']) {
                    $r['errors'][] = "Sana noto'g'ri: $rawDate (masalan: 01.09.2026)";
                } elseif ($r['group_id'] && $r['start_date'] < today()->subDays($maxBack)->toDateString()) {
                    $r['errors'][] = "Qo'shilgan sana $maxBack kundan eski bo'lmasligi kerak.";
                }
            }
            $out[] = $r;
        }

        // Bazadagi mavjud foydalanuvchilar (bitta so'rov bilan).
        $phones = array_values(array_unique(array_filter(array_merge(array_column($out, 'phone'), array_column($out, 'parent_phone')))));
        $existing = User::whereIn('phone', $phones)->get(['id', 'phone', 'first_name', 'last_name', 'role'])->keyBy('phone');
        $members = DB::table('group_members')->whereIn('student_id', $existing->pluck('id'))->whereNull('left_at')
            ->get(['group_id', 'student_id'])->map(fn ($m) => $m->group_id.':'.$m->student_id)->flip();
        $links = DB::table('parent_child')->whereIn('child_id', $existing->pluck('id'))->get()->map(fn ($l) => $l->parent_id.':'.$l->child_id)->flip();
        $roleLabels = (array) $this->settings->get('telegram.role_labels');
        $summary = ['rows' => count($out), 'valid' => 0, 'errors' => 0, 'new_students' => 0, 'existing_students' => 0,
            'new_parents' => 0, 'links' => 0, 'memberships' => 0, 'already_members' => 0];
        $newParents = [];
        foreach ($out as &$r) {
            $student = $r['phone'] ? $existing->get($r['phone']) : null;
            if ($student && $student->role !== Role::Student) {
                $r['errors'][] = "Bu telefon {$this->who($student, $roleLabels)} ga tegishli.";
            }
            if ($r['phone'] && isset($parentPhones[$r['phone']])) {
                $r['errors'][] = "Bu telefon faylda ota-ona telefoni sifatida ham yozilgan (".implode(', ', $parentPhones[$r['phone']])."-qator).";
            }
            $r['student_id'] = $student?->id;
            $r['action'] = $student ? 'existing' : 'new';
            if ($student && ($student->first_name !== $r['first_name'] || (string) $student->last_name !== $r['last_name'])) {
                $r['warnings'][] = $this->settings->bool('import.update_names')
                    ? "Ism yangilanadi: {$student->first_name} {$student->last_name} → {$r['first_name']} {$r['last_name']}"
                    : "Bazada boshqa ism bilan: {$student->first_name} {$student->last_name}";
            }
            $parent = $r['parent_phone'] ? $existing->get($r['parent_phone']) : null;
            if ($parent && $parent->role !== Role::Parent) {
                $r['errors'][] = "Ota-ona telefoni {$this->who($parent, $roleLabels)} ga tegishli.";
            }
            $r['parent_id'] = $parent?->id;
            $r['parent_action'] = $r['parent_phone'] ? ($parent ? (isset($links[$parent->id.':'.$student?->id]) ? 'linked' : 'link') : 'new') : null;
            $r['membership'] = $r['group_id'] ? ($student && isset($members[$r['group_id'].':'.$student->id]) ? 'already' : 'add') : null;
            $r['status'] = $r['errors'] ? 'error' : 'ok';
            if ($r['errors']) {
                $summary['errors']++;

                continue;
            }
            $summary['valid']++;
            $summary[$student ? 'existing_students' : 'new_students']++;
            if ($r['parent_action'] === 'new' && ! isset($newParents[$r['parent_phone']])) {
                $newParents[$r['parent_phone']] = true;
                $summary['new_parents']++;
            }
            if (in_array($r['parent_action'], ['new', 'link'], true)) {
                $summary['links']++;
            }
            if ($r['membership'] === 'add') {
                $summary['memberships']++;
            } elseif ($r['membership'] === 'already') {
                $summary['already_members']++;
            }
        }
        unset($r);

        return ['columns' => $columns, 'unknown' => $unknown, 'rows' => $out, 'summary' => $summary];
    }

    /**
     * Rejani bajarish. Xato qator bo'lsa va $skipInvalid=false — hech narsa yozilmaydi.
     *
     * @return array{students_created:int, students_updated:int, parents_created:int, links:int, memberships:int, skipped:int}
     */
    public function commit(array $plan, bool $skipInvalid, int $actorId): array
    {
        $rows = array_values(array_filter($plan['rows'], fn ($r) => $r['status'] === 'ok'));
        $skipped = count($plan['rows']) - count($rows);
        if ($skipped > 0 && ! $skipInvalid) {
            throw new \RuntimeException("Faylda $skipped ta xato qator bor. Tuzating yoki «faqat to'g'ri qatorlar» ni tanlang.");
        }
        $res = ['students_created' => 0, 'students_updated' => 0, 'parents_created' => 0, 'links' => 0, 'memberships' => 0, 'skipped' => $skipped];
        DB::transaction(function () use ($rows, $actorId, &$res) {
            $groups = StudyGroup::whereIn('id', array_filter(array_column($rows, 'group_id')))->get()->keyBy('id');
            $update = $this->settings->bool('import.update_names');
            foreach ($rows as $r) {
                $student = User::where('phone', $r['phone'])->first();
                if (! $student) {
                    $student = User::create(['phone' => $r['phone'], 'first_name' => mb_substr($r['first_name'], 0, 64),
                        'last_name' => $r['last_name'] !== '' ? mb_substr($r['last_name'], 0, 64) : null, 'role' => Role::Student, 'active' => true]);
                    $res['students_created']++;
                } elseif ($student->role !== Role::Student) {
                    throw new \RuntimeException("{$r['line']}-qator: telefon boshqa rolga tegishli.");
                } elseif ($update && ($student->first_name !== $r['first_name'] || (string) $student->last_name !== $r['last_name'])) {
                    $student->update(['first_name' => mb_substr($r['first_name'], 0, 64), 'last_name' => $r['last_name'] !== '' ? mb_substr($r['last_name'], 0, 64) : null]);
                    $res['students_updated']++;
                }
                if ($r['parent_phone']) {
                    $parent = User::where('phone', $r['parent_phone'])->first();
                    if (! $parent) {
                        $parent = User::create(['phone' => $r['parent_phone'], 'first_name' => mb_substr($r['parent_name'] !== '' ? $r['parent_name'] : $r['first_name'].' (ota-ona)', 0, 64),
                            'role' => Role::Parent, 'active' => true]);
                        $res['parents_created']++;
                    } elseif ($parent->role !== Role::Parent) {
                        throw new \RuntimeException("{$r['line']}-qator: ota-ona telefoni boshqa rolga tegishli.");
                    }
                    $res['links'] += DB::table('parent_child')->insertOrIgnore(['parent_id' => $parent->id, 'child_id' => $student->id]);
                }
                if ($r['group_id']) {
                    $result = $this->enrollments->join($groups[$r['group_id']], $student, $r['start_date'], $actorId);
                    $res['memberships'] += $result === 'already' ? 0 : 1;
                }
            }
        });

        return $res;
    }

    /** Sarlavha → maydon: sozlamadagi nom, maydon kaliti yoki keng tarqalgan sinonimlar (katta-kichik harf, bo'shliqlar farqsiz). */
    private function mapHeader(array $header): array
    {
        $norm = fn ($s) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(str_replace(['ʻ', 'ʼ', '`', '’', '‘'], "'", trim((string) $s))));
        $labels = (array) $this->settings->get('import.columns');
        $aliases = [
            'first_name' => ['ism', 'name', 'firstname', 'имя', 'fio', 'fish', 'ismifamiliyasi', 'ф.и.о', 'фио'],
            'last_name' => ['familiya', 'lastname', 'surname', 'фамилия'],
            'phone' => ['telefon', 'tel', 'phone', 'телефон', 'raqam', 'telefonraqami'],
            'parent_name' => ['otaonaismi', 'parentname', 'otaona', 'родитель', 'имяродителя'],
            'parent_phone' => ['otaonatelefoni', 'parentphone', 'otaonaraqami', 'телефонродителя'],
            'group' => ['guruh', 'group', 'группа'],
            'start_date' => ['qoshilgansana', 'sana', 'startdate', 'boshlanishsanasi', 'дата'],
        ];
        $map = [];
        foreach ($header as $i => $h) {
            $key = $norm($h);
            if ($key === '') {
                continue;
            }
            foreach (self::FIELDS as $f) {
                $candidates = array_map($norm, array_merge([$f, (string) ($labels[$f] ?? '')], $aliases[$f]));
                if (! isset($map[$f]) && in_array($key, $candidates, true)) {
                    $map[$f] = $i;

                    continue 2;
                }
            }
        }

        return $map;
    }

    /** Excel'dan kelgan qiymat: ="+998…", '+998…, ortiqcha bo'shliqlar. */
    private function clean(string $v): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $v));
        if (preg_match('/^="(.*)"$/', $v, $m)) {
            $v = $m[1];
        }

        return ltrim($v, "'");
    }

    private function parseDate(string $v): ?string
    {
        $v = trim($v);
        if (ctype_digit($v) && (int) $v > 20000 && (int) $v < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString(); // Excel sana raqami
        }
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2}|\d{4})$/', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) (strlen($m[3]) === 2 ? '20'.$m[3] : $m[3])];
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private function who(User $u, array $roleLabels): string
    {
        return ($roleLabels[$u->role->value] ?? $u->role->value).' «'.trim($u->first_name.' '.$u->last_name).'»';
    }
}
