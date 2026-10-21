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

/** Xodimlar uchun o'quvchi profili. */
class ProfileController
{
    public function __construct(private StudentOverview $overview, private Enrollments $enrollments) {}

    /** O'quvchi profili: kontaktlar, a'zolik tarixi, davomat, natijalar, balans, arizalar. */
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        $manager = $viewer->hasPermission('user.manage') || $viewer->hasPermission('group.manage');
        // O'qituvchi — faqat o'z guruhlarida (hozir yoki avval) o'qigan o'quvchilarni ko'radi.
        $teacherGroups = $manager ? null : DB::table('study_groups')->where('teacher_id', $viewer->id)->pluck('id')->all();
        abort_unless($manager || ($viewer->hasPermission('attendance.mark') && $user->role === Role::Student
            && DB::table('group_members')->where('student_id', $user->id)->whereIn('group_id', $teacherGroups)->exists()), 403, 'Ruxsat yo\'q.');

        return response()->json([
            'user' => array_diff_key($user->toApi(), ['permissions' => 1]) + [
                'created_at' => $user->created_at?->toIso8601String(),
                'telegram' => DB::table('telegram_links')->where('user_id', $user->id)->exists(),
            ],
            'can_manage' => $manager,
            'overview' => $user->role === Role::Student ? $this->overview->build($user, Billing::canView($viewer, $user->id), 20, $teacherGroups) : null,
            'timeline' => $user->role === Role::Student ? $this->enrollments->timeline($user->id, $teacherGroups) : [],
            'leads' => $viewer->hasPermission('lead.manage')
                ? Lead::where('student_id', $user->id)->orWhere('phone', $user->phone)->orderByDesc('id')->limit(10)->get()
                    ->map(fn (Lead $l) => ['id' => $l->id, 'status' => $l->status, 'source' => $l->source, 'created_at' => $l->created_at?->toIso8601String()])
                : [],
        ]);
    }
}
