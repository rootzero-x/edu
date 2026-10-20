<?php

namespace App\Http\Controllers\Api;

use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Audit jurnali (audit.view — faqat administratorlar): kim, qachon, nimani o'zgartirdi.
 * Har bir yozuv uchun "oldin → keyin" farqi: data.before/after, data.changes (davomat, baholar) yoki yozilgan qiymatlar.
 */
class AuditController
{
    public function __construct(private Settings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'actor_id' => ['nullable', 'integer'],
            'entity' => ['nullable', 'string', 'max:64'],
            'entity_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null;
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
        $maxDays = $this->settings->int('audit.max_days');
        abort_if($from && $from->diffInDays($to ?? now()) > $maxDays, 422, "Davr juda katta ($maxDays kungacha).");

        $page = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->when($data['actor_id'] ?? null, fn ($q, $v) => $q->where('audit_logs.actor_id', $v))
            ->when($data['entity'] ?? null, fn ($q, $v) => $q->where('audit_logs.entity', $v))
            ->when($data['entity_id'] ?? null, fn ($q, $v) => $q->where('audit_logs.entity_id', $v))
            // "group" — barcha group.* amallari, "group.update" — aniq amal.
            ->when($data['action'] ?? null, fn ($q, $v) => str_contains($v, '.') ? $q->where('audit_logs.action', $v) : $q->where('audit_logs.action', 'like', $v.'.%'))
            ->when($from, fn ($q) => $q->where('audit_logs.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('audit_logs.created_at', '<=', $to))
            ->orderByDesc('audit_logs.id')
            ->paginate($this->settings->int('audit.page_size'), ['audit_logs.*', 'users.first_name', 'users.last_name', 'users.role']);

        $rows = $page->getCollection();
        $names = $this->entityNames($rows);
        $studentNames = $this->studentNames($rows);

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->present($r, $names, $studentNames))->values(),
            'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Filtrlar uchun: amallar, obyekt turlari va bajaruvchilar (jurnalda uchraganlari). */
    public function facets(): JsonResponse
    {
        $actions = DB::table('audit_logs')->distinct()->orderBy('action')->pluck('action');
        $entities = DB::table('audit_logs')->distinct()->orderBy('entity')->pluck('entity');
        $actors = DB::table('users')->whereIn('id', DB::table('audit_logs')->whereNotNull('actor_id')->distinct()->select('actor_id'))
            ->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'last_name', 'role'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->first_name.' '.$u->last_name), 'role' => $u->role]);

