<?php

namespace App\Telegram;

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\QuizController;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\User;
use App\Support\Billing;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bot menyusi: rolga qarab reply-klaviatura va bo'limlar. Ma'lumotlar API controller'lari
 * orqali (ApiBridge) olinadi — huquq va ko'rinish doirasi sayt bilan bir xil.
 */
class BotMenu
{
    /** Menyu amallari (telegram.menu sozlamasida ishlatiladi). */
    public const ACTIONS = ['courses', 'schedule', 'attendance', 'quizzes', 'certificates', 'balance', 'profile', 'today', 'unmarked', 'children', 'news', 'app'];

    private const STATUS_CODES = ['P' => 'PRESENT', 'L' => 'LATE', 'A' => 'ABSENT', 'E' => 'EXCUSED'];

    public function __construct(
        private TelegramClient $tg,
        private Settings $settings,
        private Texts $t,
        private ApiBridge $api,
        private BotAdmin $admin,
    ) {}

    public function linkedUser(int $telegramId): ?User
    {
        $userId = DB::table('telegram_links')->where('telegram_id', $telegramId)->value('user_id');

        return $userId ? User::find($userId) : null;
    }

    /** Rolga mos doimiy reply-klaviatura. */
    public function keyboard(User $user, int $chatId): array
    {
        $layout = ((array) $this->settings->get('telegram.menu'))[$user->role->value] ?? [];
        $rows = [];
        foreach ((array) $layout as $row) {
            $buttons = [];
            foreach ((array) $row as $action) {
                if (in_array($action, self::ACTIONS, true)) {
                    $buttons[] = ['text' => $this->t->get("telegram.text.btn_$action")];
                }
            }
            if ($buttons) {
                $rows[] = $buttons;
            }
        }
        if ($this->admin->isAdmin($chatId)) {
            $rows[] = [['text' => $this->t->get('telegram.text.btn_admin')]];
        }

        return ['keyboard' => $rows, 'resize_keyboard' => true, 'is_persistent' => true];
    }

    public function sendMenu(User $user, int $chatId, string $textKey = 'telegram.text.menu', array $vars = []): void
    {
        $this->tg->sendMessage($chatId, $this->t->get($textKey, $vars), $this->keyboard($user, $chatId));
    }

    /** Matnli xabar. false — foydalanuvchi bog'lanmagan (telefon so'rash kerak). */
    public function onText(int $chatId, array $from, string $text): bool
    {
        $command = null;
        $arg = '';
        if (preg_match('/^\/([A-Za-z0-9_]+)(?:@\S+)?\s*(.*)$/s', $text, $m)) {
            $command = strtolower($m[1]);
            $arg = trim($m[2]);
            if ($command === 'cancel' && Cache::pull($this->reasonKey($chatId)) !== null) {
                $this->tg->sendMessage($chatId, $this->t->get('telegram.text.admin_cancelled'));

                return true;
            }
            if ($command === 'cancel' && $this->admin->cancel($chatId)) {
                return true;
            }
            if ($this->admin->onCommand($chatId, $command, $arg)) {
                return true;
            }
        }
        $action = $command !== null ? $this->commandAction($command) : $this->buttonAction($text);
        if ($action === 'admin') {
            return $this->admin->onCommand($chatId, 'admin', '');
        }
        if ($command === null && $action === null && $this->admin->onText($chatId, $text)) {
            return true;
        }

        $user = $this->linkedUser((int) $from['id']);
        if (! $user) {
            return false;
        }
        if (! $user->active) {
            $this->tg->sendMessage($chatId, $this->t->get($user->isPending() ? 'telegram.text.account_pending' : 'telegram.text.account_blocked'), ['remove_keyboard' => true]);

            return true;
        }
        // Davomat o'zgartirish sababi kutilmoqda: oddiy matn — sabab.
        if ($command === null && $action === null && $this->pendingReason($chatId)) {
            $this->applyReason($user, $chatId, $text);

            return true;
        }
        match ($action) {
            null => $this->sendMenu($user, $chatId, 'telegram.text.unknown'),
            'menu' => $this->sendMenu($user, $chatId),
            'help' => $this->sendMenu($user, $chatId, 'telegram.text.help'),
            default => $this->show($user, $chatId, $action),
        };

        return true;
    }

