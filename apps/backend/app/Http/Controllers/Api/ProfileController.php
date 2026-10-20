<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Models\Lead;
use App\Models\User;
use App\Support\Billing;
use App\Support\Enrollments;
use App\Support\StudentOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** "Farzandlarim" (ota-ona) va xodimlar uchun o'quvchi profili. */
class ProfileController
{
    public function __construct(private StudentOverview $overview, private Enrollments $enrollments) {}

    /** Ota-onaning farzandlari: har biri bo'yicha guruhlar, davomat, testlar, balans. */
    public function children(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== Role::Parent) {
            return response()->json(['data' => []]);
        }
        $children = User::whereIn('id', DB::table('parent_child')->where('parent_id', $user->id)->select('child_id'))
            ->where('role', Role::Student->value)->orderBy('first_name')->get();

        return response()->json(['data' => $children->map(fn (User $c) => $this->overview->build($c, Billing::canView($user, $c->id)))->values()]);
    }

    /** O'quvchi profili: kontaktlar, ota-onalar, a'zolik tarixi, davomat, natijalar, balans, arizalar. */
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        $manager = $viewer->hasPermission('user.manage') || $viewer->hasPermission('group.manage');
        // O'qituvchi — faqat o'z guruhlarida (hozir yoki avval) o'qigan o'quvchilarni ko'radi.
        $teacherGroups = $manager ? null : DB::table('study_groups')->where('teacher_id', $viewer->id)->pluck('id')->all();
        abort_unless($manager || ($viewer->hasPermission('attendance.mark') && $user->role === Role::Student
            && DB::table('group_members')->where('student_id', $user->id)->whereIn('group_id', $teacherGroups)->exists()), 403, 'Ruxsat yo\'q.');

        $parents = User::whereIn('id', DB::table('parent_child')->where('child_id', $user->id)->select('parent_id'))->orderBy('first_name')
            ->get()->map(fn (User $p) => ['id' => $p->id, 'first_name' => $p->first_name, 'last_name' => $p->last_name, 'phone' => $manager ? $p->phone : null,
                'telegram' => DB::table('telegram_links')->where('user_id', $p->id)->exists()]);
        $children = $user->role === Role::Parent
            ? User::whereIn('id', DB::table('parent_child')->where('parent_id', $user->id)->select('child_id'))->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'phone'])
            : collect();

        return response()->json([
            'user' => array_diff_key($user->toApi(), ['permissions' => 1]) + [
                'created_at' => $user->created_at?->toIso8601String(),
                'telegram' => DB::table('telegram_links')->where('user_id', $user->id)->exists(),
            ],
            'can_manage' => $manager,
            'parents' => $parents,
            'children' => $children,
            'overview' => $user->role === Role::Student ? $this->overview->build($user, Billing::canView($viewer, $user->id), 20) : null,
            'timeline' => $user->role === Role::Student ? $this->enrollments->timeline($user->id, $teacherGroups) : [],
            'leads' => $viewer->hasPermission('lead.manage')
                ? Lead::where('student_id', $user->id)->orWhere('phone', $user->phone)->orderByDesc('id')->limit(10)->get()
                    ->map(fn (Lead $l) => ['id' => $l->id, 'status' => $l->status, 'source' => $l->source, 'created_at' => $l->created_at?->toIso8601String()])
                : [],
        ]);
    }
}
