<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Models\Announcement;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Yangiliklar va e'lonlar: hammaga / rolga / guruhga; o'qilganlik; Telegram xabari. */
class AnnouncementController
{
    // ── O'qish ───────────────────────────────────────────────

    /** Foydalanuvchiga ko'rinadigan e'lonlar (chop etilganlar). */
    public static function visibleTo(User $user): Builder
    {
        $groupIds = GroupController::visible($user)->pluck('id');

        return Announcement::query()->whereNotNull('published_at')->where('published_at', '<=', now())
            ->where(fn ($w) => $w->where('audience', 'ALL')
                ->orWhere(fn ($r) => $r->where('audience', 'ROLE')->where('audience_role', $user->role->value))
                ->orWhere(fn ($g) => $g->where('audience', 'GROUP')->whereIn('group_id', $groupIds)));
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['scope' => ['nullable', Rule::in(['feed', 'manage'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $manage = ($data['scope'] ?? 'feed') === 'manage' && $user->hasPermission('announcement.manage');
        $q = $manage
            ? Announcement::query()->when(! $user->isAdmin(), fn ($q) => $q->where('created_by', $user->id))
            : self::visibleTo($user);
        $page = $q->with(['author:id,first_name,last_name', 'group:id,name'])
            ->orderByDesc('pinned')->orderByRaw('published_at IS NULL DESC')->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(20);
        $read = DB::table('announcement_reads')->where('user_id', $user->id)
            ->whereIn('announcement_id', $page->getCollection()->pluck('id'))->pluck('announcement_id')->flip();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Announcement $a) => $this->present($a) + ['read' => isset($read[$a->id])]),
            'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total(), 'unread' => $this->unreadCount($user)],
        ]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->unreadCount($request->user())]);
    }

    public function markRead(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless(self::visibleTo($request->user())->whereKey($announcement->id)->exists(), 404);
        DB::table('announcement_reads')->insertOrIgnore(['announcement_id' => $announcement->id, 'user_id' => $request->user()->id, 'read_at' => now()]);

        return response()->json(['ok' => true, 'unread' => $this->unreadCount($request->user())]);
    }

    /** Ochiq e'lonlar (bosh sahifa uchun, login kerak emas). */
    public function public(Settings $settings): JsonResponse
    {
        if (! $settings->bool('news.public_on_home')) {
            return response()->json(['data' => []]);
        }
        $rows = Announcement::query()->where('is_public', true)->where('audience', 'ALL')
            ->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByDesc('pinned')->orderByDesc('published_at')->limit($settings->int('news.home_limit'))
            ->get(['id', 'title', 'body', 'image_url', 'pinned', 'published_at']);

        return response()->json(['data' => $rows])->header('Cache-Control', 'public, max-age=60');
    }

    // ── Boshqarish (announcement.manage) ─────────────────────

    public function store(Request $request, Settings $settings, Notifier $notifier): JsonResponse
    {
        $data = $this->validated($request);
        $a = Announcement::create($data + ['created_by' => $request->user()->id]);
        Audit::log('announcement.create', 'announcement', $a->id, ['title' => $a->title, 'audience' => $a->audience]);
        if ($request->boolean('publish')) {
            $this->doPublish($a, $settings, $notifier);
        }

        return response()->json(['announcement' => $this->present($a->fresh(['author', 'group']))], 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $this->authorizeOwn($request->user(), $announcement);
        $announcement->update($this->validated($request, partial: true, current: $announcement));

        return response()->json(['announcement' => $this->present($announcement->fresh(['author', 'group']))]);
    }

    public function publish(Request $request, Settings $settings, Notifier $notifier, Announcement $announcement): JsonResponse
    {
        $this->authorizeOwn($request->user(), $announcement);
        $data = $request->validate(['published' => ['required', 'boolean']]);
        if ($data['published']) {
            // O'qituvchi guruhdan olib tashlangan bo'lsa, eski e'lonini endi u guruhga yubora olmaydi.
            $this->assertTeacherGroup($request->user(), $announcement->audience, $announcement->group_id);
            $sent = $this->doPublish($announcement, $settings, $notifier);
        } else {
            $announcement->update(['published_at' => null]);
            $sent = 0;
        }
        Audit::log($data['published'] ? 'announcement.publish' : 'announcement.unpublish', 'announcement', $announcement->id);

        return response()->json(['announcement' => $this->present($announcement->fresh(['author', 'group'])), 'notified' => $sent]);
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $this->authorizeOwn($request->user(), $announcement);
        $announcement->delete();
        Audit::log('announcement.delete', 'announcement', $announcement->id, ['title' => $announcement->title]);

        return response()->json(['ok' => true]);
    }

    // ── Yordamchilar ─────────────────────────────────────────

    /** Chop etish; Telegram xabari faqat birinchi marta va javobdan keyin. @return int qabul qiluvchilar soni */
    private function doPublish(Announcement $a, Settings $settings, Notifier $notifier): int
    {
        $a->update(['published_at' => $a->published_at ?? now()]);
        if (! $a->notify_telegram) {
            return 0;
        }
        // Atomar: bir vaqtdagi ikki so'rov (ikki marta bosish) xabarni ikki marta yubormasin.
        $claimed = Announcement::whereKey($a->id)->whereNull('notified_at')->update(['notified_at' => now()]);
        if ($claimed === 0) {
            return 0;
        }
        $recipients = $this->audienceUserIds($a);
        $url = rtrim((string) $settings->get('site.url'), '/').'/dashboard/news';
        $vars = ['title' => $a->title, 'body' => mb_strimwidth($a->body, 0, 1500, '…'), 'url' => $url];
        $id = $a->id;
        $markRead = $settings->bool('notifications.mark_pushed_news_read');
        defer(function () use ($notifier, $recipients, $vars, $id, $markRead) {
            foreach ($recipients as $uid) {
                $notifier->sendTemplate((int) $uid, 'announcement:'.$id, 'telegram.text.announcement', $vars);
            }
            // Telegram'ga yuborilgan yangilik o'qilgan hisoblanadi (panelda "o'qilmagan" bo'lib qolmaydi).
            if ($markRead) {
                foreach (array_chunk($recipients, 500) as $chunk) {
                    DB::table('announcement_reads')->insertOrIgnore(array_map(fn ($uid) => ['announcement_id' => $id, 'user_id' => (int) $uid, 'read_at' => now()], $chunk));
                }
            }
        });

        return count($recipients);
    }

    /** E'lon auditoriyasidagi faol, Telegram'ga bog'langan foydalanuvchilar (guruh: o'quvchilar + o'qituvchi). */
    private function audienceUserIds(Announcement $a): array
    {
        $q = DB::table('users')->where('active', true)
            ->whereIn('id', DB::table('telegram_links')->select('user_id'));
        if ($a->audience === 'ROLE') {
            $q->where('role', $a->audience_role);
        } elseif ($a->audience === 'GROUP') {
            $students = DB::table('group_members')->where('group_id', $a->group_id)->whereNull('left_at')->pluck('student_id');
            $teacher = DB::table('study_groups')->where('id', $a->group_id)->pluck('teacher_id');
            $q->whereIn('id', $students->merge($teacher)->unique());
        }

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function unreadCount(User $user): int
    {
        return self::visibleTo($user)
            ->whereNotIn('id', DB::table('announcement_reads')->where('user_id', $user->id)->select('announcement_id'))
            ->count();
    }

    private function assertTeacherGroup(User $user, ?string $audience, mixed $groupId): void
    {
        if ($user->isAdmin()) {
            return;
        }
        abort_unless($audience === 'GROUP' && GroupController::visible($user)->where('teacher_id', $user->id)
            ->whereKey((int) ($groupId ?? 0))->exists(), 403, "O'qituvchi faqat o'z guruhiga e'lon bera oladi.");
    }

    private function authorizeOwn(User $user, Announcement $a): void
    {
        abort_unless($user->isAdmin() || (int) $a->created_by === (int) $user->id, 403, 'Bu e\'lonni faqat muallif yoki administrator o\'zgartiradi.');
    }

    private function validated(Request $request, bool $partial = false, ?Announcement $current = null): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'body' => [$req, 'string', 'max:5000'],
            'image_url' => ['sometimes', 'nullable', 'url:https', 'max:1000'],
            'audience' => [$req, Rule::in(['ALL', 'ROLE', 'GROUP'])],
            'audience_role' => ['nullable', 'required_if:audience,ROLE', Rule::in(array_map(fn ($r) => $r->value, Role::cases()))],
            'group_id' => ['nullable', 'required_if:audience,GROUP', 'integer', 'exists:study_groups,id'],
            'is_public' => ['sometimes', 'boolean'],
            'pinned' => ['sometimes', 'boolean'],
            'notify_telegram' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $audience = $data['audience'] ?? null;
        // O'qituvchi (admin emas) faqat o'z guruhlariga e'lon beradi — auditoriya yoki guruh
        // so'rovda bo'lmasa ham, yakuniy (saqlanadigan) qiymatlar tekshiriladi.
        $this->assertTeacherGroup($user, $audience ?? $current?->audience,
            array_key_exists('group_id', $data) ? $data['group_id'] : $current?->group_id);
        if ($audience !== null) {
            $data['audience_role'] = $audience === 'ROLE' ? $data['audience_role'] : null;
            $data['group_id'] = $audience === 'GROUP' ? $data['group_id'] : null;
        } elseif ($current) {
            // Auditoriya o'zgarmasa, unga tegishli bo'lmagan maydon saqlanmaydi.
            if (array_key_exists('group_id', $data) && $current->audience !== 'GROUP') {
                $data['group_id'] = null;
            }
            if (array_key_exists('audience_role', $data) && $current->audience !== 'ROLE') {
                $data['audience_role'] = null;
            }
        }
        // Ochiq (bosh sahifadagi) e'lon faqat "hammaga" bo'ladi.
        if (($data['is_public'] ?? false) && ($audience ?? 'ALL') !== 'ALL') {
            $data['is_public'] = false;
        }

        return $data;
    }

    private function present(Announcement $a): array
    {
        return [
            'id' => $a->id, 'title' => $a->title, 'body' => $a->body, 'image_url' => $a->image_url,
            'audience' => $a->audience, 'audience_role' => $a->audience_role,
            'group' => $a->group ? ['id' => $a->group->id, 'name' => $a->group->name] : null,
            'is_public' => $a->is_public, 'pinned' => $a->pinned, 'notify_telegram' => $a->notify_telegram,
            'published_at' => $a->published_at?->toIso8601String(), 'notified_at' => $a->notified_at?->toIso8601String(),
            'author' => $a->author ? trim($a->author->first_name.' '.$a->author->last_name) : null,
            'created_by' => $a->created_by,
        ];
    }
}
