<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\User;
use App\Support\Audit;
use App\Support\CourseAccess;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseController
{
    /** Kurs tarkibini (modul, dars, test) faqat muallif yoki admin o'zgartiradi. */
    public static function canEdit(User $user, Course $course): bool
    {
        return ($user->hasPermission('course.manage') || $user->hasPermission('course.author'))
            && ($user->isAdmin() || (int) $course->created_by === (int) $user->id);
    }

    public static function authorizeEdit(User $user, Course $course): void
    {
        if (! $user->isAdmin() && (int) $course->created_by !== (int) $user->id) {
            abort(403, 'Bu kursni faqat muallif yoki admin tahrirlay oladi.');
        }
    }

    /** Barcha kurslarni (yashirinlari bilan) ko'radi. */
    private static function canManage(User $user): bool
    {
        return $user->hasPermission('course.manage');
    }

    /** Kurs muallifi yoki shu kurs guruhining o'qituvchisi (course.author huquqi bilan yashirin kurslarini ham ko'radi). */
    public static function isOwnOrTaught(User $user, Course $course): bool
    {
        return (int) $course->created_by === (int) $user->id
            || DB::table('study_groups')->where(['course_id' => $course->id, 'teacher_id' => $user->id])->exists();
    }

    public function index(Request $request, CourseAccess $access): JsonResponse
    {
        $user = $request->user();
        $manage = self::canManage($user);
        $author = ! $manage && $user->hasPermission('course.author');
        $courses = Course::query()
            ->when(! $manage, fn ($q) => $q->where(fn ($w) => $w->where('published', true)
                ->when($author, fn ($a) => $a->orWhere('created_by', $user->id)
                    ->orWhereIn('id', DB::table('study_groups')->where('teacher_id', $user->id)->select('course_id')))))
            ->withCount('modules')
            ->orderByDesc('id')
            ->get();
        $granted = $manage ? null : array_flip($access->grantedCourseIds($user));
        $courses->each(fn ($c) => $c->setAttribute('access', ['granted' => $manage || isset($granted[$c->id])])
            ->setAttribute('can_edit', self::canEdit($user, $c)));

        return response()->json(['data' => $courses]);
    }

    public function show(Request $request, CourseAccess $access, Settings $settings, Course $course): JsonResponse
    {
        $user = $request->user();
        // Yashirin kursni muallif va uning o'qituvchilari ham ko'radi (tahrirlash/tayyorlanish uchun).
        $manage = self::canManage($user) || ($user->hasPermission('course.author') && self::isOwnOrTaught($user, $course));
        if (! $manage && ! $course->published) {
            abort(404);
        }
        $granted = $manage || $access->granted($request->user(), $course);
        if (! $granted && ! $settings->bool('course.show_outline_to_guests')) {
            $course->setRelation('modules', collect());

            return response()->json(['course' => $course, 'can_edit' => false, 'access' => ['granted' => false] + $access->denial($course)]);
        }
        $course->load(['modules.lessons' => function ($q) use ($manage) {
            $q->select('id', 'module_id', 'title', 'position', 'published');
            if (! $manage) {
                $q->where('published', true);
            }
        }]);

        return response()->json(['course' => $course, 'can_edit' => self::canEdit($request->user(), $course), 'access' => $granted ? ['granted' => true] : ['granted' => false] + $access->denial($course)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $course = Course::create($data + ['created_by' => $request->user()->id]);
        Audit::log('course.create', 'course', $course->id, ['title' => $course->title]);

        return response()->json(['course' => $course], 201);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        self::authorizeEdit($request->user(), $course);
        $data = $request->validate($this->rules(partial: true));
        $course->update($data);
        Audit::log('course.update', 'course', $course->id, $data);

        return response()->json(['course' => $course]);
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        self::authorizeEdit($request->user(), $course);
        if (DB::table('study_groups')->where('course_id', $course->id)->exists()) {
            abort(409, 'Kursga guruhlar biriktirilgan. Avval kursni yashiring (published=false).');
        }
        $course->delete();
        Audit::log('course.delete', 'course', $course->id, ['title' => $course->title]);

        return response()->json(['ok' => true]);
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$req, 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'cover_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'monthly_fee' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'duration_months' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'published' => ['sometimes', 'boolean'],
        ];
    }
}