    /** Inline tugmalar. */
    public function onCallback(array $cq): void
    {
        $data = (string) ($cq['data'] ?? '');
        $chatId = (int) ($cq['message']['chat']['id'] ?? 0);
        $messageId = (int) ($cq['message']['message_id'] ?? 0);
        if (! $chatId || ! $messageId) {
            $this->tg->answerCallback($cq['id']);

            return;
        }
        if (str_starts_with($data, 'adm:')) {
            $this->admin->onCallback($chatId, $messageId, $cq['id'], $data);

            return;
        }
        $user = $this->linkedUser((int) $cq['from']['id']);
        if (! $user) {
            $this->tg->answerCallback($cq['id'], $this->t->get('telegram.text.need_phone'), true);

            return;
        }
        // Javob har doim bitta va har doim (kutilmagan xatoda ham) — tugma "yuklanmoqda" holatida osilib qolmasin.
        $answer = [null, false];
        try {
            $parts = explode(':', $data);
            $answer[0] = match ($parts[0]) {
                'm' => $this->show($user, $chatId, $parts[1] ?? 'menu', $messageId),
                'a' => $this->attendanceCallback($user, $chatId, $messageId, $parts),
                default => null,
            };
        } catch (BotError $e) {
            $answer = [$e->getMessage(), true];
        } catch (\Throwable $e) {
            report($e);
            $answer = [$this->t->get('telegram.text.error_generic'), true];
        } finally {
            try {
                $this->tg->answerCallback($cq['id'], $answer[0], $answer[1]);
            } catch (\Throwable) {
                // Eskirgan callback — e'tiborsiz.
            }
        }
    }

    /** Bo'limni ko'rsatish (yangi xabar yoki mavjudini tahrirlash). */
    private function show(User $user, int $chatId, string $action, ?int $messageId = null): ?string
    {
        try {
            [$text, $markup] = match ($action) {
                'courses' => $this->courses($user),
                'schedule' => $this->schedule($user),
                'attendance' => $this->myAttendance($user),
                'quizzes' => $this->quizzes($user),
                'certificates' => $this->certificates($user),
                'balance' => $this->balance($user),
                'profile' => $this->profile($user),
                'today' => $this->today($user),
                'unmarked' => $this->unmarked($user),
                'news' => $this->news($user),
                'children' => $this->children($user),
                'app' => [$this->t->get('telegram.text.app_open'), $this->inline([[$this->webApp('telegram.text.btn_open_app', '')]])],
                default => [$this->t->get('telegram.text.unknown'), null],
            };
        } catch (BotError $e) {
            if ($messageId) {
                throw $e;
            }
            [$text, $markup] = [$this->t->get('telegram.text.error', ['message' => $e->getMessage()]), null];
        }
        $messageId
            ? $this->tg->editMessageText($chatId, $messageId, $text, $markup)
            : $this->tg->sendMessage($chatId, $text, $markup);

        return null;
    }

    // ── O'quvchi ─────────────────────────────────────────────

    private function courses(User $user): array
    {
        $groups = GroupController::visible($user)->where('active', true)->with('course:id,title')
            ->orderBy('name')->limit($this->limit())->get();
        if ($groups->isEmpty()) {
            return [$this->t->get('telegram.text.courses_empty'), null];
        }
        $lines = [$this->t->get('telegram.text.courses_title'), ''];
        $buttons = [];
        foreach ($groups->values() as $i => $g) {
            $lines[] = $this->t->get('telegram.text.course_line', ['n' => $i + 1, 'course' => $g->course->title, 'group' => $g->name]);
            $buttons[$g->course_id] ??= [$this->webAppRaw(($i + 1).'. '.$g->course->title, '/courses/'.$g->course_id)];
        }

        return [implode("\n", $lines), $this->inline(array_values($buttons))];
    }

