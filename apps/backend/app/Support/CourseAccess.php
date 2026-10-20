<?php

namespace App\Support;

use App\Models\Course;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Kurs kontentiga (dars materiallari, testlar) kirish huquqi.
 * Kurs ro'yxati va tuzilmasi hammaga ko'rinadi; ichini faqat:
 *  - xodimlar (course.manage huquqi: admin, o'qituvchi),
 *  - kursning faol guruhiga a'zo o'quvchi,
 *  - shunday o'quvchining ota-onasi ochadi.
 */
final class CourseAccess
{
    public function __construct(private Settings $settings) {}

    public function granted(User $user, Course|int $course): bool
    {
        $courseId = $course instanceof Course ? $course->id : $course;
        if ($user->hasPermission('course.manage') || $user->isAdmin()) {
            return true;
        }

        return $this->enrolledQuery($courseId)->where(function ($w) use ($user) {
            $w->where('group_members.student_id', $user->id)
                ->orWhereIn('group_members.student_id', DB::table('parent_child')->where('parent_id', $user->id)->select('child_id'));
        })->exists();
    }

    /** O'quvchining o'zi shu kursda o'qiydimi (test topshirish uchun — ota-onasiz). */
    public function enrolledStudent(User $user, int $courseId): bool
    {
        return $this->enrolledQuery($courseId)->where('group_members.student_id', $user->id)->exists();
    }

    /** @return list<int> foydalanuvchi kontentini ocha oladigan kurs id'lari */
    public function grantedCourseIds(User $user): array
    {
        return DB::table('group_members')
            ->join('study_groups', 'study_groups.id', '=', 'group_members.group_id')
            ->whereNull('group_members.left_at')->where('study_groups.active', true)
            ->where(fn ($w) => $w->where('group_members.student_id', $user->id)
                ->orWhereIn('group_members.student_id', DB::table('parent_child')->where('parent_id', $user->id)->select('child_id')))
            ->distinct()->pluck('study_groups.course_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Ruxsat bo'lmasa 403 + sozlamadagi xabar (frontend/bot shu formatni ko'rsatadi). */
    public function authorize(User $user, Course $course): void
    {
        if (! $this->granted($user, $course)) {
            throw new HttpResponseException($this->deniedResponse($course));
        }
    }

    public function denial(Course $course): array
    {
        $contact = (string) $this->settings->get('course.access_contact');
        $vars = ['{course}' => $course->title, '{contact}' => $contact];

        return [
            'code' => 'COURSE_ACCESS_DENIED',
            'title' => strtr((string) $this->settings->get('course.access_denied_title'), $vars),
            'message' => strtr((string) $this->settings->get('course.access_denied_text'), $vars),
            'button' => strtr((string) $this->settings->get('course.access_denied_button'), $vars),
            'contact' => $contact,
            'contact_url' => (string) $this->settings->get('course.contact_url'),
            'course' => ['id' => $course->id, 'title' => $course->title],
        ];
    }

    public function deniedResponse(Course $course): JsonResponse
    {
        return response()->json($this->denial($course), 403);
    }

    private function enrolledQuery(int $courseId)
    {
        return DB::table('group_members')
            ->join('study_groups', 'study_groups.id', '=', 'group_members.group_id')
            ->whereNull('group_members.left_at')
            ->where('study_groups.active', true)
            ->where('study_groups.course_id', $courseId);
    }
}
