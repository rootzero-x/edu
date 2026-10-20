<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Support\Csv;
use App\Support\Settings;
use App\Support\Spreadsheet;
use App\Support\StudentImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * O'quvchilar importi: 1) shablon, 2) fayl → ko'rib chiqish (bazaga yozilmaydi), 3) tasdiqlash (bitta tranzaksiya).
 * Fayl JSON ichida base64 bo'lib keladi (web proksi faqat JSON uzatadi).
 */
class ImportController
{
    private const TTL = 1800;

    public function __construct(private Settings $settings, private StudentImport $import) {}

    public function template(Request $request): Response
    {
        $format = $request->query('format') === 'csv' ? 'csv' : 'xlsx';
        $rows = [$this->import->headers(), ['Ali', 'Valiyev', '+998901234567', 'Dilnoza Valiyeva', '+998935554433', 'ENG-1', today()->format('d.m.Y')]];
        if ($format === 'xlsx') {
            return response(Spreadsheet::writeXlsx($rows), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="oquvchilar-import.xlsx"',
            ]);
        }
        $out = "\xEF\xBB\xBF".implode("\r\n", array_map(fn ($r) => implode(';', array_map(fn ($v) => '"'.str_replace('"', '""', Csv::cell($v)).'"', $r)), $rows))."\r\n";

        return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="oquvchilar-import.csv"']);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'group_id' => ['nullable', 'integer', 'exists:study_groups,id'],
            'start_date' => ['nullable', 'date'],
        ]);
        $bytes = base64_decode($data['content'], true);
        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages(['content' => "Faylni o'qib bo'lmadi."]);
        }
        $maxKb = $this->settings->int('import.max_file_kb');
        if (strlen($bytes) > $maxKb * 1024) {
            throw ValidationException::withMessages(['content' => "Fayl hajmi $maxKb KB dan oshmasligi kerak."]);
        }
        try {
            $rows = Spreadsheet::read($bytes, $data['filename']);
            $options = ['group_id' => $data['group_id'] ?? null, 'start_date' => $data['start_date'] ?? null];
            $plan = $this->plan($request, $rows, $options);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['content' => $e->getMessage()]);
        }
        $token = Str::random(40);
        Cache::put($this->key($request, $token), ['rows' => $rows, 'options' => $options, 'filename' => $data['filename']], self::TTL);

        return response()->json(['token' => $token] + $this->present($plan));
    }

    public function commit(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40'], 'skip_invalid' => ['nullable', 'boolean']]);
        $stored = Cache::get($this->key($request, $data['token']));
        abort_unless(is_array($stored), 410, "Ko'rib chiqish muddati tugagan. Faylni qaytadan yuklang.");
        try {
            // Ko'rib chiqishdan keyin baza o'zgargan bo'lishi mumkin — reja qaytadan tuziladi.
            $plan = $this->plan($request, $stored['rows'], $stored['options']);
            $result = $this->import->commit($plan, (bool) ($data['skip_invalid'] ?? false), $request->user()->id);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['token' => $e->getMessage()]);
        }
        Cache::forget($this->key($request, $data['token']));
        Audit::log('import.students', 'user', null, ['file' => $stored['filename'], 'rows' => count($plan['rows'])] + $result);

        return response()->json(['result' => $result] + $this->present($plan));
    }

    private function plan(Request $request, array $rows, array $options): array
    {
        $plan = $this->import->plan($rows, $options['group_id'], $options['start_date']);
        $assignsGroups = collect($plan['rows'])->contains(fn ($r) => $r['group_id'] !== null);
        abort_if($assignsGroups && ! $request->user()->hasPermission('group.manage'), 403, "Guruhga qo'shish uchun guruhlarni boshqarish huquqi kerak.");

        return $plan;
    }

    private function present(array $plan): array
    {
        return [
            'summary' => $plan['summary'],
            'columns' => array_keys($plan['columns']),
            'unknown_columns' => $plan['unknown'],
            'rows' => array_map(fn ($r) => array_intersect_key($r, array_flip([
                'line', 'first_name', 'last_name', 'phone', 'phone_raw', 'parent_name', 'parent_phone', 'group_name', 'start_date',
                'status', 'action', 'parent_action', 'membership', 'errors', 'warnings',
            ])), $plan['rows']),
        ];
    }

    private function key(Request $request, string $token): string
    {
        return 'import.students.'.$request->user()->id.'.'.$token;
    }
}