    private function schedule(User $user): array
    {
        $days = $this->settings->int('telegram.schedule_days');
        $groupIds = GroupController::visible($user)->where('active', true)->pluck('id');
        $sessions = ClassSession::with('group:id,name,room')
            ->whereIn('group_id', $groupIds)
            ->where('starts_at', '>=', now()->startOfDay())
            ->where('starts_at', '<', now()->startOfDay()->addDays($days))
            ->orderBy('starts_at')->limit($this->limit() * 3)->get();
        if ($sessions->isEmpty()) {
            return [$this->t->get('telegram.text.schedule_empty'), $this->inline([[$this->webApp('telegram.text.btn_open_app', '/schedule')]])];
        }
        $weekdays = (array) $this->settings->get('telegram.weekday_names');
        $lines = [$this->t->get('telegram.text.schedule_title', ['days' => $days])];
        foreach ($sessions->groupBy(fn ($s) => $s->starts_at->format('Y-m-d')) as $list) {
            $first = $list->first()->starts_at;
            $lines[] = '';
            $lines[] = $this->t->get('telegram.text.schedule_day', [
                'date' => $first->format('d.m'), 'weekday' => $weekdays[$first->isoWeekday() - 1] ?? '',
            ]);
            foreach ($list as $s) {
                $lines[] = $this->t->get('telegram.text.schedule_line', [
                    'time' => $s->starts_at->format('H:i'), 'end' => $s->ends_at->format('H:i'),
                    'group' => $s->group->name, 'room' => $s->group->room ? ' · '.$s->group->room : '',
                    'topic' => (string) $s->topic,
                ]).($s->cancelled ? $this->t->get('telegram.text.schedule_cancelled') : '');
            }
        }

        return [implode("\n", $lines), $this->inline([[$this->webApp('telegram.text.btn_open_app', '/schedule')]])];
    }

    private function myAttendance(User $user): array
    {
        $groupIds = DB::table('group_members')->where('student_id', $user->id)->whereNull('left_at')->pluck('group_id');
        $lines = $this->attendanceLines($user, $user->id, $groupIds->all());
        if (! $lines) {
            return [$this->t->get('telegram.text.attendance_empty'), null];
        }

        return [$this->t->get('telegram.text.attendance_title')."\n\n".implode("\n", $lines),
            $this->inline([[$this->webApp('telegram.text.btn_open_app', '/attendance')]])];
    }

    /** Bitta o'quvchining guruhlar bo'yicha davomat qatorlari (AttendanceController::report orqali). */
    private function attendanceLines(User $viewer, int $studentId, array $groupIds): array
    {
        $names = DB::table('study_groups')->whereIn('id', $groupIds)->pluck('name', 'id');
        $lines = [];
        foreach ($groupIds as $gid) {
            $report = $this->api->call($viewer, [AttendanceController::class, 'report'], ['group' => (int) $gid]);
            $row = collect($report['data'] ?? [])->firstWhere('student_id', $studentId);
            if (! $row) {
                continue;
            }
            $lines[] = $this->t->get('telegram.text.attendance_line', [
                'group' => $names[$gid] ?? '', 'rate' => rtrim(rtrim(number_format((float) $row['rate'], 1, '.', ''), '0'), '.'),
                'present' => $row['counts']['PRESENT'] ?? 0, 'late' => $row['counts']['LATE'] ?? 0,
                'absent' => $row['counts']['ABSENT'] ?? 0, 'excused' => $row['counts']['EXCUSED'] ?? 0,
            ]);
        }

        return $lines;
    }

