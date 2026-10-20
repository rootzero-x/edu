<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Modullar, darslar va materiallar. Tahrirlash huquqi kurs bo'yicha tekshiriladi. */
class CurriculumController
{
    // ── Modullar ─────────────────────────────────────────────

    public function storeModule(Request $request, Course $course): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $course);
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'position' => ['sometimes', 'integer', 'min:0']]);
        $data['position'] ??= (int) $course->modules()->max('position') + 1;
        $module = $course->modules()->create($data);
        Audit::log('module.create', 'module', $module->id, $data);

        return response()->json(['module' => $module], 201);
    }

    public function updateModule(Request $request, CourseModule $module): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $module->course);
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:200'], 'position' => ['sometimes', 'integer', 'min:0']]);
        $module->update($data);

        return response()->json(['module' => $module]);
    }

    public function destroyModule(Request $request, CourseModule $module): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $module->course);
        $module->delete();
        Audit::log('module.delete', 'module', $module->id, ['title' => $module->title]);

        return response()->json(['ok' => true]);
    }

    // ── Darslar ──────────────────────────────────────────────

    public function showLesson(Request $request, Lesson $lesson): JsonResponse
    {
        $lesson->load('module.course', 'materials');
        $manage = $request->user()->role->can('course.manage');
        if (! $manage && (! $lesson->published || ! $lesson->module->course->published)) {
            abort(404);
        }

        return response()->json(['lesson' => $lesson]);
    }

    public function storeLesson(Request $request, CourseModule $module): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $module->course);
        $data = $request->validate($this->lessonRules());
        $data['position'] ??= (int) $module->lessons()->max('position') + 1;
        $lesson = $module->lessons()->create($data);
        Audit::log('lesson.create', 'lesson', $lesson->id, ['title' => $lesson->title]);

        return response()->json(['lesson' => $lesson], 201);
    }

    public function updateLesson(Request $request, Lesson $lesson): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $lesson->module->course);
        $data = $request->validate($this->lessonRules(partial: true));
        $lesson->update($data);

        return response()->json(['lesson' => $lesson]);
    }

    public function destroyLesson(Request $request, Lesson $lesson): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $lesson->module->course);
        $lesson->delete();
        Audit::log('lesson.delete', 'lesson', $lesson->id, ['title' => $lesson->title]);

        return response()->json(['ok' => true]);
    }

    // ── Materiallar ──────────────────────────────────────────

    public function storeMaterial(Request $request, Lesson $lesson): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $lesson->module->course);
        $data = $request->validate($this->materialRules());
        $data['position'] ??= (int) $lesson->materials()->max('position') + 1;
        $material = $lesson->materials()->create($data);

        return response()->json(['material' => $material], 201);
    }

    public function updateMaterial(Request $request, LessonMaterial $material): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $material->lesson->module->course);
        $data = $request->validate($this->materialRules(partial: true));
        $material->update($data);

        return response()->json(['material' => $material]);
    }

    public function destroyMaterial(Request $request, LessonMaterial $material): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $material->lesson->module->course);
        $material->delete();

        return response()->json(['ok' => true]);
    }

    private function lessonRules(bool $partial = false): array
    {
        return [
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'published' => ['sometimes', 'boolean'],
        ];
    }

    private function materialRules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'type' => [$req, Rule::in(['VIDEO', 'FILE', 'LINK', 'TEXT'])],
            'title' => [$req, 'string', 'max:200'],
            'url' => ['sometimes', 'nullable', 'url:http,https', 'max:2000', 'required_if:type,VIDEO,FILE,LINK'],
            'content' => ['sometimes', 'nullable', 'string', 'max:200000', 'required_if:type,TEXT'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
