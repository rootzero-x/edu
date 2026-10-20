<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseController
{
    /** Kursni tahrirlash: admin — hammasi, o'qituvchi — faqat o'zi yaratgani. */
    public static function authorizeEdit(User $user, Course $course): void
    {
        if (! $user->isAdmin() && (int) $course->created_by !== (int) $user->id) {
            abort(403, 'Bu kursni faqat muallif yoki admin tahrirlay oladi.');
        }
    }

    private static function canManage(User $user): bool
    {
        return $user->role->can('course.manage');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $courses = Course::query()
            ->when(! self::canManage($user), fn ($q) => $q->where('published', true))
            ->withCount('modules')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $courses]);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $manage = self::canManage($request->user());
        if (! $manage && ! $course->published) {
            abort(404);
        }
        $course->load(['modules.lessons' => function ($q) use ($manage) {
            $q->select('id', 'module_id', 'title', 'position', 'published');
            if (! $manage) {
                $q->where('published', true);
            }
        }]);

        return response()->json(['course' => $course]);
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
            'cover_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'monthly_fee' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'duration_months' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'published' => ['sometimes', 'boolean'],
        ];
    }
}