    private function quizzes(User $user): array
    {
        if (! $user->hasPermission('quiz.take')) {
            throw new BotError($this->t->get('telegram.text.no_permission'));
        }
        $courseIds = DB::table('group_members')
            ->join('study_groups', 'study_groups.id', '=', 'group_members.group_id')
            ->where('group_members.student_id', $user->id)->whereNull('group_members.left_at')
            ->where('study_groups.active', true)->distinct()->pluck('study_groups.course_id');
        $lines = [];
        $buttons = [];
        foreach (Course::whereIn('id', $courseIds)->where('published', true)->get() as $course) {
            $res = $this->api->call($user, [QuizController::class, 'index'], ['course' => $course], [], 'course.view');
            foreach ($res['data'] ?? [] as $q) {
                if (count($lines) >= $this->limit()) {
                    break 2;
                }
                $n = count($lines) + 1;
                $status = match (true) {
                    (bool) ($q['my_passed'] ?? false) => $this->t->get('telegram.text.quiz_passed', ['percent' => $q['my_best_percent']]),
                    ($q['my_attempts'] ?? 0) > 0 => $this->t->get('telegram.text.quiz_failed', [
                        'percent' => $q['my_best_percent'], 'attempts' => $q['my_attempts'], 'max' => $q['max_attempts'],
                    ]),
                    default => $this->t->get('telegram.text.quiz_new'),
                };
                $lines[] = $this->t->get('telegram.text.quiz_line', ['n' => $n, 'title' => $q['title'], 'course' => $course->title, 'status' => $status]);
                $buttons[] = [$this->webAppRaw($n.'. '.$q['title'], '/quizzes/'.$q['id'])];
            }
        }
        if (! $lines) {
            return [$this->t->get('telegram.text.quizzes_empty'), null];
        }

        return [$this->t->get('telegram.text.quizzes_title')."\n\n".implode("\n", $lines), $this->inline($buttons)];
    }

    private function certificates(User $user): array
    {
        $rows = array_slice($this->api->call($user, [CertificateController::class, 'index'])['data'] ?? [], 0, $this->limit());
        if (! $rows) {
            return [$this->t->get('telegram.text.certificates_empty'), null];
        }
        $base = rtrim((string) $this->settings->get('certificate.public_base_url'), '/');
        $lines = array_map(fn ($c) => $this->t->get('telegram.text.certificate_line', [
            'course' => $c['course'], 'name' => trim($c['first_name'].' '.$c['last_name']), 'code' => $c['code'],
            'date' => Carbon::parse($c['issued_at'])->format('d.m.Y'), 'url' => $base.'/'.$c['code'],
        ]), $rows);

        return [$this->t->get('telegram.text.certificates_title')."\n\n".implode("\n\n", $lines), null];
    }

    /** O'quvchi — o'z balansi, ota-ona — farzandlariniki (BillingController::me orqali). */
    private function balance(User $user): array
    {
        $res = $this->api->call($user, [BillingController::class, 'me']);
        if (! ($res['enabled'] ?? false) || ! ($res['students'] ?? [])) {
            return [$this->t->get('telegram.text.balance_empty'), null];
        }
        $money = fn (int $v) => app(Billing::class)->money($v);
        $methods = (array) $this->settings->get('billing.method_labels');
        $types = (array) $this->settings->get('billing.type_labels');
        $blocks = [];
        foreach ($res['students'] as $s) {
            $lines = array_map(fn ($e) => $this->t->get('telegram.text.balance_line', [
                'date' => Carbon::parse($e['entry_date'])->format('d.m.Y'), 'type' => $types[$e['type']] ?? $e['type'],
                'amount' => $money((int) $e['amount']), 'method' => $e['method'] ? '('.($methods[$e['method']] ?? $e['method']).')' : '',
            ]), array_values(array_filter($s['recent'], fn ($e) => ! $e['voided'])));
            $blocks[] = $this->t->get('telegram.text.balance_block', [
                'name' => trim($s['student']['first_name'].' '.$s['student']['last_name']),
                'balance' => $money((int) $s['balance']),
                'status' => $s['debt'] > 0 ? $this->t->get('telegram.text.balance_debt', ['debt' => $money((int) $s['debt'])]) : $this->t->get('telegram.text.balance_ok'),
                'month' => $money((int) $s['month_charged']),
                'lines' => $lines ? "\n".$this->t->get('telegram.text.balance_recent')."\n".implode("\n", $lines) : '',
            ]);
        }

        return [$this->t->get('telegram.text.balance_title')."\n\n".implode("\n\n", array_map('trim', $blocks)),
            $this->inline([[$this->webApp('telegram.text.btn_open_app', '/balance')]])];
    }

