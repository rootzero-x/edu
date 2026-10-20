<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Support\Audit;
use App\Support\Leads;
use App\Support\PhoneNormalizer;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Arizalar (CRM-lite): ro'yxat, kartochka, qo'ng'iroq/izoh, holat, o'quvchiga aylantirish, voronka. Huquq: lead.manage. */
class LeadController
{
    public function __construct(private Settings $settings, private Leads $leads) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:32'],
            'course_id' => ['nullable', 'integer'],
            'owner_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:64'],
            'due' => ['nullable', Rule::in(['today', 'none'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $base = $this->filtered($data);
        $statuses = array_values(array_intersect(Lead::STATUSES, explode(',', (string) ($data['status'] ?? ''))));
        $page = (clone $base)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->with(['course:id,title', 'owner:id,first_name,last_name', 'trialSession.group:id,name'])
            // Ochiq arizalar: avval qo'ng'iroq muddati kelganlari, keyin yangilari.
            ->orderByRaw("case when status in ('NEW','CONTACTED','TRIAL') then 0 else 1 end")
            ->orderByRaw('case when next_contact_on is null then 1 else 0 end')->orderBy('next_contact_on')->orderByDesc('id')
            ->paginate($data['per_page'] ?? $this->settings->int('ui.page_size'));
        $counts = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'data' => $page->getCollection()->map->toApi(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'pages' => $page->lastPage()],
            'counts' => array_merge(array_fill_keys(Lead::STATUSES, 0), $counts->map(fn ($c) => (int) $c)->all()),
            'due_today' => Lead::whereIn('status', Lead::OPEN)->whereNotNull('next_contact_on')->where('next_contact_on', '<=', today()->toDateString())->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->assertNoOpenDuplicate($data['phone']);
        $lead = DB::transaction(function () use ($data, $request) {
            $lead = Lead::create($data + ['status' => Lead::NEW, 'created_by' => $request->user()->id, 'next_contact_on' => $data['next_contact_on'] ?? today()->toDateString()]);
            LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'type' => 'SYSTEM', 'data' => ['event' => 'created', 'source' => $lead->source]]);

            return $lead;
        });
        Audit::log('lead.create', 'lead', $lead->id, ['source' => $lead->source, 'phone' => $lead->phone]);

        return response()->json(['lead' => $this->load($lead)], 201);
    }

    public function show(Lead $lead): JsonResponse
    {
        $user = User::where('phone', $lead->phone)->first(['id', 'first_name', 'last_name', 'role']);

        return response()->json([
            'lead' => $this->load($lead),
            'activities' => $lead->activities()->with('user:id,first_name,last_name')->limit(200)->get()->map->toApi(),
            // Shu raqam bilan ro'yxatdan o'tgan foydalanuvchi (aylantirishda qayta ishlatiladi).
            'existing_user' => $user ? ['id' => $user->id, 'name' => trim($user->first_name.' '.$user->last_name), 'role' => $user->role->value] : null,
            'other_leads' => Lead::where('phone', $lead->phone)->where('id', '!=', $lead->id)->orderByDesc('id')->limit(10)
                ->get(['id', 'status', 'source', 'created_at'])->map(fn ($l) => ['id' => $l->id, 'status' => $l->status, 'source' => $l->source, 'created_at' => $l->created_at?->toIso8601String()]),
        ]);
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $data = $this->validated($request, partial: true);
        if (isset($data['phone']) && $data['phone'] !== $lead->phone) {
            $this->assertNoOpenDuplicate($data['phone'], $lead->id);
        }
        $changes = array_diff_assoc(array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $data),
            array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $lead->only(array_keys($data))));
        $lead->update($data);
        if (isset($changes['owner_id']) || array_key_exists('next_contact_on', $changes)) {
            LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'type' => 'SYSTEM',
                'data' => ['event' => 'updated'] + array_intersect_key($changes, ['owner_id' => 1, 'next_contact_on' => 1])]);
        }
        Audit::log('lead.update', 'lead', $lead->id, $changes);

        return response()->json(['lead' => $this->load($lead->fresh())]);
    }

    /** Holatni o'zgartirish: LOST — sabab majburiy; TRIAL — sinov darsi (mashg'ulot) tanlanadi. */
    public function status(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Lead::STATUSES)],
            'lost_reason' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $request->input('status') === Lead::LOST)],
            'trial_session_id' => ['nullable', 'integer', 'exists:class_sessions,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'next_contact_on' => ['nullable', 'date'],
        ]);
        abort_if($data['status'] === Lead::ENROLLED && ! $lead->student_id, 422, "O'quvchiga aylantirish uchun «Yozish» tugmasidan foydalaning.");
        $from = $lead->status;
        $update = ['status' => $data['status'], 'lost_reason' => $data['status'] === Lead::LOST ? $data['lost_reason'] : null];
        if ($data['status'] === Lead::TRIAL && isset($data['trial_session_id'])) {
            $session = ClassSession::with('group:id,course_id')->findOrFail($data['trial_session_id']);
            $update['trial_session_id'] = $session->id;
            $update['group_id'] = $session->group_id;
            $update['course_id'] = $lead->course_id ?? $session->group?->course_id;
            // Sinov darsidan keyingi kuni qayta bog'lanish.
            $update['next_contact_on'] = $data['next_contact_on'] ?? $session->starts_at->copy()->addDay()->toDateString();
        } elseif (array_key_exists('next_contact_on', $data)) {
            $update['next_contact_on'] = $data['next_contact_on'];
        }
        if (in_array($data['status'], [Lead::LOST, Lead::ENROLLED], true)) {
            $update['next_contact_on'] = null;
        }
        $lead->update($update);
        LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'type' => 'STATUS', 'text' => $data['note'] ?? null,
            'data' => array_filter(['from' => $from, 'to' => $data['status'], 'lost_reason' => $update['lost_reason'], 'trial_session_id' => $update['trial_session_id'] ?? null])]);
        Audit::log('lead.status', 'lead', $lead->id, ['from' => $from, 'to' => $data['status']]);

        return response()->json(['lead' => $this->load($lead->fresh())]);
    }

    /** Qo'ng'iroq yoki izoh. Yangi arizaga qo'ng'iroq qilinsa — avtomatik "Bog'lanildi". */
    public function addActivity(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['NOTE', 'CALL'])],
            'text' => ['required', 'string', 'max:2000'],
            'next_contact_on' => ['nullable', 'date'],
        ]);
        $activity = LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'type' => $data['type'], 'text' => $data['text']]);
        $update = [];
        if ($data['type'] === 'CALL' && $lead->status === Lead::NEW) {
            $update['status'] = Lead::CONTACTED;
            LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'type' => 'STATUS', 'data' => ['from' => Lead::NEW, 'to' => Lead::CONTACTED]]);
        }
        if (array_key_exists('next_contact_on', $data)) {
            $update['next_contact_on'] = $data['next_contact_on'];
        }
        if ($update) {
            $lead->update($update);
        }

        return response()->json(['activity' => $activity->load('user:id,first_name,last_name')->toApi(), 'lead' => $this->load($lead->fresh())], 201);
    }

    public function convert(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:64'],
            'last_name' => ['nullable', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'group_id' => ['nullable', 'integer', 'exists:study_groups,id'],
            'start_date' => ['nullable', 'date'],
            'parent_name' => ['nullable', 'string', 'max:64'],
            'parent_phone' => ['nullable', 'string', 'max:32'],
        ]);
        $r = $this->leads->convert($lead, $data, $request->user()->id);
        Audit::log('lead.convert', 'lead', $lead->id, ['student_id' => $r['student']->id, 'created' => $r['created'], 'group_id' => $data['group_id'] ?? null, 'parent_id' => $r['parent']?->id]);

        return response()->json([
            'lead' => $this->load($lead->fresh()),
            'student' => $r['student']->toApi(),
            'parent' => $r['parent']?->toApi(),
            'created' => $r['created'],
            'joined' => $r['joined'],
        ]);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        Audit::log('lead.delete', 'lead', $lead->id, ['phone' => $lead->phone, 'name' => $lead->name, 'status' => $lead->status]);
        $lead->delete();

        return response()->json(['ok' => true]);
    }

    /** Sinov darsi uchun yaqin mashg'ulotlar (kurs bo'yicha yoki hammasi). */
    public function trialSessions(Request $request): JsonResponse
    {
        $data = $request->validate(['course_id' => ['nullable', 'integer'], 'days' => ['nullable', 'integer', 'between:1,60']]);
        $sessions = ClassSession::query()
            ->join('study_groups', 'study_groups.id', '=', 'class_sessions.group_id')
            ->leftJoin('users', 'users.id', '=', 'study_groups.teacher_id')
            ->where('study_groups.active', true)->where('class_sessions.cancelled', false)
            ->whereBetween('class_sessions.starts_at', [now(), now()->addDays($data['days'] ?? 14)])
            ->when($data['course_id'] ?? null, fn ($q, $c) => $q->where('study_groups.course_id', $c))
            ->orderBy('class_sessions.starts_at')->limit(100)
            ->get(['class_sessions.id', 'class_sessions.group_id', 'class_sessions.starts_at', 'study_groups.name as group_name',
                'study_groups.room', 'users.first_name as teacher_first', 'users.last_name as teacher_last'])
            ->map(fn ($s) => [
                'id' => $s->id, 'group_id' => $s->group_id, 'group_name' => $s->group_name, 'room' => $s->room,
                'teacher' => trim($s->teacher_first.' '.$s->teacher_last), 'starts_at' => Carbon::parse($s->starts_at)->toIso8601String(),
            ]);

        return response()->json(['data' => $sessions]);
    }

    /** Voronka: manba va kurs bo'yicha arizalar soni va yozilganlar ulushi (davr — ariza yaratilgan sana). */
    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($data['from'] ?? today()->subDays(29))->startOfDay();
        $to = Carbon::parse($data['to'] ?? today())->endOfDay();
        $base = Lead::query()->whereBetween('leads.created_at', [$from, $to]);
        $row = fn ($r) => [
            'total' => (int) $r->total, 'contacted' => (int) $r->contacted, 'trial' => (int) $r->trial,
            'enrolled' => (int) $r->enrolled, 'lost' => (int) $r->lost,
            'conversion' => $r->total ? round($r->enrolled * 100 / $r->total, 1) : null,
        ];
        $agg = "count(*) as total,
            sum(case when status <> 'NEW' then 1 else 0 end) as contacted,
            sum(case when status in ('TRIAL','ENROLLED') or trial_session_id is not null then 1 else 0 end) as trial,
            sum(case when status = 'ENROLLED' then 1 else 0 end) as enrolled,
            sum(case when status = 'LOST' then 1 else 0 end) as lost";
        $total = (clone $base)->selectRaw($agg)->first();
        $bySource = (clone $base)->selectRaw("source, $agg")->groupBy('source')->get()->map(fn ($r) => ['source' => $r->source] + $row($r));
        $byCourse = (clone $base)->leftJoin('courses', 'courses.id', '=', 'leads.course_id')
            ->selectRaw("leads.course_id, max(courses.title) as course_title, count(*) as total,
                sum(case when leads.status <> 'NEW' then 1 else 0 end) as contacted,
                sum(case when leads.status in ('TRIAL','ENROLLED') or leads.trial_session_id is not null then 1 else 0 end) as trial,
                sum(case when leads.status = 'ENROLLED' then 1 else 0 end) as enrolled,
                sum(case when leads.status = 'LOST' then 1 else 0 end) as lost")
            ->groupBy('leads.course_id')->get()->map(fn ($r) => ['course_id' => $r->course_id, 'course_title' => $r->course_title] + $row($r));
        $lost = (clone $base)->where('status', Lead::LOST)->selectRaw('lost_reason, count(*) as c')->groupBy('lost_reason')
            ->orderByDesc('c')->get()->map(fn ($r) => ['reason' => $r->lost_reason, 'count' => (int) $r->c]);

        return response()->json([
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'total' => $row($total), 'by_source' => $bySource->sortByDesc('total')->values(), 'by_course' => $byCourse->sortByDesc('total')->values(),
            'lost_reasons' => $lost,
        ]);
    }

    // ── Yordamchilar ─────────────────────────────────────────

    private function filtered(array $data): Builder
    {
        $q = Lead::query()
            ->when($data['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->when($data['course_id'] ?? null, fn ($q, $c) => $q->where('course_id', $c))
            ->when($data['owner_id'] ?? null, fn ($q, $o) => $q->where('owner_id', $o))
            ->when(($data['due'] ?? null) === 'today', fn ($q) => $q->whereIn('status', Lead::OPEN)->whereNotNull('next_contact_on')->where('next_contact_on', '<=', today()->toDateString()))
            ->when(($data['due'] ?? null) === 'none', fn ($q) => $q->whereIn('status', Lead::OPEN)->whereNull('next_contact_on'));
        if ($s = trim((string) ($data['q'] ?? ''))) {
            $digits = PhoneNormalizer::searchDigits($s);
            $q->where(fn ($w) => $digits ? $w->where('phone', 'like', "%$digits%") : $w->where('name', 'like', '%'.addcslashes($s, '%_\\').'%'));
        }

        return $q;
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:128'],
            'phone' => [$req, 'string', 'max:32'],
            'course_id' => ['sometimes', 'nullable', 'integer', 'exists:courses,id'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:study_groups,id'],
            'source' => [$req, Rule::in(array_keys((array) $this->settings->get('leads.sources')))],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'next_contact_on' => ['sometimes', 'nullable', 'date'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        if (isset($data['phone'])) {
            $data['phone'] = PhoneNormalizer::normalize($data['phone'])
                ?? throw ValidationException::withMessages(['phone' => "Telefon raqami noto'g'ri. Masalan: 90 123 45 67"]);
        }
        if (! empty($data['owner_id'])) {
            $owner = User::find($data['owner_id']);
            abort_unless($owner && $owner->active && $owner->hasPermission('lead.manage'), 422, "Mas'ul xodimda arizalar huquqi yo'q.");
        }

        return $data;
    }

    private function assertNoOpenDuplicate(string $phone, ?int $exceptId = null): void
    {
        $dup = Lead::where('phone', $phone)->whereIn('status', Lead::OPEN)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->first();
        if ($dup) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => "Bu raqam bilan ochiq ariza bor (#{$dup->id}, {$dup->name}).",
                'errors' => ['phone' => ["Bu raqam bilan ochiq ariza bor (#{$dup->id}, {$dup->name})."]],
                'existing_lead_id' => $dup->id,
            ], 422));
        }
    }

    private function load(Lead $lead): array
    {
        return $lead->load(['course:id,title', 'group:id,name', 'owner:id,first_name,last_name', 'trialSession.group:id,name'])->toApi();
    }

    /** Ariza qabul qiluvchi xodimlar (mas'ul tanlash uchun). */
    public function owners(): JsonResponse
    {
        $users = User::where('active', true)->whereIn('role', [Role::SuperAdmin->value, Role::Admin->value, Role::Teacher->value])
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'role'])
            ->filter(fn (User $u) => $u->hasPermission('lead.manage'))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => trim($u->first_name.' '.$u->last_name)])->values();

        return response()->json(['data' => $users, 'courses' => Course::orderBy('title')->get(['id', 'title'])]);
    }
}