        return response()->json([
            'actions' => $actions,
            'entities' => $entities,
            'actors' => $actors,
            'action_labels' => (array) $this->settings->get('audit.action_labels'),
            'entity_labels' => (array) $this->settings->get('audit.entity_labels'),
        ]);
    }

    /** Bitta sozlamaning o'zgarish tarixi (Sozlamalar → "Tarix"). settings.manage. */
    public function settingHistory(string $key): JsonResponse
    {
        abort_unless(array_key_exists($key, Settings::definitions()), 404);
        $items = [];
        DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->where('audit_logs.entity', 'settings')->orderByDesc('audit_logs.id')
            ->select(['audit_logs.*', 'users.first_name', 'users.last_name'])
            ->chunk(500, function (Collection $rows) use ($key, &$items) {
                foreach ($rows as $r) {
                    $d = json_decode((string) $r->data, true) ?: [];
                    $hit = null;
                    if ($r->action === 'settings.reset' && ($d['key'] ?? null) === $key) {
                        $hit = ['from' => $d['before'] ?? null, 'to' => $d['after'] ?? null, 'reset' => true];
                    } elseif (isset($d['after']) && is_array($d['after']) && array_key_exists($key, $d['after'])) {
                        $hit = ['from' => $d['before'][$key] ?? null, 'to' => $d['after'][$key], 'reset' => false];
                    } elseif (! isset($d['after']) && array_key_exists($key, $d)) {
                        // Eski yozuvlar: faqat yangi qiymat saqlangan.
                        $hit = ['from' => null, 'to' => $d[$key], 'reset' => false];
                    }
                    if ($hit) {
                        $items[] = $hit + ['id' => (int) $r->id, 'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
                            'actor' => $r->actor_id ? trim($r->first_name.' '.$r->last_name) : null];
                    }
                    if (count($items) >= 50) {
                        return false;
                    }
                }
            });

        return response()->json(['key' => $key, 'data' => $items]);
    }

    private function present(object $r, array $names, array $studentNames): array
    {
        $data = json_decode((string) $r->data, true);
        $actions = (array) $this->settings->get('audit.action_labels');
        $entities = (array) $this->settings->get('audit.entity_labels');

        return [
            'id' => (int) $r->id,
            'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
            'actor' => $r->actor_id ? ['id' => (int) $r->actor_id, 'name' => trim($r->first_name.' '.$r->last_name), 'role' => $r->role] : null,
            'action' => $r->action,
            'action_label' => $actions[$r->action] ?? null,
            'entity' => $r->entity,
            'entity_label' => $entities[$r->entity] ?? $r->entity,
            'entity_id' => $r->entity_id !== null ? (int) $r->entity_id : null,
            'entity_name' => $names[$r->entity][(int) $r->entity_id] ?? null,
            'ip' => $r->ip,
            'diff' => self::diff(is_array($data) ? $data : [], $studentNames),
            'note' => is_array($data) ? ($data['reason'] ?? null) : null,
        ];
    }

    /**
     * "Oldin → keyin" qatorlari.
     *
     * @return list<array{field:string, from:mixed, to:mixed}>
     */
    public static function diff(array $data, array $studentNames = []): array
    {
        $out = [];
        if (isset($data['before']) || isset($data['after'])) {
            $before = is_array($data['before'] ?? null) ? $data['before'] : [];
            $after = is_array($data['after'] ?? null) ? $data['after'] : [];
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
                $from = $before[$k] ?? null;
                $to = $after[$k] ?? null;
                if ($from !== $to) {
                    $out[] = ['field' => (string) $k, 'from' => self::scalar($from), 'to' => self::scalar($to)];
                }
            }
        }
        if (isset($data['changes']) && is_array($data['changes'])) {
            foreach ($data['changes'] as $c) {
                if (! is_array($c)) {
                    continue;
                }
                $sid = (int) ($c['student_id'] ?? 0);
                $out[] = ['field' => $studentNames[$sid] ?? ('#'.$sid), 'from' => self::scalar($c['from'] ?? null), 'to' => self::scalar($c['to'] ?? null)];
            }
        }
        if (! $out) {
            foreach ($data as $k => $v) {
                if (! in_array($k, ['before', 'after', 'changes', 'reason'], true)) {
                    $out[] = ['field' => (string) $k, 'from' => null, 'to' => self::scalar($v)];
                }
            }
        }

        return $out;
    }

    private static function scalar(mixed $v): mixed
    {
        if (is_array($v) || is_object($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        return $v;
    }

    /** Obyekt nomlari (foydalanuvchi, guruh, kurs, ...) — bir sahifa uchun bitta so'rovdan. */
    private function entityNames(Collection $rows): array
    {
        $map = [
            'user' => ['users', null],
            'group' => ['study_groups', 'name'], 'course' => ['courses', 'title'], 'lesson' => ['lessons', 'title'],
            'module' => ['course_modules', 'title'], 'room' => ['rooms', 'name'], 'assignment' => ['assignments', 'title'],
            'announcement' => ['announcements', 'title'], 'quiz' => ['quizzes', 'title'],
        ];
        $out = [];
        foreach ($rows->groupBy('entity') as $entity => $list) {
            if (! isset($map[$entity])) {
                continue;
            }
            [$table, $col] = $map[$entity];
            $ids = $list->pluck('entity_id')->filter()->unique()->values()->all();
            if (! $ids) {
                continue;
            }
            $q = DB::table($table)->whereIn('id', $ids);
            $out[$entity] = $entity === 'user'
                ? $q->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name.' '.$u->last_name)])->all()
                : $q->pluck($col, 'id')->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all();
        }

        return $out;
    }

    private function studentNames(Collection $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->data, true);
            foreach ((is_array($d) ? (array) ($d['changes'] ?? []) : []) as $c) {
                if (is_array($c) && isset($c['student_id'])) {
                    $ids[] = (int) $c['student_id'];
                }
            }
        }

        return $ids ? DB::table('users')->whereIn('id', array_unique($ids))->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name.' '.$u->last_name)])->all() : [];
    }
}