    private function profile(User $user): array
    {
        $text = $this->t->get('telegram.text.profile', [
            'name' => trim($user->first_name.' '.$user->last_name),
            'phone' => $user->phone,
            'role' => ((array) $this->settings->get('telegram.role_labels'))[$user->role->value] ?? $user->role->value,
            'groups' => GroupController::visible($user)->where('active', true)->count(),
        ]);

        return [$text, $this->inline([[$this->webApp('telegram.text.btn_open_app', '')]])];
    }

    // ── Ota-ona ──────────────────────────────────────────────

    private function children(User $user): array
    {
        $children = User::whereIn('id', DB::table('parent_child')->where('parent_id', $user->id)->select('child_id'))
            ->orderBy('first_name')->get();
        if ($children->isEmpty()) {
            return [$this->t->get('telegram.text.children_empty'), null];
        }
        $emoji = (array) $this->settings->get('telegram.attendance_emoji');
        $blocks = [];
        foreach ($children as $child) {
            $groupIds = DB::table('group_members')->where('student_id', $child->id)->whereNull('left_at')->pluck('group_id')->all();
            $lines = $groupIds ? $this->attendanceLines($user, $child->id, $groupIds) : [$this->t->get('telegram.text.child_no_groups')];
            $absences = DB::table('attendances')
                ->join('class_sessions', 'class_sessions.id', '=', 'attendances.session_id')
                ->where('attendances.student_id', $child->id)->where('attendances.status', 'ABSENT')
                ->orderByDesc('class_sessions.starts_at')->limit(3)->pluck('class_sessions.starts_at');
            if ($absences->isNotEmpty()) {
                $lines[] = $this->t->get('telegram.text.child_absences', [
                    'dates' => $absences->map(fn ($d) => ($emoji['ABSENT'] ?? '').Carbon::parse($d)->format('d.m'))->implode(', '),
                ]);
            }
            $blocks[] = $this->t->get('telegram.text.child_block', ['name' => trim($child->first_name.' '.$child->last_name), 'lines' => implode("\n", $lines)]);
        }

        return [$this->t->get('telegram.text.children_title')."\n\n".implode("\n\n", $blocks),
            $this->inline([[$this->webApp('telegram.text.btn_open_app', '/attendance')]])];
    }

    // ── O'qituvchi: bugungi darslar va davomat ───────────────

    private function today(User $user): array
    {
        if (! $user->hasPermission('attendance.mark')) {
            throw new BotError($this->t->get('telegram.text.no_permission'));
        }
        $groups = GroupController::visible($user)->where('active', true)
            ->when(! $user->hasPermission('group.manage'), fn ($q) => $q->where('teacher_id', $user->id))
            ->withCount('students')->get()->keyBy('id');
        $sessions = ClassSession::whereIn('group_id', $groups->keys())
            ->whereDate('starts_at', now()->toDateString())->where('cancelled', false)
            ->withCount('attendances')->orderBy('starts_at')->limit($this->limit())->get();
        if ($sessions->isEmpty()) {
            return [$this->t->get('telegram.text.today_empty'), null];
        }
        $rows = $sessions->map(fn ($s) => [['text' => $this->t->get('telegram.text.today_button', [
            'time' => $s->starts_at->format('H:i'), 'group' => $groups[$s->group_id]->name,
            'marked' => $s->attendances_count, 'total' => $groups[$s->group_id]->students_count,
        ]), 'callback_data' => 'a:s:'.$s->id]])->all();
        $rows[] = [['text' => $this->t->get('telegram.text.btn_refresh'), 'callback_data' => 'm:today'],
            ['text' => $this->t->get('telegram.text.btn_unmarked'), 'callback_data' => 'm:unmarked']];

        return [$this->t->get('telegram.text.today_title', ['date' => now()->format('d.m.Y')]), $this->inline($rows)];
    }

