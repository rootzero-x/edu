<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Models\Course;
use App\Models\Lead;
use App\Models\User;
use App\Support\PhoneNormalizer;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Umumiy qidiruv (Ctrl+K): o'quvchilar va boshqa foydalanuvchilar, guruhlar, kurslar, arizalar, sertifikat kodi.
 * Natijalar rol bo'yicha cheklanadi: o'qituvchi faqat o'z guruhlaridagi o'quvchilarni ko'radi.
 */
class SearchController
{
    public function __invoke(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:64']]);
        $q = trim($data['q']);
        $user = $request->user();
        $limit = $settings->int('ui.search_limit');
        // Bir tur (masalan, o'quvchilar) butun ro'yxatni egallab olmasin.
        $per = max(3, intdiv($limit, 2));
        $like = '%'.addcslashes($q, '%_\\').'%';
        $digits = PhoneNormalizer::searchDigits($q);
        $staff = $user->hasPermission('attendance.mark') || $user->hasPermission('group.manage');
        $out = [];
        $roleLabels = (array) $settings->get('telegram.role_labels');

        // Odamlar
        if ($user->hasPermission('user.manage') || $staff) {
            $people = UserController::search(User::query(), $q);
            if (! $user->hasPermission('user.manage')) {
                // O'qituvchi: faqat o'z guruhlaridagi hozirgi o'quvchilar.
                $people->where('role', Role::Student->value)->whereIn('id', DB::table('group_members')->whereNull('left_at')
                    ->whereIn('group_id', GroupController::visible($user)->select('id'))->select('student_id'));
            }
            foreach ($people->orderByRaw("case when role = 'STUDENT' then 0 else 1 end")->orderBy('first_name')->limit($per)->get() as $u) {
                $out[] = ['type' => $u->role === Role::Student ? 'student' : 'user', 'id' => $u->id,
                    'title' => trim($u->first_name.' '.$u->last_name),
                    'subtitle' => trim(($roleLabels[$u->role->value] ?? $u->role->value).($staff || $user->hasPermission('user.manage') ? ' · '.$u->phone : '')),
                    'href' => $u->role === Role::Student || $user->hasPermission('user.manage') ? "/dashboard/users/{$u->id}" : '/dashboard/users?q='.urlencode($u->phone),
                    'active' => $u->active];
            }
        }

        // Guruhlar
        if (! $digits) {
            foreach (GroupController::visible($user)->with('course:id,title')->where('name', 'like', $like)->orderByDesc('active')->orderBy('name')->limit($per)->get() as $g) {
                $out[] = ['type' => 'group', 'id' => $g->id, 'title' => $g->name, 'subtitle' => (string) $g->course?->title, 'href' => "/dashboard/groups/{$g->id}", 'active' => $g->active];
            }
        }

        // Kurslar
        if (! $digits && $user->hasPermission('course.view')) {
            $courses = Course::query()->where('title', 'like', $like)
                ->when(! $user->hasPermission('course.manage'), fn ($c) => $c->where('published', true))->orderBy('title')->limit($per)->get(['id', 'title', 'published']);
            foreach ($courses as $c) {
                $out[] = ['type' => 'course', 'id' => $c->id, 'title' => $c->title, 'subtitle' => '', 'href' => "/dashboard/courses/{$c->id}", 'active' => (bool) $c->published];
            }
        }

        // Arizalar
        if ($user->hasPermission('lead.manage')) {
            $leads = Lead::query()->where(fn ($w) => $digits ? $w->where('phone', 'like', "%$digits%") : $w->where('name', 'like', $like))
                ->orderByRaw("case when status in ('NEW','CONTACTED','TRIAL') then 0 else 1 end")->orderByDesc('id')->limit($per)->get();
            $labels = (array) $settings->get('leads.status_labels');
            foreach ($leads as $l) {
                $out[] = ['type' => 'lead', 'id' => $l->id, 'title' => $l->name, 'subtitle' => ($labels[$l->status] ?? $l->status).' · '.$l->phone,
                    'href' => "/dashboard/leads?id={$l->id}", 'active' => $l->isOpen()];
            }
        }

        // Sertifikat kodi (aniq yoki boshlanishi)
        if ($user->hasPermission('group.manage') && preg_match('/^[A-Za-z0-9-]{4,}$/', $q)) {
            $certs = DB::table('certificates')->join('users', 'users.id', '=', 'certificates.student_id')
                ->where('certificates.code', 'like', addcslashes(strtoupper($q), '%_\\').'%')->limit(5)
                ->get(['certificates.code', 'users.first_name', 'users.last_name']);
            foreach ($certs as $c) {
                $out[] = ['type' => 'certificate', 'id' => $c->code, 'title' => $c->code, 'subtitle' => trim($c->first_name.' '.$c->last_name), 'href' => "/c/{$c->code}", 'active' => true];
            }
        }

        return response()->json(['data' => array_slice($out, 0, $limit), 'total' => count($out)]);
    }
}
