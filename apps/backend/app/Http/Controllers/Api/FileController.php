<?php

namespace App\Http\Controllers\Api;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Audit;
use App\Support\CourseAccess;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fayllar: dars materialini yuklash, yuklab olish havolasi (CourseAccess / guruh a'zoligi tekshiriladi),
 * imzolangan havola orqali yuklab olish (token kerak emas — imzo va muddat yetarli), o'chirish, joy hisobi.
 */
class FileController
{
    public function __construct(private Uploads $uploads) {}

    /** Dars materiali sifatida fayl: type=FILE, url o'rniga file_id. */
    public function uploadMaterial(Request $request, Lesson $lesson): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $lesson->module->course);
        $data = $request->validate(['file' => ['required', 'file'], 'title' => ['nullable', 'string', 'max:200']]);
        $material = DB::transaction(function () use ($request, $lesson, $data) {
            $file = $this->uploads->store($request->file('file'), 'material', null, $request->user()->id);
            $material = $lesson->materials()->create([
                'type' => 'FILE', 'title' => ($data['title'] ?? '') ?: pathinfo($file->original_name, PATHINFO_FILENAME),
                'file_id' => $file->id, 'position' => (int) $lesson->materials()->max('position') + 1,
            ]);
            $file->update(['owner_id' => $material->id]);

            return $material;
        });
        Audit::log('file.upload', 'material', $material->id, ['file_id' => $material->file_id, 'lesson_id' => $lesson->id, 'name' => $material->file->original_name]);

        return response()->json(['material' => $material->fresh()], 201);
    }

    /** Qisqa muddatli yuklab olish havolasi — faqat ruxsati borlarga. */
    public function link(Request $request, CourseAccess $access, StoredFile $file): JsonResponse
    {
        $this->authorizeView($request->user(), $access, $file);

        return response()->json($this->uploads->link($file));
    }

    /** Imzolangan havola (signed:relative): faylni uzatish. */
    public function download(StoredFile $file): Response
    {
        $disk = Storage::disk(Uploads::DISK);
        abort_unless($disk->exists($file->path), 404);
        $disposition = Uploads::inline($file) ? 'inline' : 'attachment';
        // Brauzerning PDF ko'rsatkichi "sandbox" ostida ishlamaydi; boshqa turlar to'liq izolyatsiyada ochiladi.
        $sandbox = $file->mime === 'application/pdf' ? '' : '; sandbox';

        return $disk->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; object-src 'self'".$sandbox,
            'Cache-Control' => 'private, max-age=300',
            'Referrer-Policy' => 'no-referrer',
        ], $disposition);
    }

    public function destroy(Request $request, StoredFile $file): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canDelete($user, $file), 403, 'Ruxsat yo\'q.');
        if ($file->owner_type === 'material') {
            // Material fayli bilan birga materialning o'zi ham olib tashlanadi.
            LessonMaterial::where('id', $file->owner_id)->where('file_id', $file->id)->delete();
        }
        $this->uploads->delete($file);
        Audit::log('file.delete', $file->owner_type, $file->owner_id, ['file_id' => $file->id, 'name' => $file->original_name]);

        return response()->json(['ok' => true]);
    }

    /** Sozlamalar sahifasi uchun: band joy, kvota, ruxsat etilgan turlar, zaxira papkasi. */
    public function usage(): JsonResponse
    {
        return response()->json($this->uploads->usage());
    }

    private function authorizeView(User $user, CourseAccess $access, StoredFile $file): void
    {
        switch ($file->owner_type) {
            case 'material':
                $material = LessonMaterial::with('lesson.module.course')->where('file_id', $file->id)->find($file->owner_id);
                abort_unless($material, 404);
                $lesson = $material->lesson;
                $course = $lesson->module->course;
                // Darsni ko'rish qoidasi bilan bir xil (CurriculumController::showLesson).
                $manage = $user->hasPermission('course.manage') || ($user->hasPermission('course.author') && CourseController::isOwnOrTaught($user, $course));
                $access->assertOpen($user, $course);
                if (! $manage && ! $lesson->published) {
                    abort(404);
                }
                $access->authorize($user, $course);

                return;
            case 'assignment':
                $a = Assignment::with('group')->find($file->owner_id);
                abort_unless($a && (HomeworkController::canManage($user, $a->group) || HomeworkController::isMember($user->id, $a->group_id)), 404);

                return;
            case 'submission':
                $s = AssignmentSubmission::with('assignment.group')->find($file->owner_id);
                abort_unless($s && ((int) $s->student_id === (int) $user->id || HomeworkController::canManage($user, $s->assignment->group)), 404);

                return;
        }
        abort(404);
    }

    private function canDelete(User $user, StoredFile $file): bool
    {
        switch ($file->owner_type) {
            case 'material':
                $material = LessonMaterial::with('lesson.module.course')->find($file->owner_id);

                return $material && CourseController::canEdit($user, $material->lesson->module->course);
            case 'assignment':
                $a = Assignment::with('group')->find($file->owner_id);

                return $a && HomeworkController::canManage($user, $a->group);
            case 'submission':
                $s = AssignmentSubmission::with('assignment.group')->find($file->owner_id);
                if (! $s) {
                    return false;
                }
                // O'quvchi — baholanmaguncha o'z faylini; o'qituvchi — har doim.
                return HomeworkController::canManage($user, $s->assignment->group)
                    || ((int) $s->student_id === (int) $user->id && $s->score === null);
        }

        return false;
    }
}