    /** Foydalanuvchiga ko'rinadigan so'nggi e'lonlar (AnnouncementController::visibleTo). */
    private function news(User $user): array
    {
        $items = \App\Http\Controllers\Api\AnnouncementController::visibleTo($user)
            ->orderByDesc('pinned')->orderByDesc('published_at')->limit($this->limit())->get(['id', 'title', 'body', 'pinned', 'published_at']);
        if ($items->isEmpty()) {
            return [$this->t->get('telegram.text.news_empty'), null];
        }
        $lines = [$this->t->get('telegram.text.news_title'), ''];
        foreach ($items as $a) {
            $lines[] = ($a->pinned ? '📌 ' : '• ').$a->title.' — '.$a->published_at->format('d.m');
            $lines[] = mb_strimwidth(preg_replace('/\s+/u', ' ', $a->body), 0, 160, '…');
            $lines[] = '';
        }

        return [implode("\n", $lines), $this->inline([[$this->webApp('telegram.text.btn_open_app', '/news')]])];
    }

    /** O'tgan, davomati to'liq belgilanmagan darslar (AttendanceController::unmarked orqali). */
    private function unmarked(User $user): array
    {
        $res = $this->api->call($user, [AttendanceController::class, 'unmarked'], [], [], 'attendance.mark');
        $list = array_slice($res['data'] ?? [], 0, $this->limit());
        if (! $list) {
            return [$this->t->get('telegram.text.unmarked_empty'), $this->inline([[['text' => $this->t->get('telegram.text.btn_today'), 'callback_data' => 'm:today']]])];
        }
        $rows = array_map(function ($s) {
            $at = \Illuminate\Support\Carbon::parse($s['starts_at']);

            return [['text' => $this->t->get('telegram.text.unmarked_button', [
                'date' => $at->format('d.m'), 'time' => $at->format('H:i'), 'group' => $s['group']['name'],
                'marked' => $s['marked'], 'total' => $s['members'],
            ]), 'callback_data' => 'a:s:'.$s['id']]];
        }, $list);
        $rows[] = [['text' => $this->t->get('telegram.text.btn_refresh'), 'callback_data' => 'm:unmarked']];

        return [$this->t->get('telegram.text.unmarked_title', ['count' => $res['total'] ?? count($list)]), $this->inline($rows)];
    }

    private function attendanceCallback(User $user, int $chatId, int $messageId, array $p): ?string
    {
        if (! $user->hasPermission('attendance.mark')) {
            throw new BotError($this->t->get('telegram.text.no_permission'));
        }
        $session = ClassSession::find((int) ($p[2] ?? 0));
        if (! $session) {
            throw new BotError($this->t->get('telegram.text.no_permission'));
        }
        switch ($p[1] ?? '') {
            case 's':
                $this->renderSheet($user, $chatId, $messageId, $session, (int) ($p[3] ?? 0));

                return null;
            case 'p':
                $this->renderPicker($user, $chatId, $messageId, $session, (int) ($p[3] ?? 0), (int) ($p[4] ?? 0));

                return null;
            case 'm':
                $status = self::STATUS_CODES[$p[4] ?? ''] ?? null;
                if (! $status) {
                    return null;
                }

                return $this->markOrAskReason($user, $chatId, $messageId, $session,
                    [['student_id' => (int) ($p[3] ?? 0), 'status' => $status]], (int) ($p[5] ?? 0));
            case 'all':
                $rows = $this->sheet($user, $session)['rows'] ?? [];
                $items = array_values(array_map(fn ($r) => ['student_id' => $r['student_id'], 'status' => 'PRESENT'],
                    array_filter($rows, fn ($r) => $r['status'] === null)));
                if (! $items) {
                    return $this->t->get('telegram.text.nothing_to_mark');
                }

                return $this->markOrAskReason($user, $chatId, $messageId, $session, $items, (int) ($p[3] ?? 0));
        }

        return null;
    }

