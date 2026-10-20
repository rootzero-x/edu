<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

/** Bosh sahifa uchun ochiq ma'lumotlar (login talab qilinmaydi). */
class LandingController
{
    /** E'lon qilingan kurslar — soni site.courses_limit sozlamasidan (0 — ko'rsatilmaydi). */
    public function courses(Settings $settings): JsonResponse
    {
        $limit = $settings->int('site.courses_limit');
        $courses = $limit > 0
            ? Course::query()->where('published', true)->withCount('modules')->orderByDesc('id')->limit($limit)
                ->get(['id', 'title', 'description', 'cover_url', 'monthly_fee', 'duration_months'])
            : collect();

        return response()->json(['data' => $courses])->header('Cache-Control', 'public, max-age=60');
    }
}
