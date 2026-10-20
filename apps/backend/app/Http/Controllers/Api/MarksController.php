<?php

namespace App\Http\Controllers\Api;

use App\Models\ClassSession;
use App\Support\Audit;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mashg'ulot baholari (davomat varaqasidan). Shkala: marks.scale_min..marks.scale_max.
 * Kim qo'yadi va qachongacha — davomat bilan bir xil qoida (guruh o'qituvchisi yoki group.manage, tahrir muddati).
 */
class MarksController
{
    public function __construct(private Settings $settings) {}

    public function show(Request $request, ClassSession $session): JsonResponse
    {
        $user = $request->user();
        $group = GroupController::findVisible($user, $session->group_id);
        $canSeeAll = $user->hasPermission('attendance.mark');
        $marks = DB::table('session_marks')->where('session_id', $session->id)->get()->keyBy('student_id');
        $students = $group->students()->select('users.id', 'first_name', 'last_name')->orderBy('first_name')->get();
        $rows = $students->filter(fn ($s) => $canSeeAll || (int) $s->id === (int) $user->id)
            ->map(fn ($s) => [
                'student_id' => $s->id,
                'name' => trim($s->first_name.' '.$s->last_name),
                'score' => isset($marks[$s->id]) ? (int) $marks[$s->id]->score : null,
                'note' => $marks[$s->id]->note ?? null,
            ])->values();

        return response()->json([
            'enabled' => $this->settings->bool('marks.session_marks'),
            'scale' => ['min' => $this->settings->int('marks.scale_min'), 'max' => $this->settings->int('marks.scale_max')],
            'rows' => $rows,
            'can_edit' => $this->canEdit($request, $session, $group),
        ]);
    }

    public function update(Request $request, Notifier $notifier, ClassSession $session): JsonResponse
    {
        abort_unless($this->settings->bool('marks.session_marks'), 404, "Mashg'ulot baholari o'chirilgan.");
        $user = $request->user();
        $group = GroupController::findVisible($user, $session->group_id);
        abort_unless($this->canEdit($request, $session, $group), 403, 'Baho qo\'yish uchun ruxsat yo\'q yoki tahrir muddati o\'tgan.');
        $min = $this->settings->int('marks.scale_min');
        $max = $this->settings->int('marks.scale_max');
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.student_id' => ['required', 'integer'],
            'items.*.score' => ['nullable', 'integer', "min:$min", "max:$max"],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        $memberIds = $group->students()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $before = DB::table('session_marks')->where('session_id', $session->id)->pluck('score', 'student_id')->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v]);

        $changes = [];
        DB::transaction(function () use ($data, $memberIds, $session, $user, $before, &$changes) {
            foreach ($data['items'] as $item) {
                $sid = (int) $item['student_id'];
                abort_unless(in_array($sid, $memberIds, true), 422, "O'quvchi #$sid bu guruhda emas.");
                $score = $item['score'] ?? null;
                if ($score === null) {
                    if (DB::table('session_marks')->where(['session_id' => $session->id, 'student_id' => $sid])->delete()) {
                        $changes[] = ['student_id' => $sid, 'from' => $before[$sid] ?? null, 'to' => null];
                    }

                    continue;
                }
                DB::table('session_marks')->updateOrInsert(
                    ['session_id' => $session->id, 'student_id' => $sid],
                    ['score' => $score, 'note' => $item['note'] ?? null, 'marked_by' => $user->id, 'marked_at' => now()],
                );
                if (($before[$sid] ?? null) !== $score) {
                    $changes[] = ['student_id' => $sid, 'from' => $before[$sid] ?? null, 'to' => $score];
                }
            }
        });
        if ($changes) {
            Audit::log('marks.update', 'session', $session->id, ['changes' => $changes]);
        }

        $notify = array_values(array_filter($changes, fn ($c) => $c['to'] !== null));
        if ($notify && $this->settings->bool('marks.notify_student')) {
            $names = DB::table('users')->whereIn('id', array_column($notify, 'student_id'))->where('active', true)->pluck('first_name', 'id');
            $notes = DB::table('session_marks')->where('session_id', $session->id)->pluck('note', 'student_id');
            $vars = ['group' => $group->name, 'date' => $session->starts_at->format('d.m.Y'), 'max' => $max];
            defer(function () use ($notifier, $notify, $names, $notes, $vars, $session) {
                foreach ($notify as $c) {
                    if (isset($names[$c['student_id']])) {
                        $notifier->sendTemplate($c['student_id'], "mark:{$session->id}", 'telegram.text.mark_received',
                            $vars + ['name' => $names[$c['student_id']], 'score' => $c['to'], 'note' => (string) ($notes[$c['student_id']] ?? '')]);
                    }
                }
            });
        }

        return response()->json(['ok' => true, 'changed' => count($changes)]);
    }

    private function canEdit(Request $request, ClassSession $session, $group): bool
    {
        $user = $request->user();
        if (! $this->settings->bool('marks.session_marks') || ! $user->hasPermission('attendance.mark')) {
            return false;
        }
        $admin = $user->hasPermission('group.manage');
        if (! $admin && (int) $group->teacher_id !== (int) $user->id) {
            return false;
        }
        if ($session->cancelled || $session->starts_at->isFuture()) {
            return false;
        }

        return AttendanceController::editWindow($this->settings, $session, $admin)['can_edit'];
    }
}