    /**
     * Eski darsni o'zgartirishda sabab majburiy bo'lsa — xato o'rniga sabab so'raladi (keyingi matnli xabar),
     * keyin o'sha belgilash sabab bilan saqlanadi.
     */
    private function markOrAskReason(User $user, int $chatId, int $messageId, ClassSession $session, array $items, int $page): ?string
    {
        $window = AttendanceController::editWindow($this->settings, $session, $user->hasPermission('group.manage'));
        if ($window['can_edit'] && $window['reason_required']) {
            Cache::put($this->reasonKey($chatId), ['session' => $session->id, 'items' => $items, 'message_id' => $messageId, 'page' => $page], 900);
            $this->tg->sendMessage($chatId, $this->t->get('telegram.text.reason_prompt', [
                'days' => (int) floor($session->starts_at->diffInDays(now(), true)),
            ]), ['force_reply' => true]);

            return null;
        }
        $toast = $this->mark($user, $session, $items);
        $this->renderSheet($user, $chatId, $messageId, $session, $page);

        return $toast;
    }

    private function pendingReason(int $chatId): ?array
    {
        return Cache::get($this->reasonKey($chatId));
    }

    private function applyReason(User $user, int $chatId, string $reason): void
    {
        $pending = Cache::pull($this->reasonKey($chatId));
        $session = $pending ? ClassSession::find($pending['session']) : null;
        if (! $session) {
            return;
        }
        try {
            $toast = $this->mark($user, $session, $pending['items'], mb_substr(trim($reason), 0, 255));
            $this->tg->sendMessage($chatId, $toast);
            $this->renderSheet($user, $chatId, (int) $pending['message_id'], $session, (int) ($pending['page'] ?? 0));
        } catch (BotError $e) {
            $this->tg->sendMessage($chatId, $this->t->get('telegram.text.error', ['message' => $e->getMessage()]));
        }
    }

    private function reasonKey(int $chatId): string
    {
        return "tg.attendance_reason.$chatId";
    }

    private function sheet(User $user, ClassSession $session): array
    {
        return $this->api->call($user, [AttendanceController::class, 'sheet'], ['session' => $session]);
    }

    private function mark(User $user, ClassSession $session, array $items, ?string $reason = null): string
    {
        $res = $this->api->call($user, [AttendanceController::class, 'mark'], ['session' => $session],
            ['items' => $items] + ($reason !== null ? ['reason' => $reason] : []), 'attendance.mark');

        return ($res['notified'] ?? 0) > 0
            ? $this->t->get('telegram.text.marked_notified', ['count' => $res['notified']])
            : $this->t->get('telegram.text.marked');
    }

