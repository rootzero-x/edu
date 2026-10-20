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
 *  - xodimlar (course.manage huquqi: admin, o'qituvchi), kurs muallifi,
 *  - kursning faol guruhi o'qituvchisi,
 *  - kursning faol guruhiga a'zo o'quvchi ochadi.
 *
 * courses.published faqat ochiq katalog uchun: kursi e'lon qilinmagan bo'lsa ham, yuqoridagilar uni,
 * uning e'lon qilingan darslari va testlarini ochadi. Qolganlar — tushunarli xabar (COURSE_UNPUBLISHED).
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
        $course = $course instanceof Course ? $course : Course::find($courseId);
        if ($course && (int) $course->created_by === (int) $user->id) {
            return true;
        }
        if (DB::table('study_groups')->where('course_id', $courseId)->where('active', true)->where('teacher_id', $user->id)->exists()) {
            return true;
        }

        return $this->enrolledStudent($user, $courseId);
    }

    /** O'quvchi shu kursning faol guruhida o'qiydimi. */
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
            ->where('group_members.student_id', $user->id)
            ->distinct()->pluck('study_groups.course_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Kursni (tuzilmasi bilan) ochish mumkinmi: e'lon qilingan yoki foydalanuvchining kontentga huquqi bor. */
    public function canOpen(User $user, Course $course): bool
    {
        return (bool) $course->published || $this->granted($user, $course);
    }

    /** E'lon qilinmagan kurs va unga aloqasi yo'q foydalanuvchi: 404 + sozlamadagi tushunarli xabar. */
    public function assertOpen(User $user, Course $course): void
    {
        if (! $this->canOpen($user, $course)) {
            throw new HttpResponseException(response()->json($this->unpublished($course), 404));
        }
    }

    public function unpublished(Course $course): array
    {
        $contact = (string) $this->settings->get('course.access_contact');
        $vars = ['{course}' => $course->title, '{contact}' => $contact];

        return [
            'code' => 'COURSE_UNPUBLISHED',
            'title' => strtr((string) $this->settings->get('course.unpublished_title'), $vars),
            'message' => strtr((string) $this->settings->get('course.unpublished_text'), $vars),
            'button' => strtr((string) $this->settings->get('course.access_denied_button'), $vars),
            'contact' => $contact,
            'contact_url' => (string) $this->settings->get('course.contact_url'),
            'course' => ['id' => $course->id, 'title' => $course->title],
        ];
    }

    /** Yopiq (e'lon qilinmagan) dars yoki test: 404 + xabar va kursga qaytish uchun ma'lumot. */
    public function unavailable(string $code, string $settingKey, Course $course): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'code' => $code,
            'title' => (string) $this->settings->get($settingKey.'_title'),
            'message' => strtr((string) $this->settings->get($settingKey.'_text'), ['{course}' => $course->title]),
            'course' => ['id' => $course->id, 'title' => $course->title],
        ], 404));
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