    private function renderSheet(User $user, int $chatId, int $messageId, ClassSession $session, int $page = 0): void
    {
        $data = $this->sheet($user, $session);
        $rows = $data['rows'] ?? [];
        $size = $this->settings->int('telegram.sheet_page_size');
        $pages = max(1, (int) ceil(count($rows) / $size));
        $page = min(max(0, $page), $pages - 1);
        $emoji = (array) $this->settings->get('telegram.attendance_emoji');
        $text = $this->t->get('telegram.text.sheet_title', [
            'group' => $data['group']['name'] ?? '', 'date' => $session->starts_at->format('d.m.Y'), 'time' => $session->starts_at->format('H:i'),
            'marked' => count(array_filter($rows, fn ($r) => $r['status'] !== null)), 'total' => count($rows),
        ]);
        if (! $rows) {
            $text .= "\n\n".$this->t->get('telegram.text.sheet_empty');
        }
        $keyboard = array_map(fn ($r) => [[
            'text' => ($emoji[$r['status'] ?? 'NONE'] ?? '').' '.$r['name'],
            'callback_data' => "a:p:{$session->id}:{$r['student_id']}:$page",
        ]], array_slice($rows, $page * $size, $size));
        if ($pages > 1) {
            $keyboard[] = array_values(array_filter([
                $page > 0 ? ['text' => $this->t->get('telegram.text.btn_prev'), 'callback_data' => "a:s:{$session->id}:".($page - 1)] : null,
                ['text' => ($page + 1).'/'.$pages, 'callback_data' => "a:s:{$session->id}:$page"],
                $page < $pages - 1 ? ['text' => $this->t->get('telegram.text.btn_next'), 'callback_data' => "a:s:{$session->id}:".($page + 1)] : null,
            ]));
        }
        $keyboard[] = [
            ['text' => $this->t->get('telegram.text.btn_all_present'), 'callback_data' => "a:all:{$session->id}:$page"],
            ['text' => $this->t->get('telegram.text.btn_refresh'), 'callback_data' => "a:s:{$session->id}:$page"],
        ];
        $nav = [['text' => $this->t->get('telegram.text.btn_back'), 'callback_data' => 'm:today']];
        if ($app = $this->webApp('telegram.text.btn_open_app', '/sessions/'.$session->id)) {
            $nav[] = $app;
        }
        $keyboard[] = $nav;
        $this->tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
    }

    private function renderPicker(User $user, int $chatId, int $messageId, ClassSession $session, int $studentId, int $page = 0): void
    {
        $row = collect($this->sheet($user, $session)['rows'] ?? [])->firstWhere('student_id', $studentId);
        if (! $row) {
            throw new BotError($this->t->get('telegram.text.no_permission'));
        }
        $emoji = (array) $this->settings->get('telegram.attendance_emoji');
        $labels = (array) $this->settings->get('attendance.status_labels');
        $buttons = [];
        foreach (self::STATUS_CODES as $code => $status) {
            $buttons[] = ['text' => ($emoji[$status] ?? '').' '.($labels[$status] ?? $status), 'callback_data' => "a:m:{$session->id}:$studentId:$code:$page"];
        }
        $this->tg->editMessageText($chatId, $messageId, $this->t->get('telegram.text.pick_status', ['name' => $row['name']]), ['inline_keyboard' => [
            array_slice($buttons, 0, 2), array_slice($buttons, 2, 2),
            [['text' => $this->t->get('telegram.text.btn_back'), 'callback_data' => "a:s:{$session->id}:$page"]],
        ]]);
    }

    // ── Yordamchilar ─────────────────────────────────────────

    private function commandAction(string $command): ?string
    {
        return match (true) {
            $command === 'admin' => 'admin',
            in_array($command, ['menu', 'help'], true) => $command,
            in_array($command, self::ACTIONS, true) => $command,
            default => null,
        };
    }

    private function buttonAction(string $text): ?string
    {
        if ($text === $this->t->get('telegram.text.btn_admin')) {
            return 'admin';
        }
        foreach (self::ACTIONS as $action) {
            if ($text === $this->t->get("telegram.text.btn_$action")) {
                return $action;
            }
        }

        return null;
    }

    private function limit(): int
    {
        return $this->settings->int('telegram.list_limit');
    }

    /** Mini App tugmasi (manzil sozlanmagan bo'lsa null). */
    private function webApp(string $textKey, string $path): ?array
    {
        return $this->webAppRaw($this->t->get($textKey), $path);
    }

    private function webAppRaw(string $text, string $path): ?array
    {
        $base = rtrim((string) $this->settings->get('telegram.miniapp_url'), '/');

        return $base === '' ? null : ['text' => $text, 'web_app' => ['url' => $base.$path]];
    }

    /** null tugmalarni tashlab, bo'sh bo'lsa null qaytaradi. */
    private function inline(array $rows): ?array
    {
        $rows = array_values(array_filter(array_map(fn ($r) => array_values(array_filter($r)), $rows)));

        return $rows ? ['inline_keyboard' => $rows] : null;
    }
}
